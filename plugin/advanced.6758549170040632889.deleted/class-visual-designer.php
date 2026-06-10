<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBVisualDesigner
 * Canvas-based operator tool for visualizing blueprint relationships,
 * dependency graphs, runtime maps, and placement configurations.
 */
class SBVisualDesigner {

	/**
	 * Return nodes + edges JSON for the canvas renderer.
	 *
	 * @param int $blueprint_id
	 * @return array{ nodes: array, edges: array }
	 */
	public static function get_graph_data( int $blueprint_id ): array {
		global $wpdb;

		$blueprint = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d",
			$blueprint_id
		) );

		if ( ! $blueprint ) {
			return [ 'nodes' => [], 'edges' => [] ];
		}

		$config = json_decode( $blueprint->config_json ?? '{}', true );
		$nodes  = [];
		$edges  = [];

		// Root blueprint node
		$nodes[] = [
			'id'    => "blueprint_{$blueprint_id}",
			'type'  => 'blueprint',
			'label' => $blueprint->label,
			'slug'  => $blueprint->slug,
			'status'=> $blueprint->status,
			'x'     => 400,
			'y'     => 50,
		];

		$y_offset = 200;

		// Roads
		if ( ! empty( $config['roads'] ) ) {
			foreach ( $config['roads'] as $idx => $road ) {
				$node_id  = "road_{$blueprint_id}_{$road['road_key']}";
				$nodes[]  = [
					'id'     => $node_id,
					'type'   => 'road',
					'label'  => $road['label'] ?? "Road {$road['road_key']}",
					'slug'   => $road['road_key'],
					'status' => 'active',
					'x'      => 100 + ( $idx * 200 ),
					'y'      => $y_offset,
				];
				$edges[] = [
					'from'  => "blueprint_{$blueprint_id}",
					'to'    => $node_id,
					'label' => 'defines_road',
				];
			}
			$y_offset += 200;
		}

		// Capabilities
		if ( ! empty( $config['capabilities'] ) ) {
			foreach ( $config['capabilities'] as $idx => $cap ) {
				$node_id = "capability_{$blueprint_id}_{$cap['slug']}";
				$nodes[] = [
					'id'     => $node_id,
					'type'   => 'capability',
					'label'  => $cap['slug'],
					'slug'   => $cap['slug'],
					'status' => 'active',
					'x'      => 100 + ( $idx * 220 ),
					'y'      => $y_offset,
				];
				$edges[] = [
					'from'  => "blueprint_{$blueprint_id}",
					'to'    => $node_id,
					'label' => 'invokes_capability',
				];
			}
			$y_offset += 200;
		}

		// Forms
		if ( ! empty( $config['forms'] ) ) {
			foreach ( $config['forms'] as $idx => $form_slug ) {
				$node_id = "form_{$form_slug}";
				$form    = $wpdb->get_row( $wpdb->prepare(
					"SELECT slug, label, status FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
					$form_slug
				) );
				$nodes[] = [
					'id'     => $node_id,
					'type'   => 'form',
					'label'  => $form ? $form->label : $form_slug,
					'slug'   => $form_slug,
					'status' => $form ? $form->status : 'missing',
					'x'      => 100 + ( $idx * 220 ),
					'y'      => $y_offset,
				];
				$edges[] = [
					'from'  => "blueprint_{$blueprint_id}",
					'to'    => $node_id,
					'label' => 'registers_form',
				];
			}
			$y_offset += 200;
		}

		// Surfaces
		if ( ! empty( $config['surfaces'] ) ) {
			foreach ( $config['surfaces'] as $idx => $surface_slug ) {
				$node_id = "surface_{$surface_slug}";
				$surface = $wpdb->get_row( $wpdb->prepare(
					"SELECT slug, label, status FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s",
					$surface_slug
				) );
				$nodes[] = [
					'id'     => $node_id,
					'type'   => 'surface',
					'label'  => $surface ? $surface->label : $surface_slug,
					'slug'   => $surface_slug,
					'status' => $surface ? $surface->status : 'missing',
					'x'      => 100 + ( $idx * 220 ),
					'y'      => $y_offset,
				];
				$edges[] = [
					'from'  => "blueprint_{$blueprint_id}",
					'to'    => $node_id,
					'label' => 'mounts_surface',
				];
			}
			$y_offset += 200;
		}

		// Placements
		if ( ! empty( $config['placements'] ) ) {
			foreach ( $config['placements'] as $idx => $placement ) {
				$node_id = "placement_{$blueprint_id}_{$idx}";
				$nodes[] = [
					'id'     => $node_id,
					'type'   => 'placement',
					'label'  => "{$placement['surface']} @ {$placement['context']}",
					'slug'   => $placement['surface'],
					'status' => 'active',
					'x'      => 100 + ( $idx * 220 ),
					'y'      => $y_offset,
				];
				$edges[] = [
					'from'  => "surface_{$placement['surface']}",
					'to'    => $node_id,
					'label' => 'placed_at',
				];
			}
		}

		return [ 'nodes' => $nodes, 'edges' => $edges ];
	}

	/**
	 * Return live runtime status of all objects in a blueprint.
	 *
	 * @param int $blueprint_id
	 * @return array
	 */
	public static function get_runtime_map( int $blueprint_id ): array {
		global $wpdb;

		$blueprint = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d",
			$blueprint_id
		) );

		if ( ! $blueprint ) {
			return [];
		}

		$config = json_decode( $blueprint->config_json ?? '{}', true );
		$map    = [
			'blueprint_id'   => $blueprint_id,
			'blueprint_status' => $blueprint->status,
			'objects'        => [],
		];

		// Check forms
		foreach ( $config['forms'] ?? [] as $form_slug ) {
			$form = $wpdb->get_row( $wpdb->prepare(
				"SELECT status, approved_at FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
				$form_slug
			) );
			$map['objects'][] = [
				'type'   => 'form',
				'slug'   => $form_slug,
				'status' => $form ? $form->status : 'missing',
				'error'  => $form ? null : 'Form not found in database',
			];
		}

		// Check surfaces
		foreach ( $config['surfaces'] ?? [] as $surface_slug ) {
			$surface = $wpdb->get_row( $wpdb->prepare(
				"SELECT status, approved_at FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s",
				$surface_slug
			) );
			$map['objects'][] = [
				'type'   => 'surface',
				'slug'   => $surface_slug,
				'status' => $surface ? $surface->status : 'missing',
				'error'  => $surface ? null : 'Surface not found in database',
			];
		}

		// Check capabilities
		foreach ( $config['capabilities'] ?? [] as $cap ) {
			$cap_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT is_active FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s",
				$cap['slug']
			) );
			$map['objects'][] = [
				'type'   => 'capability',
				'slug'   => $cap['slug'],
				'status' => $cap_row ? ( $cap_row->is_active ? 'active' : 'inactive' ) : 'missing',
				'error'  => $cap_row ? null : 'Capability not registered',
			];
		}

		return $map;
	}

	/**
	 * Return all placements with their surface/context/road mappings.
	 *
	 * @return array
	 */
	public static function get_placement_map(): array {
		global $wpdb;

		$placements = $wpdb->get_results(
			"SELECT p.*, s.label as surface_label, f.label as form_label
			 FROM {$wpdb->prefix}sb_placements p
			 LEFT JOIN {$wpdb->prefix}sb_ui_surfaces s ON s.slug = p.surface_slug
			 LEFT JOIN {$wpdb->prefix}sb_tiny_forms f ON f.slug = p.form_slug
			 WHERE p.status = 'active'
			 ORDER BY p.priority ASC, p.id ASC"
		);

		$map = [];
		foreach ( $placements as $p ) {
			$map[] = [
				'id'            => (int) $p->id,
				'label'         => $p->label,
				'surface_slug'  => $p->surface_slug,
				'surface_label' => $p->surface_label,
				'form_slug'     => $p->form_slug,
				'form_label'    => $p->form_label,
				'context_type'  => $p->context_type,
				'context_key'   => $p->context_key,
				'road_key'      => $p->road_key,
				'required_cap'  => $p->required_cap,
				'pmpro_level'   => (int) $p->pmpro_level,
				'priority'      => (int) $p->priority,
				'status'        => $p->status,
			];
		}

		return $map;
	}

	/**
	 * Submit an edit to a node — validates, queues HITM approval, logs.
	 *
	 * @param string $node_type  blueprint|form|surface|capability
	 * @param int    $node_id
	 * @param array  $changes
	 * @return array|WP_Error
	 */
	public static function submit_edit( string $node_type, int $node_id, array $changes ) {
		if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) {
			return new WP_Error( 'unauthorized', 'Insufficient capability.', [ 'status' => 403 ] );
		}

		$allowlist = [
			'blueprint' => [ 'label', 'config_json' ],
			'form'      => [ 'label', 'fields_json', 'validation_json', 'success_message' ],
			'surface'   => [ 'label', 'content_json', 'visibility_rules_json' ],
			'capability'=> [ 'label', 'budget_cap', 'rate_limit_per_hour', 'model_slug' ],
		];

		if ( ! isset( $allowlist[ $node_type ] ) ) {
			return new WP_Error( 'invalid_type', 'Unknown node type.', [ 'status' => 400 ] );
		}

		$safe_changes = array_intersect_key( $changes, array_flip( $allowlist[ $node_type ] ) );

		if ( empty( $safe_changes ) ) {
			return new WP_Error( 'no_valid_changes', 'No valid fields in change set.', [ 'status' => 400 ] );
		}

		// Route approval type and capability by node type per ASK5.5 spec
		$type_map = [
			'form'      => [ 'approval_type' => 'form_publish',         'cap' => 'manage_sovereign_forms' ],
			'surface'   => [ 'approval_type' => 'surface_publish',      'cap' => 'manage_sovereign_surfaces' ],
			'blueprint' => [ 'approval_type' => 'blueprint_activation', 'cap' => 'manage_sovereign_blueprints' ],
			'capability'=> [ 'approval_type' => 'blueprint_activation', 'cap' => 'manage_sovereign_blueprints' ],
		];
		$route = $type_map[ $node_type ] ?? [ 'approval_type' => 'blueprint_activation', 'cap' => 'manage_sovereign_blueprints' ];

		// Re-check capability for this specific node type
		if ( ! current_user_can( $route['cap'] ) ) {
			return new WP_Error( 'unauthorized', 'Insufficient capability for ' . $node_type . ' edits.', [ 'status' => 403 ] );
		}

		$approval_id = SB_Approval_Engine::create_approval( [
			'approval_type' => $route['approval_type'],
			'payload'       => wp_json_encode( [
				'node_type'    => $node_type,
				'node_id'      => $node_id,
				'changes'      => $safe_changes,
				'blueprint_id' => $node_type === 'blueprint' ? $node_id : 0,
			] ),
			'campaign_id'   => 0,
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_VISUAL_DESIGNER_EDIT_SUBMITTED,
			"Visual designer edit submitted for {$node_type} #{$node_id}",
			get_current_user_id(),
			[ 'node_type' => $node_type, 'node_id' => $node_id, 'approval_id' => $approval_id ],
			'info'
		);

		return [ 'success' => true, 'approval_id' => $approval_id ];
	}

	/**
	 * Return what would change downstream if a node is modified.
	 *
	 * @param string $node_type
	 * @param int    $node_id
	 * @param array  $proposed_change
	 * @return array
	 */
	public static function get_impact_preview( string $node_type, int $node_id, array $proposed_change ): array {
		// Delegate to dependency graph for upstream/downstream
		if ( class_exists( 'SBDependencyGraph' ) ) {
			return SBDependencyGraph::analyze_change_impact( $node_type, $node_id, $proposed_change );
		}

		return [
			'node_type'       => $node_type,
			'node_id'         => $node_id,
			'affected_objects'=> [],
			'risk_score'      => 0,
			'note'            => 'Dependency graph not loaded.',
		];
	}

	/**
	 * Render admin screen.
	 */
	public static function render_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		global $wpdb;
		$blueprints   = $wpdb->get_results( "SELECT id, slug, label, status FROM {$wpdb->prefix}sb_app_blueprints ORDER BY created_at DESC" );
		$blueprint_id = absint( $_GET['blueprint_id'] ?? ( $blueprints[0]->id ?? 0 ) );
		$rest_base    = esc_url_raw( get_rest_url( null, 'sovereign-builder/v1' ) );
		$nonce        = wp_create_nonce( 'wp_rest' );
		?>
		<style>
		#sb-vd-wrap { display:flex; height:calc(100vh - 80px); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; gap:0; overflow:hidden; }
		#sb-vd-sidebar { width:280px; min-width:280px; background:#1a1a2e; color:#f5f0e8; display:flex; flex-direction:column; overflow:hidden; }
		#sb-vd-sidebar-head { padding:1.25rem 1.5rem; border-bottom:2px solid #c9a84c; }
		#sb-vd-sidebar-head h2 { margin:0; font-size:1rem; font-weight:700; color:#f5f0e8; letter-spacing:0.02em; }
		#sb-vd-sidebar-head p { margin:0.25rem 0 0; font-size:0.75rem; color:#9ca3af; }
		#sb-vd-bp-list { flex:1; overflow-y:auto; padding:0.75rem 0; }
		.sb-vd-bp-item { padding:0.7rem 1.5rem; cursor:pointer; border-left:3px solid transparent; transition:all 0.15s; }
		.sb-vd-bp-item:hover { background:rgba(255,255,255,0.05); }
		.sb-vd-bp-item.active { border-left-color:#c9a84c; background:rgba(201,168,76,0.1); }
		.sb-vd-bp-name { font-size:0.88rem; font-weight:600; color:#f5f0e8; }
		.sb-vd-bp-slug { font-size:0.72rem; color:#6b7280; font-family:monospace; }
		.sb-vd-bp-status { display:inline-flex; padding:0.1rem 0.5rem; border-radius:10px; font-size:0.68rem; font-weight:700; margin-top:0.25rem; }
		.sb-vd-bp-status-active { background:#065f46; color:#6ee7b7; }
		.sb-vd-bp-status-installed { background:#1e3a5f; color:#93c5fd; }
		#sb-vd-main { flex:1; display:flex; flex-direction:column; overflow:hidden; background:#f8fafc; }
		#sb-vd-toolbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:0.75rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
		#sb-vd-toolbar h3 { margin:0; font-size:1rem; color:#1a1a2e; font-weight:700; flex:1; }
		.sb-vd-btn { padding:0.45rem 1rem; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer; border:none; transition:all 0.15s; }
		.sb-vd-btn-primary { background:#1a1a2e; color:#c9a84c; }
		.sb-vd-btn-primary:hover { background:#252545; }
		.sb-vd-btn-outline { background:#fff; color:#374151; border:1.5px solid #e5e7eb !important; }
		.sb-vd-btn-outline:hover { border-color:#c9a84c !important; color:#1a1a2e; }
		.sb-vd-tab-bar { display:flex; gap:0; border-bottom:1px solid #e5e7eb; background:#fff; padding:0 1.5rem; }
		.sb-vd-tab { padding:0.65rem 1.1rem; font-size:0.82rem; font-weight:600; color:#6b7280; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; background:none; border-top:none; border-left:none; border-right:none; transition:all 0.15s; }
		.sb-vd-tab.active { color:#1a1a2e; border-bottom-color:#c9a84c; }
		#sb-vd-content { flex:1; overflow:auto; padding:1.5rem; }
		.sb-vd-panel { display:none; }
		.sb-vd-panel.active { display:block; }

		/* Graph canvas */
		#sb-vd-canvas { width:100%; min-height:500px; position:relative; background:#fff; border-radius:10px; border:1px solid #e5e7eb; overflow:auto; cursor:grab; }
		#sb-vd-canvas:active { cursor:grabbing; }
		svg#sb-graph-svg { display:block; min-width:100%; min-height:500px; }

		/* Node styles */
		.sb-node { cursor:pointer; }
		.sb-node:hover rect { filter:brightness(0.95); stroke-width:3; }
		.sb-node rect { rx:8; ry:8; stroke-width:2; transition:all 0.15s; }
		.sb-node text { font-family:-apple-system,sans-serif; font-size:12px; }
		.sb-node-blueprint rect { fill:#1a1a2e; stroke:#c9a84c; }
		.sb-node-blueprint text { fill:#f5f0e8; font-weight:700; }
		.sb-node-form rect { fill:#eff6ff; stroke:#2563eb; }
		.sb-node-form text { fill:#1e3a5f; }
		.sb-node-road rect { fill:#f0fdf4; stroke:#059669; }
		.sb-node-road text { fill:#065f46; }
		.sb-node-signal rect { fill:#fef9c3; stroke:#d97706; }
		.sb-node-signal text { fill:#78350f; }
		.sb-node-schema rect { fill:#fdf4ff; stroke:#9333ea; }
		.sb-node-schema text { fill:#581c87; }
		.sb-node-page rect { fill:#fff7ed; stroke:#ea580c; }
		.sb-node-page text { fill:#7c2d12; }
		.sb-edge { stroke:#d1d5db; stroke-width:1.5; fill:none; marker-end:url(#arrow); }

		/* Portal config panel */
		.sb-pc-section { background:#fff; border-radius:10px; border:1px solid #e5e7eb; padding:1.5rem; margin-bottom:1.25rem; }
		.sb-pc-section h4 { margin:0 0 1rem; font-size:0.9rem; font-weight:700; color:#1a1a2e; border-bottom:1px solid #f3f4f6; padding-bottom:0.75rem; }
		.sb-pc-row { display:flex; align-items:center; gap:1rem; margin-bottom:0.85rem; }
		.sb-pc-label { font-size:0.82rem; font-weight:600; color:#374151; width:160px; flex-shrink:0; }
		.sb-pc-control select, .sb-pc-control input[type="text"] { padding:0.45rem 0.75rem; border:1.5px solid #e5e7eb; border-radius:6px; font-size:0.85rem; color:#1a1a2e; background:#f9fafb; outline:none; }
		.sb-pc-control select:focus, .sb-pc-control input:focus { border-color:#c9a84c; background:#fff; }
		.sb-nav-preview { display:flex; gap:0.5rem; margin-top:0.5rem; flex-wrap:wrap; }
		.sb-nav-opt { padding:0.5rem 1rem; border-radius:6px; border:2px solid #e5e7eb; cursor:pointer; font-size:0.8rem; font-weight:600; color:#6b7280; transition:all 0.15s; background:#fff; }
		.sb-nav-opt.selected { border-color:#c9a84c; color:#1a1a2e; background:#fefce8; }
		.sb-stage-list { margin-top:0.75rem; }
		.sb-stage-item { display:flex; align-items:center; gap:0.75rem; padding:0.65rem 1rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:7px; margin-bottom:0.5rem; }
		.sb-stage-icon { font-size:1.1rem; }
		.sb-stage-name { flex:1; font-size:0.88rem; font-weight:600; color:#1a1a2e; }
		.sb-stage-type { font-size:0.75rem; color:#6b7280; font-family:monospace; }

		/* Runtime panel */
		.sb-rt-item { display:flex; align-items:center; gap:0.75rem; padding:0.65rem 1rem; border-bottom:1px solid #f3f4f6; }
		.sb-rt-type { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; width:80px; }
		.sb-rt-slug { font-family:monospace; font-size:0.82rem; color:#1a1a2e; flex:1; }
		.sb-rt-status { font-size:0.75rem; font-weight:700; padding:0.15rem 0.55rem; border-radius:10px; }
		.sb-rt-active { background:#ecfdf5; color:#059669; }
		.sb-rt-missing { background:#fef2f2; color:#dc2626; }
		.sb-rt-draft { background:#fef9c3; color:#92400e; }
		</style>

		<div id="sb-vd-wrap">
			<!-- Sidebar: blueprint list -->
			<div id="sb-vd-sidebar">
				<div id="sb-vd-sidebar-head">
					<h2>⚙ Visual Designer</h2>
					<p>Select a blueprint to design</p>
				</div>
				<div id="sb-vd-bp-list">
					<?php foreach ( $blueprints as $bp ) : ?>
					<div class="sb-vd-bp-item <?php echo $bp->id === $blueprint_id ? 'active' : ''; ?>"
						onclick="sbVDLoad(<?php echo (int) $bp->id; ?>, '<?php echo esc_js( $bp->label ); ?>', this)">
						<div class="sb-vd-bp-name"><?php echo esc_html( $bp->label ); ?></div>
						<div class="sb-vd-bp-slug"><?php echo esc_html( $bp->slug ); ?></div>
						<span class="sb-vd-bp-status sb-vd-bp-status-<?php echo esc_attr( $bp->status ); ?>">
							<?php echo esc_html( strtoupper( $bp->status ) ); ?>
						</span>
					</div>
					<?php endforeach; ?>
					<?php if ( empty( $blueprints ) ) : ?>
					<div style="padding:1.5rem;color:#6b7280;font-size:0.85rem;">No blueprints installed yet.</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Main area -->
			<div id="sb-vd-main">
				<div id="sb-vd-toolbar">
					<h3 id="sb-vd-title">
						<?php echo $blueprints ? esc_html( $blueprints[0]->label ?? 'Select a Blueprint' ) : 'No Blueprints'; ?>
					</h3>
					<button class="sb-vd-btn sb-vd-btn-outline" onclick="sbVDRefresh()">↻ Refresh</button>
					<button class="sb-vd-btn sb-vd-btn-primary" onclick="sbVDActivate()">▶ Activate Blueprint</button>
					<a id="sb-vd-export-btn" class="sb-vd-btn sb-vd-btn-outline" href="#" onclick="sbVDExport(); return false;">⬇ Export JSON</a>
				</div>

				<div class="sb-vd-tab-bar">
					<button class="sb-vd-tab active" onclick="sbVDTab('graph', this)">Graph View</button>
					<button class="sb-vd-tab" onclick="sbVDTab('portal', this)">Portal Config</button>
					<button class="sb-vd-tab" onclick="sbVDTab('runtime', this)">Runtime Status</button>
					<button class="sb-vd-tab" onclick="sbVDTab('json', this)">Blueprint JSON</button>
				</div>

				<div id="sb-vd-content">

					<!-- Graph panel -->
					<div class="sb-vd-panel active" id="sb-panel-graph">
						<div id="sb-vd-canvas">
							<svg id="sb-graph-svg">
								<defs>
									<marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5"
										markerWidth="6" markerHeight="6" orient="auto-start-reverse">
										<path d="M2 1L8 5L2 9" fill="none" stroke="#d1d5db"
											stroke-width="1.5" stroke-linecap="round"/>
									</marker>
								</defs>
								<g id="sb-graph-edges"></g>
								<g id="sb-graph-nodes"></g>
							</svg>
						</div>
						<div id="sb-vd-node-detail" style="display:none;margin-top:1rem;background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:1.25rem;">
							<h4 id="sb-nd-title" style="margin:0 0 0.75rem;color:#1a1a2e;"></h4>
							<div id="sb-nd-body" style="font-size:0.85rem;color:#374151;"></div>
						</div>
					</div>

					<!-- Portal config panel -->
					<div class="sb-vd-panel" id="sb-panel-portal">
						<div class="sb-pc-section">
							<h4>Navigation Style</h4>
							<div class="sb-pc-row">
								<span class="sb-pc-label">Layout Type</span>
								<div class="sb-pc-control">
									<div class="sb-nav-preview">
										<div class="sb-nav-opt selected" id="nav-opt-horizontal" onclick="sbVDSetNav('horizontal', this)">
											☰ Horizontal Tabs
										</div>
										<div class="sb-nav-opt" id="nav-opt-vertical" onclick="sbVDSetNav('vertical', this)">
											⫿ Left Sidebar
										</div>
										<div class="sb-nav-opt" id="nav-opt-dropdown" onclick="sbVDSetNav('dropdown', this)">
											▾ Dropdown Menu
										</div>
										<div class="sb-nav-opt" id="nav-opt-flyout" onclick="sbVDSetNav('flyout', this)">
											↗ Flyout Panels
										</div>
									</div>
								</div>
							</div>
							<div class="sb-pc-row">
								<span class="sb-pc-label">Lock Future Stages</span>
								<div class="sb-pc-control">
									<select id="pc-lock-stages">
										<option value="1">Yes — sequential only</option>
										<option value="0">No — free navigation</option>
									</select>
								</div>
							</div>
							<div class="sb-pc-row">
								<span class="sb-pc-label">Show Progress Bar</span>
								<div class="sb-pc-control">
									<select id="pc-show-progress">
										<option value="1">Yes</option>
										<option value="0">No</option>
									</select>
								</div>
							</div>
							<div class="sb-pc-row">
								<span class="sb-pc-label">Color Scheme</span>
								<div class="sb-pc-control">
									<select id="pc-color-scheme">
										<option value="dark">Dark (Ink + Gold)</option>
										<option value="light">Light (White + Gold)</option>
										<option value="custom">Custom</option>
									</select>
								</div>
							</div>
						</div>

						<div class="sb-pc-section">
							<h4>Portal Stages</h4>
							<div class="sb-stage-list" id="pc-stage-list">
								<div style="color:#9ca3af;font-size:0.85rem;">Loading stages...</div>
							</div>
						</div>

						<div class="sb-pc-section">
							<h4>Portal Entry Point</h4>
							<div class="sb-pc-row">
								<span class="sb-pc-label">Portal Page Slug</span>
								<div class="sb-pc-control">
									<input type="text" id="pc-portal-slug" placeholder="e.g. my-book-portal" style="width:240px;">
								</div>
							</div>
							<div style="margin-top:1rem;">
								<button class="sb-vd-btn sb-vd-btn-primary" onclick="sbVDSavePortalConfig()">Save Portal Config →</button>
								<span id="pc-save-msg" style="margin-left:1rem;font-size:0.82rem;color:#059669;display:none;">✓ Saved</span>
							</div>
						</div>
					</div>

					<!-- Runtime panel -->
					<div class="sb-vd-panel" id="sb-panel-runtime">
						<div style="background:#fff;border-radius:10px;border:1px solid #e5e7eb;overflow:hidden;">
							<div style="background:#1a1a2e;padding:1rem 1.5rem;border-bottom:2px solid #c9a84c;">
								<h4 style="margin:0;color:#f5f0e8;font-size:0.9rem;">Live Runtime Status</h4>
							</div>
							<div id="sb-rt-body">
								<div style="padding:2rem;color:#9ca3af;text-align:center;">Loading runtime map...</div>
							</div>
						</div>
					</div>

					<!-- JSON panel -->
					<div class="sb-vd-panel" id="sb-panel-json">
						<div style="background:#1a1a2e;border-radius:10px;overflow:hidden;">
							<div style="padding:0.75rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
								<span style="color:#c9a84c;font-size:0.82rem;font-weight:700;">BLUEPRINT JSON</span>
								<button class="sb-vd-btn sb-vd-btn-outline sb-vd-btn-sm" onclick="sbVDCopyJSON()" style="font-size:0.75rem;padding:0.3rem 0.7rem;">Copy</button>
							</div>
							<pre id="sb-json-view" style="margin:0;padding:1.5rem;color:#e2e8f0;font-size:0.78rem;overflow:auto;max-height:600px;line-height:1.6;"></pre>
						</div>
					</div>

				</div>
			</div>
		</div>

		<script>
		var sbVDCurrentId   = <?php echo (int) $blueprint_id; ?>;
		var sbVDCurrentLabel = '';
		var sbVDCurrentConfig = {};
		var sbVDNavStyle    = 'horizontal';
		var sbVDRestBase    = '<?php echo esc_js( $rest_base ); ?>';
		var sbVDNonce       = '<?php echo esc_js( $nonce ); ?>';

		// Node type colors
		var sbNodeColors = {
			blueprint: { fill:'#1a1a2e', stroke:'#c9a84c', text:'#f5f0e8' },
			form:      { fill:'#eff6ff', stroke:'#2563eb', text:'#1e3a5f' },
			road:      { fill:'#f0fdf4', stroke:'#059669', text:'#065f46' },
			signal:    { fill:'#fef9c3', stroke:'#d97706', text:'#78350f' },
			schema:    { fill:'#fdf4ff', stroke:'#9333ea', text:'#581c87' },
			page:      { fill:'#fff7ed', stroke:'#ea580c', text:'#7c2d12' },
			pipeline:  { fill:'#f0f9ff', stroke:'#0284c7', text:'#0c4a6e' },
		};

		function sbVDTab(tab, btn) {
			document.querySelectorAll('.sb-vd-tab').forEach(function(t){ t.classList.remove('active'); });
			document.querySelectorAll('.sb-vd-panel').forEach(function(p){ p.classList.remove('active'); });
			btn.classList.add('active');
			document.getElementById('sb-panel-' + tab).classList.add('active');
			if (tab === 'runtime') { sbVDLoadRuntime(); }
			if (tab === 'json')    { sbVDLoadJSON(); }
			if (tab === 'portal')  { sbVDLoadPortalConfig(); }
		}

		function sbVDLoad(id, label, el) {
			sbVDCurrentId    = id;
			sbVDCurrentLabel = label;
			document.getElementById('sb-vd-title').textContent = label;
			document.querySelectorAll('.sb-vd-bp-item').forEach(function(e){ e.classList.remove('active'); });
			if (el) { el.classList.add('active'); }
			sbVDLoadGraph();
			// Update URL without reload
			var url = new URL(window.location.href);
			url.searchParams.set('blueprint_id', id);
			window.history.replaceState({}, '', url);
		}

		function sbVDRefresh() { sbVDLoadGraph(); }

		function sbVDLoadGraph() {
			if (!sbVDCurrentId) { return; }
			fetch(sbVDRestBase + '/visual-designer/graph?blueprint_id=' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(data){ sbVDRenderGraph(data); })
			.catch(function(){ sbVDRenderGraphFromConfig(); });
		}

		function sbVDRenderGraphFromConfig() {
			// Fallback: build graph from exported blueprint config
			fetch(sbVDRestBase + '/blueprint/export/' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(config){
				sbVDCurrentConfig = config;
				var nodes = [], edges = [];
				var cx = 400, cy = 60, W = 160, H = 50, gap = 180;

				// Root
				nodes.push({ id:'bp', type:'blueprint', label: config.label || config.slug, x: cx, y: cy });

				var sections = [
					{ key:'forms',    type:'form',     color:'form' },
					{ key:'schemas',  type:'schema',   color:'schema' },
					{ key:'pages',    type:'page',     color:'page' },
					{ key:'roads',    type:'road',     color:'road' },
					{ key:'signals',  type:'signal',   color:'signal' },
				];

				var rowY = cy + 130;
				sections.forEach(function(sec) {
					var items = config[sec.key];
					if (!items || !items.length) { return; }
					var totalW = items.length * gap;
					var startX = Math.max(40, cx - totalW/2 + gap/2);
					items.forEach(function(item, i) {
						var label = item.label || item.slug || item.title || item.road_key || item;
						if (typeof label !== 'string') { label = String(label); }
						var nid = sec.type + '_' + i;
						nodes.push({ id:nid, type:sec.type, label:label, x: startX + i*gap, y: rowY });
						edges.push({ from:'bp', to:nid });
					});
					rowY += 130;
				});

				// Pipeline
				if (config.pipeline) {
					nodes.push({ id:'pipeline', type:'pipeline', label: config.pipeline.label || 'AI Pipeline', x: cx, y: rowY });
					edges.push({ from:'bp', to:'pipeline' });
				}

				sbVDRenderGraph({ nodes:nodes, edges:edges });
			})
			.catch(function(e){ console.error(e); });
		}

		function sbVDRenderGraph(data) {
			var svg    = document.getElementById('sb-graph-svg');
			var nodesG = document.getElementById('sb-graph-nodes');
			var edgesG = document.getElementById('sb-graph-edges');
			nodesG.innerHTML = '';
			edgesG.innerHTML = '';

			if (!data.nodes || !data.nodes.length) {
				nodesG.innerHTML = '<text x="400" y="200" text-anchor="middle" fill="#9ca3af" font-size="14">No graph data — activate blueprint first</text>';
				return;
			}

			var nodeMap = {};
			data.nodes.forEach(function(n){ nodeMap[n.id] = n; });

			var W = 160, H = 46, rx = 8;

			// Draw edges first
			data.edges.forEach(function(e) {
				var from = nodeMap[e.from], to = nodeMap[e.to];
				if (!from || !to) { return; }
				var line = document.createElementNS('http://www.w3.org/2000/svg','line');
				line.setAttribute('x1', from.x + W/2);
				line.setAttribute('y1', from.y + H);
				line.setAttribute('x2', to.x + W/2);
				line.setAttribute('y2', to.y);
				line.setAttribute('class', 'sb-edge');
				edgesG.appendChild(line);
			});

			// Draw nodes
			var maxX = 0, maxY = 0;
			data.nodes.forEach(function(n) {
				var colors = sbNodeColors[n.type] || sbNodeColors.form;
				var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
				g.setAttribute('class', 'sb-node');
				g.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
				g.addEventListener('click', function(){ sbVDShowNodeDetail(n); });
				g.addEventListener('dblclick', function(){ sbVDOpenNode(n); });

				var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
				rect.setAttribute('width', W);
				rect.setAttribute('height', H);
				rect.setAttribute('rx', rx);
				rect.setAttribute('fill', colors.fill);
				rect.setAttribute('stroke', colors.stroke);
				rect.setAttribute('stroke-width', '2');
				g.appendChild(rect);

				// Type badge
				var badge = document.createElementNS('http://www.w3.org/2000/svg', 'text');
				badge.setAttribute('x', 8);
				badge.setAttribute('y', 16);
				badge.setAttribute('fill', colors.stroke);
				badge.setAttribute('font-size', '9');
				badge.setAttribute('font-weight', '700');
				badge.setAttribute('text-transform', 'uppercase');
				badge.textContent = n.type.toUpperCase();
				g.appendChild(badge);

				// Label
				var label = n.label || n.slug || n.id;
				if (label.length > 18) { label = label.substring(0, 17) + '…'; }
				var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
				text.setAttribute('x', W/2);
				text.setAttribute('y', 32);
				text.setAttribute('text-anchor', 'middle');
				text.setAttribute('fill', colors.text);
				text.setAttribute('font-size', '12');
				text.setAttribute('font-weight', '600');
				text.textContent = label;
				g.appendChild(text);

				nodesG.appendChild(g);
				maxX = Math.max(maxX, n.x + W + 40);
				maxY = Math.max(maxY, n.y + H + 40);
			});

			var padX = 60, padY = 40;
			svg.setAttribute('viewBox', '0 0 ' + (Math.max(maxX, 800) + padX) + ' ' + (Math.max(maxY, 400) + padY));
			svg.setAttribute('width', Math.max(maxX, 800) + padX);
			svg.setAttribute('height', Math.max(maxY, 400) + padY);
			svg.style.minHeight = (Math.max(maxY, 400) + padY) + 'px';
			svg.style.minWidth  = (Math.max(maxX, 800) + padX) + 'px';
			// Scroll canvas to show root node
			var canvas = document.getElementById('sb-vd-canvas');
			if (canvas) { canvas.scrollLeft = 0; canvas.scrollTop = 0; }
		}

		function sbVDShowNodeDetail(node) {
			var detail = document.getElementById('sb-vd-node-detail');
			var title  = document.getElementById('sb-nd-title');
			var body   = document.getElementById('sb-nd-body');
			title.textContent = node.type.toUpperCase() + ': ' + (node.label || node.slug || node.id);
			body.innerHTML = '<table style="width:100%;border-collapse:collapse;">' +
				Object.entries(node).map(function(e){
					return '<tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#6b7280;width:120px;">' + e[0] + '</td>' +
					'<td style="padding:0.3rem 0.5rem;font-family:monospace;color:#1a1a2e;">' + String(e[1]) + '</td></tr>';
				}).join('') + '</table>' +
				'<div style="margin-top:0.75rem;"><button class="sb-vd-btn sb-vd-btn-primary" onclick="sbVDOpenNode(' + JSON.stringify(node) + ')">Open →</button></div>';
			detail.style.display = 'block';
		}

		function sbVDOpenNode(node) {
			var base    = '<?php echo esc_js( admin_url( "admin.php" ) ); ?>';
			var editUrl = null;
			var slug    = node.slug || node.id || '';

			switch(node.type) {
				case 'blueprint':
					editUrl = base + '?page=sb-blueprints';
					break;
				case 'form':
					editUrl = base + '?page=sb-tiny-forms&action=edit&slug=' + encodeURIComponent(slug);
					break;
				case 'schema':
					editUrl = base + '?page=sb-schema-designer&slug=' + encodeURIComponent(slug);
					break;
				case 'road':
					editUrl = base + '?page=sb-many-roads';
					break;
				case 'signal':
					editUrl = base + '?page=sb-signal-definitions';
					break;
				case 'page':
					// Open WordPress page editor — look up page by slug
					editUrl = '<?php echo esc_js( admin_url( "edit.php?post_type=page" ) ); ?>';
					break;
				case 'pipeline':
					editUrl = base + '?page=sb-ai-capabilities';
					break;
				case 'surface':
					editUrl = base + '?page=sb-ui-surfaces';
					break;
				default:
					editUrl = base + '?page=sb-blueprints';
			}

			if (editUrl) { window.open(editUrl, '_blank'); }
		}

		function sbVDLoadPortalConfig() {
			fetch(sbVDRestBase + '/blueprint/export/' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(config){
				sbVDCurrentConfig = config;
				var portal = config.portal || {};
				var navStyle = portal.nav || 'horizontal';
				sbVDSetNav(navStyle, null);

				document.getElementById('pc-lock-stages').value = portal.lock_stages !== false ? '1' : '0';
				document.getElementById('pc-show-progress').value = portal.show_progress !== false ? '1' : '0';
				document.getElementById('pc-color-scheme').value = portal.color_scheme || 'dark';
				document.getElementById('pc-portal-slug').value = portal.portal_slug || (config.slug + '-portal');

				// Render stages
				var stageList = document.getElementById('pc-stage-list');
				var stages = portal.stages || [];
				if (!stages.length && config.pages) {
					stages = config.pages.map(function(p){ return { slug:p.slug, label:p.title, icon:'📄', form: p.shortcode }; });
				}
				if (stages.length) {
					stageList.innerHTML = stages.map(function(s, i){
						return '<div class="sb-stage-item">' +
							'<span class="sb-stage-icon">' + (s.icon || '📄') + '</span>' +
							'<span class="sb-stage-name">' + (s.label || s.slug) + '</span>' +
							'<span class="sb-stage-type">' + (s.slug || '') + '</span>' +
							'</div>';
					}).join('');
				} else {
					stageList.innerHTML = '<div style="color:#9ca3af;font-size:0.85rem;">No stages defined. Add pages to your blueprint.</div>';
				}
			});
		}

		function sbVDSetNav(style, el) {
			sbVDNavStyle = style;
			document.querySelectorAll('.sb-nav-opt').forEach(function(o){ o.classList.remove('selected'); });
			var target = el || document.getElementById('nav-opt-' + style);
			if (target) { target.classList.add('selected'); }
		}

		function sbVDSavePortalConfig() {
			if (!sbVDCurrentConfig) { return; }
			sbVDCurrentConfig.portal = {
				nav:           sbVDNavStyle,
				lock_stages:   document.getElementById('pc-lock-stages').value === '1',
				show_progress: document.getElementById('pc-show-progress').value === '1',
				color_scheme:  document.getElementById('pc-color-scheme').value,
				portal_slug:   document.getElementById('pc-portal-slug').value,
				stages:        sbVDCurrentConfig.pages ? sbVDCurrentConfig.pages.map(function(p){
					return { slug:p.slug, label:p.title, icon:'📄' };
				}) : [],
			};
			// Save back to blueprint via install (upsert)
			fetch(sbVDRestBase + '/blueprint/import', {
				method: 'POST',
				headers: { 'Content-Type':'application/json', 'X-WP-Nonce': sbVDNonce },
				body: JSON.stringify(sbVDCurrentConfig)
			})
			.then(function(r){ return r.json(); })
			.then(function(res){
				var msg = document.getElementById('pc-save-msg');
				msg.style.display = 'inline';
				msg.textContent = res.success || res.id ? '✓ Portal config saved' : '✗ Save failed';
				msg.style.color = res.success || res.id ? '#059669' : '#dc2626';
				setTimeout(function(){ msg.style.display='none'; }, 3000);
			});
		}

		function sbVDLoadRuntime() {
			fetch(sbVDRestBase + '/blueprint/export/' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(config){
				var items = [];
				(config.forms || []).forEach(function(f){ items.push({ type:'form', slug: f.slug || f, status:'active' }); });
				(config.schemas || []).forEach(function(s){ items.push({ type:'schema', slug: s.slug || s, status:'active' }); });
				(config.pages || []).forEach(function(p){ items.push({ type:'page', slug: p.slug, status:'active' }); });
				(config.signals || []).forEach(function(s){ items.push({ type:'signal', slug: s, status:'active' }); });
				(config.roads || []).forEach(function(r){ items.push({ type:'road', slug: r.road_key, status:'active' }); });
				if (config.pipeline) { items.push({ type:'pipeline', slug: config.pipeline.slug, status:'active' }); }

				var html = items.map(function(item){
					var statusClass = 'sb-rt-' + (item.status || 'missing');
					return '<div class="sb-rt-item">' +
						'<span class="sb-rt-type">' + item.type + '</span>' +
						'<span class="sb-rt-slug">' + item.slug + '</span>' +
						'<span class="sb-rt-status ' + statusClass + '">' + (item.status||'missing').toUpperCase() + '</span>' +
						'</div>';
				}).join('');
				document.getElementById('sb-rt-body').innerHTML = html || '<div style="padding:2rem;color:#9ca3af;text-align:center;">No objects found.</div>';
			});
		}

		function sbVDLoadJSON() {
			fetch(sbVDRestBase + '/blueprint/export/' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(config){
				document.getElementById('sb-json-view').textContent = JSON.stringify(config, null, 2);
			});
		}

		function sbVDCopyJSON() {
			var text = document.getElementById('sb-json-view').textContent;
			navigator.clipboard.writeText(text).then(function(){
				alert('Blueprint JSON copied to clipboard');
			});
		}

		function sbVDExport() {
			fetch(sbVDRestBase + '/blueprint/export/' + sbVDCurrentId, {
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(data){
				var blob = new Blob([JSON.stringify(data, null, 2)], { type:'application/json' });
				var a    = document.createElement('a');
				a.href   = URL.createObjectURL(blob);
				a.download = (data.slug || 'blueprint') + '.json';
				a.click();
			});
		}

		function sbVDActivate() {
			if (!sbVDCurrentId) { return; }
			if (!confirm('Activate this blueprint? This will create WordPress pages and deploy all forms and schemas.')) { return; }
			fetch(sbVDRestBase + '/blueprint/activate/' + sbVDCurrentId, {
				method: 'POST',
				headers: { 'X-WP-Nonce': sbVDNonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(res){
				if (res.hitm_required) {
					alert('HITM approval required before activation.');
				} else {
					alert('Blueprint activated successfully! Pages and forms have been deployed.');
					sbVDLoadGraph();
				}
			})
			.catch(function(){ alert('Activation failed. Check the audit log.'); });
		}

		// Auto-load on page ready
		document.addEventListener('DOMContentLoaded', function() {
			if (sbVDCurrentId) { sbVDRenderGraphFromConfig(); }
		});
		</script>
		<?php
	}

	// ── REST wrapper methods ─────────────────────────────────────────────────────

	public static function handle_rest_graph( WP_REST_Request $request ): WP_REST_Response {
		$bp_id = absint( $request->get_param( 'blueprint_id' ) ?? 0 );
		$data  = self::get_graph_data( $bp_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_runtime_map( WP_REST_Request $request ): WP_REST_Response {
		$data = self::get_runtime_map();
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_placement_map( WP_REST_Request $request ): WP_REST_Response {
		$data = self::get_placement_map();
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_edit( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$result = self::submit_edit( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function handle_rest_impact_preview( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$data   = self::get_impact_preview( $params );
		return new WP_REST_Response( $data, 200 );
	}

	// ── Graph normalization (canonical — only this method may hash graphs) ─────


	/**
	 * Fields excluded from graph hashing — volatile/operator metadata.
	 * Changing this list is a schema-behaviour change, not an implementation detail.
	 */
	public const IGNORED_GRAPH_FIELDS = [
		'id',
		'created_at',
		'updated_at',
		'reviewed_at',
		'reviewed_by',
		'operator_note',
		'last_materialized_at',
		'materialization_status',
		'x',
		'y',
	];

	/**
	 * Structural form fields — changes require HITM approval.
	 * Anything not in STRUCTURAL or COSMETIC is treated as structural (fail-safe).
	 */
	public const FORM_STRUCTURAL_FIELDS = [
		'fields_json',
		'validation_json',
		'save_adapter',
		'save_config_json',
		'visibility_rules_json',
		'form_action',
	];

	/** Cosmetic form fields — changes applied immediately without approval. */
	public const FORM_COSMETIC_FIELDS = [
		'label',
		'success_message',
		'error_message',
		'placeholder',
		'css_class',
		'help_text',
	];

	/** Structural surface fields — changes require approval. */
	public const SURFACE_STRUCTURAL_FIELDS = [
		'surface_type',
		'content_json',
		'visibility_rules_json',
		'placement_region',
	];

	/** Cosmetic surface fields — changes applied immediately. */
	public const SURFACE_COSMETIC_FIELDS = [
		'label',
	];

	/**
	 * Canonical graph normalizer.
	 *
	 * Strips IGNORED_GRAPH_FIELDS recursively, sorts all keys, JSON-encodes
	 * with JSON_UNESCAPED_UNICODE, and returns a SHA-256 hex string.
	 *
	 * Only this method may compute graphhash or nodehash.
	 * No inline hashing anywhere else in the codebase.
	 *
	 * @param  array $graph  The logical blueprint graph array.
	 * @return string        SHA-256 hex digest.
	 */
	public static function normalize_graph( array $graph ): string {
		$clean  = self::strip_ignored_fields( $graph );
		$sorted = self::recursive_ksort( $clean );
		$json   = wp_json_encode( $sorted, JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', (string) $json );
	}

	/**
	 * Rebuild and persist graphhash for a blueprint.
	 *
	 * Must be called by all blueprint-changing paths:
	 *   - Visual designer save (after approval materialization)
	 *   - Blueprint import
	 *   - Blueprint activation
	 *   - isregulated toggle
	 *
	 * @param  int $blueprint_id
	 * @return string|WP_Error  The new hash on success.
	 */
	public static function update_graphhash_for_blueprint( int $blueprint_id ): string|WP_Error {
		$graph_data = self::get_graph_data( $blueprint_id );
		if ( empty( $graph_data['nodes'] ) ) {
			return new WP_Error( 'graphhash_no_nodes', "Blueprint {$blueprint_id} has no nodes to hash.", [ 'status' => 404 ] );
		}

		// Build canonical graph array from nodes + edges
		$canonical = [
			'blueprint_id' => $blueprint_id,
			'nodes'        => $graph_data['nodes'],
			'edges'        => $graph_data['edges'],
		];

		$hash = self::normalize_graph( $canonical );

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}sb_app_blueprints",
			[ 'graph_hash' => $hash, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $blueprint_id ]
		);

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_BLUEPRINT_GRAPH_HASH_UPDATED,
			"graphhash updated for blueprint {$blueprint_id}.",
			get_current_user_id(),
			[ 'blueprint_id' => $blueprint_id, 'hash' => substr( $hash, 0, 12 ) . '...' ]
		);

		return $hash;
	}

	// ── Graph normalization helpers ────────────────────────────────────────────

	/**
	 * Recursively strip IGNORED_GRAPH_FIELDS from an array.
	 */
	private static function strip_ignored_fields( array $arr ): array {
		$out = [];
		foreach ( $arr as $key => $val ) {
			if ( in_array( $key, self::IGNORED_GRAPH_FIELDS, true ) ) { continue; }
			$out[ $key ] = is_array( $val ) ? self::strip_ignored_fields( $val ) : $val;
		}
		return $out;
	}

	/**
	 * Recursively sort array keys alphabetically.
	 */
	private static function recursive_ksort( array $arr ): array {
		ksort( $arr );
		foreach ( $arr as $key => $val ) {
			if ( is_array( $val ) ) { $arr[ $key ] = self::recursive_ksort( $val ); }
		}
		return $arr;
	}

}