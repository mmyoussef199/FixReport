<?php
/** Persistence and concurrency boundary for FixReport snapshots. */
class FixReport_Snapshot_Repository {
	const STALE_SECONDS = 1800; // More than the normal admin request limit; checked only under the named lock.

	/** @var wpdb */
	private $wpdb;
	private $snapshots;
	private $items;
	private $lock_name;
	private $lock_held = false;
	private static $process_locks = array();

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->snapshots = $wpdb->prefix . 'fixreport_snapshots';
		$this->items = $wpdb->prefix . 'fixreport_snapshot_items';
		$this->lock_name = 'fixreport_snapshot_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . get_current_blog_id() ), 0, 32 );
	}

	/** MySQL/MariaDB connection-scoped advisory lock, with a bounded wait. */
	public function acquire_capture_lock() {
		if ( $this->lock_held || isset( self::$process_locks[ $this->lock_name ] ) ) {
			return false;
		}
		$result = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name, 2 ) );
		$this->lock_held = '1' === (string) $result;
		if ( $this->lock_held ) {
			self::$process_locks[ $this->lock_name ] = true;
		}
		return $this->lock_held;
	}

	public function release_capture_lock() {
		if ( $this->lock_held ) {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) );
			$this->lock_held = false;
			unset( self::$process_locks[ $this->lock_name ] );
		}
	}

	public function completed_initial_exists() {
		$sql = "SELECT snapshot_id FROM {$this->snapshots} WHERE snapshot_type = 'initial' AND generation_status = 'complete' LIMIT 1";
		return null !== $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Fail interrupted attempts only after obtaining the installation lock. */
	public function fail_stale_building() {
		if ( ! $this->lock_held ) {
			return false;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = "UPDATE {$this->snapshots} SET generation_status = 'failed', failure_code = 'attempt_interrupted', updated_at = %s
			WHERE snapshot_type IN ('initial', 'manual', 'checkpoint') AND generation_status = 'building' AND started_at < %s";
		return false !== $this->wpdb->query( $this->wpdb->prepare( $sql, $now, $cutoff ) );
	}

	public function create_building( $creator, $type ) {
		if ( ! $this->lock_held || ! in_array( $type, array( 'initial', 'manual', 'checkpoint' ), true ) || ( 'initial' === $type && $this->completed_initial_exists() ) || ( 'manual' === $type && ! $this->completed_initial_exists() ) ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok = $this->wpdb->insert( $this->snapshots, array(
			'snapshot_type' => $type, 'generation_status' => 'building', 'created_by' => $creator,
			'created_at' => $now, 'started_at' => $now, 'updated_at' => $now,
			'site_url' => '', 'home_url' => '',
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		return false === $ok ? false : (int) $this->wpdb->insert_id;
	}

	/** Only building -> failed is allowed. Completed rows are never mutated. */
	public function mark_failed( $id, $code ) {
		if ( ! in_array( $code, array( 'collection_failed', 'validation_failed', 'persistence_failed', 'consistency_failed', 'attempt_interrupted' ), true ) ) {
			return false;
		}
		$sql = "UPDATE {$this->snapshots} SET generation_status = 'failed', failure_code = %s, updated_at = %s
			WHERE snapshot_id = %d AND generation_status = 'building'";
		return 1 === $this->wpdb->query( $this->wpdb->prepare( $sql, $code, gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/** Write validated summary/items atomically; no public generic edit/delete method exists. */
	public function complete_snapshot( $id, $type, $validated ) {
		if ( ! $this->lock_held || ! in_array( $type, array( 'initial', 'manual', 'checkpoint' ), true ) ) {
			return new WP_Error( 'snapshot_transition_invalid' );
		}
		if ( 'initial' === $type && $this->completed_initial_exists() ) {
			return new WP_Error( 'duplicate_initial' );
		}
		$old_suppression = $this->wpdb->suppress_errors( true );
		$began = false;
		try {
			$began = false !== $this->wpdb->query( 'START TRANSACTION' );
			if ( ! $began ) {
				return new WP_Error( 'persistence_failed' );
			}
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT snapshot_type, generation_status FROM {$this->snapshots} WHERE snapshot_id = %d FOR UPDATE", $id ) );
			if ( ! $row || $type !== $row->snapshot_type || 'building' !== $row->generation_status || ( 'initial' === $type && $this->completed_initial_exists() ) ) {
				return new WP_Error( 'snapshot_transition_invalid' );
			}
			$summary = $validated['summary'];
			$summary['updated_at'] = gmdate( 'Y-m-d H:i:s' );
			$summary['completed_at'] = $summary['updated_at'];
			$summary['generation_status'] = 'complete';
			$summary['failure_code'] = null;
			$formats = array();
			foreach ( $summary as $value ) {
				$formats[] = is_int( $value ) ? '%d' : '%s';
			}
			$changed = $this->wpdb->update( $this->snapshots, $summary, array( 'snapshot_id' => $id, 'generation_status' => 'building' ), $formats, array( '%d', '%s' ) );
			if ( 1 !== $changed ) {
				return new WP_Error( 'persistence_failed' );
			}
			foreach ( $validated['items'] as $item ) {
				$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT item_key FROM {$this->items} WHERE snapshot_id = %d AND item_type = %s AND item_key_hash = %s", $id, $item['item_type'], $item['item_key_hash'] ) );
				if ( null !== $existing ) {
					return new WP_Error( $existing === $item['item_key'] ? 'snapshot_item_duplicate' : 'snapshot_item_hash_collision' );
				}
				$item['snapshot_id'] = $id;
				$formats = array();
				foreach ( $item as $value ) {
					$formats[] = is_int( $value ) ? '%d' : '%s';
				}
				if ( false === $this->wpdb->insert( $this->items, $item, $formats ) ) {
					return new WP_Error( 'persistence_failed' );
				}
			}
			if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
				return new WP_Error( 'snapshot_maintenance_active' );
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'persistence_failed' );
			}
			$began = false;
			return $id;
		} finally {
			if ( $began ) {
				$this->wpdb->query( 'ROLLBACK' );
			}
			$this->wpdb->suppress_errors( $old_suppression );
		}
	}

	/** Read-only retrieval for verification and later Phase 3B display. */
	public function get_snapshot( $id ) {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->snapshots} WHERE snapshot_id = %d", $id ) );
	}

	/** Only completed, supported historical evidence is exposed to view/selection. */
	public function get_completed_snapshot( $id ) {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->snapshots} WHERE snapshot_id = %d AND snapshot_type IN ('initial', 'manual', 'checkpoint') AND generation_status = 'complete'", $id ) );
		return '' !== $this->wpdb->last_error ? new WP_Error( 'snapshot_read_failed' ) : $row;
	}

	/** Token lookup makes a committed capture idempotent after a worker crash. */
	public function get_completed_checkpoint_by_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9-]{36}$/', $token ) ) {
			return null;
		}
		$like = '%"token":"' . $this->wpdb->esc_like( $token ) . '"%';
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT snapshot_id, completed_at FROM {$this->snapshots} WHERE snapshot_type = 'checkpoint' AND generation_status = 'complete' AND summary_metadata LIKE %s ORDER BY snapshot_id DESC LIMIT 1", $like ) );
	}

	public function get_latest_completed_checkpoint() {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( "SELECT snapshot_id, completed_at FROM {$this->snapshots} WHERE snapshot_type = 'checkpoint' AND generation_status = 'complete' ORDER BY completed_at DESC, snapshot_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'checkpoint_read_failed' ) : $row;
	}

	/** Latest completed historical capture, including checkpoints. No live site inspection. */
	public function get_latest_completed_for_dashboard() {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( "SELECT snapshot_id, snapshot_type, completed_at, wordpress_version FROM {$this->snapshots} WHERE snapshot_type IN ('initial', 'manual', 'checkpoint') AND generation_status = 'complete' ORDER BY completed_at DESC, snapshot_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'snapshot_read_failed' ) : $row;
	}

	public function count_completed() {
		$this->wpdb->last_error = '';
		$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->snapshots} WHERE snapshot_type IN ('initial', 'manual') AND generation_status = 'complete'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'snapshot_read_failed' ) : (int) $count;
	}

	public function list_completed( $limit, $offset ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT snapshot_id, snapshot_type, generation_status, completed_at, site_name, wordpress_version, plugin_count, theme_count FROM {$this->snapshots} WHERE snapshot_type IN ('initial', 'manual') AND generation_status = 'complete' ORDER BY completed_at DESC, snapshot_id DESC LIMIT %d OFFSET %d", max( 1, min( 100, (int) $limit ) ), max( 0, (int) $offset ) ) );
		return '' !== $this->wpdb->last_error || ! is_array( $rows ) ? new WP_Error( 'snapshot_read_failed' ) : $rows;
	}

	/** The latest completed predecessor in confirmation-time order, with ID tie-break. */
	public function get_previous_completed( $id ) {
		$target = $this->get_completed_snapshot( $id );
		if ( is_wp_error( $target ) || ! $target ) {
			return is_wp_error( $target ) ? $target : new WP_Error( 'snapshot_not_found' );
		}
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT snapshot_id, snapshot_type, generation_status, completed_at FROM {$this->snapshots} WHERE snapshot_type IN ('initial', 'manual') AND generation_status = 'complete' AND (completed_at < %s OR (completed_at = %s AND snapshot_id < %d)) ORDER BY completed_at DESC, snapshot_id DESC LIMIT 1", $target->completed_at, $target->completed_at, $id ) );
		return '' !== $this->wpdb->last_error ? new WP_Error( 'snapshot_read_failed' ) : $row;
	}

	public function count_items( $id ) {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->items} WHERE snapshot_id = %d", $id ) );
	}

	/** Read the immutable historical Initial Baseline; never collect live state here. */
	public function get_completed_initial() {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( "SELECT * FROM {$this->snapshots} WHERE snapshot_type = 'initial' AND generation_status = 'complete' ORDER BY snapshot_id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'baseline_read_failed' ) : $row;
	}

	/** Latest attempt is only used when no completed Baseline exists. */
	public function get_latest_initial_attempt() {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( "SELECT snapshot_id, generation_status, failure_code, started_at FROM {$this->snapshots} WHERE snapshot_type = 'initial' ORDER BY snapshot_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'baseline_read_failed' ) : $row;
	}

	/** One ordered query; no per-item database lookups in the presentation. */
	public function get_snapshot_items( $id ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT item_type, item_key, item_key_hash, display_name, installed_version, available_version, is_active, update_available, parent_key, severity, item_payload, display_order FROM {$this->items} WHERE snapshot_id = %d ORDER BY item_type ASC, display_order ASC, snapshot_item_id ASC", $id ) );
		return '' !== $this->wpdb->last_error || ! is_array( $rows ) ? new WP_Error( 'baseline_items_read_failed' ) : $rows;
	}
}
