<?php
/** Validates persisted 3a.1/3a.2 evidence before comparison. */
class FixReport_Comparison_Normalizer {
	public function normalize( $snapshot, $items ) {
		if ( ! is_object( $snapshot ) || ! is_array( $items ) ) {
			return $this->corrupt( 'shape' );
		}
		$s = get_object_vars( $snapshot );
		if ( ! isset( $s['snapshot_id'], $s['snapshot_type'], $s['generation_status'], $s['completed_at'], $s['summary_metadata'] ) || ! in_array( $s['snapshot_type'], array( 'initial', 'manual', 'checkpoint' ), true ) || 'complete' !== $s['generation_status'] || ! $this->positive_id( $s['snapshot_id'] ) || ! $this->timestamp( $s['completed_at'] ) ) {
			return $this->corrupt( 'identity' );
		}
		$m = is_string( $s['summary_metadata'] ) ? json_decode( $s['summary_metadata'], true ) : null;
		if ( ! is_array( $m ) || ! isset( $m['contract_version'] ) ) {
			return $this->corrupt( 'metadata' );
		}
		if ( ! in_array( $m['contract_version'], array( '3a.1', '3a.2' ), true ) ) {
			return new WP_Error( 'comparison_contract_incompatible', '', array( 'contract_version' => $m['contract_version'] ) );
		}
		if ( ( 'checkpoint' === $s['snapshot_type'] ) !== ( '3a.2' === $m['contract_version'] ) || ( 'checkpoint' === $s['snapshot_type'] && ( ! isset( $m['checkpoint']['captured_at'] ) || ! FixReport_Snapshot_Validator::valid_checkpoint_provenance( $m['checkpoint'] ) || $m['checkpoint']['captured_at'] > $s['completed_at'] ) ) ) {
			return $this->corrupt( 'checkpoint_metadata' );
		}
		$policies = array( 'content_policy' => 'published_posts_pages_and_public_cpts', 'comment_policy' => 'approved_plus_pending', 'plugin_policy' => 'regular_counts_mu_items_dropins_excluded', 'theme_policy' => 'valid_and_errored_enumerated', 'health_policy' => 'known_updates_v1' );
		foreach ( $policies as $key => $value ) {
			if ( ! isset( $m[ $key ] ) || $value !== $m[ $key ] ) {
				return $this->corrupt( 'policy' );
			}
		}
		if ( ! isset( $m['update_max_age_sec'], $m['update_state'], $m['memory_state'], $m['health_coverage'] ) || 43200 !== $m['update_max_age_sec'] || ! is_array( $m['update_state'] ) || ! is_array( $m['memory_state'] ) || ! in_array( $m['health_coverage'], array( 'complete', 'incomplete_update_evidence' ), true ) ) {
			return $this->corrupt( 'metadata' );
		}
		foreach ( array( 'core', 'plugins', 'themes' ) as $kind ) {
			$state = $m['update_state'][ $kind ] ?? null;
			if ( ! is_array( $state ) || ! isset( $state['status'], $state['reason'] ) || ! in_array( $state['status'], array( 'known', 'unknown' ), true ) || ! in_array( $state['reason'], array( 'missing_or_stale', 'version_or_shape_mismatch', 'cache_shape_invalid', 'inventory_mismatch', 'fresh_matching_cache' ), true ) ) {
				return $this->corrupt( 'update_state' );
			}
		}
		foreach ( array( 'wordpress', 'php' ) as $kind ) {
			if ( ! isset( $m['memory_state'][ $kind ] ) || ! in_array( $m['memory_state'][ $kind ], array( 'finite', 'unlimited', 'unknown' ), true ) ) {
				return $this->corrupt( 'memory_state' );
			}
		}
		foreach ( array( 'site_name', 'site_url', 'home_url', 'site_language', 'timezone', 'wordpress_version', 'php_version', 'database_engine', 'database_version' ) as $key ) {
			if ( ! isset( $s[ $key ] ) || ! is_string( $s[ $key ] ) || '' === $s[ $key ] ) {
				return $this->corrupt( 'summary_text' );
			}
		}
		if ( ! array_key_exists( 'web_server', $s ) || ( null !== $s['web_server'] && ! is_string( $s['web_server'] ) ) || ! array_key_exists( 'available_wordpress_version', $s ) || ( null !== $s['available_wordpress_version'] && ! is_string( $s['available_wordpress_version'] ) ) ) {
			return $this->corrupt( 'summary_text' );
		}
		$required_numbers = array( 'is_https', 'is_multisite', 'wp_debug', 'wp_debug_log', 'wp_debug_display', 'plugin_count', 'active_plugin_count', 'inactive_plugin_count', 'theme_count', 'post_count', 'page_count', 'comment_count', 'user_count' );
		$nullable_numbers = array( 'core_update_available', 'plugin_update_count', 'theme_update_count', 'pending_update_count', 'site_health_critical_count', 'site_health_recommended_count', 'wp_memory_limit_bytes', 'php_memory_limit_bytes' );
		foreach ( array_merge( $required_numbers, $nullable_numbers ) as $key ) {
			if ( ! array_key_exists( $key, $s ) || ( null === $s[ $key ] && in_array( $key, $required_numbers, true ) ) || ( null !== $s[ $key ] && ! $this->unsigned( $s[ $key ] ) ) ) {
				return $this->corrupt( 'summary_number' );
			}
			$s[ $key ] = null === $s[ $key ] ? null : (int) $s[ $key ];
		}
		foreach ( array( 'is_https', 'is_multisite', 'wp_debug', 'wp_debug_log', 'wp_debug_display', 'core_update_available' ) as $key ) {
			if ( null !== $s[ $key ] && ! in_array( $s[ $key ], array( 0, 1 ), true ) ) {
				return $this->corrupt( 'summary_boolean' );
			}
		}
		if ( $s['active_plugin_count'] + $s['inactive_plugin_count'] !== $s['plugin_count'] ) {
			return $this->corrupt( 'plugin_counts' );
		}
		foreach ( array( 'core' => 'core_update_available', 'plugins' => 'plugin_update_count', 'themes' => 'theme_update_count' ) as $kind => $field ) {
			if ( ( 'known' === $m['update_state'][ $kind ]['status'] ) !== ( null !== $s[ $field ] ) ) {
				return $this->corrupt( 'update_counts' );
			}
		}
		$parts = array( $s['core_update_available'], $s['plugin_update_count'], $s['theme_update_count'] );
		if ( $s['pending_update_count'] !== ( in_array( null, $parts, true ) ? null : array_sum( $parts ) ) || ( 1 !== $s['core_update_available'] && null !== $s['available_wordpress_version'] ) ) {
			return $this->corrupt( 'update_total' );
		}
		foreach ( array( 'wordpress' => 'wp_memory_limit_bytes', 'php' => 'php_memory_limit_bytes' ) as $kind => $field ) {
			if ( ( 'finite' === $m['memory_state'][ $kind ] ) !== ( null !== $s[ $field ] ) ) {
				return $this->corrupt( 'memory_value' );
			}
		}
		$maps = array( 'plugin' => array(), 'theme' => array(), 'content_type' => array(), 'site_health' => array() );
		$regular = 0;
		$active = 0;
		$plugin_updates = 0;
		$theme_updates = 0;
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				return $this->corrupt( 'item_shape' );
			}
			$i = get_object_vars( $item );
			foreach ( array( 'item_type', 'item_key', 'item_key_hash', 'display_name', 'installed_version', 'available_version', 'is_active', 'update_available', 'parent_key', 'severity', 'item_payload' ) as $key ) {
				if ( ! array_key_exists( $key, $i ) ) {
					return $this->corrupt( 'item_field' );
				}
			}
			if ( ! is_string( $i['item_type'] ) || ! isset( $maps[ $i['item_type'] ] ) || ! is_string( $i['item_key'] ) || '' === $i['item_key'] || ! is_string( $i['item_key_hash'] ) || hash( 'sha256', $i['item_key'] ) !== $i['item_key_hash'] || isset( $maps[ $i['item_type'] ][ $i['item_key'] ] ) || ! is_string( $i['display_name'] ) ) {
				return $this->corrupt( 'item_identity' );
			}
			foreach ( array( 'is_active', 'update_available' ) as $key ) {
				if ( null !== $i[ $key ] && ( ! $this->unsigned( $i[ $key ] ) || ! in_array( (int) $i[ $key ], array( 0, 1 ), true ) ) ) {
					return $this->corrupt( 'item_boolean' );
				}
				$i[ $key ] = null === $i[ $key ] ? null : (int) $i[ $key ];
			}
			foreach ( array( 'installed_version', 'available_version', 'parent_key', 'severity' ) as $key ) {
				if ( null !== $i[ $key ] && ! is_string( $i[ $key ] ) ) {
					return $this->corrupt( 'item_text' );
				}
			}
			$i['payload'] = is_string( $i['item_payload'] ) ? json_decode( $i['item_payload'], true ) : null;
			if ( ! is_array( $i['payload'] ) ) {
				return $this->corrupt( 'item_payload' );
			}
			if ( 'plugin' === $i['item_type'] ) {
				$kind = $i['payload']['plugin_kind'] ?? null;
				if ( array_keys( $i['payload'] ) !== array( 'plugin_kind' ) || ! in_array( $kind, array( 'regular', 'mu' ), true ) || null !== $i['severity'] || null !== $i['parent_key'] || ( 'mu' === $kind ) !== ( 0 === strpos( $i['item_key'], 'mu:' ) ) ) {
					return $this->corrupt( 'plugin_item' );
				}
				if ( 'mu' === $kind ) {
					if ( null !== $i['is_active'] || null !== $i['update_available'] || null !== $i['available_version'] ) {
						return $this->corrupt( 'mu_item' );
					}
				} else {
					$regular++;
					$active += $i['is_active'];
					$plugin_updates += (int) $i['update_available'];
					if ( null === $i['is_active'] || ( 'known' === $m['update_state']['plugins']['status'] ) !== ( null !== $i['update_available'] ) || ( 1 !== $i['update_available'] && null !== $i['available_version'] ) ) {
						return $this->corrupt( 'plugin_item_state' );
					}
				}
			} elseif ( 'theme' === $i['item_type'] ) {
				$state = $i['payload']['theme_state'] ?? null;
				if ( array_keys( $i['payload'] ) !== array( 'theme_state' ) || ! in_array( $state, array( 'valid', 'errored' ), true ) || null !== $i['severity'] ) {
					return $this->corrupt( 'theme_item' );
				}
				if ( 'errored' === $state ) {
					if ( null !== $i['installed_version'] || null !== $i['available_version'] || null !== $i['is_active'] || null !== $i['update_available'] || null !== $i['parent_key'] ) {
						return $this->corrupt( 'errored_theme' );
					}
				} elseif ( null === $i['is_active'] || ( 'known' === $m['update_state']['themes']['status'] ) !== ( null !== $i['update_available'] ) || ( 1 !== $i['update_available'] && null !== $i['available_version'] ) ) {
					return $this->corrupt( 'theme_item_state' );
				}
				$theme_updates += (int) $i['update_available'];
			} elseif ( 'content_type' === $i['item_type'] ) {
				if ( array_keys( $i['payload'] ) !== array( 'count', 'counted_status' ) || ! isset( $i['payload']['counted_status'], $i['payload']['count'] ) || 'publish' !== $i['payload']['counted_status'] || ! is_int( $i['payload']['count'] ) || $i['payload']['count'] < 0 || null !== $i['installed_version'] || null !== $i['available_version'] || null !== $i['is_active'] || null !== $i['update_available'] || null !== $i['parent_key'] || null !== $i['severity'] ) {
					return $this->corrupt( 'content_item' );
				}
			} else {
				$rules = array( 'fixreport.core_update_available', 'fixreport.plugin_updates_available', 'fixreport.theme_updates_available' );
				if ( ! in_array( $i['item_key'], $rules, true ) || 'recommended' !== $i['severity'] || array_keys( $i['payload'] ) !== array( 'known_pending_count' ) || ! isset( $i['payload']['known_pending_count'] ) || ! is_int( $i['payload']['known_pending_count'] ) || $i['payload']['known_pending_count'] <= 0 || null !== $i['installed_version'] || null !== $i['available_version'] || null !== $i['is_active'] || null !== $i['update_available'] || null !== $i['parent_key'] ) {
					return $this->corrupt( 'health_item' );
				}
			}
			$maps[ $i['item_type'] ][ $i['item_key'] ] = $i;
		}
		if ( $regular !== $s['plugin_count'] || $active !== $s['active_plugin_count'] || count( $maps['theme'] ) !== $s['theme_count'] || ( null !== $s['plugin_update_count'] && $plugin_updates !== $s['plugin_update_count'] ) || ( null !== $s['theme_update_count'] && $theme_updates !== $s['theme_update_count'] ) ) {
			return $this->corrupt( 'inventory_counts' );
		}
		foreach ( array( 'fixreport.core_update_available' => 'core_update_available', 'fixreport.plugin_updates_available' => 'plugin_update_count', 'fixreport.theme_updates_available' => 'theme_update_count' ) as $rule => $field ) {
			$expected = (int) $s[ $field ];
			$present = $maps['site_health'][ $rule ] ?? null;
			if ( ( null === $s[ $field ] && $present ) || ( null !== $s[ $field ] && ( $expected > 0 ) !== (bool) $present ) || ( $present && $present['payload']['known_pending_count'] !== $expected ) ) {
				return $this->corrupt( 'health_consistency' );
			}
		}
		$coverage = in_array( 'unknown', array_column( $m['update_state'], 'status' ), true ) ? 'incomplete_update_evidence' : 'complete';
		if ( $m['health_coverage'] !== $coverage || ( 'complete' === $coverage && ( 0 !== $s['site_health_critical_count'] || count( $maps['site_health'] ) !== $s['site_health_recommended_count'] ) ) || ( 'incomplete_update_evidence' === $coverage && ( null !== $s['site_health_critical_count'] || null !== $s['site_health_recommended_count'] ) ) ) {
			return $this->corrupt( 'health_coverage' );
		}
		foreach ( $maps as &$map ) {
			ksort( $map, SORT_STRING );
		}
		unset( $map );
		return array( 'identity' => array( 'id' => (int) $s['snapshot_id'], 'type' => $s['snapshot_type'], 'completed_at' => $s['completed_at'], 'collection_contract' => $m['contract_version'] ), 'summary' => $s, 'metadata' => $m, 'items' => $maps );
	}

	private function unsigned( $value ) {
		return ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/', $value ) && (string) (int) $value === $value );
	}

	private function positive_id( $value ) {
		return $this->unsigned( $value ) && (int) $value > 0;
	}

	private function timestamp( $value ) {
		$date = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function corrupt( $reason ) {
		return new WP_Error( 'comparison_evidence_corrupt', '', array( 'reason' => $reason, 'evidence_state' => 'missing_or_corrupt' ) );
	}
}
