<?php
/**
 * Phase 2 schema verification and legacy-log migration.
 */
class FixReport_Migrator {

	const LOCK_OPTION      = 'fixreport_db_upgrade_lock';
	const MIGRATION_OPTION = 'fixreport_legacy_logs_migration';
	const LEGACY_SOURCE    = 'fixreport_logs';
	const LOCK_TTL         = 300;
	const BATCH_SIZE       = 50;

	/**
	 * Mapped Activity fields that no edit or soft-delete path writes.
	 *
	 * Editable fields (title, description, category, status, priority,
	 * activity_date, performed_by, client_visible, internal_note, updated_at,
	 * deleted_at, deleted_by) are deliberately excluded. The surrogate
	 * activity_id is not covered by the recorded evidence and is not checked.
	 */
	const STABLE_PROVENANCE_FIELDS = array(
		'source', 'event_type', 'metadata', 'object_type', 'object_key', 'object_key_hash', 'object_name',
		'previous_version', 'new_version', 'created_at', 'legacy_source', 'legacy_id', 'legacy_fingerprint',
	);

	/**
	 * Acquire an atomic, expiring option-backed migration lock.
	 *
	 * The option name is unique. An interrupted owner can be replaced after five
	 * minutes, but only through a compare-and-delete of the exact stored payload.
	 *
	 * @return array|WP_Error Lock handle or failure.
	 */
	public static function acquire_lock() {
		global $wpdb;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$payload = wp_json_encode(
				array(
					'token'      => wp_generate_uuid4(),
					'expires_at' => time() + self::LOCK_TTL,
				),
				JSON_UNESCAPED_SLASHES
			);

			if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
				return array( 'payload' => $payload );
			}

			$existing = get_option( self::LOCK_OPTION, '' );
			$decoded  = is_string( $existing ) ? json_decode( $existing, true ) : null;
			if ( ! is_array( $decoded ) || empty( $decoded['expires_at'] ) || (int) $decoded['expires_at'] >= time() ) {
				return new WP_Error( 'migration_locked' );
			}

			$deleted = $wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => self::LOCK_OPTION,
					'option_value' => $existing,
				),
				array( '%s', '%s' )
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			if ( 1 !== $deleted ) {
				return new WP_Error( 'migration_locked' );
			}
		}

		return new WP_Error( 'migration_locked' );
	}

	/**
	 * Release the lock only when this process still owns the exact payload.
	 *
	 * @param array $lock Lock handle.
	 */
	public static function release_lock( $lock ) {
		if ( empty( $lock['payload'] ) ) {
			return;
		}

		global $wpdb;
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => $lock['payload'],
			),
			array( '%s', '%s' )
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Determine whether a table exists.
	 *
	 * @param string $table_name Full prefixed table name.
	 * @return bool
	 */
	public static function table_exists( $table_name ) {
		global $wpdb;
		return $table_name === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);
	}

	/**
	 * Capture immutable evidence for the legacy source table.
	 *
	 * @param string $table_name Full prefixed legacy table name.
	 * @return array|WP_Error
	 */
	public static function capture_legacy_evidence( $table_name ) {
		global $wpdb;

		if ( ! self::table_exists( $table_name ) ) {
			return new WP_Error( 'legacy_table_missing' );
		}

		$columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . esc_sql( $table_name ) . '`', ARRAY_A );
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table_name ) . '`', ARRAY_A );
		if ( ! is_array( $columns ) || ! is_array( $indexes ) ) {
			return new WP_Error( 'legacy_schema_unreadable' );
		}

		$required_columns = array( 'log_id', 'title', 'description', 'type', 'priority', 'status', 'user_id', 'created_at', 'updated_at' );
		$column_names     = wp_list_pluck( $columns, 'Field' );
		if ( array_diff( $required_columns, $column_names ) ) {
			return new WP_Error( 'legacy_schema_invalid' );
		}

		$index_names = array_unique( wp_list_pluck( $indexes, 'Key_name' ) );
		if ( array_diff( array( 'PRIMARY', 'type', 'priority', 'status', 'user_id' ), $index_names ) ) {
			return new WP_Error( 'legacy_indexes_invalid' );
		}

		$count       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table_name ) . '`' );
		$checksum_row = $wpdb->get_row( 'CHECKSUM TABLE `' . esc_sql( $table_name ) . '`', ARRAY_N );
		$checksum     = isset( $checksum_row[1] ) ? (string) $checksum_row[1] : null;
		$fingerprints = self::legacy_dataset_fingerprints( $table_name );
		if ( is_wp_error( $fingerprints ) ) {
			return $fingerprints;
		}

		return array(
			'count'               => $count,
			'checksum'            => $checksum,
			'dataset_fingerprint' => $fingerprints['accepted'],
			'full_dataset_fingerprint' => $fingerprints['full'],
			'schema_signature'    => self::schema_signature( $columns, $indexes ),
			'types'               => self::value_inventory( $table_name, 'type' ),
			'priorities'          => self::value_inventory( $table_name, 'priority' ),
			'statuses'            => self::value_inventory( $table_name, 'status' ),
		);
	}

	/**
	 * Compare two legacy-evidence captures.
	 *
	 * @param array|WP_Error $expected Earlier evidence.
	 * @param array|WP_Error $actual   Later evidence.
	 * @return bool
	 */
	public static function evidence_matches( $expected, $actual ) {
		return ! is_wp_error( $expected ) && ! is_wp_error( $actual ) && $expected === $actual;
	}

	/**
	 * Verify all six domain tables, their columns, engines, collations and indexes.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_domain_schema() {
		global $wpdb;

		foreach ( self::expected_domain_schema() as $suffix => $expected ) {
			$table_name = $wpdb->prefix . $suffix;
			if ( ! self::table_exists( $table_name ) ) {
				return new WP_Error( 'domain_table_missing' );
			}

			$status = $wpdb->get_row(
				$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table_name ) ),
				ARRAY_A
			);
			if ( ! $status || 'InnoDB' !== $status['Engine'] ) {
				return new WP_Error( 'domain_table_engine_invalid' );
			}
			if ( ! empty( $wpdb->collate ) && strtolower( $wpdb->collate ) !== strtolower( $status['Collation'] ) ) {
				return new WP_Error( 'domain_table_collation_invalid' );
			}

			$actual_columns = array();
			foreach ( $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table_name ) . '`', ARRAY_A ) as $column ) {
				$actual_columns[ $column['Field'] ] = $column;
			}
			foreach ( $expected['columns'] as $name => $definition ) {
				if ( ! isset( $actual_columns[ $name ] ) ) {
					return new WP_Error( 'domain_column_missing' );
				}
				$actual = $actual_columns[ $name ];
				if (
					self::normalize_column_type( $definition['type'] ) !== self::normalize_column_type( $actual['Type'] ) ||
					$definition['null'] !== $actual['Null'] ||
					$definition['default'] !== $actual['Default'] ||
					$definition['extra'] !== $actual['Extra']
				) {
					return new WP_Error( 'domain_column_invalid' );
				}
			}

			$actual_indexes = self::read_indexes( $table_name );
			foreach ( $expected['indexes'] as $name => $definition ) {
				if ( ! isset( $actual_indexes[ $name ] ) || $definition !== $actual_indexes[ $name ] ) {
					return new WP_Error( 'domain_index_invalid' );
				}
			}
		}

		return true;
	}

	/**
	 * Normalize integer display widths removed by modern MySQL releases.
	 *
	 * TINYINT(1), BIGINT(20), and similar widths are not storage constraints.
	 * MySQL 8.0.19+ may omit them from SHOW COLUMNS even when dbDelta SQL
	 * includes the traditional WordPress-compatible declarations.
	 *
	 * @param string $type Column type.
	 * @return string
	 */
	private static function normalize_column_type( $type ) {
		$type = strtolower( trim( $type ) );
		return preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type );
	}

	/**
	 * Migrate or verify every legacy row and advance no state on failure.
	 *
	 * @param array $before_evidence Captured source evidence.
	 * @return array|WP_Error Verification metadata or failure.
	 */
	public static function migrate_legacy_logs( $before_evidence ) {
		global $wpdb;

		$legacy_table   = $wpdb->prefix . 'fixreport_logs';
		$activity_table = $wpdb->prefix . 'fixreport_activities';
		$last_id        = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $legacy_table ) . '` WHERE log_id > %d ORDER BY log_id ASC LIMIT %d',
					$last_id,
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $rows ) ) {
				return new WP_Error( 'legacy_rows_unreadable' );
			}
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$expected = self::map_legacy_row( $row );
				if ( is_wp_error( $expected ) ) {
					return $expected;
				}

				$existing = self::find_migrated_activity( $activity_table, (int) $row->log_id );
				if ( ! $existing ) {
					$inserted = $wpdb->insert( $activity_table, $expected, self::activity_formats() );
					if ( false === $inserted ) {
						return new WP_Error( 'activity_insert_failed' );
					}
					$existing = self::find_migrated_activity( $activity_table, (int) $row->log_id );
				}

				if ( ! $existing || ! self::activity_matches( $existing, $expected ) ) {
					return new WP_Error( 'legacy_migration_conflict' );
				}
				$last_id = (int) $row->log_id;
			}
		}

		$verification = self::verify_migrated_rows( $legacy_table, $activity_table, $before_evidence );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		update_option( self::MIGRATION_OPTION, $verification, false );
		return $verification;
	}

	/**
	 * Choose how an upgrade treats legacy migration state.
	 *
	 * Below 2.0.0 the strict first-time migration runs, and only when no
	 * migration record exists yet. From 2.0.0 a completed record is required and
	 * is verified read-only; it is never regenerated.
	 *
	 * @param string $installed_version Installed schema version.
	 * @param bool   $record_exists     Whether a migration record row exists.
	 * @return string|WP_Error 'first_time', 'verify_completed', or failure.
	 */
	public static function migration_gate( $installed_version, $record_exists ) {
		if ( ! self::valid_schema_version( $installed_version ) || ! is_bool( $record_exists ) ) {
			return new WP_Error( 'schema_version_invalid' );
		}
		if ( '0' === $installed_version || version_compare( $installed_version, '2.0.0', '<' ) ) {
			return $record_exists ? new WP_Error( 'legacy_migration_record_conflict' ) : 'first_time';
		}
		return $record_exists ? 'verify_completed' : new WP_Error( 'legacy_migration_record_missing' );
	}

	/**
	 * Accept only the historical schema-version formats.
	 *
	 * '0' is the fresh-install default. Every stored version (1.0.0, 2.0.0 to
	 * 2.3.0) is MAJOR.MINOR.PATCH without leading zeros. Anything else, including
	 * non-strings, is ambiguous and must not reach version_compare().
	 *
	 * @param mixed $version Installed schema version.
	 * @return bool
	 */
	public static function valid_schema_version( $version ) {
		return is_string( $version ) && (bool) preg_match( '/^(?:0|(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))$/', $version );
	}

	/**
	 * Read the stored migration record from the database, bypassing the option cache.
	 *
	 * @return array|WP_Error Array with 'exists' (bool) and 'value' (mixed).
	 */
	public static function read_migration_record() {
		global $wpdb;
		$wpdb->last_error = '';
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::MIGRATION_OPTION )
		);
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'legacy_migration_record_unreadable' );
		}
		return array(
			'exists' => null !== $raw,
			'value'  => null === $raw ? null : maybe_unserialize( $raw ),
		);
	}

	/**
	 * Validate the exact shape of a record written by verify_migrated_rows().
	 *
	 * @param mixed $record Stored migration record.
	 * @return bool
	 */
	public static function valid_migration_record( $record ) {
		$keys = array(
			'version', 'state', 'legacy_source', 'source_count', 'migrated_count', 'distinct_legacy_ids',
			'source_checksum', 'source_dataset_fingerprint', 'source_full_dataset_fingerprint',
			'source_schema_signature', 'target_dataset_fingerprint', 'verified_at_utc',
		);
		if ( ! is_array( $record ) || count( $record ) !== count( $keys ) || array_diff( $keys, array_keys( $record ) ) ) {
			return false;
		}
		if ( 'verified' !== $record['state'] || self::LEGACY_SOURCE !== $record['legacy_source'] ) {
			return false;
		}
		if (
			! self::valid_schema_version( $record['version'] ) || '0' === $record['version'] ||
			version_compare( $record['version'], '2.0.0', '<' ) || version_compare( $record['version'], FIXREPORT_DB_VERSION, '>' )
		) {
			return false;
		}
		foreach ( array( 'source_count', 'migrated_count', 'distinct_legacy_ids' ) as $count ) {
			if ( ! is_int( $record[ $count ] ) || $record[ $count ] < 0 ) {
				return false;
			}
		}
		if ( $record['source_count'] !== $record['migrated_count'] || $record['source_count'] !== $record['distinct_legacy_ids'] ) {
			return false;
		}
		if ( null !== $record['source_checksum'] && ( ! is_string( $record['source_checksum'] ) || ! preg_match( '/^\d+$/', $record['source_checksum'] ) ) ) {
			return false;
		}
		foreach ( array( 'source_dataset_fingerprint', 'source_full_dataset_fingerprint', 'source_schema_signature', 'target_dataset_fingerprint' ) as $hash ) {
			if ( ! is_string( $record[ $hash ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $record[ $hash ] ) ) {
				return false;
			}
		}
		return is_string( $record['verified_at_utc'] ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['verified_at_utc'] );
	}

	/**
	 * Read-only verification of a previously completed legacy migration.
	 *
	 * Every legacy row must map to exactly one Activity (soft-deleted included)
	 * whose stable provenance still matches. Editable fields are not compared,
	 * nothing is written, and the stored record is left unchanged.
	 *
	 * @param mixed      $record         Stored migration record.
	 * @param array      $evidence       Legacy evidence captured under the upgrade lock.
	 * @param string|null $legacy_table   Legacy table; defaults to the installed table.
	 * @param string|null $activity_table Activity table; defaults to the installed table.
	 * @return true|WP_Error
	 */
	public static function verify_completed_migration( $record, $evidence, $legacy_table = null, $activity_table = null ) {
		global $wpdb;

		$legacy_table   = null === $legacy_table ? $wpdb->prefix . 'fixreport_logs' : $legacy_table;
		$activity_table = null === $activity_table ? $wpdb->prefix . 'fixreport_activities' : $activity_table;
		if ( ! is_string( $legacy_table ) || ! is_string( $activity_table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $legacy_table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $activity_table ) ) {
			return new WP_Error( 'legacy_verification_target_invalid' );
		}
		if ( ! self::valid_migration_record( $record ) ) {
			return new WP_Error( 'legacy_migration_record_invalid' );
		}
		if ( ! self::record_matches_evidence( $record, $evidence ) ) {
			return new WP_Error( 'legacy_evidence_changed' );
		}

		// Provenance-bearing rows outside the recorded dataset are never accepted.
		$wpdb->last_error = '';
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM( legacy_source = %s ) AS linked, COUNT( DISTINCT CASE WHEN legacy_source = %s THEN legacy_id END ) AS distinct_ids, ' .
				'SUM( legacy_source = %s AND legacy_id IS NULL ) AS null_ids, SUM( legacy_source IS NOT NULL AND legacy_source <> %s ) AS foreign_sources, ' .
				'SUM( legacy_source IS NULL AND ( legacy_id IS NOT NULL OR legacy_fingerprint IS NOT NULL ) ) AS stray FROM `' . esc_sql( $activity_table ) . '`',
				self::LEGACY_SOURCE,
				self::LEGACY_SOURCE,
				self::LEGACY_SOURCE,
				self::LEGACY_SOURCE
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error || ! is_array( $counts ) ) {
			return new WP_Error( 'legacy_activities_unreadable' );
		}
		if (
			$record['source_count'] !== (int) $counts['linked'] || $record['source_count'] !== (int) $counts['distinct_ids'] ||
			0 !== (int) $counts['null_ids'] || 0 !== (int) $counts['foreign_sources'] || 0 !== (int) $counts['stray']
		) {
			return new WP_Error( 'legacy_provenance_count_mismatch' );
		}

		$target_context = hash_init( 'sha256' );
		$seen           = 0;
		$last_id        = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $legacy_table ) . '` WHERE log_id > %d ORDER BY log_id ASC LIMIT %d',
					$last_id,
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $rows ) ) {
				return new WP_Error( 'legacy_rows_unreadable' );
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$expected = self::map_legacy_row( $row );
				if ( is_wp_error( $expected ) ) {
					return $expected;
				}
				$matches = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM `' . esc_sql( $activity_table ) . '` WHERE legacy_source = %s AND legacy_id = %d',
						self::LEGACY_SOURCE,
						(int) $row->log_id
					)
				);
				if ( ! is_array( $matches ) || 1 !== count( $matches ) || ! self::provenance_matches( $matches[0], $expected ) ) {
					return new WP_Error( 'legacy_provenance_mismatch' );
				}
				hash_update( $target_context, self::mapped_activity_fingerprint( $expected ) );
				$seen++;
				$last_id = (int) $row->log_id;
			}
		}

		if ( $record['source_count'] !== $seen ) {
			return new WP_Error( 'legacy_evidence_changed' );
		}
		if ( ! hash_equals( $record['target_dataset_fingerprint'], hash_final( $target_context ) ) ) {
			return new WP_Error( 'legacy_mapping_changed' );
		}

		$after = self::capture_legacy_evidence( $legacy_table );
		if ( ! self::evidence_matches( $evidence, $after ) ) {
			return new WP_Error( 'legacy_changed_during_verification' );
		}
		return true;
	}

	/**
	 * Compare a validated record to current legacy evidence.
	 *
	 * @param array $record   Validated migration record.
	 * @param mixed $evidence Current legacy evidence.
	 * @return bool
	 */
	private static function record_matches_evidence( $record, $evidence ) {
		$mapping = array(
			'source_count'                    => 'count',
			'source_checksum'                 => 'checksum',
			'source_dataset_fingerprint'      => 'dataset_fingerprint',
			'source_full_dataset_fingerprint' => 'full_dataset_fingerprint',
			'source_schema_signature'         => 'schema_signature',
		);
		if ( ! is_array( $evidence ) ) {
			return false;
		}
		foreach ( $mapping as $stored => $current ) {
			if ( ! array_key_exists( $current, $evidence ) || (string) $record[ $stored ] !== (string) $evidence[ $current ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compare only the stable provenance fields of a migrated Activity.
	 *
	 * @param object $actual   Database row.
	 * @param array  $expected Expected mapped values.
	 * @return bool
	 */
	private static function provenance_matches( $actual, $expected ) {
		foreach ( self::STABLE_PROVENANCE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $expected ) || ! property_exists( $actual, $field ) ) {
				return false;
			}
			if ( null === $expected[ $field ] ) {
				if ( null !== $actual->{$field} ) {
					return false;
				}
			} elseif ( null === $actual->{$field} || (string) $expected[ $field ] !== (string) $actual->{$field} ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Create an unambiguous SHA-256 fingerprint for one original legacy row.
	 *
	 * Each UTF-8 byte string is serialized as byte-length, a colon, then the exact
	 * value. The ordered vectors are concatenated without an ambiguous delimiter.
	 *
	 * @param object $row Legacy database row.
	 * @return string
	 */
	public static function legacy_row_fingerprint( $row ) {
		$values = array(
			$row->log_id,
			$row->title,
			$row->description,
			$row->type,
			$row->priority,
			$row->status,
			$row->user_id,
			$row->created_at,
			$row->updated_at,
		);
		$canonical = '';
		foreach ( $values as $value ) {
			$value      = (string) $value;
			$canonical .= strlen( $value ) . ':' . $value;
		}
		return hash( 'sha256', $canonical );
	}

	/**
	 * Read deterministic fingerprints for the ordered legacy dataset.
	 *
	 * The accepted Phase 2 gate fingerprint was originally calculated through
	 * MySQL GROUP_CONCAT with its default 1,024-byte limit. Preserve that value
	 * for evidence continuity, and also calculate an untruncated streaming hash
	 * that covers every row fingerprint.
	 *
	 * @param string $table_name Full legacy table name.
	 * @return array|WP_Error
	 */
	private static function legacy_dataset_fingerprints( $table_name ) {
		global $wpdb;
		$full_context     = hash_init( 'sha256' );
		$accepted_context = hash_init( 'sha256' );
		$accepted_bytes   = 0;
		$last_id          = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $table_name ) . '` WHERE log_id > %d ORDER BY log_id ASC LIMIT %d',
					$last_id,
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $rows ) ) {
				return new WP_Error( 'legacy_rows_unreadable' );
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$row_fingerprint = self::legacy_row_fingerprint( $row );
				hash_update( $full_context, $row_fingerprint );
				if ( $accepted_bytes < 1024 ) {
					$chunk = substr( $row_fingerprint, 0, 1024 - $accepted_bytes );
					hash_update( $accepted_context, $chunk );
					$accepted_bytes += strlen( $chunk );
				}
				$last_id = (int) $row->log_id;
			}
		}

		return array(
			'accepted' => hash_final( $accepted_context ),
			'full'     => hash_final( $full_context ),
		);
	}

	/**
	 * Map a legacy log to its exact Phase 2 activity representation.
	 *
	 * @param object $row Legacy row.
	 * @return array|WP_Error
	 */
	private static function map_legacy_row( $row ) {
		$type_map = array(
			'Fix'         => array( 'legacy_fix', 'bug_fix' ),
			'Update'      => array( 'legacy_update', 'update' ),
			'Maintenance' => array( 'legacy_maintenance', 'other' ),
			'Security'    => array( 'legacy_security', 'security' ),
			'Error'       => array( 'legacy_error', 'other' ),
			'Other'       => array( 'legacy_other', 'other' ),
		);
		$priority_map = array(
			'Low'      => 'low',
			'Medium'   => 'medium',
			'High'     => 'high',
			'Critical' => 'critical',
		);
		$status_map = array(
			'Open'        => 'requires_attention',
			'In Progress' => 'pending',
			'Resolved'    => 'completed',
			'Closed'      => 'completed',
		);

		if ( ! isset( $type_map[ $row->type ] ) ) {
			return new WP_Error( 'unsupported_legacy_type' );
		}
		if ( ! isset( $priority_map[ $row->priority ] ) ) {
			return new WP_Error( 'unsupported_legacy_priority' );
		}
		if ( ! isset( $status_map[ $row->status ] ) ) {
			return new WP_Error( 'unsupported_legacy_status' );
		}

		$metadata = wp_json_encode(
			array(
				'migration' => array(
					'legacy_type'     => $row->type,
					'legacy_priority' => $row->priority,
					'legacy_status'   => $row->status,
				),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $metadata || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'migration_metadata_invalid' );
		}

		return array(
			'source'             => 'manual',
			'event_type'         => $type_map[ $row->type ][0],
			'category'           => $type_map[ $row->type ][1],
			'title'              => $row->title,
			'description'        => $row->description,
			'internal_note'      => null,
			'status'             => $status_map[ $row->status ],
			'priority'           => $priority_map[ $row->priority ],
			'client_visible'     => 1,
			'object_type'        => null,
			'object_key'         => null,
			'object_key_hash'    => null,
			'object_name'        => null,
			'previous_version'   => null,
			'new_version'        => null,
			'metadata'           => $metadata,
			'performed_by'       => 0 === (int) $row->user_id ? null : (int) $row->user_id,
			'activity_date'      => $row->created_at,
			'created_at'         => $row->created_at,
			'updated_at'         => $row->updated_at,
			'deleted_at'         => null,
			'deleted_by'         => null,
			'legacy_source'      => self::LEGACY_SOURCE,
			'legacy_id'          => (int) $row->log_id,
			'legacy_fingerprint' => self::legacy_row_fingerprint( $row ),
		);
	}

	/**
	 * Verify all mapped rows and source preservation.
	 *
	 * @param string $legacy_table   Legacy table.
	 * @param string $activity_table Activity table.
	 * @param array  $before         Original evidence.
	 * @return array|WP_Error
	 */
	private static function verify_migrated_rows( $legacy_table, $activity_table, $before ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . esc_sql( $activity_table ) . '` WHERE legacy_source = %s',
				self::LEGACY_SOURCE
			)
		);
		$distinct = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT legacy_id) FROM `' . esc_sql( $activity_table ) . '` WHERE legacy_source = %s',
				self::LEGACY_SOURCE
			)
		);
		if ( $before['count'] !== $count || $before['count'] !== $distinct ) {
			return new WP_Error( 'migration_count_mismatch' );
		}

		$target_context = hash_init( 'sha256' );
		$last_id        = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $legacy_table ) . '` WHERE log_id > %d ORDER BY log_id ASC LIMIT %d',
					$last_id,
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $rows ) ) {
				return new WP_Error( 'legacy_rows_unreadable' );
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$expected = self::map_legacy_row( $row );
				if ( is_wp_error( $expected ) ) {
					return $expected;
				}
				$activity = self::find_migrated_activity( $activity_table, (int) $row->log_id );
				if ( ! $activity || ! self::activity_matches( $activity, $expected ) ) {
					return new WP_Error( 'migration_field_mismatch' );
				}
				hash_update( $target_context, self::mapped_activity_fingerprint( $expected ) );
				$last_id = (int) $row->log_id;
			}
		}

		$after = self::capture_legacy_evidence( $legacy_table );
		if ( ! self::evidence_matches( $before, $after ) ) {
			return new WP_Error( 'legacy_changed_during_migration' );
		}

		return array(
			'version'                    => FIXREPORT_DB_VERSION,
			'state'                      => 'verified',
			'legacy_source'              => self::LEGACY_SOURCE,
			'source_count'               => $before['count'],
			'migrated_count'             => $count,
			'distinct_legacy_ids'         => $distinct,
			'source_checksum'            => $before['checksum'],
			'source_dataset_fingerprint' => $before['dataset_fingerprint'],
			'source_full_dataset_fingerprint' => $before['full_dataset_fingerprint'],
			'source_schema_signature'    => $before['schema_signature'],
			'target_dataset_fingerprint' => hash_final( $target_context ),
			'verified_at_utc'            => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Return a migrated activity by its authoritative legacy origin.
	 *
	 * @param string $table_name Activity table.
	 * @param int    $legacy_id  Legacy row ID.
	 * @return object|null
	 */
	private static function find_migrated_activity( $table_name, $legacy_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table_name ) . '` WHERE legacy_source = %s AND legacy_id = %d',
				self::LEGACY_SOURCE,
				$legacy_id
			)
		);
	}

	/**
	 * Compare all migrated fields exactly, preserving byte-level content.
	 *
	 * @param object $actual   Database row.
	 * @param array  $expected Expected mapped values.
	 * @return bool
	 */
	private static function activity_matches( $actual, $expected ) {
		foreach ( $expected as $field => $value ) {
			if ( ! property_exists( $actual, $field ) ) {
				return false;
			}
			if ( null === $value ) {
				if ( null !== $actual->{$field} ) {
					return false;
				}
			} elseif ( (string) $value !== (string) $actual->{$field} ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hash a mapped activity using stable ordered JSON values.
	 *
	 * @param array $activity Mapped activity.
	 * @return string
	 */
	private static function mapped_activity_fingerprint( $activity ) {
		return hash(
			'sha256',
			wp_json_encode( array_values( $activity ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Formats matching the ordered mapped-activity fields.
	 *
	 * @return array
	 */
	private static function activity_formats() {
		return array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s',
		);
	}

	/**
	 * Read a grouped, deterministic index definition.
	 *
	 * @param string $table_name Full table name.
	 * @return array
	 */
	private static function read_indexes( $table_name ) {
		global $wpdb;
		$grouped = array();
		$rows    = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table_name ) . '`', ARRAY_A );
		foreach ( $rows as $row ) {
			$name = $row['Key_name'];
			if ( ! isset( $grouped[ $name ] ) ) {
				$grouped[ $name ] = array(
					'unique'  => 0 === (int) $row['Non_unique'],
					'columns' => array(),
				);
			}
			$grouped[ $name ]['columns'][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		foreach ( $grouped as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );
		return $grouped;
	}

	/**
	 * Create a normalized signature for legacy columns and indexes.
	 *
	 * @param array $columns SHOW FULL COLUMNS rows.
	 * @param array $indexes SHOW INDEX rows.
	 * @return string
	 */
	private static function schema_signature( $columns, $indexes ) {
		$normalized_columns = array();
		foreach ( $columns as $column ) {
			$normalized_columns[] = array(
				$column['Field'], $column['Type'], $column['Collation'], $column['Null'],
				$column['Key'], $column['Default'], $column['Extra'],
			);
		}
		$normalized_indexes = array();
		foreach ( $indexes as $index ) {
			$normalized_indexes[] = array(
				$index['Key_name'], (int) $index['Non_unique'], (int) $index['Seq_in_index'],
				$index['Column_name'], $index['Sub_part'], $index['Index_type'],
			);
		}
		return hash(
			'sha256',
			wp_json_encode( array( $normalized_columns, $normalized_indexes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Read exact distinct values and counts from an allow-listed legacy column.
	 *
	 * @param string $table_name Full table name.
	 * @param string $column     Allow-listed column.
	 * @return array
	 */
	private static function value_inventory( $table_name, $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'type', 'priority', 'status' ), true ) ) {
			return array();
		}
		$inventory = array();
		$rows      = $wpdb->get_results(
			'SELECT `' . $column . '` AS value, COUNT(*) AS value_count FROM `' . esc_sql( $table_name ) . '` GROUP BY `' . $column . '` ORDER BY `' . $column . '` ASC',
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$inventory[ $row['value'] ] = (int) $row['value_count'];
		}
		return $inventory;
	}

	/**
	 * Exact required domain columns and indexes.
	 *
	 * @return array
	 */
	private static function expected_domain_schema() {
		$column = static function ( $type, $null = 'NO', $default = null, $extra = '' ) {
			return compact( 'type', 'null', 'default', 'extra' );
		};
		$index = static function ( $unique, $columns ) {
			return compact( 'unique', 'columns' );
		};

		return array(
			'fixreport_checklist_events' => array(
				'columns' => array(
					'event_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ),
					'task_key' => $column( 'varchar(64)' ), 'event_type' => $column( 'varchar(16)' ),
					'actor_user_id' => $column( 'bigint unsigned' ), 'occurred_at_utc' => $column( 'datetime' ),
					'note' => $column( 'text', 'YES' ), 'title_snapshot' => $column( 'varchar(255)' ),
					'description_snapshot' => $column( 'text', 'YES' ), 'category_key_snapshot' => $column( 'varchar(64)' ),
					'expected_event_id' => $column( 'bigint unsigned', 'NO', '0' ), 'request_token' => $column( 'char(36)' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'event_id' ) ),
					'request_token' => $index( true, array( 'request_token' ) ),
					'task_history' => $index( false, array( 'task_key', 'event_id' ) ),
				),
			),
			'fixreport_activities' => array(
				'columns' => array(
					'activity_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ),
					'source' => $column( 'varchar(16)' ), 'event_type' => $column( 'varchar(64)' ),
					'category' => $column( 'varchar(32)', 'YES' ), 'title' => $column( 'varchar(255)', 'NO', '' ),
					'description' => $column( 'longtext' ), 'internal_note' => $column( 'longtext', 'YES' ),
					'status' => $column( 'varchar(24)', 'NO', 'completed' ), 'priority' => $column( 'varchar(16)', 'NO', 'medium' ),
					'client_visible' => $column( 'tinyint(1) unsigned', 'NO', '1' ), 'object_type' => $column( 'varchar(32)', 'YES' ),
					'object_key' => $column( 'varchar(191)', 'YES' ), 'object_key_hash' => $column( 'char(64)', 'YES' ),
					'object_name' => $column( 'varchar(255)', 'YES' ), 'previous_version' => $column( 'varchar(64)', 'YES' ),
					'new_version' => $column( 'varchar(64)', 'YES' ), 'metadata' => $column( 'longtext', 'YES' ),
					'performed_by' => $column( 'bigint unsigned', 'YES' ), 'activity_date' => $column( 'datetime' ),
					'created_at' => $column( 'datetime' ), 'updated_at' => $column( 'datetime' ),
					'deleted_at' => $column( 'datetime', 'YES' ), 'deleted_by' => $column( 'bigint unsigned', 'YES' ),
					'legacy_source' => $column( 'varchar(32)', 'YES' ), 'legacy_id' => $column( 'bigint unsigned', 'YES' ),
					'legacy_fingerprint' => $column( 'char(64)', 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'activity_id' ) ), 'legacy_origin' => $index( true, array( 'legacy_source', 'legacy_id' ) ),
					'active_timeline' => $index( false, array( 'deleted_at', 'activity_date', 'activity_id' ) ),
					'source_timeline' => $index( false, array( 'source', 'activity_date', 'activity_id' ) ),
					'category_timeline' => $index( false, array( 'category', 'activity_date', 'activity_id' ) ),
					'event_timeline' => $index( false, array( 'event_type', 'activity_date', 'activity_id' ) ),
					'status_timeline' => $index( false, array( 'status', 'activity_date', 'activity_id' ) ),
					'report_candidates' => $index( false, array( 'client_visible', 'activity_date', 'activity_id' ) ),
					'object_history' => $index( false, array( 'object_type', 'object_key_hash', 'activity_date', 'activity_id' ) ),
				),
			),
			'fixreport_snapshots' => array(
				'columns' => array(
					'snapshot_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'snapshot_type' => $column( 'varchar(32)' ),
					'generation_status' => $column( 'varchar(16)', 'NO', 'building' ), 'label' => $column( 'varchar(255)', 'YES' ),
					'site_name' => $column( 'varchar(255)', 'NO', '' ), 'site_url' => $column( 'text' ), 'home_url' => $column( 'text' ),
					'site_language' => $column( 'varchar(32)', 'YES' ), 'timezone' => $column( 'varchar(64)', 'YES' ),
					'is_multisite' => $column( 'tinyint(1) unsigned', 'YES' ), 'wordpress_version' => $column( 'varchar(64)', 'YES' ),
					'core_update_available' => $column( 'tinyint(1) unsigned', 'YES' ), 'available_wordpress_version' => $column( 'varchar(64)', 'YES' ),
					'php_version' => $column( 'varchar(64)', 'YES' ), 'database_engine' => $column( 'varchar(32)', 'YES' ),
					'database_version' => $column( 'varchar(64)', 'YES' ), 'web_server' => $column( 'varchar(255)', 'YES' ),
					'is_https' => $column( 'tinyint(1) unsigned', 'YES' ), 'wp_memory_limit_bytes' => $column( 'bigint unsigned', 'YES' ),
					'php_memory_limit_bytes' => $column( 'bigint unsigned', 'YES' ), 'wp_debug' => $column( 'tinyint(1) unsigned', 'YES' ),
					'wp_debug_log' => $column( 'tinyint(1) unsigned', 'YES' ), 'wp_debug_display' => $column( 'tinyint(1) unsigned', 'YES' ),
					'plugin_count' => $column( 'int unsigned', 'YES' ), 'active_plugin_count' => $column( 'int unsigned', 'YES' ),
					'inactive_plugin_count' => $column( 'int unsigned', 'YES' ), 'plugin_update_count' => $column( 'int unsigned', 'YES' ),
					'theme_count' => $column( 'int unsigned', 'YES' ), 'theme_update_count' => $column( 'int unsigned', 'YES' ),
					'post_count' => $column( 'bigint unsigned', 'YES' ), 'page_count' => $column( 'bigint unsigned', 'YES' ),
					'comment_count' => $column( 'bigint unsigned', 'YES' ), 'user_count' => $column( 'bigint unsigned', 'YES' ),
					'site_health_critical_count' => $column( 'int unsigned', 'YES' ), 'site_health_recommended_count' => $column( 'int unsigned', 'YES' ),
					'pending_update_count' => $column( 'int unsigned', 'YES' ), 'summary_metadata' => $column( 'longtext', 'YES' ),
					'failure_code' => $column( 'varchar(64)', 'YES' ), 'created_by' => $column( 'bigint unsigned', 'YES' ),
					'created_at' => $column( 'datetime' ), 'started_at' => $column( 'datetime' ), 'updated_at' => $column( 'datetime' ),
					'completed_at' => $column( 'datetime', 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'snapshot_id' ) ), 'status_created' => $index( false, array( 'generation_status', 'created_at', 'snapshot_id' ) ),
					'type_status_date' => $index( false, array( 'snapshot_type', 'generation_status', 'completed_at', 'snapshot_id' ) ),
					'completed_date' => $index( false, array( 'completed_at', 'snapshot_id' ) ),
				),
			),
			'fixreport_snapshot_items' => array(
				'columns' => array(
					'snapshot_item_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'snapshot_id' => $column( 'bigint unsigned' ),
					'item_type' => $column( 'varchar(32)' ), 'item_key' => $column( 'varchar(255)' ), 'item_key_hash' => $column( 'char(64)' ),
					'display_name' => $column( 'varchar(255)', 'NO', '' ), 'installed_version' => $column( 'varchar(64)', 'YES' ),
					'available_version' => $column( 'varchar(64)', 'YES' ), 'is_active' => $column( 'tinyint(1) unsigned', 'YES' ),
					'update_available' => $column( 'tinyint(1) unsigned', 'YES' ), 'parent_key' => $column( 'varchar(255)', 'YES' ),
					'severity' => $column( 'varchar(24)', 'YES' ), 'item_payload' => $column( 'longtext', 'YES' ),
					'display_order' => $column( 'int unsigned', 'NO', '0' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'snapshot_item_id' ) ), 'snapshot_item' => $index( true, array( 'snapshot_id', 'item_type', 'item_key_hash' ) ),
					'snapshot_type_order' => $index( false, array( 'snapshot_id', 'item_type', 'display_order', 'snapshot_item_id' ) ),
					'item_history' => $index( false, array( 'item_type', 'item_key_hash', 'snapshot_id' ) ),
				),
			),
			'fixreport_reports' => array(
				'columns' => array(
					'report_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'report_type' => $column( 'varchar(32)' ),
					'status' => $column( 'varchar(16)', 'NO', 'draft' ), 'title' => $column( 'varchar(255)', 'NO', '' ),
					'report_locale' => $column( 'varchar(20)' ), 'period_timezone' => $column( 'varchar(64)', 'YES' ), 'period_start' => $column( 'datetime', 'YES' ), 'period_end' => $column( 'datetime', 'YES' ),
					'start_snapshot_id' => $column( 'bigint unsigned', 'YES' ), 'end_snapshot_id' => $column( 'bigint unsigned', 'YES' ),
					'assessment_summary' => $column( 'longtext', 'YES' ), 'findings' => $column( 'longtext', 'YES' ), 'recommendations' => $column( 'longtext', 'YES' ), 'final_notes' => $column( 'longtext', 'YES' ),
					'include_comparison' => $column( 'tinyint(1) unsigned', 'NO', '0' ), 'draft_revision' => $column( 'bigint unsigned', 'NO', '1' ), 'initial_assessment_key' => $column( 'varchar(32)', 'YES' ),
					'branding_overrides' => $column( 'longtext', 'YES' ),
					'frozen_payload' => $column( 'longtext', 'YES' ), 'frozen_payload_sha256' => $column( 'char(64)', 'YES' ),
					'payload_schema_version' => $column( 'varchar(16)', 'YES' ), 'created_by' => $column( 'bigint unsigned', 'YES' ),
					'updated_by' => $column( 'bigint unsigned', 'YES' ), 'finalized_by' => $column( 'bigint unsigned', 'YES' ),
					'created_at' => $column( 'datetime' ), 'updated_at' => $column( 'datetime' ), 'finalized_at' => $column( 'datetime', 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'report_id' ) ), 'status_updated' => $index( false, array( 'status', 'updated_at', 'report_id' ) ),
					'type_created' => $index( false, array( 'report_type', 'created_at', 'report_id' ) ), 'start_snapshot' => $index( false, array( 'start_snapshot_id' ) ),
					'end_snapshot' => $index( false, array( 'end_snapshot_id' ) ), 'finalized_date' => $index( false, array( 'finalized_at', 'report_id' ) ), 'initial_assessment_once' => $index( true, array( 'initial_assessment_key' ) ),
				),
			),
			'fixreport_report_activities' => array(
				'columns' => array(
					'report_activity_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'report_id' => $column( 'bigint unsigned' ),
					'activity_id' => $column( 'bigint unsigned' ), 'display_order' => $column( 'int unsigned', 'NO', '0' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'report_activity_id' ) ), 'report_activity' => $index( true, array( 'report_id', 'activity_id' ) ),
					'report_order' => $index( false, array( 'report_id', 'display_order', 'report_activity_id' ) ),
					'activity_reports' => $index( false, array( 'activity_id', 'report_id' ) ),
				),
			),
			'fixreport_report_checklist' => array(
				'columns' => array(
					'checklist_item_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'report_id' => $column( 'bigint unsigned' ),
					'check_key' => $column( 'varchar(64)' ), 'displayed_label' => $column( 'varchar(255)', 'NO', '' ), 'displayed_description' => $column( 'text', 'YES' ), 'included' => $column( 'tinyint(1) unsigned', 'NO', '1' ),
					'status' => $column( 'varchar(24)', 'NO', 'pending' ), 'note' => $column( 'text', 'YES' ),
					'display_order' => $column( 'int unsigned', 'NO', '0' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'checklist_item_id' ) ), 'report_check' => $index( true, array( 'report_id', 'check_key' ) ),
					'report_check_order' => $index( false, array( 'report_id', 'display_order', 'checklist_item_id' ) ),
				),
			),
			'fixreport_report_logo_assets' => array(
				'columns' => array(
					'asset_id' => $column( 'bigint unsigned', 'NO', null, 'auto_increment' ), 'report_id' => $column( 'bigint unsigned' ),
					'mime_type' => $column( 'varchar(32)' ), 'width' => $column( 'int unsigned' ), 'height' => $column( 'int unsigned' ),
					'byte_length' => $column( 'int unsigned' ), 'sha256' => $column( 'char(64)' ), 'image_bytes' => $column( 'mediumblob' ),
					'created_at' => $column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY' => $index( true, array( 'asset_id' ) ), 'report_logo_once' => $index( true, array( 'report_id' ) ),
				),
			),
		);
	}
}
