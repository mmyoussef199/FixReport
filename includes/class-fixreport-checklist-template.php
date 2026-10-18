<?php
/** Editable site-wide checklist recommendations; never report execution data. */
class FixReport_Checklist_Template {
	const OPTION = 'fixreport_checklist_template_v1';
	const ARCHIVE_OPTION = 'fixreport_checklist_retired_v1';
	const CONTRACT = 1;
	private $option;
	private $archive_option;
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	public function __construct( $option = self::OPTION, $archive_option = self::ARCHIVE_OPTION ) {
		$this->option = $option;
		$this->archive_option = $archive_option;
	}
	public function option_name() { return $this->option; }
	public function archive_option_name() { return $this->archive_option; }

	public static function transactional_options() {
		global $wpdb;
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->options ) ), ARRAY_A );
		return $status && 'InnoDB' === $status['Engine'];
	}

	public static function lock_option( $name ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $name ) );
		return null === $raw ? new WP_Error( 'checklist_option_missing' ) : $raw;
	}

	public static function valid_archive( $data ) {
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'schema_version', 'revision', 'items' ) || 1 !== $data['schema_version'] || ! is_string( $data['revision'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', $data['revision'] ) || ! is_array( $data['items'] ) ) { return false; }
		foreach ( $data['items'] as $key => $item ) {
			if ( ! is_string( $key ) || ! preg_match( '/^custom_[a-f0-9]{32}$/', $key ) || ! is_array( $item ) || ! isset( $item['id'], $item['label_override'], $item['sort_order'], $item['retired_at_utc'], $item['retired_by'] ) || $item['id'] !== $key || ! is_string( $item['label_override'] ) || ! is_string( $item['description_override'] ) || ! is_int( $item['sort_order'] ) || ! is_string( $item['retired_at_utc'] ) || ! is_int( $item['retired_by'] ) ) { return false; }
		}
		return true;
	}

	public function initialize_archive() {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $this->archive_option ) );
		if ( null === $exists ) { add_option( $this->archive_option, array( 'schema_version' => 1, 'revision' => wp_generate_uuid4(), 'items' => array() ), '', false ); }
		return ! is_wp_error( $this->get_archive() );
	}

	public function get_archive() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $this->archive_option ) );
		$data = null === $raw ? null : maybe_unserialize( $raw );
		return self::valid_archive( $data ) ? $data : new WP_Error( 'checklist_archive_invalid' );
	}

	/** Keys are permanent; strings are translated only when displayed. */
	public static function defaults() {
		return array(
			'backup_verification' => array( __( 'Backup Verification', 'fixreport-maintenance-logger' ), __( 'Confirm that a recent backup exists and can be restored.', 'fixreport-maintenance-logger' ) ),
			'wordpress_core_review' => array( __( 'WordPress Core Review', 'fixreport-maintenance-logger' ), __( 'Review the installed WordPress version and available core updates.', 'fixreport-maintenance-logger' ) ),
			'plugin_review_updates' => array( __( 'Plugin Review & Updates', 'fixreport-maintenance-logger' ), __( 'Review installed plugins, their status, and available updates.', 'fixreport-maintenance-logger' ) ),
			'theme_review_updates' => array( __( 'Theme Review & Updates', 'fixreport-maintenance-logger' ), __( 'Review installed themes and available theme updates.', 'fixreport-maintenance-logger' ) ),
			'security_review' => array( __( 'Security Review', 'fixreport-maintenance-logger' ), __( 'Check key security settings and investigate visible warnings.', 'fixreport-maintenance-logger' ) ),
			'site_health_review' => array( __( 'Site Health Review', 'fixreport-maintenance-logger' ), __( 'Review WordPress Site Health findings and recommended actions.', 'fixreport-maintenance-logger' ) ),
			'performance_check' => array( __( 'Performance Check', 'fixreport-maintenance-logger' ), __( 'Check representative pages for performance concerns.', 'fixreport-maintenance-logger' ) ),
			'critical_functionality_test' => array( __( 'Critical Functionality Test', 'fixreport-maintenance-logger' ), __( 'Test the site’s most important user journeys.', 'fixreport-maintenance-logger' ) ),
			'forms_submission_test' => array( __( 'Forms & Submission Test', 'fixreport-maintenance-logger' ), __( 'Submit key forms and verify delivery or confirmation.', 'fixreport-maintenance-logger' ) ),
			'frontend_visual_check' => array( __( 'Front-end Visual Check', 'fixreport-maintenance-logger' ), __( 'Inspect representative front-end pages for visual issues.', 'fixreport-maintenance-logger' ) ),
			'final_verification' => array( __( 'Final Verification', 'fixreport-maintenance-logger' ), __( 'Confirm the completed work and any remaining follow-up.', 'fixreport-maintenance-logger' ) ),
		);
	}

	private static function seed() {
		$items = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$items[] = array( 'id' => $key, 'label_override' => null, 'description_override' => null, 'sort_order' => count( $items ) );
		}
		return array( 'schema_version' => self::CONTRACT, 'revision' => wp_generate_uuid4(), 'items' => $items );
	}

	/** add_option is atomic on option_name; an existing empty array is never reseeded. */
	public function initialize() {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );
		if ( null !== $exists ) {
			return true;
		}
		return add_option( $this->option, self::seed(), '', false ) || null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );
	}

	/** Read the database, not a possibly stale option cache, for revision-aware writes. */
	public function get() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );
		if ( null === $raw ) {
			return new WP_Error( 'checklist_missing' );
		}
		$data = maybe_unserialize( $raw );
		return self::valid( $data ) ? $data : new WP_Error( 'checklist_malformed' );
	}

	public static function valid( $data ) {
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'schema_version', 'revision', 'items' ) || self::CONTRACT !== $data['schema_version'] || ! is_string( $data['revision'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', $data['revision'] ) || ! is_array( $data['items'] ) || array_values( $data['items'] ) !== $data['items'] || count( $data['items'] ) > 100 ) {
			return false;
		}
		$defaults = self::defaults();
		$seen = array();
		foreach ( $data['items'] as $index => $item ) {
			if ( ! is_array( $item ) || array_keys( $item ) !== array( 'id', 'label_override', 'description_override', 'sort_order' ) || ! is_string( $item['id'] ) || isset( $seen[ $item['id'] ] ) || $index !== $item['sort_order'] ) {
				return false;
			}
			$id = $item['id'];
			$builtin = isset( $defaults[ $id ] );
			if ( ! $builtin && ! preg_match( '/^custom_[a-f0-9]{32}$/', $id ) ) {
				return false;
			}
			if ( ! ( null === $item['label_override'] && $builtin || is_string( $item['label_override'] ) && '' !== trim( $item['label_override'] ) && self::length( $item['label_override'] ) <= 255 ) || ! ( null === $item['description_override'] || is_string( $item['description_override'] ) && self::length( $item['description_override'] ) <= 2000 ) ) {
				return false;
			}
			$seen[ $id ] = true;
		}
		return true;
	}

	public static function resolved( $item ) {
		$default = isset( self::defaults()[ $item['id'] ] ) ? self::defaults()[ $item['id'] ] : array( '', '' );
		return array( 'id' => $item['id'], 'label' => null === $item['label_override'] ? $default[0] : $item['label_override'], 'description' => null === $item['description_override'] ? $default[1] : $item['description_override'], 'sort_order' => $item['sort_order'] );
	}

	public static function missing_count( $data ) {
		$present = array_column( $data['items'], 'id' );
		return count( array_diff( array_keys( self::defaults() ), $present ) );
	}

	private static function clean_text( $raw, $limit, $required ) {
		if ( ! is_string( $raw ) ) {
			return new WP_Error( 'checklist_invalid_text' );
		}
		$value = trim( 255 === $limit ? sanitize_text_field( wp_unslash( $raw ) ) : sanitize_textarea_field( wp_unslash( $raw ) ) );
		if ( $required && '' === $value ) {
			return new WP_Error( 'checklist_label_required' );
		}
		return self::length( $value ) > $limit ? new WP_Error( 'checklist_text_too_long' ) : $value;
	}

	/** All operations use the submitted revision and one atomic SQL CAS. */
	public function mutate( $action, $expected_revision, $input = array() ) {
		global $wpdb;
		if ( ! self::transactional_options() ) { return new WP_Error( 'checklist_storage_unavailable' ); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'checklist_transaction_failed' ); }
		$committed = false;
		try {
			$result = $this->mutate_locked( $action, $expected_revision, $input );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( false === $wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'checklist_transaction_failed' ); }
			$committed = true;
			return $result;
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}

	private function mutate_locked( $action, $expected_revision, $input ) {
		global $wpdb;
		if ( ! is_string( $expected_revision ) || ! preg_match( '/^[a-f0-9-]{36}$/', $expected_revision ) ) {
			return new WP_Error( 'checklist_stale' );
		}
		$old_raw = self::lock_option( $this->option );
		$old = is_wp_error( $old_raw ) ? $old_raw : maybe_unserialize( $old_raw );
		if ( is_wp_error( $old ) || ! self::valid( $old ) ) {
			return new WP_Error( 'checklist_malformed' );
		}
		if ( $old['revision'] !== $expected_revision ) {
			return new WP_Error( 'checklist_stale' );
		}
		$next = $old;
		$id = isset( $input['id'] ) && is_string( $input['id'] ) ? $input['id'] : '';
		$index = false;
		foreach ( $next['items'] as $i => $item ) {
			if ( $item['id'] === $id ) { $index = $i; break; }
		}
		if ( 'add' === $action || 'edit' === $action ) {
			if ( 'edit' === $action && false === $index ) { return new WP_Error( 'checklist_item_missing' ); }
			if ( 'add' === $action && count( $next['items'] ) >= 100 ) { return new WP_Error( 'checklist_full' ); }
			$label = self::clean_text( isset( $input['label'] ) ? $input['label'] : null, 255, true );
			$description = self::clean_text( isset( $input['description'] ) ? $input['description'] : null, 2000, false );
			if ( is_wp_error( $label ) ) { return $label; }
			if ( is_wp_error( $description ) ) { return $description; }
			if ( 'add' === $action ) {
				$archive_raw = self::lock_option( $this->archive_option );
				$archive = is_wp_error( $archive_raw ) ? $archive_raw : maybe_unserialize( $archive_raw );
				if ( is_wp_error( $archive ) || ! self::valid_archive( $archive ) ) { return new WP_Error( 'checklist_archive_invalid' ); }
				$present = array_column( $next['items'], 'id' );
				for ( $attempt = 0; $attempt < 5; $attempt++ ) {
					$new_id = 'custom_' . str_replace( '-', '', wp_generate_uuid4() );
					if ( ! in_array( $new_id, $present, true ) && ! isset( $archive['items'][ $new_id ] ) ) { break; }
				}
				if ( in_array( $new_id, $present, true ) || isset( $archive['items'][ $new_id ] ) ) { return new WP_Error( 'checklist_id_collision' ); }
				$next['items'][] = array( 'id' => $new_id, 'label_override' => $label, 'description_override' => $description, 'sort_order' => count( $next['items'] ) );
			} else {
				$next['items'][ $index ]['label_override'] = $label;
				$next['items'][ $index ]['description_override'] = $description;
			}
		} elseif ( 'delete' === $action ) {
			if ( false === $index ) { return new WP_Error( 'checklist_item_missing' ); }
			if ( 0 === strpos( $id, 'custom_' ) ) {
				$archive_result = $this->archive_change( $id, $next['items'][ $index ], false );
				if ( is_wp_error( $archive_result ) ) { return $archive_result; }
			}
			array_splice( $next['items'], $index, 1 );
		} elseif ( 'restore_custom' === $action ) {
			if ( false !== $index ) { return new WP_Error( 'checklist_id_collision' ); }
			if ( count( $next['items'] ) >= 100 ) { return new WP_Error( 'checklist_full' ); }
			$archived = $this->archive_change( $id, null, true );
			if ( is_wp_error( $archived ) ) { return $archived; }
			$next['items'][] = array( 'id' => $id, 'label_override' => $archived['label_override'], 'description_override' => $archived['description_override'], 'sort_order' => count( $next['items'] ) );
		} elseif ( 'up' === $action || 'down' === $action ) {
			if ( false === $index ) { return new WP_Error( 'checklist_item_missing' ); }
			$target = $index + ( 'up' === $action ? -1 : 1 );
			if ( $target < 0 || $target >= count( $next['items'] ) ) { return new WP_Error( 'checklist_no_change' ); }
			$tmp = $next['items'][ $target ]; $next['items'][ $target ] = $next['items'][ $index ]; $next['items'][ $index ] = $tmp;
		} elseif ( 'restore' === $action ) {
			$missing = self::missing_count( $next );
			if ( 0 === $missing ) { return new WP_Error( 'checklist_no_change' ); }
			if ( count( $next['items'] ) + $missing > 100 ) { return new WP_Error( 'checklist_full' ); }
			$present = array_column( $next['items'], 'id' );
			foreach ( array_keys( self::defaults() ) as $key ) {
				if ( ! in_array( $key, $present, true ) ) {
					$next['items'][] = array( 'id' => $key, 'label_override' => null, 'description_override' => null, 'sort_order' => count( $next['items'] ) );
				}
			}
		} else {
			return new WP_Error( 'checklist_invalid_action' );
		}
		foreach ( $next['items'] as $i => &$item ) { $item['sort_order'] = $i; }
		unset( $item );
		if ( ! self::valid( $next ) ) { return new WP_Error( 'checklist_malformed' ); }
		$next['revision'] = wp_generate_uuid4();
		// BINARY avoids a case-insensitive collation treating distinct serialized values as equal.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), $this->option, $old_raw ) );
		if ( 1 !== $changed ) { return new WP_Error( false === $changed ? 'checklist_save_failed' : 'checklist_stale' ); }
		wp_cache_delete( $this->option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $next;
	}

	/** Called only under the template lock; lock order is template then archive. */
	private function archive_change( $id, $item, $restore ) {
		global $wpdb;
		$raw = self::lock_option( $this->archive_option );
		$archive = is_wp_error( $raw ) ? $raw : maybe_unserialize( $raw );
		if ( is_wp_error( $archive ) || ! self::valid_archive( $archive ) ) { return new WP_Error( 'checklist_archive_invalid' ); }
		if ( $restore ) {
			if ( ! isset( $archive['items'][ $id ] ) ) { return new WP_Error( 'checklist_retired_item_missing' ); }
			$result = $archive['items'][ $id ];
			unset( $archive['items'][ $id ] );
		} else {
			if ( isset( $archive['items'][ $id ] ) ) { return new WP_Error( 'checklist_id_collision' ); }
			$archive['items'][ $id ] = $item + array( 'retired_at_utc' => current_time( 'mysql', true ), 'retired_by' => (int) get_current_user_id() );
			$result = true;
		}
		$archive['revision'] = wp_generate_uuid4();
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $archive ), $this->archive_option, $raw ) );
		if ( 1 !== $changed ) { return new WP_Error( 'checklist_archive_save_failed' ); }
		wp_cache_delete( $this->archive_option, 'options' ); wp_cache_delete( 'alloptions', 'options' );
		return $result;
	}
}
