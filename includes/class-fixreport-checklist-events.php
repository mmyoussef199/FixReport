<?php
/** Site-wide, manually documented checklist transitions; independent of Reports. */
class FixReport_Checklist_Events {
	private $db;
	private $repository;
	private $template;
	private $categories;

	public function __construct( $template = null, $categories = null, $fixture_table = null ) {
		global $wpdb;
		$this->db = $wpdb;
		$this->repository = new FixReport_Checklist_Event_Repository( $fixture_table );
		$this->template = $template ?: new FixReport_Checklist_Template();
		$this->categories = $categories ?: new FixReport_Checklist_Categories();
	}

	public static function nonce_action( $task_key, $action ) {
		return 'fixreport_checklist_transition_' . $task_key . '_' . $action;
	}

	private function authorized() { return current_user_can( 'manage_options' ); }

	private function storage_ready() {
		if ( ! FixReport_Checklist_Template::transactional_options() || FIXREPORT_DB_VERSION !== get_option( FixReport_Activator::SCHEMA_VERSION_OPTION ) ) { return false; }
		return $this->repository->transactional_storage_ready();
	}

	private function same_request( $event, $task_key, $action, $expected_id, $note, $actor ) {
		return $event && $event['task_key'] === $task_key && $event['event_type'] === $action && (int) $event['expected_event_id'] === $expected_id && (string) ( $event['note'] ?? '' ) === $note && (int) $event['actor_user_id'] === $actor;
	}

	/**
	 * The template option row is the coordination lock, including the first event.
	 * Consistent order: template -> categories -> event read/insert. Template edits
	 * lock template; category edits lock template -> categories; custom retirement
	 * locks template -> archive. Deadlocks fail closed and may be retried with the
	 * same request token after the transaction outcome is known.
	 */
	public function transition( $task_key, $action, $expected_id, $token, $note, $nonce ) {
		if ( ! $this->authorized() ) { return new WP_Error( 'checklist_forbidden' ); }
		if ( ! is_string( $task_key ) || ! preg_match( '/^(?:[a-z][a-z0-9_]{0,63}|custom_[a-f0-9]{32})$/', $task_key ) || ! in_array( $action, array( 'completed', 'reopened' ), true ) || ! is_int( $expected_id ) || $expected_id < 0 || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $token ) || ! is_string( $note ) || ! is_string( $nonce ) ) { return new WP_Error( 'checklist_transition_invalid' ); }
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $task_key, $action ) ) ) { return new WP_Error( 'checklist_nonce_invalid' ); }
		$note = trim( sanitize_textarea_field( $note ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $note, 'UTF-8' ) : strlen( $note ) ) > 2000 ) { return new WP_Error( 'checklist_note_too_long' ); }
		if ( ! $this->storage_ready() ) { return new WP_Error( 'checklist_storage_unavailable' ); }
		$actor = (int) get_current_user_id();
		if ( $actor < 1 || false === $this->db->query( 'START TRANSACTION' ) ) { return new WP_Error( 'checklist_transaction_failed' ); }
		$committed = false;
		try {
			$raw = FixReport_Checklist_Template::lock_option( $this->template->option_name() );
			$template = is_wp_error( $raw ) ? $raw : maybe_unserialize( $raw );
			if ( is_wp_error( $template ) || ! FixReport_Checklist_Template::valid( $template ) ) { return new WP_Error( 'checklist_template_invalid' ); }
			$category_raw = FixReport_Checklist_Template::lock_option( $this->categories->option_name() );
			$categories = is_wp_error( $category_raw ) ? $category_raw : maybe_unserialize( $category_raw );
			if ( is_wp_error( $categories ) || ! FixReport_Checklist_Categories::valid( $categories ) ) { return new WP_Error( 'checklist_categories_invalid' ); }
			$existing = $this->repository->by_token( $token );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( $existing ) {
				return $this->same_request( $existing, $task_key, $action, $expected_id, $note, $actor ) ? $existing : new WP_Error( 'checklist_token_conflict' );
			}
			$item = null;
			foreach ( $template['items'] as $candidate ) { if ( $candidate['id'] === $task_key ) { $item = $candidate; break; } }
			if ( null === $item ) { return new WP_Error( 'checklist_task_missing' ); }
			$latest = $this->repository->latest( $task_key );
			if ( is_wp_error( $latest ) ) { return $latest; }
			if ( $latest && ! in_array( $latest['event_type'], array( 'completed', 'reopened' ), true ) ) { return new WP_Error( 'checklist_history_invalid' ); }
			$latest_id = $latest ? (int) $latest['event_id'] : 0;
			if ( $latest_id !== $expected_id ) { return new WP_Error( 'checklist_transition_stale' ); }
			if ( ( 'completed' === $action && $latest && 'completed' === $latest['event_type'] ) || ( 'reopened' === $action && ( ! $latest || 'completed' !== $latest['event_type'] ) ) ) { return new WP_Error( 'checklist_transition_invalid_state' ); }
			$resolved = FixReport_Checklist_Template::resolved( $item );
			$inserted = $this->repository->append( array(
				'task_key' => $task_key, 'event_type' => $action, 'actor_user_id' => $actor,
				'occurred_at_utc' => current_time( 'mysql', true ), 'note' => '' === $note ? null : $note,
				'title_snapshot' => $resolved['label'], 'description_snapshot' => $resolved['description'],
				'category_key_snapshot' => FixReport_Checklist_Categories::category_for( $categories, $task_key ),
				'expected_event_id' => $expected_id, 'request_token' => $token,
			) );
			if ( is_wp_error( $inserted ) ) {
				// UNIQUE request_token is the final backstop; no other insert failure is success.
				return $inserted;
			}
			$id = $inserted;
			if ( false === $this->db->query( 'COMMIT' ) ) { return new WP_Error( 'checklist_transaction_failed' ); }
			$committed = true;
			return $this->repository->by_id( $id );
		} finally {
			if ( ! $committed ) { $this->db->query( 'ROLLBACK' ); }
		}
	}

	public function latest_for_active() {
		if ( ! $this->authorized() ) { return new WP_Error( 'checklist_forbidden' ); }
		if ( ! $this->storage_ready() ) { return new WP_Error( 'checklist_storage_unavailable' ); }
		$template = $this->template->get();
		if ( is_wp_error( $template ) ) { return $template; }
		$keys = array_column( $template['items'], 'id' );
		$rows = $this->repository->latest_for_keys( $keys );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$active = array_fill_keys( array_column( $template['items'], 'id' ), true );
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['event_type'], array( 'completed', 'reopened' ), true ) ) { return new WP_Error( 'checklist_history_invalid' ); }
			if ( isset( $active[ $row['task_key'] ] ) ) { $result[ $row['task_key'] ] = $row; }
		}
		return $result;
	}

	public function summary() {
		if ( ! $this->authorized() ) { return new WP_Error( 'checklist_forbidden' ); }
		$template = $this->template->get();
		$latest = $this->latest_for_active();
		if ( is_wp_error( $template ) ) { return $template; }
		if ( is_wp_error( $latest ) ) { return $latest; }
		$completed = 0;
		foreach ( $latest as $event ) { if ( 'completed' === $event['event_type'] ) { $completed++; } }
		$last = $this->repository->last_status_change();
		if ( is_wp_error( $last ) ) { return $last; }
		$total = count( $template['items'] );
		return array( 'total' => $total, 'completed' => $completed, 'pending' => $total - $completed, 'last_status_change' => $last ?: null );
	}

	/** History remains available by stable key even when its definition is retired. */
	public function history( $task_key, $before_event_id = 0, $limit = 25 ) {
		if ( ! $this->authorized() ) { return new WP_Error( 'checklist_forbidden' ); }
		if ( ! $this->storage_ready() ) { return new WP_Error( 'checklist_storage_unavailable' ); }
		if ( ! is_string( $task_key ) || ! preg_match( '/^(?:[a-z][a-z0-9_]{0,63}|custom_[a-f0-9]{32})$/', $task_key ) || ! is_int( $before_event_id ) || $before_event_id < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { return new WP_Error( 'checklist_history_invalid' ); }
		return $this->repository->history( $task_key, $before_event_id, $limit );
	}

	public static function actor_label( $event ) {
		$user = get_userdata( (int) $event['actor_user_id'] );
		return $user ? $user->display_name : sprintf( __( 'Deleted user #%d', 'fixreport-maintenance-logger' ), (int) $event['actor_user_id'] );
	}
}
