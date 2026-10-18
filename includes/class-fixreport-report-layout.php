<?php
/**
 * Report layout routing and the compact 9.2 presentation model shared by the
 * HTML preview and the PDF. Presentation only: it reads an assembled Draft or
 * an integrity-verified frozen document and never changes it, so document
 * hashes, preview binding, and frozen payloads are unaffected. Values are
 * shown exactly as stored (including Unknown); nothing is inferred.
 */
class FixReport_Report_Layout {
	const LEGACY = '9.1';
	const COMPACT = '9.2';

	/** Fixed Baseline Presenter sections and row counts the compact table relies on. */
	const SHAPE = array( 'overview' => 4, 'site' => 7, 'environment' => 10, 'updates' => 4, 'content' => 4, 'technical' => 7 );

	/**
	 * Drafts of the current contract use 9.2. A finalized report uses 9.2 only
	 * when its frozen finalization metadata carries the immutable 9.2 marker;
	 * every other document keeps the historical 9.1 rendering.
	 */
	public static function version( $document, $draft ) {
		if ( ! is_array( $document ) || FixReport_Report_Document::CONTRACT !== ( $document['contract_version'] ?? null ) ) { return self::LEGACY; }
		$finalization = $document['finalization'] ?? null;
		if ( $draft ) { return null === $finalization ? self::COMPACT : self::LEGACY; }
		return is_array( $finalization ) && self::COMPACT === ( $finalization['layout'] ?? null ) ? self::COMPACT : self::LEGACY;
	}

	/** Compact model: header facts, then ordered sections of text, notes, warnings, and tables. */
	public static function build( $document, $draft ) {
		$p = FixReport_Report_Language::for_document( $document );
		$w = self::words( $document['locale'] ?? 'en_US' );
		$t = static function ( $group, $key ) use ( $p ) { return FixReport_Report_Language::text( $p, $group, $key ); };
		$maintenance = 'maintenance' === ( $document['identity']['type'] ?? '' );
		$brand = $document['branding']['fields'] ?? null;
		$facts = array( array( $t( 'ui', 'report' ), self::cell( '#' . ( $document['identity']['report_id'] ?? '' ), true ) ), array( $w['type'], self::cell( $t( 'ui', $document['identity']['type'] ?? '' ) ) ) );
		if ( ! empty( $document['period'] ) ) {
			$facts[] = array( $t( 'ui', 'period' ), self::cell( $document['period']['start_utc'] . ' UTC → ' . $document['period']['end_utc_exclusive'] . ' UTC (' . $t( 'ui', 'exclusive' ) . ')', true ) );
			$facts[] = array( $t( 'ui', 'timezone' ), self::cell( $document['period']['timezone'], true ) );
		}
		$sections = array();
		$sections[] = array( 'title' => $p['labels']['summary'], 'blocks' => array( self::text( $document['authored']['summary'] ?? '' ) ) );
		if ( $maintenance ) {
			$sections[] = self::activities( $document, $p, $t, $w );
			$sections[] = self::checklist( $document, $p, $t, $w );
		}
		foreach ( self::states( $document, $p, $t, $w ) as $section ) { $sections[] = $section; }
		if ( ! empty( $document['comparison']['groups'] ) && is_array( $document['comparison']['groups'] ) ) { $sections[] = self::comparison( $document['comparison'], $p, $t, $w ); }
		foreach ( array( 'findings', 'recommendations', 'final_notes' ) as $field ) {
			$sections[] = array( 'title' => $p['labels'][ $field ], 'blocks' => array( self::text( $document['authored'][ $field ] ?? '' ) ) );
		}
		$sections[] = self::evidence( $document, $draft, $p, $t, $w );
		return array(
			'version' => self::COMPACT,
			'rtl' => 'ar' === ( $document['locale'] ?? '' ),
			'brand' => is_array( $brand ) ? $brand : null,
			'title' => $document['identity']['title'] ?? '',
			'banner' => $t( 'ui', $draft ? 'draft' : 'historical' ),
			'facts' => $facts,
			'sections' => $sections,
			'footer' => is_array( $brand ) && ! empty( $brand['footer_text'] ) ? $brand['footer_text'] : null,
		);
	}

	private static function activities( $document, $p, $t, $w ) {
		$activities = is_array( $document['activities'] ?? null ) ? $document['activities'] : array();
		if ( ! $activities ) { return array( 'title' => $p['labels']['activities'], 'blocks' => array( self::text( $t( 'ui', 'no_activities' ) ) ) ); }
		$rows = array();
		foreach ( $activities as $a ) {
			$sub = array();
			if ( '' !== trim( (string) ( $a['description'] ?? '' ) ) ) { $sub[] = (string) $a['description']; }
			if ( ! empty( $a['object_name'] ) || ! empty( $a['previous_version'] ) || ! empty( $a['new_version'] ) ) { $sub[] = trim( (string) $a['object_name'] . ' ' . (string) $a['previous_version'] . ' → ' . (string) $a['new_version'] ); }
			$sub[] = $t( 'sources', $a['source'] ?? '' ) . ( 'legacy_local_clock_uncertain' === ( $a['time_basis'] ?? '' ) ? ' · ' . $t( 'ui', 'legacy_clock' ) : '' );
			$rows[] = array( 'cells' => array( self::cell( (string) ( $a['id'] ?? '' ), true ), self::cell( (string) ( $a['title'] ?? '' ), false, $sub, true ), self::cell( $t( 'statuses', $a['status'] ?? '' ) ), self::cell( ( $a['displayed_at'] ?? '' ) . ' ' . ( $document['period']['timezone'] ?? '' ), true ) ) );
		}
		return array( 'title' => $p['labels']['activities'], 'blocks' => array( self::table( array( '#', $t( 'ui', 'details' ), $t( 'ui', 'status' ), $t( 'ui', 'date' ) ), $rows, 'activities' ) ) );
	}

	private static function checklist( $document, $p, $t, $w ) {
		$checks = is_array( $document['checklist'] ?? null ) ? $document['checklist'] : array();
		if ( ! $checks ) { return array( 'title' => $p['labels']['checklist'], 'blocks' => array( self::text( $t( 'ui', 'no_checklist' ) ) ) ); }
		$rows = array();
		foreach ( $checks as $c ) {
			$description = trim( (string) ( $c['description'] ?? '' ) );
			$rows[] = array( 'cells' => array( self::cell( (string) ( $c['label'] ?? '' ), false, '' === $description ? array() : array( $description ), true ), self::cell( $t( 'statuses', $c['status'] ?? '' ) ), self::cell( '' === trim( (string) ( $c['note'] ?? '' ) ) ? '—' : (string) $c['note'] ) ), 'mark' => 'completed' !== ( $c['status'] ?? '' ) && 'not_applicable' !== ( $c['status'] ?? '' ) );
		}
		return array( 'title' => $p['labels']['checklist'], 'blocks' => array( self::table( array( $w['check'], $t( 'ui', 'status' ), $w['note'] ), $rows, 'checklist' ) ) );
	}

	/** Paired Before/After table when both snapshots have the fixed structure; otherwise each snapshot on its own. */
	private static function states( $document, $p, $t, $w ) {
		$sides = array();
		foreach ( array( 'start_state' => $w['before'], 'end_state' => $w['after'] ) as $role => $label ) {
			if ( ! empty( $document[ $role ] ) && is_array( $document[ $role ] ) ) { $sides[] = array( 'role' => $role, 'label' => $label, 'state' => $document[ $role ], 'ok' => self::shaped( $document[ $role ] ) ); }
		}
		if ( ! $sides ) { return array( array( 'title' => $w['site_state_single'], 'blocks' => array( self::text( $w['no_state'] ) ) ) ); }
		$paired = 2 === count( $sides ) && $sides[0]['ok'] && $sides[1]['ok'];
		$sections = array();
		$warnings = array();
		foreach ( $sides as $side ) {
			$prefix = 2 === count( $sides ) ? $side['label'] . ': ' : '';
			if ( in_array( $side['state']['relation'] ?? '', array( 'late_after_start', 'early_before_end' ), true ) ) { $warnings[] = array( 'type' => 'warning', 'text' => $prefix . $t( 'ui', 'approximation' ) ); }
			if ( ! empty( $side['state']['presentation']['integrity_warning'] ) ) { $warnings[] = array( 'type' => 'warning', 'text' => $prefix . $t( 'ui', 'integrity_warning' ) ); }
		}
		if ( $paired ) {
			$sections[] = array( 'title' => $w['site_state'], 'blocks' => array_merge( $warnings, array( self::facts_table( $sides, $p, $t, $w ), array( 'type' => 'note', 'text' => $w['differs_note'] ) ) ) );
		} else {
			$first = true;
			foreach ( $sides as $side ) {
				$blocks = $first ? $warnings : array();
				$first = false;
				if ( $side['ok'] ) { $blocks[] = self::facts_table( array( $side ), $p, $t, $w ); }
				else { $blocks[] = array( 'type' => 'note', 'text' => $w['fallback'] ); foreach ( self::full_listing( $side['state'], $p, $t ) as $block ) { $blocks[] = $block; } }
				$sections[] = array( 'title' => $p['labels'][ $side['role'] ], 'blocks' => $blocks );
			}
		}
		// A snapshot shown through the full fallback listing already includes its lists.
		$listed = array_values( array_filter( $sides, static function ( $side ) { return $side['ok']; } ) );
		$inventory = $listed ? self::inventories( $listed, ! empty( $document['comparison']['groups'] ), $p, $w ) : array();
		if ( $inventory ) { $sections[] = array( 'title' => $w['inventories'], 'blocks' => $inventory ); }
		return $sections;
	}

	private static function shaped( $state ) {
		$view = $state['presentation'] ?? null;
		if ( ! is_array( $view ) ) { return false; }
		foreach ( self::SHAPE as $section => $count ) {
			if ( ! isset( $view[ $section ] ) || ! is_array( $view[ $section ] ) || count( $view[ $section ] ) !== $count ) { return false; }
			foreach ( $view[ $section ] as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['label'] ) || ! array_key_exists( 'value', $item ) || ! ( null === $item['value'] || is_scalar( $item['value'] ) ) ) { return false; }
			}
		}
		foreach ( array( 0, 1 ) as $index ) {
			if ( ! array_key_exists( 'detail', $view['overview'][ $index ] ) || ! ( null === $view['overview'][ $index ]['detail'] || is_scalar( $view['overview'][ $index ]['detail'] ) ) ) { return false; }
		}
		return true;
	}

	/** One row per fact; a duplicated source is shown once and only split out when its stored value differs. */
	private static function facts_table( $sides, $p, $t, $w ) {
		$paired = 2 === count( $sides );
		$groups = array(
			array( $t( 'ui', 'snapshot' ), 'provenance' ),
			array( $p['labels']['site'], array(
				array( 'site_name', array( 'site', 0 ) ), array( 'site_url', array( 'site', 1 ), true ), array( 'home_url', array( 'site', 2 ), true ),
				array( 'language', array( 'site', 3 ), true ), array( 'timezone', array( 'site', 4 ), true ),
				array( 'https', array( 'site', 5 ), false, array( array( 'environment', 4 ) ) ), array( 'multisite', array( 'site', 6 ) ),
			) ),
			array( $p['labels']['environment'], array(
				array( 'wordpress', array( 'overview', 0 ), true, array( array( 'environment', 0 ) ) ),
				array( 'core_update', array( 'overview', 0, 'detail' ), false, array( array( 'updates', 0 ) ) ),
				array( 'php', array( 'environment', 1 ), true ), array( 'database', array( 'environment', 2 ), true ), array( 'web_server', array( 'environment', 3 ), true ),
				array( 'wp_memory', array( 'environment', 5 ), true ), array( 'php_memory', array( 'environment', 6 ), true ),
				array( 'wp_debug', array( 'environment', 7 ) ), array( 'wp_debug_log', array( 'environment', 8 ) ), array( 'wp_debug_display', array( 'environment', 9 ) ),
			) ),
			array( $p['labels']['updates'], array(
				array( 'plugins_installed', array( 'overview', 1 ), false, array(), array( 'overview', 1, 'detail' ) ), array( 'themes_installed', array( 'overview', 2 ) ),
				array( 'plugin_updates', array( 'updates', 1 ) ), array( 'theme_updates', array( 'updates', 2 ) ),
				array( 'total_pending', array( 'updates', 3 ), false, array( array( 'overview', 3 ) ) ), array( 'update_evidence', array( 'technical', 6 ) ),
			) ),
			array( $p['labels']['content'], array( array( 'posts', array( 'content', 0 ) ), array( 'pages', array( 'content', 1 ) ), array( 'comments', array( 'content', 2 ) ), array( 'users', array( 'content', 3 ) ) ) ),
			array( $w['health'], 'health' ),
		);
		$compare = $paired ? $w['differs'] : null;
		$provenance = $paired ? '' : null;
		$rows = array();
		foreach ( $groups as $group ) {
			$rows[] = array( 'group' => $group[0] );
			if ( 'provenance' === $group[1] ) {
				$rows[] = self::row( $t( 'ui', 'snapshot' ), self::values( $sides, static function ( $s ) { return '#' . (int) ( $s['id'] ?? 0 ); } ), true, $provenance );
				$rows[] = self::row( $w['snapshot_type'], self::values( $sides, static function ( $s ) use ( $w ) { return $w['snapshot_types'][ $s['type'] ?? '' ] ?? (string) ( $s['type'] ?? '' ); } ), false, $provenance );
				$rows[] = self::row( $w['captured_utc'], self::values( $sides, static function ( $s ) { return ( $s['completed_at'] ?? '' ) . ' UTC'; } ), true, $provenance );
				$rows[] = self::row( $w['timing'], self::values( $sides, static function ( $s ) use ( $t ) { return $t( 'relations', $s['relation'] ?? '' ); } ), false, $provenance );
				continue;
			}
			if ( 'health' === $group[1] ) {
				$rows[] = self::row( $t( 'ui', 'health_coverage' ), self::values( $sides, static function ( $s ) use ( $w ) { $code = (string) ( $s['presentation']['health_coverage'] ?? 'unknown' ); return $w['coverage'][ $code ] ?? $code; } ), false, $compare );
				$rows[] = self::row( $w['critical'], self::values( $sides, static function ( $s ) { return $s['presentation']['health_critical'] ?? null; } ), true, $compare );
				$rows[] = self::row( $w['recommended'], self::values( $sides, static function ( $s ) { return $s['presentation']['health_recommended'] ?? null; } ), true, $compare );
				continue;
			}
			foreach ( $group[1] as $fact ) {
				$path = $fact[1];
				$ltr = ! empty( $fact[2] );
				$values = self::values( $sides, static function ( $s ) use ( $path ) { return FixReport_Report_Layout::at( $s, $path ); } );
				$sub = array();
				if ( ! empty( $fact[4] ) ) {
					$detail_path = $fact[4];
					$sub = self::values( $sides, static function ( $s ) use ( $detail_path ) { return FixReport_Report_Layout::at( $s, $detail_path ); } );
				}
				$rows[] = self::row( $w[ $fact[0] ], $values, $ltr, $compare, $sub );
				foreach ( $fact[3] ?? array() as $duplicate ) {
					$dup = self::values( $sides, static function ( $s ) use ( $duplicate ) { return FixReport_Report_Layout::at( $s, $duplicate ); } );
					if ( $dup !== $values ) { $rows[] = self::row( $w[ $fact[0] ] . ' — ' . $p['labels'][ $duplicate[0] ], $dup, $ltr, $compare ); }
				}
			}
		}
		$head = array( $w['item'] );
		foreach ( $sides as $side ) { $head[] = $paired ? $side['label'] : $w['value']; }
		if ( $paired ) { $head[] = $w['difference']; }
		return self::table( $head, $rows, $paired ? 'facts paired' : 'facts' );
	}

	/** Stored presentation value at [section, index, field]; null stays null. */
	public static function at( $state, $path ) {
		$item = $state['presentation'][ $path[0] ][ $path[1] ] ?? null;
		$value = is_array( $item ) ? ( $item[ $path[2] ?? 'value' ] ?? null ) : null;
		return null === $value ? null : (string) $value;
	}

	private static function values( $sides, $reader ) {
		$values = array();
		foreach ( $sides as $side ) { $values[] = $reader( $side['state'] ); }
		return $values;
	}

	/**
	 * $difference: null = no Difference column; '' = empty Difference cell (row not compared);
	 * any other string = compare both values and show that word when they differ.
	 */
	private static function row( $label, $values, $ltr, $difference, $sub = array() ) {
		$cells = array( self::cell( $label ) );
		foreach ( $values as $index => $value ) {
			$cells[] = self::cell( null === $value || '' === $value ? '—' : (string) $value, $ltr, isset( $sub[ $index ] ) && null !== $sub[ $index ] && '' !== $sub[ $index ] ? array( (string) $sub[ $index ] ) : array() );
		}
		$differs = is_string( $difference ) && '' !== $difference && 2 === count( $values ) && ( $values[0] !== $values[1] || ( $sub && $sub[0] !== $sub[1] ) );
		if ( null !== $difference ) { $cells[] = self::cell( $differs ? $difference : '' ); }
		return array( 'cells' => $cells, 'mark' => $differs );
	}

	/**
	 * Plugins and themes are never matched by display name. With an included
	 * comparison they come from its stable plugin/theme keys (see comparison());
	 * otherwise each snapshot keeps its own compact list. Exactly identical lists
	 * are shown once.
	 */
	private static function inventories( $sides, $has_comparison, $p, $w ) {
		$columns = array(
			'plugins' => array( 'name', 'version', 'status', 'update' ),
			'themes' => array( 'name', 'version', 'status', 'update', 'parent' ),
			'content_types' => array( 'name', 'count' ),
			'health_items' => array( 'name', 'severity', 'evidence' ),
		);
		$blocks = array();
		foreach ( $columns as $kind => $keys ) {
			if ( $has_comparison && in_array( $kind, array( 'plugins', 'themes' ), true ) ) { continue; }
			$lists = array();
			foreach ( $sides as $side ) {
				$list = $side['state']['presentation'][ $kind ] ?? array();
				$lists[] = array( 'label' => $side['label'], 'items' => is_array( $list ) ? $list : array() );
			}
			$all_empty = true;
			foreach ( $lists as $list ) { if ( $list['items'] ) { $all_empty = false; } }
			if ( $all_empty && in_array( $kind, array( 'content_types', 'health_items' ), true ) ) { continue; }
			$head = array();
			foreach ( $keys as $key ) { $head[] = $w['columns'][ $key ]; }
			if ( 2 === count( $lists ) && $lists[0]['items'] === $lists[1]['items'] ) {
				$blocks[] = array( 'type' => 'heading', 'text' => $p['labels'][ $kind ] );
				$blocks[] = array( 'type' => 'note', 'text' => $w['identical'] );
				$blocks[] = $lists[0]['items'] ? self::list_table( $head, $keys, $lists[0]['items'], $w ) : array( 'type' => 'note', 'text' => $w['none_captured'] );
				continue;
			}
			foreach ( $lists as $list ) {
				$blocks[] = array( 'type' => 'heading', 'text' => 1 === count( $lists ) ? $p['labels'][ $kind ] : $p['labels'][ $kind ] . ' — ' . $list['label'] );
				$blocks[] = $list['items'] ? self::list_table( $head, $keys, $list['items'], $w ) : array( 'type' => 'note', 'text' => $w['none_captured'] );
			}
		}
		return $blocks;
	}

	private static function list_table( $head, $keys, $items, $w ) {
		$rows = array();
		foreach ( $items as $item ) {
			$cells = array();
			foreach ( $keys as $key ) {
				$value = is_array( $item ) ? ( $item[ $key ] ?? null ) : null;
				if ( 'severity' === $key && is_string( $value ) ) { $value = $w['severity'][ $value ] ?? $value; }
				$cells[] = self::cell( null === $value || '' === $value || ! is_scalar( $value ) ? '—' : (string) $value, in_array( $key, array( 'version', 'count' ), true ) );
			}
			$rows[] = array( 'cells' => $cells );
		}
		return self::table( $head, $rows, 'inventory' );
	}

	/** Derived comparison: inventory groups in full (stable keys); other groups list only changed or incomparable entries. */
	private static function comparison( $comparison, $p, $t, $w ) {
		$rows = array();
		foreach ( array( 'core', 'environment', 'plugins', 'themes', 'content', 'health' ) as $key ) {
			$group = $comparison['groups'][ $key ] ?? null;
			if ( ! is_array( $group ) ) { continue; }
			$code = (string) ( $group['comparability'] ?? '' );
			$rows[] = array( 'group' => ( $w['groups'][ $key ] ?? (string) ( $group['name'] ?? $key ) ) . ' · ' . $w['comparability'] . ': ' . ( $w['comparabilities'][ $code ] ?? $code ) );
			$inventory = in_array( $key, array( 'plugins', 'themes' ), true );
			foreach ( $inventory ? array( 'changes', 'incomparable', 'unchanged', 'technical' ) : array( 'changes', 'incomparable', 'technical' ) as $bucket ) {
				foreach ( is_array( $group[ $bucket ] ?? null ) ? $group[ $bucket ] : array() as $entry ) {
					$details = array();
					foreach ( is_array( $entry['details'] ?? null ) ? $entry['details'] : array() as $detail ) { $details[] = (string) $detail; }
					$rows[] = array( 'cells' => array( self::cell( (string) ( $entry['title'] ?? '' ), $inventory, $details ), self::cell( (string) ( $entry['state'] ?? '' ) ), self::cell( (string) ( $entry['before'] ?? '' ) ), self::cell( (string) ( $entry['after'] ?? '' ) ) ), 'mark' => 'unchanged' !== $bucket );
				}
			}
			$unchanged = is_array( $group['unchanged'] ?? null ) ? count( $group['unchanged'] ) : 0;
			if ( ! $inventory && $unchanged ) { $rows[] = array( 'cells' => array( self::cell( sprintf( $w['unchanged_count'], $unchanged ) ), self::cell( '' ), self::cell( '' ), self::cell( '' ) ) ); }
		}
		return array( 'title' => $p['labels']['comparison'], 'blocks' => array( array( 'type' => 'note', 'text' => $t( 'ui', 'comparison_caveat' ) ), self::table( array( $w['item'], $w['result'], $w['before'], $w['after'] ), $rows, 'comparison' ) ) );
	}

	/** Fallback for a snapshot whose stored structure is not the expected one: every stored row, without internal field names. */
	private static function full_listing( $state, $p, $t ) {
		$view = is_array( $state['presentation'] ?? null ) ? $state['presentation'] : array();
		$blocks = array( array( 'type' => 'note', 'text' => $t( 'ui', 'snapshot' ) . ' #' . (int) ( $state['id'] ?? 0 ) . ' · ' . $t( 'ui', 'captured' ) . ' ' . ( $state['completed_at'] ?? '' ) . ' UTC · ' . $t( 'relations', $state['relation'] ?? '' ) ) );
		foreach ( array( 'overview', 'site', 'environment', 'updates', 'content', 'plugins', 'themes', 'content_types', 'health_items', 'technical' ) as $section ) {
			if ( empty( $view[ $section ] ) || ! is_array( $view[ $section ] ) ) { continue; }
			$rows = array();
			foreach ( $view[ $section ] as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$parts = array();
				foreach ( $item as $key => $value ) {
					if ( in_array( $key, array( 'label', 'name', 'url' ), true ) || is_array( $value ) ) { continue; }
					$parts[] = null === $value ? '—' : (string) $value;
				}
				$rows[] = array( 'cells' => array( self::cell( (string) ( $item['label'] ?? $item['name'] ?? $t( 'ui', 'evidence' ) ) ), self::cell( implode( ' · ', $parts ) ) ) );
			}
			$blocks[] = array( 'type' => 'heading', 'text' => $p['labels'][ $section ] ?? $section );
			$blocks[] = self::table( array(), $rows, 'listing' );
		}
		return $blocks;
	}

	private static function evidence( $document, $draft, $p, $t, $w ) {
		$blocks = array();
		$sides = array();
		foreach ( array( 'start_state' => $w['before'], 'end_state' => $w['after'] ) as $role => $label ) {
			if ( ! empty( $document[ $role ] ) && is_array( $document[ $role ] ) && self::shaped( $document[ $role ] ) ) { $sides[] = array( 'label' => $label, 'state' => $document[ $role ] ); }
		}
		if ( $sides ) {
			$rows = array();
			$read = static function ( $index ) { return static function ( $s ) use ( $index ) { return FixReport_Report_Layout::at( $s, array( 'technical', $index ) ); }; };
			$rows[] = self::row( $w['generation_status'], self::values( $sides, $read( 2 ) ), false, null );
			$rows[] = self::row( $w['collection_contract'], self::values( $sides, $read( 4 ) ), true, null );
			$rows[] = self::row( $w['health_policy'], self::values( $sides, $read( 5 ) ), true, null );
			$stored_ids = self::values( $sides, $read( 0 ) );
			$ids = self::values( $sides, static function ( $s ) { return (string) (int) ( $s['id'] ?? 0 ); } );
			if ( $stored_ids !== $ids ) { $rows[] = self::row( $p['labels']['technical'] . ' — ' . $t( 'ui', 'snapshot' ), $stored_ids, true, null ); }
			$head = array( $w['item'] );
			foreach ( $sides as $side ) { $head[] = 2 === count( $sides ) ? $side['label'] : $w['value']; }
			$blocks[] = array( 'type' => 'heading', 'text' => $p['labels']['technical'] );
			$blocks[] = self::table( $head, $rows, 'facts' );
		}
		$blocks[] = array( 'type' => 'note', 'text' => $t( 'ui', 'health_caveat' ) );
		if ( ! empty( $document['coverage']['legacy_clock_present'] ) ) { $blocks[] = array( 'type' => 'note', 'text' => $t( 'ui', 'legacy_clock' ) ); }
		$blocks[] = array( 'type' => 'note', 'text' => sprintf( $w['document'], (string) ( $document['contract_version'] ?? '' ), (string) ( $document['locale'] ?? '' ), self::COMPACT ) );
		if ( ! $draft && ! empty( $document['finalization'] ) ) {
			$blocks[] = array( 'type' => 'note', 'text' => $t( 'ui', 'finalized' ) . ' ' . $document['finalization']['at_utc'] . ' UTC ' . $t( 'ui', 'by_user' ) . ' #' . $document['finalization']['by_user_id'] . '. ' . $t( 'ui', 'frozen_caveat' ) );
		}
		return array( 'title' => $w['evidence'], 'blocks' => $blocks );
	}

	private static function text( $value ) {
		return array( 'type' => 'text', 'text' => '' === trim( (string) $value ) ? '—' : (string) $value );
	}

	private static function table( $head, $rows, $class ) {
		return array( 'type' => 'table', 'head' => $head, 'rows' => $rows, 'class' => $class );
	}

	private static function cell( $text, $ltr = false, $sub = array(), $strong = false ) {
		return array( 'text' => (string) $text, 'ltr' => (bool) $ltr, 'sub' => $sub, 'strong' => (bool) $strong );
	}

	/** Fixed layout wording in the report language (independent of the dashboard locale). */
	private static function words( $locale ) {
		$english = array(
			'type' => 'Type', 'site_state' => 'Site State: Before / After', 'site_state_single' => 'Site State', 'inventories' => 'Captured Inventories',
			'before' => 'Before', 'after' => 'After', 'value' => 'Value', 'item' => 'Item', 'difference' => 'Difference', 'differs' => 'Differs',
			'differs_note' => '"Differs" marks a value recorded differently in the two snapshots. It does not state a cause.',
			'fallback' => 'The stored details of this snapshot could not be arranged in the compact table, so they are listed in full.',
			'no_state' => 'No site snapshot is included in this report.',
			'snapshot_type' => 'Snapshot type', 'captured_utc' => 'Captured (UTC)', 'timing' => 'Position in period',
			'snapshot_types' => array( 'initial' => 'Initial Baseline', 'manual' => 'Manual snapshot', 'checkpoint' => 'Automatic checkpoint' ),
			'site_name' => 'Site name', 'site_url' => 'Site URL', 'home_url' => 'Home URL', 'language' => 'Site language', 'timezone' => 'Site time zone',
			'https' => 'HTTPS', 'multisite' => 'Multisite', 'wordpress' => 'WordPress version', 'core_update' => 'WordPress core update',
			'php' => 'PHP version', 'database' => 'Database', 'web_server' => 'Web server', 'wp_memory' => 'WordPress memory limit', 'php_memory' => 'PHP memory limit',
			'wp_debug' => 'WP_DEBUG', 'wp_debug_log' => 'WP_DEBUG_LOG', 'wp_debug_display' => 'WP_DEBUG_DISPLAY',
			'plugins_installed' => 'Plugins installed', 'themes_installed' => 'Themes installed', 'plugin_updates' => 'Plugin updates pending',
			'theme_updates' => 'Theme updates pending', 'total_pending' => 'Total pending updates', 'update_evidence' => 'Update evidence',
			'posts' => 'Published posts', 'pages' => 'Published pages', 'comments' => 'Comments', 'users' => 'Users',
			'health' => 'Maintenance Health', 'critical' => 'Critical findings', 'recommended' => 'Recommended findings',
			'coverage' => array( 'complete' => 'Complete', 'incomplete_update_evidence' => 'Incomplete (update evidence unknown)', 'unknown' => 'Unknown' ),
			'columns' => array( 'name' => 'Name', 'version' => 'Version', 'status' => 'Status', 'update' => 'Update', 'parent' => 'Parent theme', 'count' => 'Published', 'severity' => 'Severity', 'evidence' => 'Evidence' ),
			'severity' => array( 'critical' => 'Critical', 'recommended' => 'Recommended', 'unknown' => 'Unknown' ),
			'identical' => 'Identical in both snapshots.', 'none_captured' => 'None captured.',
			'check' => 'Check', 'note' => 'Note', 'result' => 'Result', 'comparability' => 'Comparability',
			'comparabilities' => array( 'complete' => 'Complete', 'partial' => 'Partial', 'incomparable' => 'Incomparable' ),
			'groups' => array( 'core' => 'WordPress Core', 'environment' => 'Environment', 'plugins' => 'Plugins', 'themes' => 'Themes', 'content' => 'Content Summary', 'health' => 'Maintenance Health' ),
			/* 1: count. */
			'unchanged_count' => '%d unchanged item(s); values are shown in the Site State table.',
			'evidence' => 'Evidence & Limitations', 'generation_status' => 'Snapshot status', 'collection_contract' => 'Collection contract', 'health_policy' => 'Health policy',
			/* 1: document contract, 2: report locale, 3: layout version. */
			'document' => 'Document contract %1$s · report language %2$s · layout %3$s.',
		);
		if ( 'ar' !== $locale ) { return $english; }
		return array(
			'type' => 'النوع', 'site_state' => 'حالة الموقع: قبل / بعد', 'site_state_single' => 'حالة الموقع', 'inventories' => 'القوائم الملتقطة',
			'before' => 'قبل', 'after' => 'بعد', 'value' => 'القيمة', 'item' => 'البند', 'difference' => 'الفرق', 'differs' => 'مختلف',
			'differs_note' => '«مختلف» يعني أن القيمة سُجلت بشكل مختلف في اللقطتين، ولا يحدد سبب الاختلاف.',
			'fallback' => 'تعذر ترتيب التفاصيل المخزنة لهذه اللقطة في الجدول المختصر، لذا تُعرض كاملة.',
			'no_state' => 'لا يتضمن هذا التقرير أي لقطة للموقع.',
			'snapshot_type' => 'نوع اللقطة', 'captured_utc' => 'وقت الالتقاط (UTC)', 'timing' => 'موقعها من الفترة',
			'snapshot_types' => array( 'initial' => 'خط الأساس الأولي', 'manual' => 'لقطة يدوية', 'checkpoint' => 'نقطة تحقق تلقائية' ),
			'site_name' => 'اسم الموقع', 'site_url' => 'رابط الموقع', 'home_url' => 'رابط الصفحة الرئيسية', 'language' => 'لغة الموقع', 'timezone' => 'المنطقة الزمنية للموقع',
			'https' => 'HTTPS', 'multisite' => 'شبكة مواقع متعددة', 'wordpress' => 'إصدار ووردبريس', 'core_update' => 'تحديث نواة ووردبريس',
			'php' => 'إصدار PHP', 'database' => 'قاعدة البيانات', 'web_server' => 'خادم الويب', 'wp_memory' => 'حد ذاكرة ووردبريس', 'php_memory' => 'حد ذاكرة PHP',
			'wp_debug' => 'WP_DEBUG', 'wp_debug_log' => 'WP_DEBUG_LOG', 'wp_debug_display' => 'WP_DEBUG_DISPLAY',
			'plugins_installed' => 'الإضافات المثبتة', 'themes_installed' => 'القوالب المثبتة', 'plugin_updates' => 'تحديثات الإضافات المعلقة',
			'theme_updates' => 'تحديثات القوالب المعلقة', 'total_pending' => 'إجمالي التحديثات المعلقة', 'update_evidence' => 'أدلة التحديثات',
			'posts' => 'المقالات المنشورة', 'pages' => 'الصفحات المنشورة', 'comments' => 'التعليقات', 'users' => 'المستخدمون',
			'health' => 'صحة الصيانة', 'critical' => 'نتائج حرجة', 'recommended' => 'نتائج موصى بها',
			'coverage' => array( 'complete' => 'مكتمل', 'incomplete_update_evidence' => 'غير مكتمل (أدلة التحديثات غير معروفة)', 'unknown' => 'غير معروف' ),
			'columns' => array( 'name' => 'الاسم', 'version' => 'الإصدار', 'status' => 'الحالة', 'update' => 'التحديث', 'parent' => 'القالب الأب', 'count' => 'المنشور', 'severity' => 'الخطورة', 'evidence' => 'الدليل' ),
			'severity' => array( 'critical' => 'حرجة', 'recommended' => 'موصى بها', 'unknown' => 'غير معروف' ),
			'identical' => 'متطابقة في اللقطتين.', 'none_captured' => 'لم يُلتقط أي عنصر.',
			'check' => 'بند الفحص', 'note' => 'ملاحظة', 'result' => 'النتيجة', 'comparability' => 'قابلية المقارنة',
			'comparabilities' => array( 'complete' => 'كاملة', 'partial' => 'جزئية', 'incomparable' => 'غير قابلة للمقارنة' ),
			'groups' => array( 'core' => 'نواة ووردبريس', 'environment' => 'بيئة التشغيل', 'plugins' => 'الإضافات', 'themes' => 'القوالب', 'content' => 'ملخص المحتوى', 'health' => 'صحة الصيانة' ),
			'unchanged_count' => '%d بند دون تغيير؛ القيم معروضة في جدول حالة الموقع.',
			'evidence' => 'الأدلة والقيود', 'generation_status' => 'حالة اللقطة', 'collection_contract' => 'إصدار عقد الجمع', 'health_policy' => 'سياسة فحص الصحة',
			'document' => 'إصدار المستند %1$s · لغة التقرير %2$s · التخطيط %3$s.',
		);
	}
}
