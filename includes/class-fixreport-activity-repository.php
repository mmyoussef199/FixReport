<?php
/**
 * Persistence boundary for FixReport activities.
 */
class FixReport_Activity_Repository {

	/** @var wpdb */
	private $wpdb;

	/** @var string */
	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'fixreport_activities';
	}

	/**
	 * Return active activities matching the supplied normalized query.
	 *
	 * @param array $args Normalized query arguments.
	 * @return array
	 */
	public function get_activities( $args ) {
		$query = $this->build_filtered_query( $args, false );
		$sql   = "SELECT a.*, u.display_name AS performed_by_name
			FROM {$this->table_name} a
			LEFT JOIN {$this->wpdb->users} u ON u.ID = a.performed_by
			{$query['where']}
			ORDER BY a.activity_date DESC, a.activity_id DESC
			LIMIT %d OFFSET %d";
		$params   = $query['params'];
		$params[] = max( 1, (int) $args['per_page'] );
		$params[] = max( 0, (int) $args['offset'] );

		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count active activities matching the supplied normalized query.
	 *
	 * @param array $args Normalized query arguments.
	 * @return int
	 */
	public function count_activities( $args ) {
		$query = $this->build_filtered_query( $args, true );
		$sql   = "SELECT COUNT(*) FROM {$this->table_name} a {$query['where']}";
		if ( $query['params'] ) {
			$sql = $this->wpdb->prepare( $sql, $query['params'] );
		}
		return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Aggregate active records without loading the activity ledger. */
	public function overview() {
		$filter = array_fill_keys( array( 'search', 'source', 'category', 'status', 'priority', 'date_from_current', 'date_from_legacy', 'date_to_current', 'date_to_legacy' ), '' );
		$total = $this->count_activities( $filter );
		$filter['source'] = 'automatic';
		$automatic = $this->count_activities( $filter );
		$filter['source'] = 'manual';
		$manual = $this->count_activities( $filter );
		$latest = $this->wpdb->get_row( "SELECT activity_id, title, activity_date, legacy_source FROM {$this->table_name} WHERE deleted_at IS NULL ORDER BY activity_date DESC, activity_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'total' => $total,
			'automatic' => $automatic,
			'manual' => $manual,
			'latest' => $latest,
		);
	}

	/**
	 * Retrieve one activity, optionally including soft-deleted rows.
	 *
	 * @param int  $activity_id Activity ID.
	 * @param bool $include_deleted Include deleted rows.
	 * @return object|null
	 */
	public function get_activity( $activity_id, $include_deleted = false ) {
		$sql = "SELECT a.*, u.display_name AS performed_by_name
			FROM {$this->table_name} a
			LEFT JOIN {$this->wpdb->users} u ON u.ID = a.performed_by
			WHERE a.activity_id = %d";
		if ( ! $include_deleted ) {
			$sql .= ' AND a.deleted_at IS NULL';
		}
		return $this->wpdb->get_row( $this->wpdb->prepare( $sql, absint( $activity_id ) ) );
	}

	/**
	 * Insert a new manual activity.
	 *
	 * @param array $data Canonical activity values.
	 * @return int|false
	 */
	public function insert_activity( $data ) {
		$formats = array(
			'source'         => '%s',
			'event_type'     => '%s',
			'category'       => '%s',
			'title'          => '%s',
			'description'    => '%s',
			'internal_note'  => '%s',
			'status'         => '%s',
			'priority'       => '%s',
			'client_visible' => '%d',
			'performed_by'   => '%d',
			'activity_date'  => '%s',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		);
		$ordered = array();
		$types   = array();
		foreach ( $formats as $field => $format ) {
			$ordered[ $field ] = $data[ $field ];
			$types[]            = $format;
		}
		$result = $this->wpdb->insert( $this->table_name, $ordered, $types );
		return false === $result ? false : (int) $this->wpdb->insert_id;
	}

	/** Insert validated automatic evidence; manual form data never reaches this path. */
	public function insert_automatic_activity( $data ) {
		$formats = array(
			'source' => '%s', 'event_type' => '%s', 'category' => '%s',
			'title' => '%s', 'description' => '%s', 'internal_note' => '%s',
			'status' => '%s', 'priority' => '%s', 'client_visible' => '%d',
			'object_type' => '%s', 'object_key' => '%s', 'object_key_hash' => '%s',
			'object_name' => '%s', 'previous_version' => '%s', 'new_version' => '%s',
			'metadata' => '%s', 'performed_by' => '%d', 'activity_date' => '%s',
			'created_at' => '%s', 'updated_at' => '%s',
		);
		$ordered = array();
		$types = array();
		foreach ( $formats as $field => $format ) {
			$ordered[ $field ] = $data[ $field ];
			$types[] = $format;
		}
		$result = $this->wpdb->insert( $this->table_name, $ordered, $types );
		return false === $result ? false : (int) $this->wpdb->insert_id;
	}

	/**
	 * Update only operationally editable fields on an active manual activity.
	 *
	 * @param int   $activity_id Activity ID.
	 * @param array $data Canonical editable values plus updated_at.
	 * @return int|false
	 */
	public function update_activity( $activity_id, $data ) {
		$allowed = array(
			'title'          => '%s',
			'description'    => '%s',
			'category'       => '%s',
			'status'         => '%s',
			'priority'       => '%s',
			'activity_date'  => '%s',
			'performed_by'   => '%d',
			'client_visible' => '%d',
			'internal_note'  => '%s',
			'updated_at'     => '%s',
		);
		$sets   = array();
		$params = array();
		foreach ( $allowed as $field => $format ) {
			if ( array_key_exists( $field, $data ) ) {
				$sets[]   = "{$field} = {$format}";
				$params[] = $data[ $field ];
			}
		}
		if ( ! $sets ) {
			return false;
		}
		$params[] = absint( $activity_id );
		$sql      = "UPDATE {$this->table_name} SET " . implode( ', ', $sets ) . "
			WHERE activity_id = %d AND source = 'manual' AND deleted_at IS NULL";
		return $this->wpdb->query( $this->wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Soft-delete an active manual activity.
	 *
	 * This is the policy boundary where finalized-report references can be
	 * enforced when that domain is implemented.
	 *
	 * @param int    $activity_id Activity ID.
	 * @param int    $deleted_by Current user ID.
	 * @param string $deleted_at UTC timestamp.
	 * @return int|false
	 */
	public function soft_delete_activity( $activity_id, $deleted_by, $deleted_at ) {
		$sql = "UPDATE {$this->table_name}
			SET deleted_at = %s, deleted_by = %d, updated_at = %s
			WHERE activity_id = %d AND source = 'manual' AND deleted_at IS NULL";
		return $this->wpdb->query(
			$this->wpdb->prepare( $sql, $deleted_at, absint( $deleted_by ), $deleted_at, absint( $activity_id ) )
		);
	}

	/**
	 * Build a safely prepared active-activity WHERE clause.
	 *
	 * Date filters use UTC boundaries for post-cutover rows and unchanged local
	 * clock boundaries for migrated rows, whose timestamps must not be reinterpreted.
	 *
	 * @param array $args Normalized query arguments.
	 * @param bool  $count_query Whether this is a count query.
	 * @return array
	 */
	private function build_filtered_query( $args, $count_query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$where  = array( 'a.deleted_at IS NULL' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(a.title LIKE %s OR a.description LIKE %s OR a.internal_note LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		foreach ( array( 'source', 'category', 'status', 'priority' ) as $field ) {
			if ( '' !== $args[ $field ] ) {
				$where[]  = "a.{$field} = %s";
				$params[] = $args[ $field ];
			}
		}

		$date_parts = array();
		foreach ( array( 'current' => 'a.legacy_source IS NULL', 'legacy' => 'a.legacy_source IS NOT NULL' ) as $kind => $identity ) {
			$range        = array( $identity );
			$range_params = array();
			if ( '' !== $args[ 'date_from_' . $kind ] ) {
				$range[]        = 'a.activity_date >= %s';
				$range_params[] = $args[ 'date_from_' . $kind ];
			}
			if ( '' !== $args[ 'date_to_' . $kind ] ) {
				$range[]        = 'a.activity_date <= %s';
				$range_params[] = $args[ 'date_to_' . $kind ];
			}
			if ( count( $range ) > 1 ) {
				$date_parts[] = '(' . implode( ' AND ', $range ) . ')';
				$params      = array_merge( $params, $range_params );
			}
		}
		if ( $date_parts ) {
			$where[] = '(' . implode( ' OR ', $date_parts ) . ')';
		}

		return array(
			'where'  => 'WHERE ' . implode( ' AND ', $where ),
			'params' => $params,
		);
	}
}
