<?php
/** Prepared, append-only persistence and indexed reads for operational events. */
class FixReport_Checklist_Event_Repository {
	private $db;
	private $table;
	private $schema_table;

	public function __construct( $fixture_table = null ) {
		global $wpdb;
		$this->db = $wpdb;
		$this->schema_table = $wpdb->prefix . 'fixreport_checklist_events';
		if ( null !== $fixture_table && ( ! defined( 'FIXREPORT_CHECKLIST_TEST_FIXTURE' ) || true !== FIXREPORT_CHECKLIST_TEST_FIXTURE || ! is_string( $fixture_table ) || ! preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'fixreport_checklist_events_fixture_[a-f0-9]{16}$/', $fixture_table ) ) ) { throw new InvalidArgumentException( 'Invalid checklist fixture table' ); }
		$this->table = null === $fixture_table ? $this->schema_table : $fixture_table;
	}

	public function transactional_storage_ready() {
		$status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $this->db->esc_like( $this->schema_table ) ), ARRAY_A );
		return $status && 'InnoDB' === $status['Engine'];
	}

	public function by_token( $token ) {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE request_token=%s", $token ), ARRAY_A );
		return $this->db->last_error ? new WP_Error( 'checklist_history_unavailable' ) : $row;
	}

	public function latest( $task_key ) {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE task_key=%s ORDER BY event_id DESC LIMIT 1", $task_key ), ARRAY_A );
		return $this->db->last_error ? new WP_Error( 'checklist_history_unavailable' ) : $row;
	}

	public function append( $row ) {
		$inserted = $this->db->insert( $this->table, $row, array( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%s' ) );
		return 1 === $inserted ? (int) $this->db->insert_id : new WP_Error( 'checklist_event_insert_failed' );
	}

	public function by_id( $id ) {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE event_id=%d", $id ), ARRAY_A );
		return $this->db->last_error || ! $row ? new WP_Error( 'checklist_history_unavailable' ) : $row;
	}

	/** Two indexed reads avoid reopening a MySQL temporary table in fixture tests. */
	public function latest_for_keys( $keys ) {
		if ( ! $keys ) { return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ids = $this->db->get_col( $this->db->prepare( "SELECT MAX(event_id) FROM {$this->table} WHERE task_key IN ({$placeholders}) GROUP BY task_key", $keys ) );
		if ( null === $ids || $this->db->last_error ) { return new WP_Error( 'checklist_history_unavailable' ); }
		if ( ! $ids ) { return array(); }
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE event_id IN ({$id_placeholders})", array_map( 'intval', $ids ) ), ARRAY_A );
		return null === $rows || $this->db->last_error ? new WP_Error( 'checklist_history_unavailable' ) : $rows;
	}

	public function last_status_change() {
		$row = $this->db->get_row( "SELECT event_id, occurred_at_utc FROM {$this->table} ORDER BY event_id DESC LIMIT 1", ARRAY_A );
		return $this->db->last_error ? new WP_Error( 'checklist_history_unavailable' ) : $row;
	}

	public function history( $task_key, $before_event_id, $limit ) {
		if ( 0 === $before_event_id ) { $rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE task_key=%s ORDER BY event_id DESC LIMIT %d", $task_key, $limit ), ARRAY_A ); }
		else { $rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE task_key=%s AND event_id<%d ORDER BY event_id DESC LIMIT %d", $task_key, $before_event_id, $limit ), ARRAY_A ); }
		return $this->db->last_error ? new WP_Error( 'checklist_history_unavailable' ) : $rows;
	}
}
