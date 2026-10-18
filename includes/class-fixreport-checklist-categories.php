<?php
/** Revisioned category assignments, deliberately separate from template v1. */
class FixReport_Checklist_Categories {
	const OPTION = 'fixreport_checklist_categories_v1';
	private $option;

	public function __construct( $option = self::OPTION ) { $this->option = $option; }
	public function option_name() { return $this->option; }
	public static function allowed_keys() {
		return array( 'wordpress_core_updates', 'plugins_themes', 'backups_recovery', 'security_review', 'performance_optimization', 'content_functionality', 'uncategorized' );
	}

	public static function labels() {
		return array(
			'wordpress_core_updates' => __( 'WordPress Core & Updates', 'fixreport-maintenance-logger' ),
			'plugins_themes' => __( 'Plugins & Themes', 'fixreport-maintenance-logger' ),
			'backups_recovery' => __( 'Backups & Recovery', 'fixreport-maintenance-logger' ),
			'security_review' => __( 'Security Review', 'fixreport-maintenance-logger' ),
			'performance_optimization' => __( 'Performance & Optimization', 'fixreport-maintenance-logger' ),
			'content_functionality' => __( 'Content & Functionality', 'fixreport-maintenance-logger' ),
			'uncategorized' => __( 'Uncategorized', 'fixreport-maintenance-logger' ),
		);
	}

	public static function valid( $data ) {
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'schema_version', 'revision', 'assignments' ) || 1 !== $data['schema_version'] || ! is_string( $data['revision'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', $data['revision'] ) || ! is_array( $data['assignments'] ) ) { return false; }
		foreach ( $data['assignments'] as $key => $category ) {
			if ( ! is_string( $key ) || ! preg_match( '/^(?:[a-z][a-z0-9_]{0,63}|custom_[a-f0-9]{32})$/', $key ) || ! is_string( $category ) || ! in_array( $category, self::allowed_keys(), true ) ) { return false; }
		}
		return true;
	}

	public function initialize() {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $this->option ) );
		if ( null === $exists ) { add_option( $this->option, array( 'schema_version' => 1, 'revision' => wp_generate_uuid4(), 'assignments' => array() ), '', false ); }
		return ! is_wp_error( $this->get() );
	}

	public function get() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $this->option ) );
		$data = null === $raw ? null : maybe_unserialize( $raw );
		return self::valid( $data ) ? $data : new WP_Error( 'checklist_categories_invalid' );
	}

	public static function category_for( $data, $task_key ) {
		return isset( $data['assignments'][ $task_key ] ) ? $data['assignments'][ $task_key ] : 'uncategorized';
	}

	/** Lock order: template option, then category option. Both must be InnoDB. */
	public function assign( $task_key, $category, $expected_revision, $template = null ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'checklist_forbidden' ); }
		if ( ! is_string( $task_key ) || ! is_string( $category ) || ! in_array( $category, self::allowed_keys(), true ) || ! is_string( $expected_revision ) || ! preg_match( '/^[a-f0-9-]{36}$/', $expected_revision ) ) { return new WP_Error( 'checklist_category_invalid' ); }
		if ( ! FixReport_Checklist_Template::transactional_options() || FIXREPORT_DB_VERSION !== get_option( FixReport_Activator::SCHEMA_VERSION_OPTION ) ) { return new WP_Error( 'checklist_storage_unavailable' ); }
		$template = $template ?: new FixReport_Checklist_Template();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'checklist_transaction_failed' ); }
		$committed = false;
		try {
			$template_raw = FixReport_Checklist_Template::lock_option( $template->option_name() );
			$template_data = is_wp_error( $template_raw ) ? $template_raw : maybe_unserialize( $template_raw );
			if ( is_wp_error( $template_data ) || ! FixReport_Checklist_Template::valid( $template_data ) || ! in_array( $task_key, array_column( $template_data['items'], 'id' ), true ) ) { return new WP_Error( 'checklist_task_missing' ); }
			$raw = FixReport_Checklist_Template::lock_option( $this->option );
			$data = is_wp_error( $raw ) ? $raw : maybe_unserialize( $raw );
			if ( is_wp_error( $data ) || ! self::valid( $data ) ) { return new WP_Error( 'checklist_categories_invalid' ); }
			if ( $data['revision'] !== $expected_revision ) { return new WP_Error( 'checklist_categories_stale' ); }
			$next = $data;
			if ( 'uncategorized' === $category ) { unset( $next['assignments'][ $task_key ] ); }
			else { $next['assignments'][ $task_key ] = $category; }
			$next['revision'] = wp_generate_uuid4();
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $next ), $this->option, $raw ) );
			if ( 1 !== $changed || false === $wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'checklist_categories_save_failed' ); }
			$committed = true;
			wp_cache_delete( $this->option, 'options' ); wp_cache_delete( 'alloptions', 'options' );
			return $next;
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}
}
