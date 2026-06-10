<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBDependencyGraph
 * Cross-system dependency graph, impact analysis, migration suggestions, usage tracing.
 */
class SBDependencyGraph {

	/**
	 * Build the full dependency graph.
	 *
	 * @param string $scope  full|blueprints|forms|surfaces|connectors
	 * @return array{ nodes: array, edges: array }
	 */
	public static function build( string $scope = 'full' ): array {
		global $wpdb;

		$nodes = [];
		$edges = [];

		// Blueprints
		if ( in_array( $scope, [ 'full', 'blueprints' ], true ) ) {
			$blueprints = $wpdb->get_results( "SELECT id, slug, label, status, config_json FROM {$wpdb->prefix}sb_app_blueprints" );
			foreach ( $blueprints as $bp ) {
				$nodes[] = [ 'id' => "bp_{$bp->id}", 'type' => 'blueprint', 'slug' => $bp->slug, 'label' => $bp->label, 'status' => $bp->status ];
				$config  = (array) json_decode( $bp->config_json ?? '{}', true );

				foreach ( $config['forms'] ?? [] as $form_slug ) {
					$edges[] = [ 'from' => "bp_{$bp->id}", 'to' => "form_{$form_slug}", 'type' => 'requires_form' ];
				}
				foreach ( $config['surfaces'] ?? [] as $surface_slug ) {
					$edges[] = [ 'from' => "bp_{$bp->id}", 'to' => "surface_{$surface_slug}", 'type' => 'requires_surface' ];
				}
				foreach ( $config['capabilities'] ?? [] as $cap ) {
					$edges[] = [ 'from' => "bp_{$bp->id}", 'to' => "cap_{$cap['slug']}", 'type' => 'invokes_capability' ];
				}
			}
		}

		// Forms
		if ( in_array( $scope, [ 'full', 'forms' ], true ) ) {
			$forms = $wpdb->get_results( "SELECT id, slug, label, status, save_adapter FROM {$wpdb->prefix}sb_tiny_forms" );
			foreach ( $forms as $f ) {
				$nodes[] = [ 'id' => "form_{$f->slug}", 'type' => 'form', 'slug' => $f->slug, 'label' => $f->label, 'status' => $f->status ];
			}
		}

		// Surfaces
		if ( in_array( $scope, [ 'full', 'surfaces' ], true ) ) {
			$surfaces = $wpdb->get_results( "SELECT id, slug, label, status, surface_type FROM {$wpdb->prefix}sb_ui_surfaces" );
			foreach ( $surfaces as $s ) {
				$nodes[] = [ 'id' => "surface_{$s->slug}", 'type' => 'surface', 'slug' => $s->slug, 'label' => $s->label, 'status' => $s->status ];
			}
		}

		// Capabilities
		if ( in_array( $scope, [ 'full', 'blueprints' ], true ) ) {
			$caps = $wpdb->get_results( "SELECT id, slug, label, is_active FROM {$wpdb->prefix}sb_capability_registry" );
			foreach ( $caps as $c ) {
				$nodes[] = [ 'id' => "cap_{$c->slug}", 'type' => 'capability', 'slug' => $c->slug, 'label' => $c->label, 'status' => $c->is_active ? 'active' : 'inactive' ];
			}
		}

		// Placements → surfaces + forms
		if ( $scope === 'full' ) {
			$placements = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_placements WHERE status = 'active'" );
			foreach ( $placements as $p ) {
				$p_id = "placement_{$p->id}";
				$nodes[] = [ 'id' => $p_id, 'type' => 'placement', 'slug' => "placement_{$p->id}", 'label' => $p->label, 'status' => $p->status ];
				if ( $p->surface_slug ) {
					$edges[] = [ 'from' => $p_id, 'to' => "surface_{$p->surface_slug}", 'type' => 'mounts_surface' ];
				}
				if ( $p->form_slug ) {
					$edges[] = [ 'from' => $p_id, 'to' => "form_{$p->form_slug}", 'type' => 'mounts_form' ];
				}
			}
		}

		// Deduplicate nodes by id
		$unique_nodes = [];
		foreach ( $nodes as $n ) {
			$unique_nodes[ $n['id'] ] = $n;
		}

		$snapshot = [ 'nodes' => array_values( $unique_nodes ), 'edges' => $edges ];

		// Persist snapshot
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_dep_graph_snapshots", [
			'scope'      => sanitize_key( $scope ),
			'nodes_json' => wp_json_encode( $snapshot['nodes'] ),
			'edges_json' => wp_json_encode( $snapshot['edges'] ),
			'snapshot_at'=> current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEP_GRAPH_BUILT, "Dependency graph built (scope: {$scope})", get_current_user_id(), [], 'info' );

		return $snapshot;
	}

	/**
	 * Get all nodes this node depends on (upstream).
	 *
	 * @param string $node_type
	 * @param int    $node_id
	 * @return array
	 */
	public static function get_upstream( string $node_type, string $node_key ): array {
		$graph   = self::build( 'full' );
		// node_key is the canonical graph node ID as produced by build()
		// e.g. "form_{slug}", "surface_{slug}", "blueprint_{id}", "capability_{slug}"
		$node_id_str = $node_key;

		$upstream = [];
		foreach ( $graph['edges'] as $edge ) {
			if ( $edge['from'] === $node_id_str ) {
				$upstream[] = $edge['to'];
			}
		}

		return array_map( fn( $id ) => [ 'id' => $id ], array_unique( $upstream ) );
	}

	/**
	 * Get all nodes that depend on this node (downstream).
	 *
	 * @param string $node_type
	 * @param int    $node_id
	 * @return array
	 */
	public static function get_downstream( string $node_type, string $node_key ): array {
		$graph       = self::build( 'full' );
		// node_key is the canonical graph node ID as produced by build()
		$node_id_str = $node_key;

		$downstream = [];
		foreach ( $graph['edges'] as $edge ) {
			if ( $edge['to'] === $node_id_str ) {
				$downstream[] = $edge['from'];
			}
		}

		return array_map( fn( $id ) => [ 'id' => $id ], array_unique( $downstream ) );
	}

	/**
	 * Analyze impact of a proposed change to a node.
	 *
	 * @param string $node_type
	 * @param int    $node_id
	 * @param array  $proposed_change
	 * @return array
	 */
	public static function analyze_change_impact( string $node_type, int $node_id, array $proposed_change ): array {
		global $wpdb;

		// Resolve canonical node key matching build() output:
		// ID-based: bp_{id}, placement_{id}
		// Slug-based: form_{slug}, surface_{slug}, cap_{slug}
		$slug_table_map = [
			'form'       => [ 'table' => 'sb_tiny_forms',         'col' => 'slug' ],
			'surface'    => [ 'table' => 'sb_ui_surfaces',         'col' => 'slug' ],
			'capability' => [ 'table' => 'sb_capability_registry', 'col' => 'slug' ],
		];
		$prefix_map = [
			'blueprint'  => 'bp',
			'placement'  => 'placement',
			'form'       => 'form',
			'surface'    => 'surface',
			'capability' => 'cap',
		];
		$node_prefix = $prefix_map[ $node_type ] ?? $node_type;

		if ( isset( $slug_table_map[ $node_type ] ) ) {
			$map   = $slug_table_map[ $node_type ];
			$slug  = $wpdb->get_var( $wpdb->prepare(
				"SELECT `{$map['col']}` FROM {$wpdb->prefix}{$map['table']} WHERE id = %d LIMIT 1",
				$node_id
			) );
			$node_key = $slug ? "{$node_prefix}_{$slug}" : "{$node_prefix}_{$node_id}";
		} else {
			$node_key = "{$node_prefix}_{$node_id}";
		}

		$downstream = self::get_downstream( $node_type, $node_key );
		$risk_score = self::get_risk_score( [ 'affected' => $downstream ] );

		$result = [
			'node_type'        => $node_type,
			'node_id'          => $node_id,
			'proposed_change'  => $proposed_change,
			'affected_objects' => $downstream,
			'affected_count'   => count( $downstream ),
			'risk_score'       => $risk_score,
			'suggestions'      => self::get_migration_suggestions( $node_type, $node_id ),
		];

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEP_GRAPH_IMPACT_ANALYZED, "Impact analysis for {$node_type}#{$node_id}", get_current_user_id(), [ 'risk' => $risk_score ], 'info' );

		return $result;
	}

	/**
	 * Calculate risk score 0-100.
	 *
	 * @param array $impact_map
	 * @return int
	 */
	public static function get_risk_score( array $impact_map ): int {
		$affected = $impact_map['affected'] ?? [];
		$count    = count( $affected );

		// 0 affected = 0 risk; 10+ affected = 80 risk; scale linearly
		return (int) min( 100, $count * 8 );
	}

	/**
	 * Get migration suggestions for a node.
	 *
	 * @param string $node_type
	 * @param int    $node_id
	 * @return array
	 */
	public static function get_migration_suggestions( string $node_type, int $node_id ): array {
		global $wpdb;

		// Resolve canonical node key — same logic as analyze_change_impact
		$slug_table_map = [
			'form'       => [ 'table' => 'sb_tiny_forms',         'col' => 'slug' ],
			'surface'    => [ 'table' => 'sb_ui_surfaces',         'col' => 'slug' ],
			'capability' => [ 'table' => 'sb_capability_registry', 'col' => 'slug' ],
		];
		$prefix_map = [
			'blueprint'  => 'bp',
			'placement'  => 'placement',
			'form'       => 'form',
			'surface'    => 'surface',
			'capability' => 'cap',
		];
		$node_prefix = $prefix_map[ $node_type ] ?? $node_type;
		if ( isset( $slug_table_map[ $node_type ] ) ) {
			$map  = $slug_table_map[ $node_type ];
			$slug = $wpdb->get_var( $wpdb->prepare(
				"SELECT `{$map['col']}` FROM {$wpdb->prefix}{$map['table']} WHERE id = %d LIMIT 1",
				$node_id
			) );
			$node_key = $slug ? "{$node_prefix}_{$slug}" : "{$node_prefix}_{$node_id}";
		} else {
			$node_key = "{$node_prefix}_{$node_id}";
		}

		$downstream = self::get_downstream( $node_type, $node_key );

		if ( empty( $downstream ) ) {
			return [ 'No downstream dependents — safe to change without migration.' ];
		}

		return [
			'1. Run simulation first: POST /sovereign-builder/v1/sim/blueprint if node is a blueprint.',
			'2. Deactivate affected blueprints before making the change.',
			'3. Apply the change.',
			'4. Re-activate blueprints in order of dependency.',
			'Affected: ' . implode( ', ', array_column( $downstream, 'id' ) ),
		];
	}

	/**
	 * Find all objects that reference a given slug/key.
	 *
	 * @param string $resource_slug
	 * @return array
	 */
	public static function trace_usage( string $resource_slug ): array {
		global $wpdb;

		$results  = [];
		$like_val = '%' . $wpdb->esc_like( $resource_slug ) . '%';

		// Blueprints
		$bps = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, label FROM {$wpdb->prefix}sb_app_blueprints WHERE config_json LIKE %s",
			$like_val
		) );
		foreach ( $bps as $bp ) {
			$results[] = [ 'type' => 'blueprint', 'slug' => $bp->slug, 'label' => $bp->label ];
		}

		// Placements
		$placements = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, label, surface_slug, form_slug FROM {$wpdb->prefix}sb_placements
			 WHERE surface_slug = %s OR form_slug = %s",
			$resource_slug,
			$resource_slug
		) );
		foreach ( $placements as $p ) {
			$results[] = [ 'type' => 'placement', 'id' => $p->id, 'label' => $p->label ];
		}

		// Signal rules
		$rules = $wpdb->get_results( $wpdb->prepare(
			"SELECT rule_slug FROM {$wpdb->prefix}sb_v2_signal_rules WHERE conditions_json LIKE %s",
			$like_val
		) );
		foreach ( $rules as $r ) {
			$results[] = [ 'type' => 'signal_rule', 'slug' => $r->rule_slug ];
		}

		return $results;
	}

	/**
	 * Render dependency graph admin screen.
	 */
	public static function render_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_app_blueprints', 'sb_tiny_forms', 'sb_ui_surfaces' ] );
		if ( $guard ) { echo $guard; return; }

		$search = sanitize_text_field( $_GET['trace'] ?? '' );
		echo '<div class="wrap"><h1>Dependency Graph</h1>';

		if ( $search ) {
			$results = self::trace_usage( $search );
			echo '<h2>Usage trace for: <code>' . esc_html( $search ) . '</code></h2>';
			echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Slug / ID</th><th>Label</th></tr></thead><tbody>';
			foreach ( $results as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( $r['type'] ) . '</td>';
				echo '<td><code>' . esc_html( $r['slug'] ?? $r['id'] ) . '</code></td>';
				echo '<td>' . esc_html( $r['label'] ?? '—' ) . '</td>';
				echo '</tr>';
			}
			if ( empty( $results ) ) {
				echo '<tr><td colspan="3">No references found for: ' . esc_html( $search ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Use the REST API to explore the full graph: <code>GET /sovereign-builder/v1/dep-graph/build</code></p>';
			echo '<form method="get"><input type="hidden" name="page" value="sb-dep-graph">';
			echo '<p><label>Usage Tracer: <input type="text" name="trace" class="regular-text" placeholder="Enter slug to find references"></label>';
			echo '<button type="submit" class="button">Trace</button></p></form>';
		}

		echo '</div>';
	}

	// ── REST wrapper methods ─────────────────────────────────────────────────────

	public static function handle_rest_build( WP_REST_Request $request ): WP_REST_Response {
		$object_type = sanitize_key( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = absint( $request->get_param( 'object_id' ) ?? 0 );
		$data = self::build( $object_type, $object_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_upstream( WP_REST_Request $request ): WP_REST_Response {
		$object_type = sanitize_key( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = absint( $request->get_param( 'object_id' ) ?? 0 );
		$data = self::get_upstream( $object_type, $object_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_downstream( WP_REST_Request $request ): WP_REST_Response {
		$object_type = sanitize_key( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = absint( $request->get_param( 'object_id' ) ?? 0 );
		$data = self::get_downstream( $object_type, $object_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_impact( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$data   = self::analyze_change_impact(
			sanitize_key( $params['object_type'] ?? '' ),
			absint( $params['object_id'] ?? 0 ),
			(array) ( $params['proposed_change'] ?? [] )
		);
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_trace( WP_REST_Request $request ): WP_REST_Response {
		// trace_usage() accepts a single resource_slug string.
		// Accept either a direct slug or construct from object_type + object_id/slug.
		$resource_slug = sanitize_text_field( $request->get_param( 'resource_slug' ) ?? '' );
		if ( ! $resource_slug ) {
			$object_type = sanitize_key( $request->get_param( 'object_type' ) ?? '' );
			$object_slug = sanitize_text_field( $request->get_param( 'object_slug' ) ?? '' );
			$resource_slug = $object_type && $object_slug ? "{$object_type}_{$object_slug}" : '';
		}
		if ( ! $resource_slug ) {
			return new WP_REST_Response( [ 'error' => 'resource_slug or object_type+object_slug required.' ], 400 );
		}
		$data = self::trace_usage( $resource_slug );
		return new WP_REST_Response( $data, 200 );
	}

}