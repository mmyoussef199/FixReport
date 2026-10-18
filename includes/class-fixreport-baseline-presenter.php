<?php
/**
 * Turns stored Snapshot evidence into a bounded, display-ready admin view model.
 * This class does not collect or replace historical values with live site data.
 */
class FixReport_Baseline_Presenter {
	private $integrity_warning = false;

	public function present( $snapshot, $items ) {
		$this->integrity_warning = false;
		if ( ! is_object( $snapshot ) || ! is_array( $items ) || ! in_array( $snapshot->snapshot_type, array( 'initial', 'manual', 'checkpoint' ), true ) || 'complete' !== $snapshot->generation_status ) {
			return new WP_Error( 'baseline_integrity_failed' );
		}
		$metadata = $this->metadata( $snapshot->summary_metadata );
		$captured = $this->date( $snapshot->completed_at );
		$site_url = $this->url( $snapshot->site_url );
		$home_url = $this->url( $snapshot->home_url );
		$health_coverage = isset( $metadata['health_coverage'] ) ? $metadata['health_coverage'] : 'unknown';
		$grouped = array( 'plugin' => array(), 'theme' => array(), 'site_health' => array(), 'content_type' => array() );
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->item_type ) || ! array_key_exists( $item->item_type, $grouped ) ) {
				$this->integrity_warning = true;
				continue;
			}
			$payload = $this->payload( $item->item_payload );
			$name = $this->text( $item->display_name );
			switch ( $item->item_type ) {
				case 'plugin':
					$kind = isset( $payload['plugin_kind'] ) && in_array( $payload['plugin_kind'], array( 'regular', 'mu' ), true ) ? $payload['plugin_kind'] : 'unknown';
					if ( 'unknown' === $kind ) {
						$this->integrity_warning = true;
					}
					$grouped['plugin'][] = array(
						'name' => $name,
						'version' => $this->text( $item->installed_version ),
						'status' => 'mu' === $kind ? __( 'Must-Use', 'fixreport-maintenance-logger' ) : ( 'regular' === $kind ? $this->active( $item->is_active ) : $this->unknown() ),
						'update' => $this->update( $item->update_available, $item->available_version ),
					);
					break;
				case 'theme':
					$state = isset( $payload['theme_state'] ) && in_array( $payload['theme_state'], array( 'valid', 'errored' ), true ) ? $payload['theme_state'] : 'unknown';
					if ( 'unknown' === $state ) {
						$this->integrity_warning = true;
					}
					$grouped['theme'][] = array(
						'name' => $name,
						'version' => $this->text( $item->installed_version ),
						'status' => 'valid' === $state ? $this->active( $item->is_active ) : $this->unknown(),
						'update' => $this->update( $item->update_available, $item->available_version ),
						'parent' => null !== $item->parent_key && '' !== $item->parent_key ? $this->text( $item->parent_key ) : null,
					);
					break;
				case 'content_type':
					$count = isset( $payload['count'], $payload['counted_status'] ) && 'publish' === $payload['counted_status'] && is_int( $payload['count'] ) && $payload['count'] >= 0 ? $payload['count'] : null;
					if ( null === $count ) {
						$this->integrity_warning = true;
					}
					$grouped['content_type'][] = array( 'name' => $name, 'count' => $this->number( $count ) );
					break;
				case 'site_health':
					$severity = in_array( $item->severity, array( 'critical', 'recommended' ), true ) ? $item->severity : 'unknown';
					$count = isset( $payload['known_pending_count'] ) && is_int( $payload['known_pending_count'] ) && $payload['known_pending_count'] > 0 ? $payload['known_pending_count'] : null;
					if ( 'unknown' === $severity || null === $count ) {
						$this->integrity_warning = true;
					}
					$grouped['site_health'][] = array( 'name' => $name, 'severity' => $severity, 'evidence' => null === $count ? $this->unknown() : sprintf( /* translators: %s: number of known pending updates. */ __( '%s known pending update(s) at capture', 'fixreport-maintenance-logger' ), number_format_i18n( $count ) ) );
					break;
			}
		}
		$update_states = isset( $metadata['update_state'] ) && is_array( $metadata['update_state'] ) ? $metadata['update_state'] : array();
		$memory_states = isset( $metadata['memory_state'] ) && is_array( $metadata['memory_state'] ) ? $metadata['memory_state'] : array();
		$database = trim( (string) $snapshot->database_engine . ' ' . (string) $snapshot->database_version );
		$result = array(
			'id' => (int) $snapshot->snapshot_id,
			'is_initial' => 'initial' === $snapshot->snapshot_type,
			'display_label' => self::display_label( $snapshot ),
			'captured' => $captured,
			'overview' => array(
				array( 'label' => __( 'WordPress', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->wordpress_version ), 'detail' => $this->update( $snapshot->core_update_available, $snapshot->available_wordpress_version ) ),
				array( 'label' => __( 'Plugins', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->plugin_count ), 'detail' => sprintf( /* translators: %s: count of active regular plugins. */ __( '%s active', 'fixreport-maintenance-logger' ), $this->number( $snapshot->active_plugin_count ) ) ),
				array( 'label' => __( 'Themes', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->theme_count ), 'detail' => __( 'installed', 'fixreport-maintenance-logger' ) ),
				array( 'label' => __( 'Pending Updates', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->pending_update_count ), 'detail' => __( 'at capture', 'fixreport-maintenance-logger' ) ),
			),
			'site' => array(
				array( 'label' => __( 'Site Name', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->site_name ) ),
				array( 'label' => __( 'Site URL', 'fixreport-maintenance-logger' ), 'value' => null === $site_url ? $this->unknown() : $site_url, 'url' => $site_url ),
				array( 'label' => __( 'Home URL', 'fixreport-maintenance-logger' ), 'value' => null === $home_url ? $this->unknown() : $home_url, 'url' => $home_url ),
				array( 'label' => __( 'Language', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->site_language ) ),
				array( 'label' => __( 'Timezone', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->timezone ) ),
				array( 'label' => __( 'HTTPS', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->is_https ) ),
				array( 'label' => __( 'Multisite', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->is_multisite ) ),
			),
			'environment' => array(
				array( 'label' => __( 'WordPress Version', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->wordpress_version ) ),
				array( 'label' => __( 'PHP Version', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->php_version ) ),
				array( 'label' => __( 'Database Engine / Version', 'fixreport-maintenance-logger' ), 'value' => '' === $database ? $this->unknown() : $database ),
				array( 'label' => __( 'Web Server', 'fixreport-maintenance-logger' ), 'value' => $this->text( $snapshot->web_server ) ),
				array( 'label' => __( 'HTTPS', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->is_https ) ),
				array( 'label' => __( 'WordPress Memory Limit', 'fixreport-maintenance-logger' ), 'value' => $this->memory( $snapshot->wp_memory_limit_bytes, isset( $memory_states['wordpress'] ) ? $memory_states['wordpress'] : null ) ),
				array( 'label' => __( 'PHP Memory Limit', 'fixreport-maintenance-logger' ), 'value' => $this->memory( $snapshot->php_memory_limit_bytes, isset( $memory_states['php'] ) ? $memory_states['php'] : null ) ),
				array( 'label' => __( 'WP_DEBUG', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->wp_debug ) ),
				array( 'label' => __( 'WP_DEBUG_LOG', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->wp_debug_log ) ),
				array( 'label' => __( 'WP_DEBUG_DISPLAY', 'fixreport-maintenance-logger' ), 'value' => $this->yes_no( $snapshot->wp_debug_display ) ),
			),
			'updates' => array(
				array( 'label' => __( 'WordPress Core', 'fixreport-maintenance-logger' ), 'value' => $this->update( $snapshot->core_update_available, $snapshot->available_wordpress_version ) ),
				array( 'label' => __( 'Plugins', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->plugin_update_count ) ),
				array( 'label' => __( 'Themes', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->theme_update_count ) ),
				array( 'label' => __( 'Total Pending', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->pending_update_count ) ),
			),
			'content' => array(
				array( 'label' => __( 'Posts', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->post_count ) ),
				array( 'label' => __( 'Pages', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->page_count ) ),
				array( 'label' => __( 'Comments', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->comment_count ) ),
				array( 'label' => __( 'Users', 'fixreport-maintenance-logger' ), 'value' => $this->number( $snapshot->user_count ) ),
			),
			'plugins' => $grouped['plugin'],
			'themes' => $grouped['theme'],
			'content_types' => $grouped['content_type'],
			'health_items' => $grouped['site_health'],
			'health_coverage' => $health_coverage,
			'health_critical' => $this->number( $snapshot->site_health_critical_count ),
			'health_recommended' => $this->number( $snapshot->site_health_recommended_count ),
			'health_empty' => 'complete' === $health_coverage && '0' === (string) $snapshot->site_health_critical_count && '0' === (string) $snapshot->site_health_recommended_count && ! $grouped['site_health'],
			'technical' => array(
				array( 'label' => __( 'Snapshot ID', 'fixreport-maintenance-logger' ), 'value' => (string) (int) $snapshot->snapshot_id ),
				array( 'label' => __( 'Snapshot Type', 'fixreport-maintenance-logger' ), 'value' => 'initial' === $snapshot->snapshot_type ? __( 'Initial', 'fixreport-maintenance-logger' ) : ( 'checkpoint' === $snapshot->snapshot_type ? __( 'Automatic Checkpoint', 'fixreport-maintenance-logger' ) : __( 'Manual', 'fixreport-maintenance-logger' ) ) ),
				array( 'label' => __( 'Status', 'fixreport-maintenance-logger' ), 'value' => __( 'Complete', 'fixreport-maintenance-logger' ) ),
				array( 'label' => __( 'Captured', 'fixreport-maintenance-logger' ), 'value' => $captured ),
				array( 'label' => __( 'Collection Contract', 'fixreport-maintenance-logger' ), 'value' => isset( $metadata['contract_version'] ) ? $metadata['contract_version'] : $this->unknown() ),
				array( 'label' => __( 'Health Policy', 'fixreport-maintenance-logger' ), 'value' => isset( $metadata['health_policy'] ) ? $metadata['health_policy'] : $this->unknown() ),
				array( 'label' => __( 'Update Evidence', 'fixreport-maintenance-logger' ), 'value' => $this->update_coverage( $update_states ) ),
			),
		);
		$result['integrity_warning'] = $this->integrity_warning;
		return $result;
	}

	/** Stable display identity from stored type, UTC completion time, and row ID. */
	public static function display_label( $snapshot ) {
		$type = isset( $snapshot->snapshot_type ) && 'initial' === $snapshot->snapshot_type ? __( 'Initial Baseline', 'fixreport-maintenance-logger' ) : ( isset( $snapshot->snapshot_type ) && 'checkpoint' === $snapshot->snapshot_type ? __( 'Automatic Checkpoint', 'fixreport-maintenance-logger' ) : __( 'Manual Snapshot', 'fixreport-maintenance-logger' ) );
		$id = isset( $snapshot->snapshot_id ) ? (int) $snapshot->snapshot_id : 0;
		$time = isset( $snapshot->completed_at ) && is_string( $snapshot->completed_at ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $snapshot->completed_at ) ? $snapshot->completed_at : __( 'Unknown time', 'fixreport-maintenance-logger' );
		/* translators: 1: Snapshot type; 2: stored UTC completion time; 3: Snapshot ID. */
		return sprintf( __( '%1$s — %2$s UTC · #%3$d', 'fixreport-maintenance-logger' ), $type, $time, $id );
	}

	private function metadata( $json ) {
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['contract_version'], $data['health_policy'], $data['health_coverage'], $data['update_state'] ) ) {
			$this->integrity_warning = true;
			return array();
		}
		$result = array();
		$result['contract_version'] = in_array( $data['contract_version'], array( '3a.1', '3a.2' ), true ) ? $data['contract_version'] : null;
		$result['health_policy'] = in_array( $data['health_policy'], array( 'known_updates_v1' ), true ) ? $data['health_policy'] : null;
		$result['health_coverage'] = in_array( $data['health_coverage'], array( 'complete', 'incomplete_update_evidence' ), true ) ? $data['health_coverage'] : 'unknown';
		$result['update_state'] = is_array( $data['update_state'] ) ? $data['update_state'] : array();
		$result['memory_state'] = isset( $data['memory_state'] ) && is_array( $data['memory_state'] ) ? $data['memory_state'] : array();
		if ( null === $result['contract_version'] || null === $result['health_policy'] || 'unknown' === $result['health_coverage'] ) {
			$this->integrity_warning = true;
		}
		return $result;
	}

	private function payload( $json ) {
		$data = is_string( $json ) && strlen( $json ) <= 2048 ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			$this->integrity_warning = true;
			return array();
		}
		return $data;
	}

	private function update_coverage( $states ) {
		if ( ! isset( $states['core']['status'], $states['plugins']['status'], $states['themes']['status'] ) ) {
			$this->integrity_warning = true;
			return $this->unknown();
		}
		foreach ( array( 'core', 'plugins', 'themes' ) as $key ) {
			if ( ! in_array( $states[ $key ]['status'], array( 'known', 'unknown' ), true ) ) {
				$this->integrity_warning = true;
				return $this->unknown();
			}
		}
		return sprintf( /* translators: 1: core update evidence, 2: plugin update evidence, 3: theme update evidence. */ __( 'Core: %1$s; Plugins: %2$s; Themes: %3$s', 'fixreport-maintenance-logger' ), $this->known( $states['core']['status'] ), $this->known( $states['plugins']['status'] ), $this->known( $states['themes']['status'] ) );
	}

	private function known( $status ) {
		return 'known' === $status ? __( 'Known', 'fixreport-maintenance-logger' ) : $this->unknown();
	}

	private function date( $value ) {
		$date = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			$this->integrity_warning = true;
			return $this->unknown();
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() );
	}

	private function url( $value ) {
		$parts = is_string( $value ) ? wp_parse_url( $value ) : false;
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			$this->integrity_warning = true;
			return null;
		}
		return $value;
	}

	private function memory( $bytes, $state ) {
		if ( 'unlimited' === $state ) {
			return __( 'Unlimited', 'fixreport-maintenance-logger' );
		}
		return 'finite' === $state && null !== $bytes && ctype_digit( (string) $bytes ) ? size_format( (int) $bytes ) : $this->unknown();
	}

	private function update( $value, $available = null ) {
		if ( null === $value ) {
			return $this->unknown();
		}
		if ( '0' === (string) $value ) {
			return __( 'Up to date', 'fixreport-maintenance-logger' );
		}
		if ( '1' !== (string) $value ) {
			$this->integrity_warning = true;
			return $this->unknown();
		}
		return null !== $available && '' !== (string) $available ? sprintf( /* translators: %s: available version. */ __( 'Update available — %s', 'fixreport-maintenance-logger' ), $this->text( $available ) ) : __( 'Update available', 'fixreport-maintenance-logger' );
	}

	private function active( $value ) {
		return '1' === (string) $value ? __( 'Active', 'fixreport-maintenance-logger' ) : ( '0' === (string) $value ? __( 'Inactive', 'fixreport-maintenance-logger' ) : $this->unknown() );
	}

	private function yes_no( $value ) {
		return '1' === (string) $value ? __( 'Yes', 'fixreport-maintenance-logger' ) : ( '0' === (string) $value ? __( 'No', 'fixreport-maintenance-logger' ) : $this->unknown() );
	}

	private function number( $value ) {
		return null !== $value && ctype_digit( (string) $value ) ? number_format_i18n( (int) $value ) : $this->unknown();
	}

	private function text( $value ) {
		return null !== $value && '' !== trim( (string) $value ) ? (string) $value : $this->unknown();
	}

	private function unknown() {
		return __( 'Unknown', 'fixreport-maintenance-logger' );
	}
}
