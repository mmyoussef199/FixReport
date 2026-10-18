<?php
/** Durable, bounded background checkpoint scheduling. No full collection runs in update hooks. */
class FixReport_Checkpoint_Coordinator {
	const OPTION = 'fixreport_checkpoint_state';
	const WORKER = 'fixreport_checkpoint_worker';
	const RECONCILE = 'fixreport_checkpoint_reconcile';
	const QUIET = 600;
	const MAX_WINDOW = 3600;
	const MAX_ATTEMPTS = 5;

	private $wpdb;
	private $lock_name;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->lock_name = 'fixreport_checkpoint_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . get_current_blog_id() ), 0, 24 );
	}

	public function register() {
		add_action( self::WORKER, array( $this, 'work' ) );
		add_action( self::RECONCILE, array( $this, 'reconcile' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		$this->initialize();
		if ( ! wp_next_scheduled( self::RECONCILE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::RECONCILE );
		}
		$this->ensure_worker();
	}

	private function lock() {
		return '1' === (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name, 2 ) );
	}

	private function unlock() {
		$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) );
	}

	private function state() {
		wp_cache_delete( self::OPTION, 'options' );
		return get_option( self::OPTION, false );
	}

	private function save( $state ) {
		return update_option( self::OPTION, $state, false );
	}

	private function initialize() {
		if ( false !== $this->state() || ! $this->lock() ) {
			return;
		}
		try {
			if ( false !== $this->state() ) {
				return;
			}
			$table = $this->wpdb->prefix . 'fixreport_activities';
			$cursor = (int) $this->wpdb->get_var( "SELECT COALESCE(MAX(activity_id), 0) FROM {$table} WHERE source = 'automatic'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$state = array( 'version' => 1, 'seen_id' => $cursor, 'processed_id' => $cursor, 'pending' => $this->window( 'anchor', null, null, time() ), 'inflight' => null, 'last_checkpoint_at' => null, 'last_error' => null );
			$this->save( $state );
		} finally {
			$this->unlock();
		}
	}

	private function window( $trigger, $first, $last, $now ) {
		return array(
			'trigger' => $trigger, 'token' => wp_generate_uuid4(),
			'first_event_at' => $first ? $first->activity_date : null,
			'last_event_at' => $last ? $last->activity_date : null,
			'first_activity_id' => $first ? (int) $first->activity_id : null,
			'last_activity_id' => $last ? (int) $last->activity_id : null,
			'opened_at' => $now, 'due_at' => $now + self::QUIET,
			'attempts' => 0, 'claim_at' => 0,
		);
	}

	/** Called only after a successful automatic Activity insert. */
	public function request( $activity_id ) {
		if ( ! $this->lock() ) {
			return;
		}
		try {
			$state = $this->state();
			if ( ! is_array( $state ) || $activity_id <= $state['processed_id'] ) {
				return;
			}
			foreach ( array( 'pending', 'inflight' ) as $slot ) {
				$covered = $state[ $slot ];
				if ( $covered && 'activity_window' === $covered['trigger'] && $activity_id >= $covered['first_activity_id'] && $activity_id <= $covered['last_activity_id'] ) {
					return;
				}
			}
			$table = $this->wpdb->prefix . 'fixreport_activities';
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT activity_id, activity_date FROM {$table} WHERE activity_id = %d AND source = 'automatic' AND deleted_at IS NULL", $activity_id ) );
			if ( ! $row ) {
				return;
			}
			$now = time();
			if ( ! $state['pending'] || 'activity_window' !== $state['pending']['trigger'] || $now >= $state['pending']['opened_at'] + self::MAX_WINDOW ) {
				// Never overwrite an unprocessed window; reconciliation will pick up later rows.
				if ( $state['pending'] && 'activity_window' === $state['pending']['trigger'] ) {
					return;
				}
				$state['pending'] = $this->window( 'activity_window', $row, $row, $now );
			} else {
				$state['pending']['first_event_at'] = min( $state['pending']['first_event_at'], $row->activity_date );
				$state['pending']['last_event_at'] = max( $state['pending']['last_event_at'], $row->activity_date );
				$state['pending']['first_activity_id'] = min( $state['pending']['first_activity_id'], (int) $row->activity_id );
				$state['pending']['last_activity_id'] = max( $state['pending']['last_activity_id'], (int) $row->activity_id );
				$state['pending']['due_at'] = min( $now + self::QUIET, $state['pending']['opened_at'] + self::MAX_WINDOW );
			}
			$state['seen_id'] = max( $state['seen_id'], (int) $row->activity_id );
			$this->save( $state );
		} finally {
			$this->unlock();
		}
		$this->ensure_worker();
	}

	private function ensure_worker() {
		$state = $this->state();
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( $state['inflight'] && $state['inflight']['attempts'] >= self::MAX_ATTEMPTS ) {
			return;
		}
		$due = $state['inflight'] ? max( time() + 60, (int) $state['inflight']['due_at'], (int) $state['inflight']['claim_at'] + 1800 ) : ( $state['pending'] ? (int) $state['pending']['due_at'] : 0 );
		if ( $due && ! wp_next_scheduled( self::WORKER ) ) {
			wp_schedule_single_event( $due, self::WORKER );
		}
	}

	public function work() {
		if ( ! $this->lock() ) {
			$this->ensure_worker();
			return;
		}
		$window = null;
		try {
			$state = $this->state();
			$now = time();
			if ( is_array( $state ) && $state['inflight'] && $state['inflight']['attempts'] < self::MAX_ATTEMPTS && $state['inflight']['claim_at'] + 1800 <= $now && $state['inflight']['due_at'] <= $now ) {
				$window = $state['inflight'];
			} elseif ( is_array( $state ) && ! $state['inflight'] && $state['pending'] && $state['pending']['due_at'] <= $now ) {
				$window = $state['pending'];
				$state['pending'] = null;
			}
			if ( $window ) {
				$window['claim_at'] = $now;
				$state['inflight'] = $window;
				if ( ! $this->save( $state ) ) {
					$window = null;
				}
			}
		} finally {
			$this->unlock();
		}
		if ( ! $window ) {
			$this->ensure_worker();
			return;
		}
		try {
			$result = $this->capture( $window );
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'checkpoint_worker_exception' );
		}
		if ( is_wp_error( $result ) && 'checkpoint_already_complete' === $result->get_error_code() ) {
			$found = ( new FixReport_Snapshot_Repository() )->get_completed_checkpoint_by_token( $window['token'] );
			$result = $found ? (int) $found->snapshot_id : $result;
		}
		$completed_at = ! is_wp_error( $result ) ? $this->completed_time( $result ) : null;
		$success = null !== $completed_at;
		if ( $this->lock() ) {
			try {
				$state = $this->state();
				if ( is_array( $state ) && $state['inflight'] && $state['inflight']['token'] === $window['token'] ) {
					if ( $success ) {
						$state['processed_id'] = max( $state['processed_id'], (int) $window['last_activity_id'] );
						$state['last_checkpoint_at'] = $completed_at;
						$state['last_error'] = null;
						$state['inflight'] = null;
					} else {
						$state['inflight']['attempts']++;
						$state['inflight']['claim_at'] = 0;
						$state['inflight']['due_at'] = time() + min( 3600, 60 * ( 2 ** $state['inflight']['attempts'] ) );
						$state['last_error'] = is_wp_error( $result ) ? $result->get_error_code() : 'checkpoint_completion_missing';
					}
					$this->save( $state );
				}
			} finally {
				$this->unlock();
			}
		}
		$this->ensure_worker();
		if ( $success ) {
			$this->reconcile();
		}
	}

	/** Split for isolated failure/overlap tests; production always uses the immutable pipeline. */
	protected function capture( $window ) {
		$repository = new FixReport_Snapshot_Repository();
		$existing = $repository->get_completed_checkpoint_by_token( $window['token'] );
		if ( $existing ) {
			return (int) $existing->snapshot_id;
		}
		$provenance = array_intersect_key( $window, array_flip( array( 'trigger', 'token', 'first_event_at', 'last_event_at', 'first_activity_id', 'last_activity_id' ) ) );
		return ( new FixReport_Snapshot_Service( $repository, new FixReport_Snapshot_Collector(), new FixReport_Snapshot_Validator() ) )->create_checkpoint( $provenance );
	}

	protected function completed_time( $id ) {
		$row = ( new FixReport_Snapshot_Repository() )->get_completed_snapshot( (int) $id );
		return ! is_wp_error( $row ) && $row && 'checkpoint' === $row->snapshot_type ? $row->completed_at : null;
	}

	/** Daily bounded repair for an Activity persisted before its scheduling request. */
	public function reconcile() {
		$state = $this->state();
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( $state['inflight'] && $state['inflight']['attempts'] >= self::MAX_ATTEMPTS && $state['inflight']['due_at'] <= time() - DAY_IN_SECONDS ) {
			if ( $this->lock() ) {
				try {
					$state = $this->state();
					if ( $state['inflight'] && $state['inflight']['attempts'] >= self::MAX_ATTEMPTS && $state['inflight']['due_at'] <= time() - DAY_IN_SECONDS ) {
						$state['inflight']['attempts'] = 0;
						$state['inflight']['due_at'] = time() + 60;
						$this->save( $state );
					}
				} finally {
					$this->unlock();
				}
			}
		}
		if ( ! $state['pending'] && ! $state['inflight'] && ( ! $state['last_checkpoint_at'] || strtotime( $state['last_checkpoint_at'] . ' UTC' ) <= time() - 7 * DAY_IN_SECONDS ) ) {
			if ( $this->lock() ) {
				try {
					$state = $this->state();
					if ( ! $state['pending'] && ! $state['inflight'] ) {
						$state['pending'] = $this->window( $state['last_checkpoint_at'] ? 'periodic' : 'anchor', null, null, time() );
						$this->save( $state );
					}
				} finally {
					$this->unlock();
				}
			}
		}
		$table = $this->wpdb->prefix . 'fixreport_activities';
		for ( $i = 0; $i < 100; $i++ ) {
			$state = $this->state();
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT activity_id FROM {$table} WHERE source = 'automatic' AND deleted_at IS NULL AND activity_id > %d ORDER BY activity_id ASC LIMIT 1", $state['seen_id'] ) );
			if ( ! $row ) {
				break;
			}
			$this->request( (int) $row->activity_id );
			$after = $this->state();
			if ( $after['seen_id'] === $state['seen_id'] ) {
				break;
			}
		}
		$this->ensure_worker();
	}

	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = $this->state();
		if ( is_array( $state ) && $state['last_error'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'FixReport checkpoint capture is delayed (%s). Pending evidence remains queued for recovery.', 'fixreport-maintenance-logger' ), $state['last_error'] ) ) . '</p></div>';
		} elseif ( is_array( $state ) && $state['pending'] && $state['pending']['due_at'] < time() - HOUR_IN_SECONDS ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'FixReport checkpoint capture is overdue. WordPress cron may be delayed or disabled; the pending window remains queued.', 'fixreport-maintenance-logger' ) . '</p></div>';
		}
	}
}
