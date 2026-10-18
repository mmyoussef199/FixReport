<?php
/**
 * Installation and schema-upgrade handling for FixReport.
 */
class FixReport_Activator {

	const SCHEMA_VERSION_OPTION = 'fixreport_db_version';
	const SCHEMA_FAILURE_OPTION = 'fixreport_db_upgrade_failed';

	/**
	 * Run the schema installer when the plugin is activated.
	 *
	 * @return bool
	 */
	public static function activate() {
		return self::maybe_upgrade( true );
	}

	/**
	 * Apply pending schema changes.
	 *
	 * This method is also called during normal plugin loading so future upgrades
	 * do not depend exclusively on the activation hook.
	 *
	 * @param bool $force Re-run the idempotent schema definition.
	 * @return bool
	 */
	public static function maybe_upgrade( $force = false ) {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '0' );

		if ( ! class_exists( 'FixReport_Migrator' ) ) {
			self::record_failure( 'migration_component_missing' );
			return false;
		}

		// version_compare() gives arbitrary answers for malformed input; never branch on one.
		if ( ! FixReport_Migrator::valid_schema_version( $installed_version ) ) {
			self::record_failure( 'schema_version_invalid' );
			return false;
		}

		if ( version_compare( $installed_version, FIXREPORT_DB_VERSION, '>=' ) ) {
			if ( ! $force ) {
				if ( is_admin() ) {
					$repair = self::repair_missing_checklist_template( $installed_version );
					if ( is_wp_error( $repair ) ) {
						self::record_failure( $repair->get_error_code() );
						return false;
					}
				}
				return true;
			}
			return self::verify_installed_schema();
		}

		global $wpdb;
		$legacy_table = $wpdb->prefix . 'fixreport_logs';
		$before_lock  = null;

		if ( FixReport_Migrator::table_exists( $legacy_table ) ) {
			$before_lock = FixReport_Migrator::capture_legacy_evidence( $legacy_table );
			if ( is_wp_error( $before_lock ) ) {
				self::record_failure( $before_lock->get_error_code() );
				return false;
			}
		}

		$lock = FixReport_Migrator::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			self::record_failure( $lock->get_error_code() );
			return false;
		}

		try {
			if ( null !== $before_lock ) {
				$locked_evidence = FixReport_Migrator::capture_legacy_evidence( $legacy_table );
				if ( is_wp_error( $locked_evidence ) || ! FixReport_Migrator::evidence_matches( $before_lock, $locked_evidence ) ) {
					self::record_failure( 'legacy_changed_before_migration' );
					return false;
				}
			}

			$record = FixReport_Migrator::read_migration_record();
			if ( is_wp_error( $record ) ) {
				self::record_failure( $record->get_error_code() );
				return false;
			}
			$gate = FixReport_Migrator::migration_gate( $installed_version, $record['exists'] );
			if ( is_wp_error( $gate ) ) {
				self::record_failure( $gate->get_error_code() );
				return false;
			}

			// After the first migration, migrated Activities are editable. Verify
			// only stable provenance, read-only, before any schema or option write,
			// and keep the original record.
			if ( 'verify_completed' === $gate ) {
				if ( null === $before_lock ) {
					self::record_failure( 'legacy_table_missing' );
					return false;
				}
				$verified = FixReport_Migrator::verify_completed_migration( $record['value'], $before_lock );
				if ( is_wp_error( $verified ) ) {
					self::record_failure( $verified->get_error_code() );
					return false;
				}
			}

			self::install_schema();

			$schema_check = FixReport_Migrator::verify_domain_schema();
			if ( is_wp_error( $schema_check ) ) {
				self::record_failure( $schema_check->get_error_code() );
				return false;
			}
			if ( ! self::initialize_checklist_options() ) {
				self::record_failure( 'checklist_options_invalid' );
				return false;
			}

			if ( 'first_time' === $gate ) {
				if ( null === $before_lock ) {
					$before_lock = FixReport_Migrator::capture_legacy_evidence( $legacy_table );
					if ( is_wp_error( $before_lock ) ) {
						self::record_failure( $before_lock->get_error_code() );
						return false;
					}
				}

				$migration = FixReport_Migrator::migrate_legacy_logs( $before_lock );
				if ( is_wp_error( $migration ) ) {
					self::record_failure( $migration->get_error_code() );
					return false;
				}
			} else {
				// dbDelta also declares the legacy table; prove it left the evidence intact.
				$after_schema = FixReport_Migrator::capture_legacy_evidence( $legacy_table );
				if ( is_wp_error( $after_schema ) || ! FixReport_Migrator::evidence_matches( $before_lock, $after_schema ) ) {
					self::record_failure( 'legacy_changed_during_migration' );
					return false;
				}
			}

			update_option( self::SCHEMA_VERSION_OPTION, FIXREPORT_DB_VERSION, false );
			delete_option( self::SCHEMA_FAILURE_OPTION );

			return true;
		} catch ( Throwable $exception ) {
			self::record_failure( 'unexpected_upgrade_failure' );
			return false;
		} finally {
			FixReport_Migrator::release_lock( $lock );
		}
	}

	/**
	 * Re-verify an already migrated installation without comparing editable
	 * Activity fields to their immutable legacy source values.
	 *
	 * Phase 2B makes migrated activities operationally editable. Activation must
	 * continue to verify the domain schema and original legacy evidence, while
	 * legacy_fingerprint remains evidence of the source row at migration time.
	 *
	 * @return bool
	 */
	private static function verify_installed_schema() {
		if ( ! class_exists( 'FixReport_Migrator' ) ) {
			self::record_failure( 'migration_component_missing' );
			return false;
		}

		$lock = FixReport_Migrator::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			self::record_failure( $lock->get_error_code() );
			return false;
		}

		try {
			global $wpdb;
			$metadata = get_option( FixReport_Migrator::MIGRATION_OPTION, array() );
			$evidence = FixReport_Migrator::capture_legacy_evidence( $wpdb->prefix . 'fixreport_logs' );
			if ( is_wp_error( $evidence ) || ! self::matches_recorded_legacy_evidence( $metadata, $evidence ) ) {
				self::record_failure( 'legacy_evidence_changed' );
				return false;
			}

			self::install_schema();
			$schema_check = FixReport_Migrator::verify_domain_schema();
			if ( is_wp_error( $schema_check ) ) {
				self::record_failure( $schema_check->get_error_code() );
				return false;
			}
			if ( ! self::initialize_checklist_options() ) {
				self::record_failure( 'checklist_options_invalid' );
				return false;
			}

			delete_option( self::SCHEMA_FAILURE_OPTION );
			return true;
		} catch ( Throwable $exception ) {
			self::record_failure( 'unexpected_upgrade_failure' );
			return false;
		} finally {
			FixReport_Migrator::release_lock( $lock );
		}
	}

	/**
	 * Compare current source evidence to the immutable Phase 2A record.
	 *
	 * @param array $metadata Stored migration verification metadata.
	 * @param array $evidence Current legacy evidence.
	 * @return bool
	 */
	private static function matches_recorded_legacy_evidence( $metadata, $evidence ) {
		$mapping = array(
			'source_count'                    => 'count',
			'source_checksum'                 => 'checksum',
			'source_dataset_fingerprint'      => 'dataset_fingerprint',
			'source_full_dataset_fingerprint' => 'full_dataset_fingerprint',
			'source_schema_signature'         => 'schema_signature',
		);
		if ( ! is_array( $metadata ) || 'verified' !== ( $metadata['state'] ?? '' ) ) {
			return false;
		}
		foreach ( $mapping as $stored => $current ) {
			if ( ! array_key_exists( $stored, $metadata ) || ! array_key_exists( $current, $evidence ) || (string) $metadata[ $stored ] !== (string) $evidence[ $current ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Seed the Checklist Template on an already-current installation only when its
	 * option row is absent, and only after the completed migration state verifies.
	 *
	 * An existing row (empty, valid, or malformed) costs one indexed read and is left
	 * as it is; no verification runs and nothing is written. Touches no schema,
	 * migration metadata, categories, archive, events, or Activities.
	 *
	 * @param string                           $installed_version Installed schema version.
	 * @param FixReport_Checklist_Template|null $template          Isolated tests only.
	 * @param callable|null                    $verify_state      Isolated tests only; returns true or WP_Error.
	 * @return true|WP_Error
	 */
	private static function repair_missing_checklist_template( $installed_version, $template = null, $verify_state = null ) {
		if ( FIXREPORT_DB_VERSION !== $installed_version ) {
			return true;
		}
		require_once __DIR__ . '/class-fixreport-checklist-template.php';
		$template = $template ?: new FixReport_Checklist_Template();

		global $wpdb;
		$wpdb->last_error = '';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $template->option_name() ) );
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'checklist_template_unreadable' );
		}
		if ( null !== $exists ) {
			return true;
		}

		try {
			$verified = $verify_state ? call_user_func( $verify_state, $installed_version ) : self::verify_current_migration_state( $installed_version );
		} catch ( Throwable $exception ) {
			$verified = new WP_Error( 'unexpected_upgrade_failure' );
		}
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( true !== $verified ) {
			return new WP_Error( 'unexpected_upgrade_failure' );
		}
		if ( ! $template->initialize() ) {
			return new WP_Error( 'checklist_template_init_failed' );
		}
		return true;
	}

	/**
	 * Read-only verification of the completed migration state under the upgrade lock.
	 * Uses the same record validation and provenance checks as the upgrade path and
	 * never regenerates the record or reruns legacy migration.
	 *
	 * @param string $installed_version Installed schema version.
	 * @return true|WP_Error
	 */
	private static function verify_current_migration_state( $installed_version ) {
		if ( ! class_exists( 'FixReport_Migrator' ) ) {
			return new WP_Error( 'migration_component_missing' );
		}
		$lock = FixReport_Migrator::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			global $wpdb;
			$evidence = FixReport_Migrator::capture_legacy_evidence( $wpdb->prefix . 'fixreport_logs' );
			if ( is_wp_error( $evidence ) ) {
				return $evidence;
			}
			$record = FixReport_Migrator::read_migration_record();
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$gate = FixReport_Migrator::migration_gate( $installed_version, $record['exists'] );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			if ( 'verify_completed' !== $gate ) {
				return new WP_Error( 'legacy_migration_record_conflict' );
			}
			return FixReport_Migrator::verify_completed_migration( $record['value'], $evidence );
		} finally {
			FixReport_Migrator::release_lock( $lock );
		}
	}

	/**
	 * Create missing, non-autoloaded checklist options; never alter existing ones.
	 *
	 * The template is seeded only when its option row is absent (atomic add_option),
	 * so existing definitions, including an intentionally empty list, are kept.
	 * Instances may be passed only by isolated tests using disposable option names.
	 */
	private static function initialize_checklist_options( $template = null, $categories = null ) {
		require_once __DIR__ . '/class-fixreport-checklist-categories.php';
		require_once __DIR__ . '/class-fixreport-checklist-template.php';
		if ( ! FixReport_Checklist_Template::transactional_options() ) { return false; }
		$categories = $categories ?: new FixReport_Checklist_Categories();
		$template   = $template ?: new FixReport_Checklist_Template();
		return $template->initialize() && $categories->initialize() && $template->initialize_archive();
	}

	/**
	 * Create the legacy table and the Phase 2 domain tables.
	 *
	 * The legacy table definition remains present solely for migration and legacy
	 * evidence compatibility. Keep every dbDelta declaration on its own line and
	 * never place SQL comments inside these CREATE TABLE strings.
	 */
	private static function install_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::get_schema_sql( $charset_collate );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Return dbDelta-compatible table definitions.
	 *
	 * @param string $charset_collate WordPress charset/collation clause.
	 * @return array
	 */
	private static function get_schema_sql( $charset_collate ) {
		global $wpdb;

		$logs              = $wpdb->prefix . 'fixreport_logs';
		$activities        = $wpdb->prefix . 'fixreport_activities';
		$snapshots         = $wpdb->prefix . 'fixreport_snapshots';
		$snapshot_items    = $wpdb->prefix . 'fixreport_snapshot_items';
		$reports           = $wpdb->prefix . 'fixreport_reports';
		$report_activities = $wpdb->prefix . 'fixreport_report_activities';
		$report_checklist  = $wpdb->prefix . 'fixreport_report_checklist';
		$checklist_events = $wpdb->prefix . 'fixreport_checklist_events';
		$report_logos      = $wpdb->prefix . 'fixreport_report_logo_assets';

		return array(
			"CREATE TABLE {$logs} (
				log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(255) NOT NULL DEFAULT '',
				description TEXT NOT NULL,
				type VARCHAR(50) NOT NULL DEFAULT '',
				priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
				status VARCHAR(20) NOT NULL DEFAULT 'Resolved',
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (log_id),
				KEY type (type),
				KEY priority (priority),
				KEY status (status),
				KEY user_id (user_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$activities} (
				activity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				source VARCHAR(16) NOT NULL,
				event_type VARCHAR(64) NOT NULL,
				category VARCHAR(32) NULL DEFAULT NULL,
				title VARCHAR(255) NOT NULL DEFAULT '',
				description LONGTEXT NOT NULL,
				internal_note LONGTEXT NULL DEFAULT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'completed',
				priority VARCHAR(16) NOT NULL DEFAULT 'medium',
				client_visible TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				object_type VARCHAR(32) NULL DEFAULT NULL,
				object_key VARCHAR(191) NULL DEFAULT NULL,
				object_key_hash CHAR(64) NULL DEFAULT NULL,
				object_name VARCHAR(255) NULL DEFAULT NULL,
				previous_version VARCHAR(64) NULL DEFAULT NULL,
				new_version VARCHAR(64) NULL DEFAULT NULL,
				metadata LONGTEXT NULL DEFAULT NULL,
				performed_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				activity_date DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				deleted_at DATETIME NULL DEFAULT NULL,
				deleted_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				legacy_source VARCHAR(32) NULL DEFAULT NULL,
				legacy_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				legacy_fingerprint CHAR(64) NULL DEFAULT NULL,
				PRIMARY KEY  (activity_id),
				UNIQUE KEY legacy_origin (legacy_source, legacy_id),
				KEY active_timeline (deleted_at, activity_date, activity_id),
				KEY source_timeline (source, activity_date, activity_id),
				KEY category_timeline (category, activity_date, activity_id),
				KEY event_timeline (event_type, activity_date, activity_id),
				KEY status_timeline (status, activity_date, activity_id),
				KEY report_candidates (client_visible, activity_date, activity_id),
				KEY object_history (object_type, object_key_hash, activity_date, activity_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$snapshots} (
				snapshot_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				snapshot_type VARCHAR(32) NOT NULL,
				generation_status VARCHAR(16) NOT NULL DEFAULT 'building',
				label VARCHAR(255) NULL DEFAULT NULL,
				site_name VARCHAR(255) NOT NULL DEFAULT '',
				site_url TEXT NOT NULL,
				home_url TEXT NOT NULL,
				site_language VARCHAR(32) NULL DEFAULT NULL,
				timezone VARCHAR(64) NULL DEFAULT NULL,
				is_multisite TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				wordpress_version VARCHAR(64) NULL DEFAULT NULL,
				core_update_available TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				available_wordpress_version VARCHAR(64) NULL DEFAULT NULL,
				php_version VARCHAR(64) NULL DEFAULT NULL,
				database_engine VARCHAR(32) NULL DEFAULT NULL,
				database_version VARCHAR(64) NULL DEFAULT NULL,
				web_server VARCHAR(255) NULL DEFAULT NULL,
				is_https TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				wp_memory_limit_bytes BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				php_memory_limit_bytes BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				wp_debug TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				wp_debug_log TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				wp_debug_display TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				plugin_count INT(10) UNSIGNED NULL DEFAULT NULL,
				active_plugin_count INT(10) UNSIGNED NULL DEFAULT NULL,
				inactive_plugin_count INT(10) UNSIGNED NULL DEFAULT NULL,
				plugin_update_count INT(10) UNSIGNED NULL DEFAULT NULL,
				theme_count INT(10) UNSIGNED NULL DEFAULT NULL,
				theme_update_count INT(10) UNSIGNED NULL DEFAULT NULL,
				post_count BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				page_count BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				comment_count BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				user_count BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				site_health_critical_count INT(10) UNSIGNED NULL DEFAULT NULL,
				site_health_recommended_count INT(10) UNSIGNED NULL DEFAULT NULL,
				pending_update_count INT(10) UNSIGNED NULL DEFAULT NULL,
				summary_metadata LONGTEXT NULL DEFAULT NULL,
				failure_code VARCHAR(64) NULL DEFAULT NULL,
				created_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				started_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				completed_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (snapshot_id),
				KEY status_created (generation_status, created_at, snapshot_id),
				KEY type_status_date (snapshot_type, generation_status, completed_at, snapshot_id),
				KEY completed_date (completed_at, snapshot_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$snapshot_items} (
				snapshot_item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				snapshot_id BIGINT(20) UNSIGNED NOT NULL,
				item_type VARCHAR(32) NOT NULL,
				item_key VARCHAR(255) NOT NULL,
				item_key_hash CHAR(64) NOT NULL,
				display_name VARCHAR(255) NOT NULL DEFAULT '',
				installed_version VARCHAR(64) NULL DEFAULT NULL,
				available_version VARCHAR(64) NULL DEFAULT NULL,
				is_active TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				update_available TINYINT(1) UNSIGNED NULL DEFAULT NULL,
				parent_key VARCHAR(255) NULL DEFAULT NULL,
				severity VARCHAR(24) NULL DEFAULT NULL,
				item_payload LONGTEXT NULL DEFAULT NULL,
				display_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (snapshot_item_id),
				UNIQUE KEY snapshot_item (snapshot_id, item_type, item_key_hash),
				KEY snapshot_type_order (snapshot_id, item_type, display_order, snapshot_item_id),
				KEY item_history (item_type, item_key_hash, snapshot_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$reports} (
				report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				report_type VARCHAR(32) NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'draft',
				title VARCHAR(255) NOT NULL DEFAULT '',
				report_locale VARCHAR(20) NOT NULL,
				period_timezone VARCHAR(64) NULL DEFAULT NULL,
				period_start DATETIME NULL DEFAULT NULL,
				period_end DATETIME NULL DEFAULT NULL,
				start_snapshot_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				end_snapshot_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				assessment_summary LONGTEXT NULL DEFAULT NULL,
				findings LONGTEXT NULL DEFAULT NULL,
				recommendations LONGTEXT NULL DEFAULT NULL,
				final_notes LONGTEXT NULL DEFAULT NULL,
				include_comparison TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				draft_revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				initial_assessment_key VARCHAR(32) NULL DEFAULT NULL,
				branding_overrides LONGTEXT NULL DEFAULT NULL,
				frozen_payload LONGTEXT NULL DEFAULT NULL,
				frozen_payload_sha256 CHAR(64) NULL DEFAULT NULL,
				payload_schema_version VARCHAR(16) NULL DEFAULT NULL,
				created_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				updated_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				finalized_by BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				finalized_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (report_id),
				KEY status_updated (status, updated_at, report_id),
				KEY type_created (report_type, created_at, report_id),
				KEY start_snapshot (start_snapshot_id),
				KEY end_snapshot (end_snapshot_id),
				KEY finalized_date (finalized_at, report_id),
				UNIQUE KEY initial_assessment_once (initial_assessment_key)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$report_activities} (
				report_activity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				report_id BIGINT(20) UNSIGNED NOT NULL,
				activity_id BIGINT(20) UNSIGNED NOT NULL,
				display_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (report_activity_id),
				UNIQUE KEY report_activity (report_id, activity_id),
				KEY report_order (report_id, display_order, report_activity_id),
				KEY activity_reports (activity_id, report_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$report_checklist} (
				checklist_item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				report_id BIGINT(20) UNSIGNED NOT NULL,
				check_key VARCHAR(64) NOT NULL,
				displayed_label VARCHAR(255) NOT NULL DEFAULT '',
				displayed_description TEXT NULL DEFAULT NULL,
				included TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				status VARCHAR(24) NOT NULL DEFAULT 'pending',
				note TEXT NULL DEFAULT NULL,
				display_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (checklist_item_id),
				UNIQUE KEY report_check (report_id, check_key),
				KEY report_check_order (report_id, display_order, checklist_item_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$checklist_events} (
				event_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				task_key VARCHAR(64) NOT NULL,
				event_type VARCHAR(16) NOT NULL,
				actor_user_id BIGINT(20) UNSIGNED NOT NULL,
				occurred_at_utc DATETIME NOT NULL,
				note TEXT NULL DEFAULT NULL,
				title_snapshot VARCHAR(255) NOT NULL,
				description_snapshot TEXT NULL DEFAULT NULL,
				category_key_snapshot VARCHAR(64) NOT NULL,
				expected_event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				request_token CHAR(36) NOT NULL,
				PRIMARY KEY  (event_id),
				UNIQUE KEY request_token (request_token),
				KEY task_history (task_key, event_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$report_logos} (
				asset_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				report_id BIGINT(20) UNSIGNED NOT NULL,
				mime_type VARCHAR(32) NOT NULL,
				width INT(10) UNSIGNED NOT NULL,
				height INT(10) UNSIGNED NOT NULL,
				byte_length INT(10) UNSIGNED NOT NULL,
				sha256 CHAR(64) NOT NULL,
				image_bytes MEDIUMBLOB NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (asset_id),
				UNIQUE KEY report_logo_once (report_id)
			) ENGINE=InnoDB {$charset_collate};",
		);
	}

	/**
	 * Persist only a safe failure code for administrator-facing diagnostics.
	 *
	 * @param string $code Failure code.
	 */
	private static function record_failure( $code ) {
		update_option(
			self::SCHEMA_FAILURE_OPTION,
			array(
				'code'       => sanitize_key( $code ),
				'failed_at'  => time(),
				'target_db'  => FIXREPORT_DB_VERSION,
			),
			false
		);
	}
}
