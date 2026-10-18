<?php
/** Authorized, synchronous immutable Snapshot lifecycle coordinator. */
class FixReport_Snapshot_Service {
	private $repository;
	private $collector;
	private $validator;
	private $consistency;

	public function __construct( FixReport_Snapshot_Repository $repository, FixReport_Snapshot_Collector $collector, FixReport_Snapshot_Validator $validator, FixReport_Snapshot_Consistency $consistency = null ) {
		$this->repository = $repository;
		$this->collector = $collector;
		$this->validator = $validator;
		$this->consistency = $consistency ? $consistency : new FixReport_Snapshot_Consistency();
	}

	/** Create one immutable completed Initial Baseline, or return a safe WP_Error. */
	public function create_initial() {
		return $this->create( 'initial' );
	}

	/** Create a new immutable manual Snapshot without replacing any previous row. */
	public function create_manual() {
		return $this->create( 'manual' );
	}

	/** Internal background capture; never exposed as an admin-post action. */
	public function create_checkpoint( $provenance ) {
		return $this->create( 'checkpoint', $provenance );
	}

	private function create( $type, $provenance = null ) {
		if ( 'checkpoint' !== $type && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'snapshot_forbidden' );
		}
		if ( 'checkpoint' === $type && ! FixReport_Snapshot_Validator::valid_checkpoint_provenance( $provenance ) ) {
			return new WP_Error( 'checkpoint_provenance_invalid' );
		}
		if ( ! $this->repository->acquire_capture_lock() ) {
			return new WP_Error( 'lock_unavailable' );
		}
		$id = 0;
		try {
			if ( 'checkpoint' === $type && $this->repository->get_completed_checkpoint_by_token( $provenance['token'] ) ) {
				return new WP_Error( 'checkpoint_already_complete' );
			}
			if ( 'initial' === $type && $this->repository->completed_initial_exists() ) {
				return new WP_Error( 'duplicate_initial' );
			}
			if ( 'manual' === $type && ! $this->repository->completed_initial_exists() ) {
				return new WP_Error( 'initial_required' );
			}
			if ( ! $this->repository->fail_stale_building() ) {
				return new WP_Error( 'persistence_failed' );
			}
			try {
				$before = $this->consistency->signature();
			} catch ( Throwable $e ) {
				return new WP_Error( 'snapshot_consistency_failed' );
			}
			if ( is_wp_error( $before ) ) {
				return $before;
			}
			$id = $this->repository->create_building( get_current_user_id(), $type );
			if ( ! $id ) {
				return new WP_Error( 'persistence_failed' );
			}
			try {
				$collection = $this->collector->collect();
				if ( 'checkpoint' === $type ) {
					$collection['summary']['summary_metadata']['contract_version'] = '3a.2';
					$provenance['captured_at'] = gmdate( 'Y-m-d H:i:s' );
					$collection['summary']['summary_metadata']['checkpoint'] = $provenance;
				}
			} catch ( Throwable $e ) {
				$this->repository->mark_failed( $id, 'collection_failed' );
				return new WP_Error( 'collection_failed' );
			}
			try {
				$validated = $this->validator->validate( $collection );
			} catch ( Throwable $e ) {
				$this->repository->mark_failed( $id, 'validation_failed' );
				return new WP_Error( 'validation_failed' );
			}
			if ( is_wp_error( $validated ) ) {
				$this->repository->mark_failed( $id, 'validation_failed' );
				return new WP_Error( 'validation_failed' );
			}
			try {
				$after = $this->consistency->signature();
			} catch ( Throwable $e ) {
				$after = new WP_Error( 'snapshot_consistency_failed' );
			}
			if ( is_wp_error( $after ) || $before !== $after ) {
				$this->repository->mark_failed( $id, 'consistency_failed' );
				return is_wp_error( $after ) ? $after : new WP_Error( 'snapshot_consistency_failed' );
			}
			try {
				$result = $this->repository->complete_snapshot( $id, $type, $validated );
			} catch ( Throwable $e ) {
				$this->repository->mark_failed( $id, 'persistence_failed' );
				return new WP_Error( 'persistence_failed' );
			}
			if ( is_wp_error( $result ) ) {
				$this->repository->mark_failed( $id, 'snapshot_maintenance_active' === $result->get_error_code() ? 'consistency_failed' : 'persistence_failed' );
				return 'snapshot_maintenance_active' === $result->get_error_code() ? $result : new WP_Error( 'persistence_failed' );
			}
			return $result;
		} finally {
			$this->repository->release_capture_lock();
		}
	}
}
