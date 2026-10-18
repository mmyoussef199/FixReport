<?php
/**
 * Handles database interactions for the FixReport plugin.
 */
class FixReport_DB {

	private $table_name;
    private $wpdb;

	public function __construct() {
		global $wpdb;
        $this->wpdb = $wpdb;
		$this->table_name = $this->wpdb->prefix . 'fixreport_logs';
	}

	public function insert_log( $data ) {
        if ( empty( $data['title'] ) || empty( $data['description'] ) || empty( $data['type'] ) ) {
            return false;
        }
		$formats = array(
			'title'       => '%s', 'description' => '%s', 'type'        => '%s',
			'priority'    => '%s', 'status'      => '%s', 'user_id'     => '%d',
			'created_at'  => '%s', 'updated_at'  => '%s',
		);
		$result = $this->wpdb->insert( $this->table_name, $data, $formats );
		if ( $result === false ) {
			return false;
		}
		return $this->wpdb->insert_id;
	}

    /**
     * Retrieve log entries from the database.
     * This is the final corrected version to address both the report issue and linter errors.
     */
    public function get_logs( $args = array() ) {
        $defaults = array(
            'number'  => 20,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        // Base SQL query - $this->table_name is considered safe.
        $sql = "SELECT * FROM {$this->table_name}"; 
        $params = array();

        // Validate and sanitize orderby and order parameters
        $allowed_orderby = array('log_id', 'title', 'type', 'priority', 'status', 'user_id', 'created_at', 'updated_at');
        $orderby_column = in_array( strtolower( $args['orderby'] ), $allowed_orderby, true ) ? strtolower( $args['orderby'] ) : 'created_at';
        $order_direction = in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ) : 'DESC';
        
        // Construct the ORDER BY clause. This part is not prepared with placeholders but is built from a whitelist.
        $sql .= " ORDER BY " . esc_sql( $orderby_column ) . " " . esc_sql( $order_direction );
        if ( 'log_id' !== $orderby_column ) {
            $sql .= ", log_id " . esc_sql( $order_direction );
        }
        
        // Add LIMIT and OFFSET with placeholders only if a positive number is provided.
        // If number is -1 (or any other non-positive value), this block is skipped.
        if ( isset($args['number']) && intval( $args['number'] ) > 0 ) {
             $sql .= " LIMIT %d OFFSET %d";
             $params[] = intval( $args['number'] );
             $params[] = intval( $args['offset'] );
        }

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, $params );
        }

        // The table and ordering tokens are internal or allow-listed above.
        return $this->wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function delete_log( $log_id ) {
        $log_id = absint( $log_id );
        if ( $log_id <= 0 ) { return false; }
        $result = $this->wpdb->delete( $this->table_name, array( 'log_id' => $log_id ), array( '%d' ) );
        return ($result !== false && $result > 0);
    }

    public function count_logs( $args = array() ) {
        $sql = "SELECT COUNT(*) FROM {$this->table_name}";
        return absint( $this->wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function get_log_by_id( $log_id ) {
        $log_id = absint( $log_id );
        if ( $log_id <= 0 ) { return null; }
        $sql = "SELECT * FROM {$this->table_name} WHERE log_id = %d";
        return $this->wpdb->get_row( $this->wpdb->prepare( $sql, $log_id ) );
    }

    public function update_log( $log_id, $data ) {
        $log_id = absint( $log_id );
        if ( $log_id <= 0 || empty( $data ) || ! is_array( $data ) ) { 
            return false; 
        }

        $update_data = array();
        $allowed_to_update_formats = array(
            'title' => '%s', 'description' => '%s', 'type' => '%s', 
            'priority' => '%s', 'status' => '%s', 'updated_at' => '%s',
        );
        foreach ( $data as $key => $value ) {
            if ( array_key_exists( $key, $allowed_to_update_formats ) ) {
                $update_data[$key] = $value;
            }
        }
        if ( empty( $update_data ) ) { return 0; }
        if ( ! isset( $update_data['updated_at'] ) ) {
            $update_data['updated_at'] = current_time( 'mysql', 1 );
        }
        $final_formats = [];
        foreach(array_keys($update_data) as $key) {
            $final_formats[] = isset($allowed_to_update_formats[$key]) ? $allowed_to_update_formats[$key] : '%s';
        }
        $where_formats = array( '%d' );
        $result = $this->wpdb->update( $this->table_name, $update_data, array( 'log_id' => $log_id ), $final_formats, $where_formats );

        if ( $result === false ) { return false; }
        return $result; 
    }
}
