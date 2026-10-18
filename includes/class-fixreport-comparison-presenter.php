<?php
/** Translates machine-coded, derived comparison evidence for the admin view. */
class FixReport_Comparison_Presenter {
	public function present( $result ) {
		$names = array( 'core' => __( 'WordPress Core', 'fixreport-maintenance-logger' ), 'environment' => __( 'Environment', 'fixreport-maintenance-logger' ), 'plugins' => __( 'Plugins', 'fixreport-maintenance-logger' ), 'themes' => __( 'Themes', 'fixreport-maintenance-logger' ), 'content' => __( 'Content Summary', 'fixreport-maintenance-logger' ), 'health' => __( 'Maintenance Health', 'fixreport-maintenance-logger' ) );
		$groups = array();
		foreach ( $names as $key => $name ) {
			$group = $result['groups'][ $key ];
			$groups[ $key ] = array( 'name' => $name, 'comparability' => $group['comparability'], 'changes' => array(), 'unchanged' => array(), 'incomparable' => array(), 'technical' => array() );
			foreach ( array( 'changes', 'incomparable', 'unchanged' ) as $bucket ) {
				foreach ( $group[ $bucket ] as $entry ) {
					$rendered = $this->entry( $entry );
					$destination = 'technical' === $entry['section'] ? 'technical' : $bucket;
					$groups[ $key ][ $destination ][] = $rendered;
				}
			}
		}
		return array( 'contract_version' => $result['contract_version'], 'from' => $result['from'], 'to' => $result['to'], 'summary' => $result['summary'], 'warnings' => $result['warnings'], 'groups' => $groups );
	}

	private function entry( $entry ) {
		$details = array();
		foreach ( $entry['details'] as $detail ) {
			$details[] = $this->label( $detail['code'] ) . ': ' . $this->format( $detail['before'], $detail['code'] ) . ' → ' . $this->format( $detail['after'], $detail['code'] );
		}
		return array( 'title' => $this->title( $entry ), 'state' => $this->state( $entry['state'] ), 'before' => $this->format( $entry['before'], $entry['code'] ), 'after' => $this->format( $entry['after'], $entry['code'] ), 'details' => $details, 'key' => $entry['key'] );
	}

	private function title( $entry ) {
		if ( in_array( $entry['code'], array( 'plugin_inventory', 'theme_inventory', 'qualifying_content_type' ), true ) ) {
			$name = $entry['after']['value']['name'] ?? ( $entry['before']['value']['name'] ?? $entry['key'] );
			return $name . ' (' . $entry['key'] . ')';
		}
		return $this->label( $entry['code'] === 'maintenance_rule' ? $entry['key'] : $entry['code'] );
	}

	private function label( $code ) {
		$labels = array(
			'wordpress_version' => __( 'WordPress version', 'fixreport-maintenance-logger' ),
			'core_update_evidence' => __( 'Cached core update evidence', 'fixreport-maintenance-logger' ),
			'offered_wordpress_version' => __( 'Offered WordPress version', 'fixreport-maintenance-logger' ),
			'php_version' => __( 'PHP version', 'fixreport-maintenance-logger' ),
			'database_engine' => __( 'Database engine', 'fixreport-maintenance-logger' ),
			'database_version' => __( 'Database version', 'fixreport-maintenance-logger' ),
			'is_https' => __( 'HTTPS', 'fixreport-maintenance-logger' ),
			'wp_memory_limit_bytes' => __( 'WordPress memory limit', 'fixreport-maintenance-logger' ),
			'php_memory_limit_bytes' => __( 'PHP memory limit', 'fixreport-maintenance-logger' ),
			'web_server_family' => __( 'Web-server family', 'fixreport-maintenance-logger' ),
			'wp_debug' => __( 'WP_DEBUG', 'fixreport-maintenance-logger' ),
			'wp_debug_log' => __( 'WP_DEBUG_LOG', 'fixreport-maintenance-logger' ),
			'wp_debug_display' => __( 'WP_DEBUG_DISPLAY', 'fixreport-maintenance-logger' ),
			'post_count' => __( 'Published posts', 'fixreport-maintenance-logger' ),
			'page_count' => __( 'Published pages', 'fixreport-maintenance-logger' ),
			'comment_count' => __( 'Approved plus pending comments', 'fixreport-maintenance-logger' ),
			'user_count' => __( 'Total users (technical)', 'fixreport-maintenance-logger' ),
			'fixreport.core_update_available' => __( 'Core update available', 'fixreport-maintenance-logger' ),
			'fixreport.plugin_updates_available' => __( 'Plugin updates available', 'fixreport-maintenance-logger' ),
			'fixreport.theme_updates_available' => __( 'Theme updates available', 'fixreport-maintenance-logger' ),
			'version' => __( 'Version', 'fixreport-maintenance-logger' ),
			'active_state' => __( 'Active state', 'fixreport-maintenance-logger' ),
			'update_state' => __( 'Update state', 'fixreport-maintenance-logger' ),
			'offered_version' => __( 'Offered version', 'fixreport-maintenance-logger' ),
			'theme_state' => __( 'Theme validity', 'fixreport-maintenance-logger' ),
			'parent_relationship' => __( 'Parent theme', 'fixreport-maintenance-logger' ),
		);
		return $labels[ $code ] ?? $code;
	}

	private function state( $state ) {
		$labels = array( 'changed' => __( 'Changed', 'fixreport-maintenance-logger' ), 'unchanged' => __( 'Unchanged', 'fixreport-maintenance-logger' ), 'added' => __( 'Entered captured inventory', 'fixreport-maintenance-logger' ), 'removed' => __( 'Left captured inventory', 'fixreport-maintenance-logger' ), 'new' => __( 'New finding', 'fixreport-maintenance-logger' ), 'resolved' => __( 'Resolved finding', 'fixreport-maintenance-logger' ), 'persisting' => __( 'Persisting finding', 'fixreport-maintenance-logger' ), 'incomparable' => __( 'Incomparable', 'fixreport-maintenance-logger' ) );
		return $labels[ $state ] ?? $state;
	}

	private function format( $evidence, $code ) {
		if ( 'not_applicable' === $evidence['state'] ) {
			return __( 'Not applicable / not in captured inventory', 'fixreport-maintenance-logger' );
		}
		if ( 'unknown_at_capture' === $evidence['state'] ) {
			return __( 'Unknown at capture', 'fixreport-maintenance-logger' );
		}
		if ( 'missing_or_corrupt' === $evidence['state'] ) {
			return __( 'Missing or corrupt evidence', 'fixreport-maintenance-logger' );
		}
		$value = $evidence['value'];
		if ( is_array( $value ) && array_key_exists( 'available', $value ) ) {
			return $value['available'] ? sprintf( /* translators: %s: offered version. */ __( 'Available; offered version: %s', 'fixreport-maintenance-logger' ), $value['offered_version'] ?: __( 'Unknown', 'fixreport-maintenance-logger' ) ) : __( 'No cached update available', 'fixreport-maintenance-logger' );
		}
		if ( is_array( $value ) && array_key_exists( 'limit', $value ) ) {
			return 'unlimited' === $value['limit'] ? __( 'Unlimited', 'fixreport-maintenance-logger' ) : size_format( (int) $value['bytes'] );
		}
		if ( is_array( $value ) && array_key_exists( 'published', $value ) ) {
			return sprintf( /* translators: 1: content type name; 2: published count. */ __( '%1$s — %2$d published', 'fixreport-maintenance-logger' ), $value['name'], $value['published'] );
		}
		if ( is_array( $value ) && array_key_exists( 'kind', $value ) ) {
			$states = $value['field_states'];
			$kind_label = array( 'mu' => __( 'Must-Use plugin', 'fixreport-maintenance-logger' ), 'regular' => __( 'Regular plugin', 'fixreport-maintenance-logger' ), 'valid' => __( 'Valid theme', 'fixreport-maintenance-logger' ), 'errored' => __( 'Errored theme', 'fixreport-maintenance-logger' ) );
			$version = 'known' === $states['version'] ? $value['version'] : $this->evidence_state( $states['version'] );
			$active = 'known' === $states['active'] ? ( $value['active'] ? __( 'Yes', 'fixreport-maintenance-logger' ) : __( 'No', 'fixreport-maintenance-logger' ) ) : $this->evidence_state( $states['active'] );
			$update = 'known' === $states['update'] ? ( $value['update'] ? __( 'Available', 'fixreport-maintenance-logger' ) : __( 'None known', 'fixreport-maintenance-logger' ) ) : $this->evidence_state( $states['update'] );
			$offered = 'known' === $states['offered_version'] ? $value['offered_version'] : $this->evidence_state( $states['offered_version'] );
			$parent = 'known' === $states['parent'] ? ( $value['parent'] ?: __( 'None', 'fixreport-maintenance-logger' ) ) : $this->evidence_state( $states['parent'] );
			return sprintf( /* translators: 1: name; 2: kind; 3: version; 4: active state; 5: update state; 6: offered version; 7: parent theme. */ __( '%1$s; %2$s; version %3$s; active %4$s; update %5$s; offered %6$s; parent %7$s', 'fixreport-maintenance-logger' ), $value['name'], $kind_label[ $value['kind'] ] ?? $value['kind'], $version, $active, $update, $offered, $parent );
		}
		if ( in_array( $code, array( 'is_https', 'wp_debug', 'wp_debug_log', 'wp_debug_display', 'active_state', 'update_state' ), true ) ) {
			return $value ? __( 'Yes', 'fixreport-maintenance-logger' ) : __( 'No', 'fixreport-maintenance-logger' );
		}
		if ( 'theme_state' === $code ) {
			return 'valid' === $value ? __( 'Valid', 'fixreport-maintenance-logger' ) : __( 'Errored', 'fixreport-maintenance-logger' );
		}
		if ( 'maintenance_rule' === $code ) {
			return 0 === $value ? __( 'Known absent', 'fixreport-maintenance-logger' ) : sprintf( /* translators: %d: known pending updates. */ __( '%d known pending', 'fixreport-maintenance-logger' ), $value );
		}
		return null === $value ? __( 'None', 'fixreport-maintenance-logger' ) : (string) $value;
	}

	private function evidence_state( $state ) {
		return 'not_applicable' === $state ? __( 'Not applicable', 'fixreport-maintenance-logger' ) : __( 'Unknown at capture', 'fixreport-maintenance-logger' );
	}
}
