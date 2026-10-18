<?php
/** Central validation boundary for the immutable snapshot write contract. */
class FixReport_Snapshot_Validator {
	/** Validate and serialize the collector result, or return a safe WP_Error. */
	public function validate( $collection ) {
		if ( ! is_array( $collection ) || ! isset( $collection['summary'], $collection['items'] ) || ! is_array( $collection['summary'] ) || ! is_array( $collection['items'] ) ) {
			return new WP_Error( 'snapshot_collection_invalid' );
		}
		$s = $collection['summary'];
		$required = array( 'site_name', 'site_url', 'home_url', 'site_language', 'timezone', 'is_https', 'is_multisite', 'wordpress_version', 'php_version', 'database_engine', 'database_version', 'post_count', 'page_count', 'comment_count', 'user_count', 'plugin_count', 'active_plugin_count', 'inactive_plugin_count', 'theme_count', 'summary_metadata' );
		if ( array_diff( $required, array_keys( $s ) ) || ! is_array( $s['summary_metadata'] ) ) {
			return new WP_Error( 'snapshot_summary_invalid' );
		}
		foreach ( array( 'site_url', 'home_url' ) as $field ) {
			$parts = is_string( $s[ $field ] ) ? wp_parse_url( $s[ $field ] ) : false;
			if ( ! is_string( $s[ $field ] ) || strlen( $s[ $field ] ) > 2048 || ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) || isset( $parts['user'], $parts['pass'] ) || false !== strpos( $s[ $field ], '@' ) || preg_match( '/[?#]/', $s[ $field ] ) ) {
				return new WP_Error( 'snapshot_url_invalid' );
			}
		}
		$text_limits = array( 'site_name' => 255, 'site_language' => 32, 'timezone' => 64, 'wordpress_version' => 64, 'available_wordpress_version' => 64, 'php_version' => 64, 'database_engine' => 32, 'database_version' => 64, 'web_server' => 255 );
		foreach ( $text_limits as $field => $limit ) {
			if ( isset( $s[ $field ] ) && ( ! is_string( $s[ $field ] ) || strlen( $s[ $field ] ) > $limit * 4 ) ) {
				return new WP_Error( 'snapshot_text_invalid' );
			}
		}
		foreach ( array( 'is_https', 'is_multisite', 'wp_debug', 'wp_debug_log', 'wp_debug_display' ) as $field ) {
			if ( ! isset( $s[ $field ] ) || ! in_array( $s[ $field ], array( 0, 1 ), true ) ) {
				return new WP_Error( 'snapshot_boolean_invalid' );
			}
		}
		if ( ! $this->nullable_boolean( $s['core_update_available'] ) ) {
			return new WP_Error( 'snapshot_update_invalid' );
		}
		foreach ( array( 'plugin_count', 'active_plugin_count', 'inactive_plugin_count', 'plugin_update_count', 'theme_count', 'theme_update_count', 'post_count', 'page_count', 'comment_count', 'user_count', 'site_health_critical_count', 'site_health_recommended_count', 'pending_update_count', 'wp_memory_limit_bytes', 'php_memory_limit_bytes' ) as $field ) {
			if ( ! array_key_exists( $field, $s ) || ( null !== $s[ $field ] && ( ! is_int( $s[ $field ] ) || $s[ $field ] < 0 || $s[ $field ] > ( false !== strpos( $field, 'bytes' ) || in_array( $field, array( 'post_count', 'page_count', 'comment_count', 'user_count' ), true ) ? PHP_INT_MAX : 4294967295 ) ) ) ) {
				return new WP_Error( 'snapshot_count_invalid' );
			}
		}
		if ( $s['active_plugin_count'] + $s['inactive_plugin_count'] !== $s['plugin_count'] ) {
			return new WP_Error( 'snapshot_plugin_count_mismatch' );
		}
		$parts = array( $s['core_update_available'], $s['plugin_update_count'], $s['theme_update_count'] );
		$expected_total = in_array( null, $parts, true ) ? null : array_sum( $parts );
		if ( $s['pending_update_count'] !== $expected_total ) {
			return new WP_Error( 'snapshot_update_total_mismatch' );
		}
		$m = $s['summary_metadata'];
		$metadata_keys = array( 'contract_version', 'content_policy', 'comment_policy', 'update_max_age_sec', 'update_state', 'plugin_policy', 'theme_policy', 'health_policy', 'health_coverage', 'memory_state' );
		$checkpoint = '3a.2' === $m['contract_version'];
		if ( array_keys( $m ) !== ( $checkpoint ? array_merge( $metadata_keys, array( 'checkpoint' ) ) : $metadata_keys ) || ! in_array( $m['contract_version'], array( '3a.1', '3a.2' ), true ) || ( $checkpoint && ! self::valid_checkpoint_provenance( $m['checkpoint'] ) ) || 'published_posts_pages_and_public_cpts' !== $m['content_policy'] || 'approved_plus_pending' !== $m['comment_policy'] || 43200 !== $m['update_max_age_sec'] || 'regular_counts_mu_items_dropins_excluded' !== $m['plugin_policy'] || 'valid_and_errored_enumerated' !== $m['theme_policy'] || 'known_updates_v1' !== $m['health_policy'] || ! in_array( $m['health_coverage'], array( 'complete', 'incomplete_update_evidence' ), true ) || ! is_array( $m['update_state'] ) || ! is_array( $m['memory_state'] ) ) {
			return new WP_Error( 'snapshot_metadata_invalid' );
		}
		foreach ( array( 'core', 'plugins', 'themes' ) as $kind ) {
			if ( ! isset( $m['update_state'][ $kind ]['status'], $m['update_state'][ $kind ]['reason'] ) || array_keys( $m['update_state'][ $kind ] ) !== array( 'status', 'reason' ) || ! in_array( $m['update_state'][ $kind ]['status'], array( 'known', 'unknown' ), true ) || ! in_array( $m['update_state'][ $kind ]['reason'], array( 'missing_or_stale', 'version_or_shape_mismatch', 'cache_shape_invalid', 'inventory_mismatch', 'fresh_matching_cache' ), true ) ) {
				return new WP_Error( 'snapshot_metadata_invalid' );
			}
		}
		foreach ( array( 'wordpress', 'php' ) as $memory_kind ) {
			if ( ! isset( $m['memory_state'][ $memory_kind ] ) || ! in_array( $m['memory_state'][ $memory_kind ], array( 'finite', 'unlimited', 'unknown' ), true ) ) {
				return new WP_Error( 'snapshot_metadata_invalid' );
			}
		}
		$metadata_json = wp_json_encode( $m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $metadata_json ) || strlen( $metadata_json ) > 4096 ) {
			return new WP_Error( 'snapshot_metadata_invalid' );
		}
		$s['summary_metadata'] = $metadata_json;
		$items = array();
		$seen = array();
		$counts = array( 'plugin' => 0, 'theme' => 0, 'site_health' => 0, 'content_type' => 0 );
		$health_counts = array( 'critical' => 0, 'recommended' => 0 );
		foreach ( $collection['items'] as $item ) {
			if ( ! is_array( $item ) || array_diff( array( 'item_type', 'item_key', 'item_key_hash', 'display_name', 'installed_version', 'available_version', 'is_active', 'update_available', 'parent_key', 'severity', 'item_payload' ), array_keys( $item ) ) || ! isset( $item['item_type'], $item['item_key'], $item['item_key_hash'], $item['display_name'] ) || ! array_key_exists( $item['item_type'], $counts ) || ! is_string( $item['item_key'] ) || '' === $item['item_key'] || strlen( $item['item_key'] ) > 255 || ! is_string( $item['display_name'] ) || strlen( $item['display_name'] ) > 1020 || hash( 'sha256', $item['item_key'] ) !== $item['item_key_hash'] ) {
				return new WP_Error( 'snapshot_item_invalid' );
			}
			$id = $item['item_type'] . "\0" . $item['item_key'];
			if ( isset( $seen[ $id ] ) ) {
				return new WP_Error( 'snapshot_item_duplicate' );
			}
			$seen[ $id ] = true;
			foreach ( array( 'installed_version' => 64, 'available_version' => 64, 'parent_key' => 255 ) as $field => $limit ) {
				if ( ! array_key_exists( $field, $item ) || ( null !== $item[ $field ] && ( ! is_string( $item[ $field ] ) || strlen( $item[ $field ] ) > $limit ) ) ) {
					return new WP_Error( 'snapshot_item_invalid' );
				}
			}
			if ( ! $this->nullable_boolean( $item['is_active'] ) || ! $this->nullable_boolean( $item['update_available'] ) || ! is_array( $item['item_payload'] ) ) {
				return new WP_Error( 'snapshot_item_invalid' );
			}
			if ( 'site_health' === $item['item_type'] ) {
				if ( ! in_array( $item['severity'], array( 'critical', 'recommended' ), true ) || null !== $item['installed_version'] || null !== $item['available_version'] || null !== $item['is_active'] || null !== $item['update_available'] || null !== $item['parent_key'] || array_keys( $item['item_payload'] ) !== array( 'known_pending_count' ) || ! is_int( $item['item_payload']['known_pending_count'] ) || $item['item_payload']['known_pending_count'] <= 0 ) {
					return new WP_Error( 'snapshot_health_item_invalid' );
				}
				$health_counts[ $item['severity'] ]++;
			} elseif ( null !== $item['severity'] ) {
				return new WP_Error( 'snapshot_item_invalid' );
			}
			if ( 'content_type' === $item['item_type'] && ( array_keys( $item['item_payload'] ) !== array( 'count', 'counted_status' ) || ! is_int( $item['item_payload']['count'] ) || $item['item_payload']['count'] < 0 || 'publish' !== $item['item_payload']['counted_status'] ) ) {
				return new WP_Error( 'snapshot_content_item_invalid' );
			}
			if ( 'content_type' === $item['item_type'] && ( null !== $item['installed_version'] || null !== $item['available_version'] || null !== $item['is_active'] || null !== $item['update_available'] || null !== $item['parent_key'] ) ) {
				return new WP_Error( 'snapshot_content_item_invalid' );
			}
			if ( 'plugin' === $item['item_type'] && ( array_keys( $item['item_payload'] ) !== array( 'plugin_kind' ) || ! in_array( $item['item_payload']['plugin_kind'], array( 'regular', 'mu' ), true ) ) ) {
				return new WP_Error( 'snapshot_plugin_item_invalid' );
			}
			if ( 'plugin' === $item['item_type'] && 'mu' === $item['item_payload']['plugin_kind'] && ( null !== $item['is_active'] || null !== $item['update_available'] || null !== $item['available_version'] ) ) {
				return new WP_Error( 'snapshot_plugin_item_invalid' );
			}
			if ( 'theme' === $item['item_type'] && ( array_keys( $item['item_payload'] ) !== array( 'theme_state' ) || ! in_array( $item['item_payload']['theme_state'], array( 'valid', 'errored' ), true ) ) ) {
				return new WP_Error( 'snapshot_theme_item_invalid' );
			}
			$payload = wp_json_encode( $item['item_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $payload ) || strlen( $payload ) > 1024 ) {
				return new WP_Error( 'snapshot_item_payload_invalid' );
			}
			$item['item_payload'] = $payload;
			$item['display_order'] = $counts[ $item['item_type'] ]++;
			$items[] = $item;
		}
		if ( $counts['theme'] !== $s['theme_count'] || $health_counts['critical'] !== ( null === $s['site_health_critical_count'] ? $health_counts['critical'] : $s['site_health_critical_count'] ) || $health_counts['recommended'] !== ( null === $s['site_health_recommended_count'] ? $health_counts['recommended'] : $s['site_health_recommended_count'] ) ) {
			return new WP_Error( 'snapshot_item_count_mismatch' );
		}
		$regular = 0;
		foreach ( $collection['items'] as $item ) {
			if ( 'plugin' === $item['item_type'] && 'regular' === $item['item_payload']['plugin_kind'] ) {
				$regular++;
			}
		}
		if ( $regular !== $s['plugin_count'] || ( 'complete' === $m['health_coverage'] && ( null === $s['site_health_critical_count'] || null === $s['site_health_recommended_count'] ) ) || ( 'incomplete_update_evidence' === $m['health_coverage'] && ( null !== $s['site_health_critical_count'] || null !== $s['site_health_recommended_count'] ) ) ) {
			return new WP_Error( 'snapshot_summary_item_mismatch' );
		}
		return array( 'summary' => $s, 'items' => $items );
	}

	private function nullable_boolean( $value ) {
		return null === $value || in_array( $value, array( 0, 1 ), true );
	}

	/** Fixed-shape provenance, shared by write and comparison boundaries. */
	public static function valid_checkpoint_provenance( $value ) {
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'trigger', 'token', 'first_event_at', 'last_event_at', 'first_activity_id', 'last_activity_id', 'captured_at' ) && array_keys( $value ) !== array( 'trigger', 'token', 'first_event_at', 'last_event_at', 'first_activity_id', 'last_activity_id' ) ) {
			return false;
		}
		if ( ! in_array( $value['trigger'], array( 'activity_window', 'anchor', 'periodic' ), true ) || ! is_string( $value['token'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', $value['token'] ) ) {
			return false;
		}
		$event_window = 'activity_window' === $value['trigger'];
		if ( $event_window !== ( is_int( $value['first_activity_id'] ) && $value['first_activity_id'] > 0 && is_int( $value['last_activity_id'] ) && $value['last_activity_id'] >= $value['first_activity_id'] && self::utc_timestamp( $value['first_event_at'] ) && self::utc_timestamp( $value['last_event_at'] ) && $value['last_event_at'] >= $value['first_event_at'] ) ) {
			return false;
		}
		if ( ! $event_window && ( null !== $value['first_activity_id'] || null !== $value['last_activity_id'] || null !== $value['first_event_at'] || null !== $value['last_event_at'] ) ) {
			return false;
		}
		return ! isset( $value['captured_at'] ) || self::utc_timestamp( $value['captured_at'] );
	}

	private static function utc_timestamp( $value ) {
		$date = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}
