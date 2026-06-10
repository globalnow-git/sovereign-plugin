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
		$blueprint_id = absint( $_GET['blueprint_id'] ?? 0 );
		echo '<div class="wrap">';
		echo '<h1>Visual App Designer</h1>';
		echo '<div id="sb-visual-designer-canvas" data-blueprint="' . esc_attr( $blueprint_id ) . '" style="min-height:600px;border:1px solid #ddd;background:#f9f9f9;position:relative;">';
		echo '<p style="padding:20px;color:#888;">Select a blueprint to visualize. Graph loads via REST.</p>';
		echo '</div>';
		echo '<script>window.sbVDBlueprint=' . (int) $blueprint_id . ';</script>';
		echo '</div>';
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