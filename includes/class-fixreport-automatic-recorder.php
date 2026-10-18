<?php
/** Validates and persists only confirmed, bounded automatic maintenance evidence. */
class FixReport_Automatic_Recorder {
	private $repository;
	private $checkpoints;

	private const EVENTS = array(
		'wordpress_core_updated' => array( 'update', 'wordpress_core' ),
		'plugin_updated' => array( 'update', 'plugin' ),
		'theme_updated' => array( 'update', 'theme' ),
		'plugin_activated' => array( 'content_configuration', 'plugin' ),
		'plugin_deactivated' => array( 'content_configuration', 'plugin' ),
		'plugin_installed' => array( 'content_configuration', 'plugin' ),
		'plugin_deleted' => array( 'content_configuration', 'plugin' ),
		'theme_installed' => array( 'content_configuration', 'theme' ),
		'theme_deleted' => array( 'content_configuration', 'theme' ),
		'theme_switched' => array( 'content_configuration', 'theme' ),
	);

	public function __construct( $repository, $checkpoints = null ) {
		$this->repository = $repository;
		$this->checkpoints = $checkpoints;
	}

	/** Returns an Activity ID or a safe WP_Error. It never throws into a WP hook. */
	public function record( $event ) {
		try {
			$data = $this->normalize( $event );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$id = $this->repository->insert_automatic_activity( $data );
			if ( ! $id ) {
				$this->diagnostic( 'persistence_failed' );
				return new WP_Error( 'automatic_persistence_failed' );
			}
			if ( $this->checkpoints ) {
				try {
					$this->checkpoints->request( $id );
				} catch ( Throwable $error ) {
					// The Activity is already durable. Reconciliation can recover checkpoint work.
					$this->diagnostic( 'checkpoint_queue_failed' );
				}
			}
			return $id;
		} catch ( Throwable $error ) {
			$this->diagnostic( 'recording_exception' );
			return new WP_Error( 'automatic_recording_failed' );
		}
	}

	private function normalize( $event ) {
		if ( ! is_array( $event ) || ! isset( $event['event_type'], $event['object_key'], $event['object_name'], $event['metadata'] ) || ! isset( self::EVENTS[ $event['event_type'] ] ) ) {
			return new WP_Error( 'automatic_event_invalid' );
		}
		$type = $event['event_type'];
		if ( array_key_exists( 'category', $event ) ) {
			return new WP_Error( 'automatic_category_invalid' );
		}
		$object_type = self::EVENTS[ $type ][1];
		$key = $event['object_key'];
		$name = $event['object_name'];
		if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 191 || ! is_string( $name ) || '' === trim( $name ) || $this->length( $name ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return new WP_Error( 'automatic_identity_invalid' );
		}
		if ( 'wordpress_core' === $object_type && 'wordpress' !== $key ) {
			return new WP_Error( 'automatic_identity_invalid' );
		}
		if ( 'plugin' === $object_type && ( 0 !== validate_file( $key ) || plugin_basename( $key ) !== $key || '.php' !== substr( $key, -4 ) ) ) {
			return new WP_Error( 'automatic_identity_invalid' );
		}
		if ( 'theme' === $object_type && ( false !== strpos( $key, '/' ) || 0 !== validate_file( $key ) ) ) {
			return new WP_Error( 'automatic_identity_invalid' );
		}
		$old = array_key_exists( 'previous_version', $event ) ? $event['previous_version'] : null;
		$new = array_key_exists( 'new_version', $event ) ? $event['new_version'] : null;
		if ( ! $this->valid_version( $old ) || ! $this->valid_version( $new ) ) {
			return new WP_Error( 'automatic_version_invalid' );
		}
		if ( in_array( $type, array( 'wordpress_core_updated', 'plugin_updated', 'theme_updated' ), true ) ) {
			if ( null === $old || null === $new || ! version_compare( $new, $old, '>' ) ) {
				return new WP_Error( 'automatic_version_transition_invalid' );
			}
		} elseif ( in_array( $type, array( 'plugin_installed', 'theme_installed' ), true ) ) {
			if ( null !== $old || null === $new ) {
				return new WP_Error( 'automatic_version_transition_invalid' );
			}
		} elseif ( in_array( $type, array( 'plugin_deleted', 'theme_deleted' ), true ) ) {
			if ( null !== $new ) {
				return new WP_Error( 'automatic_version_transition_invalid' );
			}
		} elseif ( null !== $old || null !== $new ) {
			return new WP_Error( 'automatic_version_transition_invalid' );
		}
		$metadata = $this->metadata( $type, $event['metadata'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$actor = array_key_exists( 'performed_by', $event ) ? $event['performed_by'] : null;
		if ( null !== $actor && ( ! is_int( $actor ) || $actor <= 0 || ! get_user_by( 'id', $actor ) ) ) {
			return new WP_Error( 'automatic_actor_invalid' );
		}
		$event_time = isset( $event['activity_date'] ) && $this->valid_timestamp( $event['activity_date'] ) ? $event['activity_date'] : gmdate( 'Y-m-d H:i:s' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$copy = $this->copy( $type, $name, $old, $new, $metadata );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		return array(
			'source' => 'automatic', 'event_type' => $type, 'category' => self::EVENTS[ $type ][0],
			'title' => $copy[0], 'description' => $copy[1], 'internal_note' => null,
			'status' => 'completed', 'priority' => 'medium', 'client_visible' => 1,
			'object_type' => $object_type, 'object_key' => $key, 'object_key_hash' => hash( 'sha256', $key ),
			'object_name' => $name, 'previous_version' => $old, 'new_version' => $new,
			'metadata' => wp_json_encode( $metadata ), 'performed_by' => $actor,
			'activity_date' => $event_time, 'created_at' => $now, 'updated_at' => $now,
		);
	}

	private function metadata( $type, $value ) {
		if ( ! is_array( $value ) || ! isset( $value['contract_version'], $value['provenance_hook'], $value['mechanism'], $value['bulk'] ) || '4b.1' !== $value['contract_version'] || ! is_bool( $value['bulk'] ) ) {
			return new WP_Error( 'automatic_metadata_invalid' );
		}
		$hooks = array( '_core_updated_successfully', 'upgrader_process_complete', 'automatic_updates_complete', 'activated_plugin', 'updated_option', 'update_site_option_active_sitewide_plugins', 'deleted_plugin', 'deleted_theme', 'switch_theme' );
		$mechanisms = array( 'wordpress_auto_updater', 'admin_request', 'wp_cli', 'programmatic_unknown' );
		if ( ! in_array( $value['provenance_hook'], $hooks, true ) || ! in_array( $value['mechanism'], $mechanisms, true ) ) {
			return new WP_Error( 'automatic_metadata_invalid' );
		}
		$allowed = array( 'contract_version', 'provenance_hook', 'mechanism', 'bulk' );
		if ( in_array( $type, array( 'plugin_activated', 'plugin_deactivated' ), true ) ) {
			$allowed = array_merge( $allowed, array( 'network_wide', 'observed_version' ) );
			if ( isset( $value['network_wide'] ) && ! is_bool( $value['network_wide'] ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
			if ( isset( $value['observed_version'] ) && ! $this->valid_version( $value['observed_version'] ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
		}
		if ( 'theme_switched' === $type ) {
			$allowed = array_merge( $allowed, array( 'previous_stylesheet', 'previous_template', 'new_template' ) );
			if ( ! isset( $value['previous_stylesheet'], $value['previous_template'], $value['new_template'] ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
			foreach ( array( 'previous_stylesheet', 'previous_template', 'new_template' ) as $key ) {
				if ( ! is_string( $value[ $key ] ) || '' === $value[ $key ] || strlen( $value[ $key ] ) > 191 || 0 !== validate_file( $value[ $key ] ) || false !== strpos( $value[ $key ], '/' ) ) {
					return new WP_Error( 'automatic_metadata_invalid' );
				}
			}
		}
		if ( in_array( $type, array( 'plugin_updated', 'theme_updated', 'plugin_installed', 'theme_installed' ), true ) ) {
			$allowed = array_merge( $allowed, array( 'package_action', 'identity_changed', 'replacement_identity' ) );
			if ( isset( $value['package_action'] ) && ! in_array( $value['package_action'], array( 'install', 'update' ), true ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
			if ( isset( $value['identity_changed'] ) && ! is_bool( $value['identity_changed'] ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
			if ( isset( $value['replacement_identity'] ) && ( ! is_string( $value['replacement_identity'] ) || strlen( $value['replacement_identity'] ) > 191 || 0 !== validate_file( $value['replacement_identity'] ) ) ) {
				return new WP_Error( 'automatic_metadata_invalid' );
			}
		}
		if ( array_diff( array_keys( $value ), $allowed ) || strlen( wp_json_encode( $value ) ) > 1024 ) {
			return new WP_Error( 'automatic_metadata_invalid' );
		}
		return $value;
	}

	private function copy( $type, $name, $old, $new, $metadata ) {
		switch ( $type ) {
			case 'wordpress_core_updated': return array( __( 'WordPress Core Updated', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: old version; 2: new version. */ __( 'WordPress Core was updated from %1$s to %2$s.', 'fixreport-maintenance-logger' ), $old, $new ) );
			case 'plugin_updated': return array( __( 'Plugin Updated', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: plugin name; 2: old version; 3: new version. */ __( '%1$s was updated from %2$s to %3$s.', 'fixreport-maintenance-logger' ), $name, $old, $new ) );
			case 'theme_updated': return array( __( 'Theme Updated', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: theme name; 2: old version; 3: new version. */ __( '%1$s was updated from %2$s to %3$s.', 'fixreport-maintenance-logger' ), $name, $old, $new ) );
			case 'plugin_activated': return array( __( 'Plugin Activated', 'fixreport-maintenance-logger' ), sprintf( /* translators: %s: plugin name. */ __( '%s was activated.', 'fixreport-maintenance-logger' ), $name ) );
			case 'plugin_deactivated': return array( __( 'Plugin Deactivated', 'fixreport-maintenance-logger' ), sprintf( /* translators: %s: plugin name. */ __( '%s was deactivated.', 'fixreport-maintenance-logger' ), $name ) );
			case 'plugin_installed': return array( __( 'Plugin Installed', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: plugin name; 2: version. */ __( '%1$s version %2$s was installed.', 'fixreport-maintenance-logger' ), $name, $new ) );
			case 'plugin_deleted': return array( __( 'Plugin Deleted', 'fixreport-maintenance-logger' ), sprintf( /* translators: %s: plugin name. */ __( '%s was deleted.', 'fixreport-maintenance-logger' ), $name ) );
			case 'theme_installed': return array( __( 'Theme Installed', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: theme name; 2: version. */ __( '%1$s version %2$s was installed.', 'fixreport-maintenance-logger' ), $name, $new ) );
			case 'theme_deleted': return array( __( 'Theme Deleted', 'fixreport-maintenance-logger' ), sprintf( /* translators: %s: theme name. */ __( '%s was deleted.', 'fixreport-maintenance-logger' ), $name ) );
			case 'theme_switched': return array( __( 'Theme Switched', 'fixreport-maintenance-logger' ), sprintf( /* translators: 1: previous theme stylesheet; 2: new theme name. */ __( 'The active theme changed from %1$s to %2$s.', 'fixreport-maintenance-logger' ), $metadata['previous_stylesheet'], $name ) );
	}
	return new WP_Error( 'automatic_event_invalid' );
	}

	private function valid_version( $version ) {
		return null === $version || ( is_string( $version ) && '' !== trim( $version ) && strlen( $version ) <= 64 && ! preg_match( '/[\x00-\x1F\x7F]/', $version ) );
	}

	private function valid_timestamp( $timestamp ) {
		$date = is_string( $timestamp ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $timestamp, new DateTimeZone( 'UTC' ) ) : false;
		return $date && $date->format( 'Y-m-d H:i:s' ) === $timestamp;
	}

	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private function diagnostic( $code ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'FixReport automatic tracking: ' . $code );
		}
	}
}
