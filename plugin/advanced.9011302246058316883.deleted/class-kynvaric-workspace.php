<?php
/**
 * SBKynvaricWorkspace — Regulated review workspace for CPA-class workflows.
 *
 * Manages review sessions, queue items (exceptions, confidence breaches,
 * sign-off requests), and sign-off records.
 *
 * Reuses existing ASK5 forms, surfaces, placements, and approval engine
 * while enforcing ASK5.5 authority and segregation rules.
 *
 * @package SovereignBuilder
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBKynvaricWorkspace {

	// ── Queue types ───────────────────────────────────────────────────────────
	const QUEUE_TYPES = [ 'exception', 'confidence_breach', 'signoff' ];

	// ── Session statuses ─────────────────────────────────────────────────────
	const SESSION_STATUSES = [ 'open', 'in_review', 'signed_off', 'returned' ];

	// ── Sign-off types ────────────────────────────────────────────────────────
	const SIGNOFF_TYPES = [ 'preparer_review', 'cpa_review', 'final_commit' ];

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'kynvaric-workspace', '2.1.0', 'SBKynvaricWorkspace' );
		} );
		// Register Kynvaric admin screens
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_screens' ], 25 );
	}

	// ── Review session management ─────────────────────────────────────────────

	/**
	 * Open a new review session.
	 *
	 * @param  array $args { client_id, engagement_id, assigned_to_user_id }
	 * @return int|WP_Error
	 */
	public static function open_session( array $args ): int|WP_Error {
		global $wpdb;

		$row = [
			'session_uuid'         => wp_generate_uuid4(),
			'client_id'            => absint( $args['client_id'] ?? 0 ),
			'engagement_id'        => absint( $args['engagement_id'] ?? 0 ),
			'status'               => 'open',
			'opened_by_user_id'    => get_current_user_id(),
			'assigned_to_user_id' => absint( $args['assigned_to_user_id'] ?? get_current_user_id() ),
			'started_at'           => current_time( 'mysql' ),
			'created_at'           => current_time( 'mysql' ),
		];

		$wpdb->insert( "{$wpdb->prefix}sb_review_sessions", $row );
		$session_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_REVIEW_SESSION_OPENED,
			"Review session {$session_id} opened.",
			get_current_user_id(),
			[ 'session_id' => $session_id ]
		);

		return $session_id;
	}

	/**
	 * Transition a review session status.
	 *
	 * @return true|WP_Error
	 */
	public static function transition_session( int $session_id, string $to_status, string $note = '' ): bool|WP_Error {
		global $wpdb;

		if ( ! in_array( $to_status, self::SESSION_STATUSES, true ) ) {
			return new WP_Error( 'invalid_session_status', "Invalid session status '{$to_status}'.", [ 'status' => 422 ] );
		}

		$updates = [ 'status' => $to_status ];
		if ( in_array( $to_status, [ 'signed_off', 'returned' ], true ) ) {
			$updates['closed_at'] = current_time( 'mysql' );
		}

		$wpdb->update( "{$wpdb->prefix}sb_review_sessions", $updates, [ 'id' => $session_id ] );

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_REVIEW_SESSION_TRANSITIONED,
			"Review session {$session_id} → {$to_status}.",
			get_current_user_id(),
			[ 'session_id' => $session_id, 'status' => $to_status, 'note' => $note ]
		);

		return true;
	}

	// ── Queue item management ─────────────────────────────────────────────────

	/**
	 * Add an item to the review queue.
	 *
	 * @param  array $args { review_session_id, queue_type, proposal_id, severity, assigned_to_user_id }
	 * @return int|WP_Error
	 */
	public static function enqueue_item( array $args ): int|WP_Error {
		global $wpdb;

		$queue_type = sanitize_key( $args['queue_type'] ?? 'exception' );
		if ( ! in_array( $queue_type, self::QUEUE_TYPES, true ) ) {
			return new WP_Error( 'invalid_queue_type', "Invalid queue_type '{$queue_type}'.", [ 'status' => 400 ] );
		}

		$wpdb->insert( "{$wpdb->prefix}sb_review_queue_items", [
			'review_session_id'    => absint( $args['review_session_id'] ?? 0 ),
			'queue_type'           => $queue_type,
			'proposal_id'          => absint( $args['proposal_id'] ?? 0 ) ?: null,
			'severity'             => sanitize_key( $args['severity'] ?? 'medium' ),
			'status'               => 'open',
			'assigned_to_user_id' => absint( $args['assigned_to_user_id'] ?? 0 ) ?: null,
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		] );

		$item_id = (int) $wpdb->insert_id;
		do_action( 'sb_kynvaric_queue_item_added', $item_id, $queue_type, $args );
		return $item_id;
	}

	/**
	 * Update a queue item status.
	 *
	 * @param  string $to_status  open|snoozed|resolved|escalated
	 * @return true|WP_Error
	 */
	public static function update_queue_item( int $item_id, string $to_status ): bool|WP_Error {
		global $wpdb;
		$allowed = [ 'open', 'snoozed', 'resolved', 'escalated' ];
		if ( ! in_array( $to_status, $allowed, true ) ) {
			return new WP_Error( 'invalid_queue_item_status', "Invalid status '{$to_status}'.", [ 'status' => 422 ] );
		}
		$wpdb->update( "{$wpdb->prefix}sb_review_queue_items",
			[ 'status' => $to_status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $item_id ]
		);
		return true;
	}

	// ── Sign-off ──────────────────────────────────────────────────────────────

	/**
	 * Record a sign-off on a review session.
	 *
	 * Sign-offs are append-only. To revoke: create a new row with
	 * status = 'voided_forward' referencing the original.
	 *
	 * @param  array $args { review_session_id, signoff_type, authority_event_id }
	 * @return int|WP_Error  New sign-off record ID.
	 */
	public static function record_signoff( array $args ): int|WP_Error {
		global $wpdb;

		$signoff_type = sanitize_key( $args['signoff_type'] ?? 'preparer_review' );
		if ( ! in_array( $signoff_type, self::SIGNOFF_TYPES, true ) ) {
			return new WP_Error( 'invalid_signoff_type', "Invalid signoff_type '{$signoff_type}'.", [ 'status' => 400 ] );
		}

		$session_id = absint( $args['review_session_id'] ?? 0 );
		if ( ! $session_id ) {
			return new WP_Error( 'signoff_no_session', 'review_session_id is required.', [ 'status' => 400 ] );
		}

		$wpdb->insert( "{$wpdb->prefix}sb_signoff_records", [
			'review_session_id'  => $session_id,
			'signoff_type'       => $signoff_type,
			'signed_by_user_id' => get_current_user_id(),
			'authority_event_id'=> absint( $args['authority_event_id'] ?? 0 ) ?: null,
			'status'             => 'signed',
			'created_at'         => current_time( 'mysql' ),
		] );

		$signoff_id = (int) $wpdb->insert_id;

		// If final_commit signoff, transition session to signed_off
		if ( $signoff_type === 'final_commit' ) {
			self::transition_session( $session_id, 'signed_off' );
		}

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_SIGNOFF_RECORDED,
			"Sign-off {$signoff_id} recorded ({$signoff_type}) on session {$session_id}.",
			get_current_user_id(),
			[ 'signoff_id' => $signoff_id, 'session_id' => $session_id, 'type' => $signoff_type ]
		);

		// If regulated blueprint, record authority event
		if ( ! empty( $args['is_regulated'] ) ) {
			SBAuditLedgerPlus::record_authority_event(
				"signoff_{$signoff_type}",
				[
					'signoff_id'  => $signoff_id,
					'session_id'  => $session_id,
					'signoff_type'=> $signoff_type,
					'domain_key'  => 'kynvaric',
				],
				get_current_user_id()
			);
		}

		return $signoff_id;
	}

	/**
	 * Void a sign-off forward (append-only — never deletes original).
	 *
	 * @param  int    $signoff_id  Original sign-off to void.
	 * @param  string $reason
	 * @return int|WP_Error        New voiding record ID.
	 */
	public static function void_signoff( int $signoff_id, string $reason = '' ): int|WP_Error {
		global $wpdb;

		$original = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_signoff_records WHERE id = %d", $signoff_id ), ARRAY_A );
		if ( ! $original ) {
			return new WP_Error( 'signoff_not_found', "Sign-off {$signoff_id} not found.", [ 'status' => 404 ] );
		}

		// A sign-off cannot be deleted. Voiding creates a new row with voided_forward status.
		// The original row remains visible and unchanged. Only the new voiding row is current.
		$wpdb->insert( "{$wpdb->prefix}sb_signoff_records", [
			'review_session_id'  => (int) $original['review_session_id'],
			'signoff_type'       => $original['signoff_type'],
			'signed_by_user_id' => get_current_user_id(),
			'authority_event_id'=> null,
			'status'             => 'voided_forward',
			'voided_signoff_id' => $signoff_id,
			'void_reason'        => sanitize_textarea_field( $reason ),
			'created_at'         => current_time( 'mysql' ),
		] );

		$void_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_SIGNOFF_VOIDED,
			"Sign-off {$signoff_id} voided forward. New record: {$void_id}.",
			get_current_user_id(),
			[ 'original_signoff_id' => $signoff_id, 'void_record_id' => $void_id ]
		);

		return $void_id;
	}

	// ── Read helpers ──────────────────────────────────────────────────────────

	public static function get_session( int $session_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_review_sessions WHERE id = %d", $session_id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['queue_items'] = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_review_queue_items WHERE review_session_id = %d ORDER BY severity DESC, id DESC", $session_id ),
			ARRAY_A
		) ?: [];
		$row['signoffs'] = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_signoff_records WHERE review_session_id = %d ORDER BY id ASC", $session_id ),
			ARRAY_A
		) ?: [];
		return $row;
	}

	public static function list_sessions( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$values = [];
		if ( ! empty( $filters['status'] ) ) { $where[] = 'status = %s'; $values[] = $filters['status']; }
		if ( ! empty( $filters['assigned_to_user_id'] ) ) { $where[] = 'assigned_to_user_id = %d'; $values[] = (int) $filters['assigned_to_user_id']; }
		$per_page  = min( 200, max( 1, $per_page ) );
		$offset    = ( max( 1, $page ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_review_sessions WHERE {$where_sql}", ...$values ) : "SELECT COUNT(*) FROM {$wpdb->prefix}sb_review_sessions WHERE {$where_sql}" );
		$rows  = $wpdb->get_results( $values ? $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_review_sessions WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...[...$values, $per_page, $offset] ) : "SELECT * FROM {$wpdb->prefix}sb_review_sessions WHERE {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}", ARRAY_A );
		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	// ── Admin screens ─────────────────────────────────────────────────────────

	public static function register_admin_screens(): void {
		$screens = [
			[ 'title' => 'Kynvaric Overview',    'slug' => 'sb-kynvaric-overview',  'cap' => 'manage_kynvaric_proposals',       'cb' => 'render_overview' ],
			[ 'title' => 'Proposal Inbox',       'slug' => 'sb-proposal-inbox',     'cap' => 'manage_kynvaric_proposals',       'cb' => 'render_proposal_inbox' ],
			[ 'title' => 'Commit Gates',         'slug' => 'sb-commit-gates',       'cap' => 'approve_kynvaric_commits',        'cb' => 'render_commit_gates' ],
			[ 'title' => 'Review Sessions',      'slug' => 'sb-review-sessions',    'cap' => 'manage_kynvaric_review_sessions', 'cb' => 'render_review_sessions' ],
			[ 'title' => 'Exception Queue',      'slug' => 'sb-exception-queue',    'cap' => 'manage_kynvaric_review_sessions', 'cb' => 'render_exception_queue' ],
			[ 'title' => 'Sign-off Console',     'slug' => 'sb-signoff-console',    'cap' => 'sign_off_kynvaric',              'cb' => 'render_signoff_console' ],
			[ 'title' => 'Evidence Vault',       'slug' => 'sb-evidence-vault',     'cap' => 'manage_kynvaric_evidence',        'cb' => 'render_evidence_vault' ],
			[ 'title' => 'Ledger Integrity',     'slug' => 'sb-ledger-integrity',   'cap' => 'view_kynvaric_ledger',            'cb' => 'render_ledger_integrity' ],
		];

		foreach ( $screens as $screen ) {
			add_submenu_page(
				'sovereign-builder',
				$screen['title'],
				$screen['title'],
				$screen['cap'],
				$screen['slug'],
				[ __CLASS__, $screen['cb'] ]
			);
		}
	}

	// ── Admin screen renderers ────────────────────────────────────────────────

	public static function render_overview(): void {
		if ( ! current_user_can( 'manage_kynvaric_proposals' ) ) { wp_die( 'Forbidden.' ); }
		$guard = SBAdminGuard::require_tables( [ 'sb_apo_store', 'sb_review_sessions', 'sb_authority_events', 'sb_commit_requests' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;
		$apo_counts     = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}sb_apo_store GROUP BY status", ARRAY_A );
		$session_counts = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}sb_review_sessions GROUP BY status", ARRAY_A );
		$event_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_authority_events" );
		$pending_commits= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_commit_requests WHERE status IN ('pending','ready')" );
		?>
		<div class="wrap">
			<h1>Kynvaric — Regulated Workflow Overview</h1>
			<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:20px 0;">
				<div class="postbox" style="padding:16px;text-align:center;">
					<h2 style="font-size:2em;margin:0"><?php echo esc_html( $event_count ); ?></h2>
					<p>Authority Events</p>
				</div>
				<div class="postbox" style="padding:16px;text-align:center;">
					<h2 style="font-size:2em;margin:0"><?php echo esc_html( $pending_commits ); ?></h2>
					<p>Pending Commits</p>
				</div>
				<div class="postbox" style="padding:16px;">
					<h3>APOs by Status</h3>
					<?php foreach ( $apo_counts as $row ) { echo '<div>' . esc_html( $row['status'] ) . ': <strong>' . esc_html( $row['cnt'] ) . '</strong></div>'; } ?>
				</div>
				<div class="postbox" style="padding:16px;">
					<h3>Sessions by Status</h3>
					<?php foreach ( $session_counts as $row ) { echo '<div>' . esc_html( $row['status'] ) . ': <strong>' . esc_html( $row['cnt'] ) . '</strong></div>'; } ?>
				</div>
			</div>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-proposal-inbox' ) ); ?>" class="button button-primary">Open Proposal Inbox</a>
			&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-commit-gates' ) ); ?>" class="button">Commit Gates</a>
			&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-ledger-integrity' ) ); ?>" class="button">Ledger Integrity</a></p>
		</div>
		<?php
	}

	public static function render_proposal_inbox(): void {
		if ( ! current_user_can( 'manage_kynvaric_proposals' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$status  = sanitize_key( $_GET['status'] ?? 'queued_review' );
		$page_n  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$apos    = SBProposalAuthority::list( [ 'status' => $status ], $page_n, 25 );
		$statuses = SBProposalAuthority::STATES;
		?>
		<div class="wrap">
			<h1>Proposal Inbox</h1>
			<ul class="subsubsub">
				<?php foreach ( $statuses as $s ) {
					$url = admin_url( "admin.php?page=sb-proposal-inbox&status={$s}" );
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $s ) ) ) . '</a></li>';
				} ?>
			</ul>
			<table class="widefat striped" style="margin-top:20px">
				<thead><tr><th>ID</th><th>Type</th><th>Domain</th><th>Subject</th><th>Confidence</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( empty( $apos['items'] ) ) { echo '<tr><td colspan="8">No proposals found.</td></tr>'; }
				foreach ( $apos['items'] as $apo ) { ?>
					<tr>
						<td><?php echo esc_html( $apo['id'] ); ?></td>
						<td><?php echo esc_html( $apo['proposal_type'] ); ?></td>
						<td><?php echo esc_html( $apo['domain_key'] ); ?></td>
						<td><?php echo esc_html( $apo['subject_type'] . ' #' . $apo['subject_id'] ); ?></td>
						<td><?php echo esc_html( number_format( (float) $apo['confidence_score'] * 100, 1 ) . '%' ); ?></td>
						<td><span class="sb-status-badge"><?php echo esc_html( $apo['status'] ); ?></span></td>
						<td><?php echo esc_html( $apo['created_at'] ); ?></td>
						<td>
							<button class="button button-small sb-apo-transition"
								data-apo-id="<?php echo esc_attr( $apo['id'] ); ?>"
								data-to="approved_for_commit"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">Approve</button>
							<button class="button button-small sb-apo-transition"
								data-apo-id="<?php echo esc_attr( $apo['id'] ); ?>"
								data-to="rejected"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">Reject</button>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
			<p>Total: <?php echo esc_html( $apos['total'] ); ?> | Page <?php echo esc_html( $page_n ); ?></p>
		</div>
		<script>
		document.querySelectorAll('.sb-apo-transition').forEach(btn => {
			btn.addEventListener('click', async () => {
				const apoId = btn.dataset.apoId, to = btn.dataset.to, nonce = btn.dataset.nonce;
				const res = await fetch('<?php echo esc_url_raw( rest_url( 'sovereign-builder/v1/apo-transition' ) ); ?>', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify({ apo_id: parseInt(apoId), to_status: to, reason_code: 'operator_action' })
				});
				const data = await res.json();
				if (data.success) { location.reload(); } else { alert('Error: ' + (data.error || 'Unknown')); }
			});
		});
		</script>
		<?php
	}

	public static function render_commit_gates(): void {
		if ( ! current_user_can( 'approve_kynvaric_commits' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$status  = sanitize_key( $_GET['status'] ?? 'pending' );
		$page_n  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per     = 25;
		$offset  = ( $page_n - 1 ) * $per;
		$commits = $wpdb->get_results( $wpdb->prepare(
			"SELECT cr.*, a.proposal_type, a.domain_key, a.subject_type, a.subject_id,
			        (SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sb_commit_approvers WHERE commit_request_id = cr.id) as approver_count
			 FROM {$wpdb->prefix}sb_commit_requests cr
			 LEFT JOIN {$wpdb->prefix}sb_apo_store a ON a.id = cr.apo_id
			 WHERE cr.status = %s ORDER BY cr.id DESC LIMIT %d OFFSET %d",
			$status, $per, $offset
		), ARRAY_A ) ?: [];
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1>Commit Gates</h1>
			<table class="widefat striped">
				<thead><tr><th>Commit ID</th><th>APO Type</th><th>Domain</th><th>Target Store</th><th>Sensitivity</th><th>Approvers</th><th>Status</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( empty( $commits ) ) { echo '<tr><td colspan="8">No commit requests found.</td></tr>'; }
				foreach ( $commits as $c ) { ?>
					<tr>
						<td><?php echo esc_html( $c['id'] ); ?></td>
						<td><?php echo esc_html( $c['proposal_type'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $c['domain_key'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $c['target_store'] ); ?></td>
						<td><?php echo esc_html( $c['sensitivity_level'] ); ?></td>
						<td><?php echo esc_html( $c['approver_count'] ); ?></td>
						<td><?php echo esc_html( $c['status'] ); ?></td>
						<td>
						<?php if ( in_array( $c['status'], [ 'pending', 'ready' ], true ) ) { ?>
							<button class="button button-primary button-small sb-commit-approve"
								data-commit-id="<?php echo esc_attr( $c['id'] ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">Approve</button>
							<?php if ( $c['status'] === 'ready' ) { ?>
							<button class="button button-small sb-commit-execute"
								data-commit-id="<?php echo esc_attr( $c['id'] ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">Execute</button>
							<?php } ?>
							<button class="button button-small sb-commit-reject"
								data-commit-id="<?php echo esc_attr( $c['id'] ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">Reject</button>
						<?php } ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
		<script>
		const sbApiBase = '<?php echo esc_url_raw( rest_url( 'sovereign-builder/v1' ) ); ?>';
		const sbAct = async (endpoint, commitId, nonce) => {
			const res = await fetch(sbApiBase + endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ commit_id: parseInt(commitId) })
			});
			const d = await res.json();
			if (d.error) { alert('Error: ' + d.error + (d.rule ? ' [' + d.rule + ']' : '')); } else { location.reload(); }
		};
		document.querySelectorAll('.sb-commit-approve').forEach(b => b.onclick = () => sbAct('/commit-approve', b.dataset.commitId, b.dataset.nonce));
		document.querySelectorAll('.sb-commit-execute').forEach(b => b.onclick = () => { if(confirm('Execute this commit? This is irreversible.')) sbAct('/commit-execute', b.dataset.commitId, b.dataset.nonce); });
		document.querySelectorAll('.sb-commit-reject').forEach(b => b.onclick = () => sbAct('/commit-reject', b.dataset.commitId, b.dataset.nonce));
		</script>
		<?php
	}

	public static function render_review_sessions(): void {
		if ( ! current_user_can( 'manage_kynvaric_review_sessions' ) ) { wp_die( 'Forbidden.' ); }
		$page_n   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$sessions = self::list_sessions( [], $page_n, 25 );
		?>
		<div class="wrap">
			<h1>Review Sessions
				<a href="#" class="page-title-action sb-open-session" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">Open New Session</a>
			</h1>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Client</th><th>Engagement</th><th>Status</th><th>Assigned To</th><th>Opened</th><th>Closed</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( empty( $sessions['items'] ) ) { echo '<tr><td colspan="8">No sessions found.</td></tr>'; }
				foreach ( $sessions['items'] as $s ) { ?>
					<tr>
						<td><?php echo esc_html( $s['id'] ); ?></td>
						<td><?php echo esc_html( $s['client_id'] ); ?></td>
						<td><?php echo esc_html( $s['engagement_id'] ); ?></td>
						<td><?php echo esc_html( $s['status'] ); ?></td>
						<td><?php $u = get_user_by( 'id', $s['assigned_to_user_id'] ); echo esc_html( $u ? $u->display_name : $s['assigned_to_user_id'] ); ?></td>
						<td><?php echo esc_html( $s['started_at'] ); ?></td>
						<td><?php echo esc_html( $s['closed_at'] ?? '—' ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( "admin.php?page=sb-review-sessions&session_id={$s['id']}" ) ); ?>" class="button button-small">View</a></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
			<p>Total: <?php echo esc_html( $sessions['total'] ); ?></p>
		</div>
		<?php
	}

	public static function render_exception_queue(): void {
		if ( ! current_user_can( 'manage_kynvaric_review_sessions' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$items = $wpdb->get_results(
			"SELECT qi.*, rs.client_id, rs.engagement_id
			 FROM {$wpdb->prefix}sb_review_queue_items qi
			 LEFT JOIN {$wpdb->prefix}sb_review_sessions rs ON rs.id = qi.review_session_id
			 WHERE qi.status = 'open'
			 ORDER BY FIELD(qi.severity,'critical','high','medium','low'), qi.id ASC
			 LIMIT 100",
			ARRAY_A
		) ?: [];
		?>
		<div class="wrap">
			<h1>Exception Queue</h1>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Session</th><th>Client</th><th>Type</th><th>Severity</th><th>Proposal</th><th>Status</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( empty( $items ) ) { echo '<tr><td colspan="8">Queue is clear.</td></tr>'; }
				foreach ( $items as $item ) {
					$severity_color = match( $item['severity'] ) { 'critical' => '#c00', 'high' => '#e65', 'medium' => '#e90', default => '#888' };
				?>
					<tr>
						<td><?php echo esc_html( $item['id'] ); ?></td>
						<td><?php echo esc_html( $item['review_session_id'] ); ?></td>
						<td><?php echo esc_html( $item['client_id'] ); ?></td>
						<td><?php echo esc_html( $item['queue_type'] ); ?></td>
						<td><span style="color:<?php echo esc_attr( $severity_color ); ?>;font-weight:bold"><?php echo esc_html( strtoupper( $item['severity'] ) ); ?></span></td>
						<td><?php echo esc_html( $item['proposal_id'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $item['status'] ); ?></td>
						<td>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'sb_queue_action' ); ?>
								<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
								<button name="queue_action" value="resolved" class="button button-small">Resolve</button>
								<button name="queue_action" value="escalated" class="button button-small">Escalate</button>
								<button name="queue_action" value="snoozed" class="button button-small">Snooze</button>
							</form>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
		<?php
		// Handle queue item updates
		if ( isset( $_POST['queue_action'] ) && check_admin_referer( 'sb_queue_action' ) ) {
			$item_id = absint( $_POST['item_id'] ?? 0 );
			$action  = sanitize_key( $_POST['queue_action'] );
			self::update_queue_item( $item_id, $action );
			wp_safe_redirect( admin_url( 'admin.php?page=sb-exception-queue' ) );
			exit;
		}
	}

	public static function render_signoff_console(): void {
		if ( ! current_user_can( 'sign_off_kynvaric' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$sessions_open = $wpdb->get_results(
			"SELECT rs.*, COUNT(qi.id) as open_items
			 FROM {$wpdb->prefix}sb_review_sessions rs
			 LEFT JOIN {$wpdb->prefix}sb_review_queue_items qi ON qi.review_session_id = rs.id AND qi.status = 'open'
			 WHERE rs.status IN ('open','in_review')
			 GROUP BY rs.id ORDER BY rs.id DESC LIMIT 50",
			ARRAY_A
		) ?: [];
		?>
		<div class="wrap">
			<h1>Sign-off Console</h1>
			<?php if ( empty( $sessions_open ) ) { echo '<p>No sessions awaiting sign-off.</p>'; }
			foreach ( $sessions_open as $s ) { ?>
			<div class="postbox" style="padding:16px;margin-bottom:16px">
				<h2>Session #<?php echo esc_html( $s['id'] ); ?> — Client <?php echo esc_html( $s['client_id'] ); ?></h2>
				<p>Status: <?php echo esc_html( $s['status'] ); ?> | Open queue items: <?php echo esc_html( $s['open_items'] ); ?></p>
				<?php if ( (int) $s['open_items'] > 0 ) { echo '<p style="color:#c00">⚠ Resolve all queue items before signing off.</p>'; } else { ?>
				<form method="post">
					<?php wp_nonce_field( 'sb_signoff_action' ); ?>
					<input type="hidden" name="session_id" value="<?php echo esc_attr( $s['id'] ); ?>">
					<label>Sign-off type:
						<select name="signoff_type">
							<?php foreach ( self::SIGNOFF_TYPES as $t ) { echo '<option value="' . esc_attr( $t ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $t ) ) ) . '</option>'; } ?>
						</select>
					</label>
					&nbsp;<button class="button button-primary" name="signoff_submit" value="1">Record Sign-off</button>
				</form>
				<?php } ?>
			</div>
			<?php } ?>
		</div>
		<?php
		// Handle sign-off form submission
		if ( isset( $_POST['signoff_submit'] ) && check_admin_referer( 'sb_signoff_action' ) ) {
			if ( ! current_user_can( 'sign_off_kynvaric' ) ) { wp_die( 'Forbidden.' ); }
			self::record_signoff( [
				'review_session_id' => absint( $_POST['session_id'] ?? 0 ),
				'signoff_type'      => sanitize_key( $_POST['signoff_type'] ?? 'preparer_review' ),
				'is_regulated'      => false, // Set true if regulated blueprint context
			] );
			wp_safe_redirect( admin_url( 'admin.php?page=sb-signoff-console' ) );
			exit;
		}
	}

	public static function render_evidence_vault(): void {
		if ( ! current_user_can( 'manage_kynvaric_evidence' ) ) { wp_die( 'Forbidden.' ); }
		$page_n = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$items  = SBEvidenceVault::list_items( [], $page_n, 25 );
		?>
		<div class="wrap">
			<h1>Evidence Vault</h1>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Type</th><th>Provider</th><th>Path</th><th>Subject</th><th>Retention</th><th>Uploaded</th></tr></thead>
				<tbody>
				<?php if ( empty( $items['items'] ) ) { echo '<tr><td colspan="7">No evidence items found.</td></tr>'; }
				foreach ( $items['items'] as $item ) { ?>
					<tr>
						<td><?php echo esc_html( $item['id'] ); ?></td>
						<td><?php echo esc_html( $item['evidence_type'] ); ?></td>
						<td><?php echo esc_html( $item['storage_provider'] ); ?></td>
						<td><?php echo esc_html( $item['storage_path'] ); ?></td>
						<td><?php echo esc_html( $item['linked_subject_type'] . ' #' . $item['linked_subject_id'] ); ?></td>
						<td><?php echo esc_html( $item['retention_class'] ); ?></td>
						<td><?php echo esc_html( $item['created_at'] ); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
			<p>Total: <?php echo esc_html( $items['total'] ); ?></p>
		</div>
		<?php
	}

	public static function render_ledger_integrity(): void {
		if ( ! current_user_can( 'view_kynvaric_ledger' ) ) { wp_die( 'Forbidden.' ); }
		$result = null;
		if ( isset( $_POST['run_check'] ) && check_admin_referer( 'sb_ledger_check' ) ) {
			$limit  = absint( $_POST['check_limit'] ?? 1000 );
			$result = SBAuditLedgerPlus::integrity_check( $limit );
		}
		global $wpdb;
		$total_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_authority_events" );
		$recent = $wpdb->get_results( "SELECT id, event_type, domain_key, caused_by_user_id, created_at FROM {$wpdb->prefix}sb_authority_events ORDER BY id DESC LIMIT 10", ARRAY_A ) ?: [];
		?>
		<div class="wrap">
			<h1>Ledger Integrity</h1>
			<p>Total authority events: <strong><?php echo esc_html( $total_events ); ?></strong></p>
			<form method="post">
				<?php wp_nonce_field( 'sb_ledger_check' ); ?>
				<label>Check limit: <input type="number" name="check_limit" value="1000" min="100" max="100000" style="width:100px"></label>
				&nbsp;<button class="button button-primary" name="run_check" value="1">Run Integrity Check</button>
			</form>
			<?php if ( $result !== null ) { ?>
			<div class="notice <?php echo $result['broken'] > 0 ? 'notice-error' : 'notice-success'; ?>" style="margin-top:16px;padding:12px">
				<strong>Results:</strong>
				Checked <?php echo esc_html( $result['checked'] ); ?> events.
				Verified: <?php echo esc_html( $result['verified'] ); ?>.
				Broken: <?php echo esc_html( $result['broken'] ); ?>.
				<?php if ( ! empty( $result['broken_ids'] ) ) { echo 'Broken IDs: ' . esc_html( implode( ', ', $result['broken_ids'] ) ); } ?>
			</div>
			<?php } ?>
			<h3>Recent Authority Events</h3>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Type</th><th>Domain</th><th>Caused By</th><th>Created</th></tr></thead>
				<tbody>
				<?php foreach ( $recent as $ev ) { ?>
					<tr>
						<td><?php echo esc_html( $ev['id'] ); ?></td>
						<td><?php echo esc_html( $ev['event_type'] ); ?></td>
						<td><?php echo esc_html( $ev['domain_key'] ); ?></td>
						<td><?php $u = get_user_by( 'id', $ev['caused_by_user_id'] ); echo esc_html( $u ? $u->display_name : $ev['caused_by_user_id'] ); ?></td>
						<td><?php echo esc_html( $ev['created_at'] ); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}