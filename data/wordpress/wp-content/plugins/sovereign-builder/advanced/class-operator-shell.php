<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBOperatorShell
 * Unified operator UI shell: navigation, object inspector, log panel, preview panels.
 */
class SBOperatorShell {

	/**
	 * Initialize shell hooks.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_shell_assets' ] );
		add_action( 'admin_footer',          [ __CLASS__, 'render_shell_html' ] );
	}

	/**
	 * Enqueue shell CSS/JS on all Sovereign Builder admin pages.
	 *
	 * @param string $hook
	 */
	public static function enqueue_shell_assets( string $hook ): void {
		if ( false === strpos( $hook, 'sovereign' ) && false === strpos( $hook, 'sb-' ) && false === strpos( $hook, 'marketing-hq' ) ) {
			return;
		}

		// Shell JS (inline — keeps it out of external file requirement for v1)
		$shell_js = self::get_shell_js();
		wp_add_inline_script( 'sb-admin-js', $shell_js );

		// Shell CSS
		$shell_css = self::get_shell_css();
		wp_add_inline_style( 'sb-admin-css', $shell_css );

		wp_localize_script( 'sb-admin-js', 'sbShellCtx', [
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'restBase'=> esc_url_raw( get_rest_url( null, 'sovereign-builder/v1/' ) ),
			'user'    => get_current_user_id(),
		] );
	}

	/**
	 * Render shell HTML structure in admin footer.
	 */
	public static function render_shell_html(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( false === strpos( $screen->id, 'sovereign' ) && false === strpos( $screen->id, 'sb-' ) ) ) {
			return;
		}

		?>
		<!-- SB Operator Shell -->
		<div id="sb-shell-inspector" style="display:none;position:fixed;right:0;top:32px;width:320px;height:calc(100vh - 32px);background:#fff;border-left:2px solid #1e3a5f;z-index:9000;overflow-y:auto;padding:15px;box-shadow:-4px 0 12px rgba(0,0,0,0.12);">
			<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:12px;">
				<strong style="color:#1e3a5f;">Object Inspector</strong>
				<button id="sb-inspector-close" style="background:none;border:none;cursor:pointer;font-size:18px;">✕</button>
			</div>
			<div id="sb-inspector-content">
				<p style="color:#888;">Click any object row to inspect.</p>
			</div>
		</div>

		<div id="sb-shell-log" style="display:none;position:fixed;bottom:0;left:160px;right:0;height:160px;background:#1e1e1e;color:#d4d4d4;z-index:8999;font-family:monospace;font-size:12px;overflow-y:auto;padding:8px 12px;border-top:2px solid #1e3a5f;">
			<div style="display:flex;justify-content:space-between;margin-bottom:4px;">
				<strong style="color:#ffd700;">Audit Log Feed</strong>
				<button id="sb-log-close" style="background:none;border:none;color:#d4d4d4;cursor:pointer;">✕</button>
			</div>
			<div id="sb-log-entries"></div>
		</div>

		<!-- Shell toggle bar -->
		<div id="sb-shell-toggles" style="position:fixed;bottom:0;left:160px;background:#1e3a5f;padding:4px 12px;z-index:9001;display:flex;gap:12px;align-items:center;">
			<span style="color:#ffd700;font-size:11px;font-weight:bold;">SB Shell</span>
			<button id="sb-toggle-log" class="button-link" style="color:#fff;font-size:11px;cursor:pointer;background:none;border:none;">📋 Log</button>
			<button id="sb-toggle-inspector" class="button-link" style="color:#fff;font-size:11px;cursor:pointer;background:none;border:none;">🔍 Inspector</button>
		</div>
		<?php
	}

	/**
	 * Get shell JavaScript — event bindings, log poll, inspector.
	 *
	 * @return string
	 */
	private static function get_shell_js(): string {
		return <<<'JS'
(function($){
  if(typeof sbShellCtx === 'undefined') return;

  var lastAuditId = 0;
  var logOpen = false;
  var inspectorOpen = false;

  // Toggle log panel
  $('#sb-toggle-log').on('click', function(){
    logOpen = !logOpen;
    $('#sb-shell-log').toggle(logOpen);
    if(logOpen) { pollAuditLog(); }
  });

  // Toggle inspector
  $('#sb-toggle-inspector').on('click', function(){
    inspectorOpen = !inspectorOpen;
    $('#sb-shell-inspector').toggle(inspectorOpen);
  });

  // Close buttons
  $('#sb-inspector-close').on('click', function(){ inspectorOpen=false; $('#sb-shell-inspector').hide(); });
  $('#sb-log-close').on('click', function(){ logOpen=false; $('#sb-shell-log').hide(); });

  // Row click → inspector
  $(document).on('click', '.widefat tbody tr', function(){
    var id   = $(this).find('td:first').text().trim();
    var slug = $(this).find('code:first').text().trim() || id;
    var label= $(this).find('td:nth-child(2)').text().trim();
    $('#sb-inspector-content').html(
      '<dl style="margin:0;">' +
      '<dt><strong>ID / Slug</strong></dt><dd><code>'+slug+'</code></dd>' +
      '<dt><strong>Label</strong></dt><dd>'+label+'</dd>' +
      '<dt style="margin-top:10px;"><strong>Status</strong></dt><dd>'+$(this).find('td:nth-child(4)').text().trim()+'</dd>' +
      '</dl>' +
      '<hr>' +
      '<button class="button" style="width:100%;margin-top:8px;" onclick="window.location.href=window.location.href">Refresh</button>'
    );
    if(!inspectorOpen){ inspectorOpen=true; $('#sb-shell-inspector').show(); }
  });

  // Audit log polling
  function pollAuditLog(){
    if(!logOpen) return;
    $.ajax({
      url: sbShellCtx.restBase + 'audit-stream',
      headers: { 'X-WP-Nonce': sbShellCtx.nonce },
      data: { since: lastAuditId },
      success: function(data){
        if(data && data.length){
          data.forEach(function(e){
            var color = e.log_level==='error'?'#f48771':(e.log_level==='warning'?'#ffd700':'#9cdcfe');
            $('#sb-log-entries').prepend(
              '<div style="border-bottom:1px solid #333;padding:2px 0;"><span style="color:'+color+';">['+e.log_level.toUpperCase()+']</span> '+
              '<span style="color:#888;">'+e.created_at+'</span> '+
              '<span>'+e.action+': '+e.message+'</span></div>'
            );
            lastAuditId = Math.max(lastAuditId, parseInt(e.id)||0);
          });
          // Keep last 50 entries
          var entries = $('#sb-log-entries > div'); // Direct children only — prevents nested element miscounting
          if(entries.length > 50){ entries.slice(50).remove(); }
        }
      }
    });
    setTimeout(pollAuditLog, 5000);
  }

})(jQuery);
JS;
	}

	/**
	 * Get shell CSS.
	 *
	 * @return string
	 */
	private static function get_shell_css(): string {
		return '
#sb-shell-toggles { font-family: -apple-system, sans-serif; }
#sb-shell-inspector dt { color: #888; font-size: 11px; margin-top: 8px; }
#sb-shell-inspector dd { margin: 2px 0 6px 0; font-size: 13px; }
.widefat tbody tr:hover { cursor: pointer; background: #f0f4ff !important; }
#sb-shell-log::-webkit-scrollbar { width: 4px; }
#sb-shell-log::-webkit-scrollbar-thumb { background: #555; }
';
	}

	/**
	 * Render unified nav — called from admin_menu hook if operator shell screen is added.
	 */
	public static function render_nav(): void {
		// Nav is rendered via standard wp admin_menu — shell augments it via JS.
		// This is a no-op hook registration point for extensibility.
		do_action( 'sb_operator_shell_nav' );
	}

	/**
	 * Render object inspector for a specific object.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return string
	 */
	public static function render_object_inspector( string $type, int $id ): string {
		global $wpdb;

		$table_map = [
			'blueprint'  => 'sb_app_blueprints',
			'form'       => 'sb_tiny_forms',
			'surface'    => 'sb_ui_surfaces',
			'placement'  => 'sb_placements',
			'capability' => 'sb_capability_registry',
		];

		$table = $table_map[ $type ] ?? '';
		if ( ! $table ) {
			return '<p>Unknown object type.</p>';
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d",
			$id
		) );

		if ( ! $row ) {
			return '<p>Object not found.</p>';
		}

		$html  = '<dl>';
		$html .= '<dt>Type</dt><dd>' . esc_html( $type ) . '</dd>';
		$html .= '<dt>ID</dt><dd>' . (int) $id . '</dd>';
		$html .= '<dt>Slug</dt><dd><code>' . esc_html( $row->slug ?? '—' ) . '</code></dd>';
		$html .= '<dt>Label</dt><dd>' . esc_html( $row->label ?? '—' ) . '</dd>';
		$html .= '<dt>Status</dt><dd>' . esc_html( $row->status ?? '—' ) . '</dd>';
		$html .= '<dt>Created</dt><dd>' . esc_html( $row->created_at ?? '—' ) . '</dd>';
		$html .= '</dl>';

		return $html;
	}

	/**
	 * Render Add-On Logs screen — aggregated audit view for Ask5 subsystems.
	 */
	public static function render_addon_logs_screen(): void {
		if ( ! current_user_can( 'view_sovereign_audit_logs' ) ) {
			wp_die( 'Forbidden.' );
		}
		global $wpdb;
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per     = 50;
		$offset  = ( $page - 1 ) * $per;
		$filter  = sanitize_key( $_GET['level'] ?? '' );
		$family  = sanitize_key( $_GET['family'] ?? '' );

		$where = "WHERE 1=1";
		$args  = [];
		if ( $filter ) {
			$where .= " AND log_level = %s";
			$args[]  = $filter;
		}
		$ask5_families = [
			'blueprint_', 'schema_', 'form_', 'surface_', 'placement_', 'connector_',
			'sim_', 'dep_', 'debugger_', 'perf_', 'release_', 'approval_', 'capability_',
			'user_field_', 'submission_',
		];
		if ( $family ) {
			$where .= " AND action LIKE %s";
			$args[]  = $family . '%';
		} elseif ( ! empty( $ask5_families ) ) {
			// Guard: only append if ask5_families is non-empty — prevents malformed SQL
			$family_conditions = implode( "' OR action LIKE '", array_map( fn($f) => $f . '%', $ask5_families ) );
			$where .= " AND ( action LIKE '" . $family_conditions . "' )";
		}

		$sql = $args
			? $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_audit_log $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per, $offset ] ) )
			: "SELECT * FROM {$wpdb->prefix}sb_audit_log $where ORDER BY id DESC LIMIT {$per} OFFSET {$offset}";
		$logs = $wpdb->get_results( $sql );

		echo '<div class="wrap">';
		echo '<h1>Add-On Logs</h1>';
		echo '<p style="color:#888;">Ask5 subsystem audit trail — blueprints, schemas, forms, surfaces, placements, connectors, releases, debugger, and performance.</p>';

		// Filters
		echo '<form method="get" style="margin-bottom:12px;">';
		echo '<input type="hidden" name="page" value="sb-addon-logs">';
		echo '<select name="level"><option value="">All Levels</option>';
		foreach ( [ 'info', 'warning', 'error' ] as $lv ) {
			$sel = selected( $filter, $lv, false );
			echo '<option value="' . esc_attr( $lv ) . '"' . $sel . '>' . esc_html( ucfirst( $lv ) ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="family"><option value="">All Families</option>';
		$families = [ 'blueprint_' => 'Blueprints', 'schema_' => 'Schemas', 'form_' => 'Forms', 'surface_' => 'Surfaces', 'connector_' => 'Connectors', 'approval_' => 'Approvals', 'debugger_' => 'Debugger', 'perf_' => 'Performance', 'release_' => 'Releases', 'sim_' => 'Simulation', 'dep_' => 'Dependency Graph', 'capability_' => 'AI Capabilities', 'user_field_' => 'User Fields' ];
		foreach ( $families as $fk => $fl ) {
			$sel = selected( $family, $fk, false );
			echo '<option value="' . esc_attr( $fk ) . '"' . $sel . '>' . esc_html( $fl ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button">Filter</button>';
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th style="width:60px">ID</th><th style="width:80px">Level</th><th style="width:160px">Action</th><th>Message</th><th style="width:140px">User</th><th style="width:140px">When</th></tr></thead>';
		echo '<tbody>';
		if ( $logs ) {
			foreach ( $logs as $log ) {
				$color_map = [ 'error' => '#d63638', 'warning' => '#d67400', 'info' => '#1e3a5f' ];
				$color     = $color_map[ $log->log_level ] ?? '#1e3a5f';
				$user_name = $log->user_id ? ( get_userdata( (int) $log->user_id )->display_name ?? 'User #' . $log->user_id ) : 'System';
				echo '<tr>';
				echo '<td>' . (int) $log->id . '</td>';
				echo '<td><span style="color:' . esc_attr( $color ) . ';font-weight:bold;">' . esc_html( strtoupper( $log->log_level ) ) . '</span></td>';
				echo '<td><code>' . esc_html( $log->action ) . '</code></td>';
				echo '<td>' . esc_html( $log->message ?? '' ) . '</td>';
				echo '<td>' . esc_html( $user_name ) . '</td>';
				echo '<td>' . esc_html( $log->created_at ) . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6">No Add-On log entries found.</td></tr>';
		}
		echo '</tbody></table>';

		// Simple prev/next pagination
		$prev_url = $page > 1 ? admin_url( 'admin.php?page=sb-addon-logs&paged=' . ( $page - 1 ) . '&level=' . urlencode( $filter ) . '&family=' . urlencode( $family ) ) : '';
		$next_url = count( $logs ) === $per ? admin_url( 'admin.php?page=sb-addon-logs&paged=' . ( $page + 1 ) . '&level=' . urlencode( $filter ) . '&family=' . urlencode( $family ) ) : '';
		echo '<div style="margin-top:12px;">';
		if ( $prev_url ) echo '<a href="' . esc_url( $prev_url ) . '" class="button">&laquo; Previous</a> ';
		echo 'Page ' . (int) $page;
		if ( $next_url ) echo ' <a href="' . esc_url( $next_url ) . '" class="button">Next &raquo;</a>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render log panel HTML (static version for non-JS fallback).
	 *
	 * @return string
	 */
	public static function render_log_panel(): string {
		global $wpdb;

		$entries = $wpdb->get_results(
			"SELECT id, created_at, log_level, action, message FROM {$wpdb->prefix}sb_audit_log
			 ORDER BY id DESC LIMIT 20"
		);

		$html = '<div id="sb-log-panel-static" style="font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:10px;">';
		foreach ( $entries as $e ) {
			$color = match( $e->log_level ) { 'error' => '#f48771', 'warning' => '#ffd700', default => '#9cdcfe' };
			$html .= '<div style="border-bottom:1px solid #333;padding:2px 0;">';
			$html .= '<span style="color:' . esc_attr( $color ) . ';">[' . esc_html( strtoupper( $e->log_level ) ) . ']</span> ';
			$html .= '<span style="color:#888;">' . esc_html( $e->created_at ) . '</span> ';
			$html .= esc_html( $e->action ) . ': ' . esc_html( substr( $e->message ?? '', 0, 100 ) );
			$html .= '</div>';
		}
		$html .= '</div>';

		return $html;
	}
}