<?php
/** Pure, deterministic derivation from two normalized persisted snapshots. */
class FixReport_Comparison_Engine {
	const CONTRACT_VERSION = '5c.1';

	public function compare( $from, $to ) {
		if ( ! is_array( $from ) || ! is_array( $to ) || ! isset( $from['identity'], $to['identity'], $from['summary'], $to['summary'], $from['items'], $to['items'] ) ) {
			return new WP_Error( 'comparison_input_invalid' );
		}
		$a = $from['identity'];
		$b = $to['identity'];
		if ( $a['id'] === $b['id'] ) {
			return new WP_Error( 'comparison_same_snapshot' );
		}
		if ( $a['completed_at'] > $b['completed_at'] || ( $a['completed_at'] === $b['completed_at'] && $a['id'] > $b['id'] ) ) {
			return new WP_Error( 'comparison_reversed_pair' );
		}
		if ( ! in_array( $a['collection_contract'], array( '3a.1', '3a.2' ), true ) || ! in_array( $b['collection_contract'], array( '3a.1', '3a.2' ), true ) ) {
			return new WP_Error( 'comparison_contract_incompatible' );
		}
		$groups = array_fill_keys( array( 'core', 'environment', 'plugins', 'themes', 'content', 'health' ), array( 'changes' => array(), 'unchanged' => array(), 'incomparable' => array(), 'comparability' => 'complete' ) );
		$this->core( $groups['core'], $from, $to );
		$this->environment( $groups['environment'], $from, $to );
		$this->inventory( $groups['plugins'], $from['items']['plugin'], $to['items']['plugin'], 'plugin' );
		$this->inventory( $groups['themes'], $from['items']['theme'], $to['items']['theme'], 'theme' );
		$this->content( $groups['content'], $from, $to );
		$this->health( $groups['health'], $from, $to );
		$summary = array( 'changed_groups' => 0, 'incomparable_groups' => 0, 'group_counts' => array() );
		$warnings = array();
		foreach ( $groups as $key => &$group ) {
			$change_count = count( $group['changes'] );
			$unknown_count = count( $group['incomparable'] );
			foreach ( array_merge( $group['changes'], $group['unchanged'] ) as $entry ) {
				foreach ( $entry['details'] as $detail ) {
					$unknown_count += (int) ( 'incomparable' === $detail['state'] );
				}
			}
			$group['comparability'] = $unknown_count ? ( $change_count || $group['unchanged'] ? 'partial' : 'incomparable' ) : 'complete';
			$summary['changed_groups'] += (int) ( $change_count > 0 );
			$summary['incomparable_groups'] += (int) ( $unknown_count > 0 );
			$summary['group_counts'][ $key ] = array( 'changes' => $change_count, 'unchanged' => count( $group['unchanged'] ), 'incomparable' => $unknown_count );
			if ( $unknown_count ) {
				$warnings[] = array( 'code' => 'group_incomparable_evidence', 'group' => $key, 'count' => $unknown_count );
			}
		}
		unset( $group );
		$comparability = 6 === $summary['incomparable_groups'] && 0 === $summary['changed_groups'] ? 'incomparable' : ( $summary['incomparable_groups'] ? 'partial' : 'complete' );
		return array( 'contract_version' => self::CONTRACT_VERSION, 'from' => $a, 'to' => $b, 'comparability' => $comparability, 'groups' => $groups, 'summary' => $summary, 'warnings' => $warnings );
	}

	private function core( &$group, $from, $to ) {
		$a = $from['summary'];
		$b = $to['summary'];
		$this->scalar( $group, 'wordpress_version', $a['wordpress_version'], $b['wordpress_version'] );
		$left = $this->update_evidence( $a['core_update_available'], $a['available_wordpress_version'] );
		$right = $this->update_evidence( $b['core_update_available'], $b['available_wordpress_version'] );
		$this->entry( $group, 'core_update_evidence', $left, $right, 'primary' );
		if ( 1 === $a['core_update_available'] && 1 === $b['core_update_available'] ) {
			$this->scalar( $group, 'offered_wordpress_version', $a['available_wordpress_version'], $b['available_wordpress_version'] );
		}
	}

	private function environment( &$group, $from, $to ) {
		$a = $from['summary'];
		$b = $to['summary'];
		foreach ( array( 'php_version', 'database_engine', 'database_version', 'is_https' ) as $field ) {
			$this->scalar( $group, $field, $a[ $field ], $b[ $field ] );
		}
		foreach ( array( 'wordpress' => 'wp_memory_limit_bytes', 'php' => 'php_memory_limit_bytes' ) as $kind => $field ) {
			$left = $this->memory( $from['metadata']['memory_state'][ $kind ], $a[ $field ] );
			$right = $this->memory( $to['metadata']['memory_state'][ $kind ], $b[ $field ] );
			$this->entry( $group, $field, $left, $right, 'primary' );
		}
		$this->scalar( $group, 'web_server_family', $this->server_family( $a['web_server'] ), $this->server_family( $b['web_server'] ), 'technical' );
		foreach ( array( 'wp_debug', 'wp_debug_log', 'wp_debug_display' ) as $field ) {
			$this->scalar( $group, $field, $a[ $field ], $b[ $field ], 'technical' );
		}
	}

	private function inventory( &$group, $before, $after, $kind ) {
		$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) {
			$a = $before[ $key ] ?? null;
			$b = $after[ $key ] ?? null;
			$left = $a ? $this->inventory_value( $a, $kind ) : $this->evidence( 'not_applicable' );
			$right = $b ? $this->inventory_value( $b, $kind ) : $this->evidence( 'not_applicable' );
			if ( ! $a || ! $b ) {
				$this->append( $group, array( 'key' => $key, 'code' => $kind . '_inventory', 'state' => $a ? 'removed' : 'added', 'section' => 'primary', 'before' => $left, 'after' => $right, 'details' => array() ) );
				continue;
			}
			$details = array();
			$compare = 'plugin' === $kind ? array( 'installed_version' => 'version', 'is_active' => 'active_state', 'update_available' => 'update_state', 'available_version' => 'offered_version' ) : array( 'theme_state' => 'theme_state', 'installed_version' => 'version', 'is_active' => 'active_state', 'update_available' => 'update_state', 'available_version' => 'offered_version', 'parent_key' => 'parent_relationship' );
			foreach ( $compare as $field => $code ) {
				if ( 'plugin' === $kind && 'mu' === $a['payload']['plugin_kind'] && in_array( $field, array( 'is_active', 'update_available', 'available_version' ), true ) ) {
					continue;
				}
				if ( 'theme' === $kind && 'theme_state' === $field ) {
					$av = $a['payload']['theme_state'];
					$bv = $b['payload']['theme_state'];
				} else {
					$av = $a[ $field ];
					$bv = $b[ $field ];
				}
				$as = $this->inventory_field_state( $a, $kind, $field );
				$bs = $this->inventory_field_state( $b, $kind, $field );
				if ( 'not_applicable' === $as && 'not_applicable' === $bs ) {
					continue;
				}
				if ( 'known' !== $as || 'known' !== $bs ) {
					$state = 'incomparable';
				} else {
					$state = $av === $bv ? 'unchanged' : 'changed';
				}
				if ( 'unchanged' !== $state ) {
					$details[] = array( 'code' => $code, 'state' => $state, 'before' => $this->evidence( $as, $av ), 'after' => $this->evidence( $bs, $bv ) );
				}
			}
			$states = array_column( $details, 'state' );
			$state = in_array( 'changed', $states, true ) ? 'changed' : ( in_array( 'incomparable', $states, true ) ? 'incomparable' : 'unchanged' );
			$this->append( $group, array( 'key' => $key, 'code' => $kind . '_inventory', 'state' => $state, 'section' => 'primary', 'before' => $left, 'after' => $right, 'details' => $details ) );
		}
	}

	private function content( &$group, $from, $to ) {
		$a = $from['summary'];
		$b = $to['summary'];
		foreach ( array( 'post_count', 'page_count', 'comment_count' ) as $field ) {
			$this->scalar( $group, $field, $a[ $field ], $b[ $field ] );
		}
		$this->scalar( $group, 'user_count', $a['user_count'], $b['user_count'], 'technical' );
		$before = $from['items']['content_type'];
		$after = $to['items']['content_type'];
		$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) {
			$a = $before[ $key ] ?? null;
			$b = $after[ $key ] ?? null;
			$state = ! $a ? 'added' : ( ! $b ? 'removed' : ( $a['payload']['count'] === $b['payload']['count'] ? 'unchanged' : 'changed' ) );
			$this->append( $group, array( 'key' => $key, 'code' => 'qualifying_content_type', 'state' => $state, 'section' => 'primary', 'before' => $a ? $this->evidence( 'known', array( 'name' => $a['display_name'], 'published' => $a['payload']['count'] ) ) : $this->evidence( 'not_applicable' ), 'after' => $b ? $this->evidence( 'known', array( 'name' => $b['display_name'], 'published' => $b['payload']['count'] ) ) : $this->evidence( 'not_applicable' ), 'details' => array() ) );
		}
	}

	private function health( &$group, $from, $to ) {
		$rules = array( 'fixreport.core_update_available' => 'core_update_available', 'fixreport.plugin_updates_available' => 'plugin_update_count', 'fixreport.theme_updates_available' => 'theme_update_count' );
		foreach ( $rules as $rule => $field ) {
			$a = $from['summary'][ $field ];
			$b = $to['summary'][ $field ];
			$state = null === $a || null === $b ? 'incomparable' : ( 0 === $a ? ( 0 === $b ? 'unchanged' : 'new' ) : ( 0 === $b ? 'resolved' : 'persisting' ) );
			$this->append( $group, array( 'key' => $rule, 'code' => 'maintenance_rule', 'state' => $state, 'section' => 'primary', 'before' => $this->evidence( null === $a ? 'unknown_at_capture' : 'known', $a ), 'after' => $this->evidence( null === $b ? 'unknown_at_capture' : 'known', $b ), 'details' => array() ) );
		}
	}

	private function scalar( &$group, $code, $before, $after, $section = 'primary' ) {
		$this->entry( $group, $code, $this->evidence( null === $before ? 'unknown_at_capture' : 'known', $before ), $this->evidence( null === $after ? 'unknown_at_capture' : 'known', $after ), $section );
	}

	private function entry( &$group, $code, $before, $after, $section ) {
		$state = 'known' !== $before['state'] || 'known' !== $after['state'] ? 'incomparable' : ( $before['value'] === $after['value'] ? 'unchanged' : 'changed' );
		$this->append( $group, array( 'key' => $code, 'code' => $code, 'state' => $state, 'section' => $section, 'before' => $before, 'after' => $after, 'details' => array() ) );
	}

	private function append( &$group, $entry ) {
		$bucket = 'incomparable' === $entry['state'] ? 'incomparable' : ( in_array( $entry['state'], array( 'unchanged', 'persisting' ), true ) ? 'unchanged' : 'changes' );
		$group[ $bucket ][] = $entry;
	}

	private function evidence( $state, $value = null ) {
		return array( 'state' => $state, 'value' => $value );
	}

	private function update_evidence( $flag, $offer ) {
		return $this->evidence( null === $flag ? 'unknown_at_capture' : 'known', null === $flag ? null : array( 'available' => $flag, 'offered_version' => 1 === $flag ? $offer : null ) );
	}

	private function memory( $state, $bytes ) {
		return $this->evidence( 'unknown' === $state ? 'unknown_at_capture' : 'known', array( 'limit' => $state, 'bytes' => $bytes ) );
	}

	private function server_family( $server ) {
		if ( null === $server || '' === $server ) {
			return null;
		}
		$parts = explode( '/', $server, 2 );
		return strtolower( $parts[0] );
	}

	private function inventory_field_state( $item, $kind, $field ) {
		if ( 'theme_state' === $field ) {
			return 'known';
		}
		if ( 'plugin' === $kind && 'mu' === $item['payload']['plugin_kind'] && in_array( $field, array( 'is_active', 'update_available', 'available_version' ), true ) ) {
			return 'not_applicable';
		}
		if ( 'theme' === $kind && 'errored' === $item['payload']['theme_state'] ) {
			return 'unknown_at_capture';
		}
		if ( 'available_version' === $field && 0 === $item['update_available'] ) {
			return 'not_applicable';
		}
		if ( 'theme' === $kind && 'parent_key' === $field && null === $item[ $field ] ) {
			return 'known'; // A valid parent-less theme has a known empty relationship.
		}
		return null === $item[ $field ] ? 'unknown_at_capture' : 'known';
	}

	private function inventory_value( $item, $kind ) {
		return $this->evidence( 'known', array( 'name' => $item['display_name'], 'kind' => 'plugin' === $kind ? $item['payload']['plugin_kind'] : $item['payload']['theme_state'], 'version' => $item['installed_version'], 'active' => $item['is_active'], 'update' => $item['update_available'], 'offered_version' => $item['available_version'], 'parent' => $item['parent_key'], 'field_states' => array( 'version' => $this->inventory_field_state( $item, $kind, 'installed_version' ), 'active' => $this->inventory_field_state( $item, $kind, 'is_active' ), 'update' => $this->inventory_field_state( $item, $kind, 'update_available' ), 'offered_version' => $this->inventory_field_state( $item, $kind, 'available_version' ), 'parent' => 'plugin' === $kind ? 'not_applicable' : $this->inventory_field_state( $item, $kind, 'parent_key' ) ) ) );
	}
}
