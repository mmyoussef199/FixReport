<?php
/** Draft editing and single-transition immutable Report finalization. */
class FixReport_Report_Service {
	private $wpdb;
	private $reports;
	private $links;
	private $checks;
	private $activities;
	private $snapshots;
	private $branding;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->reports = $wpdb->prefix . 'fixreport_reports';
		$this->links = $wpdb->prefix . 'fixreport_report_activities';
		$this->checks = $wpdb->prefix . 'fixreport_report_checklist';
		$this->activities = $wpdb->prefix . 'fixreport_activities';
		$this->snapshots = $wpdb->prefix . 'fixreport_snapshots';
		$this->branding = new FixReport_Branding();
	}

	public function get( $id ) {
		return $id > 0 ? $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->reports} WHERE report_id = %d", $id ) ) : null;
	}

	/** One bounded page of report rows; report_id breaks created_at ties so pages never overlap or skip. */
	public function list_reports( $limit = 100, $offset = 0 ) {
		$limit = max( 1, min( 100, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT report_id, report_type, status, title, period_start, period_end, period_timezone, created_at, updated_at, finalized_at FROM {$this->reports} ORDER BY created_at DESC, report_id DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** A supported Draft for the Dashboard shortcut; no report is created or changed. */
	public function latest_draft_for_dashboard() {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( "SELECT report_id, title FROM {$this->reports} WHERE status = 'draft' AND report_type IN ('maintenance', 'initial_assessment') ORDER BY updated_at DESC, report_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' !== $this->wpdb->last_error ? new WP_Error( 'report_read_failed' ) : $row;
	}

	/** Read-only totals over every stored report (the list itself shows at most 100 rows). */
	public function status_counts() {
		$counts = array( 'total' => 0, 'draft' => 0, 'finalized' => 0 );
		$rows = $this->wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->reports} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts['total'] += (int) $row->total;
			if ( isset( $counts[ $row->status ] ) ) { $counts[ $row->status ] += (int) $row->total; }
		}
		return $counts;
	}

	public function checklist( $id ) {
		return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->checks} WHERE report_id = %d ORDER BY display_order ASC, checklist_item_id ASC", $id ) );
	}

	public function linked_activities( $id, $lock = false ) {
		$sql = $this->wpdb->prepare( "SELECT ra.activity_id AS linked_activity_id, a.* FROM {$this->links} ra LEFT JOIN {$this->activities} a ON a.activity_id = ra.activity_id WHERE ra.report_id = %d ORDER BY ra.display_order ASC, ra.report_activity_id ASC", $id );
		return $this->wpdb->get_results( $sql . ( $lock ? ' FOR UPDATE' : '' ) );
	}

	public function all_snapshot_options() {
		return $this->wpdb->get_results( "SELECT snapshot_id, snapshot_type, completed_at FROM {$this->snapshots} WHERE generation_status = 'complete' AND snapshot_type IN ('initial','manual','checkpoint') ORDER BY completed_at ASC, snapshot_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function comparison_eligible( $start_id, $end_id ) {
		if ( ! $start_id || ! $end_id || $start_id === $end_id ) { return false; }
		$repository = new FixReport_Snapshot_Repository();
		$a = $repository->get_completed_snapshot( $start_id );
		$b = $repository->get_completed_snapshot( $end_id );
		if ( is_wp_error( $a ) || is_wp_error( $b ) || ! $a || ! $b ) { return false; }
		$normalizer = new FixReport_Comparison_Normalizer();
		$left = $normalizer->normalize( $a, $repository->get_snapshot_items( $start_id ) );
		$right = $normalizer->normalize( $b, $repository->get_snapshot_items( $end_id ) );
		return ! is_wp_error( $left ) && ! is_wp_error( $right ) && ! is_wp_error( ( new FixReport_Comparison_Engine() )->compare( $left, $right ) );
	}

	/** The Report range is UTC; legacy rows keep their original local clock. */
	public function candidates( $report ) {
		if ( ! $report || 'maintenance' !== $report->report_type ) {
			return array();
		}
		try {
			$zone = new DateTimeZone( $report->period_timezone );
			$local_start = ( new DateTimeImmutable( $report->period_start, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			$local_end = ( new DateTimeImmutable( $report->period_end, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
		} catch ( Throwable $error ) {
			return new WP_Error( 'report_period_invalid' );
		}
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->activities} WHERE deleted_at IS NULL AND client_visible = 1 AND source IN ('manual','automatic') AND ((legacy_source IS NULL AND activity_date >= %s AND activity_date < %s) OR (legacy_source IS NOT NULL AND activity_date >= %s AND activity_date < %s)) ORDER BY activity_id ASC LIMIT 1001", $report->period_start, $report->period_end, $local_start, $local_end ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1000 ) {
			return new WP_Error( 'report_activity_limit' );
		}
		usort( $rows, static function ( $a, $b ) use ( $zone ) {
			$ta = $a->legacy_source ? $a->activity_date : ( new DateTimeImmutable( $a->activity_date, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			$tb = $b->legacy_source ? $b->activity_date : ( new DateTimeImmutable( $b->activity_date, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			return strcmp( $ta, $tb ) ?: (int) $a->activity_id <=> (int) $b->activity_id;
		} );
		return $rows;
	}

	private function suggested_snapshot( $boundary, $side, $period_start, $period_end ) {
		$types = "snapshot_type IN ('initial','manual','checkpoint') AND generation_status = 'complete'";
		if ( 'start' === $side ) {
			$id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT snapshot_id FROM {$this->snapshots} WHERE {$types} AND completed_at <= %s ORDER BY completed_at DESC, snapshot_id DESC LIMIT 1", $boundary ) );
			return $id ? (int) $id : (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT snapshot_id FROM {$this->snapshots} WHERE {$types} AND completed_at > %s AND completed_at < %s ORDER BY completed_at ASC, snapshot_id ASC LIMIT 1", $period_start, $period_end ) );
		}
		$id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT snapshot_id FROM {$this->snapshots} WHERE {$types} AND completed_at >= %s ORDER BY completed_at ASC, snapshot_id ASC LIMIT 1", $boundary ) );
		return $id ? (int) $id : (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT snapshot_id FROM {$this->snapshots} WHERE {$types} AND completed_at >= %s AND completed_at < %s ORDER BY completed_at DESC, snapshot_id DESC LIMIT 1", $period_start, $period_end ) );
	}

	public function create_maintenance( $title, $from, $through, $locale = 'en_US' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'report_forbidden' );
		}
		$title = is_string( $title ) ? sanitize_text_field( wp_unslash( $title ) ) : '';
		if ( '' === $title || strlen( $title ) > 255 || ! FixReport_Report_Language::valid( $locale ) || ! is_string( $from ) || ! is_string( $through ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $through ) || $from > $through ) {
			return new WP_Error( 'report_input_invalid' );
		}
		$zone = wp_timezone();
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $zone );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', $through, $zone );
		if ( ! $a || ! $b || $a->format( 'Y-m-d' ) !== $from || $b->format( 'Y-m-d' ) !== $through ) {
			return new WP_Error( 'report_input_invalid' );
		}
		$start = $a->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end = $b->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$draft = (object) array( 'report_type' => 'maintenance', 'period_start' => $start, 'period_end' => $end, 'period_timezone' => $zone->getName() );
		$candidates = $this->candidates( $draft );
		$template_service = new FixReport_Checklist_Template();
		if ( ! $template_service->initialize() ) { return new WP_Error( 'checklist_save_failed' ); }
		$template = $template_service->get();
		if ( is_wp_error( $candidates ) || is_wp_error( $template ) ) {
			return is_wp_error( $candidates ) ? $candidates : $template;
		}
		$start_id = $this->suggested_snapshot( $start, 'start', $start, $end );
		$end_id = $this->suggested_snapshot( $end, 'end', $start, $end );
		if ( $start_id && $start_id === $end_id ) {
			$end_id = 0;
		}
		$began = false;
		try {
			$began = false !== $this->wpdb->query( 'START TRANSACTION' );
			if ( ! $began ) { return new WP_Error( 'report_persistence_failed' ); }
			$ok = $this->wpdb->insert( $this->reports, array( 'report_type' => 'maintenance', 'status' => 'draft', 'title' => $title, 'report_locale' => $locale, 'period_timezone' => $zone->getName(), 'period_start' => $start, 'period_end' => $end, 'start_snapshot_id' => $start_id ?: null, 'end_snapshot_id' => $end_id ?: null, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ), array( '%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s' ) );
			if ( false === $ok ) { return new WP_Error( 'report_persistence_failed' ); }
			$id = (int) $this->wpdb->insert_id;
			foreach ( $candidates as $order => $activity ) {
				if ( false === $this->wpdb->insert( $this->links, array( 'report_id' => $id, 'activity_id' => $activity->activity_id, 'display_order' => $order ), array( '%d','%d','%d' ) ) ) { return new WP_Error( 'report_persistence_failed' ); }
			}
			foreach ( $template['items'] as $item ) {
				$resolved = FixReport_Checklist_Template::resolved( $item );
				if ( false === $this->wpdb->insert( $this->checks, array( 'report_id' => $id, 'check_key' => $resolved['id'], 'displayed_label' => $resolved['label'], 'displayed_description' => $resolved['description'], 'included' => 1, 'status' => 'pending', 'display_order' => $resolved['sort_order'] ), array( '%d','%s','%s','%s','%d','%s','%d' ) ) ) { return new WP_Error( 'report_persistence_failed' ); }
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'report_persistence_failed' ); }
			$began = false;
			return $id;
		} finally {
			if ( $began ) { $this->wpdb->query( 'ROLLBACK' ); }
		}
	}

	/** One insert guarded by a unique nullable key; safe on an existing installation. */
	public function ensure_initial_draft() {
		if ( FIXREPORT_DB_VERSION !== get_option( FixReport_Activator::SCHEMA_VERSION_OPTION ) ) {
			return new WP_Error( 'report_schema_unavailable' );
		}
		$existing = $this->wpdb->get_var( "SELECT report_id FROM {$this->reports} WHERE report_type = 'initial_assessment' ORDER BY report_id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $existing ) { return (int) $existing; }
		$baseline = ( new FixReport_Snapshot_Repository() )->get_completed_initial();
		if ( is_wp_error( $baseline ) || ! $baseline ) { return new WP_Error( 'report_initial_snapshot_unavailable' ); }
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok = $this->wpdb->insert( $this->reports, array( 'report_type' => 'initial_assessment', 'status' => 'draft', 'title' => __( 'Initial Assessment', 'fixreport-maintenance-logger' ), 'report_locale' => 'en_US', 'start_snapshot_id' => (int) $baseline->snapshot_id, 'initial_assessment_key' => 'site_initial', 'created_at' => $now, 'updated_at' => $now ), array( '%s','%s','%s','%s','%d','%s','%s','%s' ) );
		if ( false === $ok ) {
			$found = $this->wpdb->get_var( "SELECT report_id FROM {$this->reports} WHERE initial_assessment_key = 'site_initial' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $found ? (int) $found : new WP_Error( 'report_persistence_failed' );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function preview( $id ) {
		$report = $this->get( $id );
		if ( ! $report ) { return new WP_Error( 'report_not_found' ); }
		if ( 'finalized' === $report->status ) {
			$document = FixReport_Report_Document::thaw( $report );
			if ( is_wp_error( $document ) ) { return $document; }
			if ( in_array( $document['contract_version'], array( '7.2', '7.3' ), true ) && is_wp_error( $this->branding->read_frozen_asset( $id, $document ) ) ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
			return $document;
		}
		$resolved = $this->branding->resolve( $report );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		$document = $this->assemble( $report, $this->linked_activities( $id ), $this->checklist( $id ), $this->branding->document_branding( $resolved ) );
		return is_wp_error( $document ) ? $document : array( 'document' => $document, 'model_hash' => FixReport_Report_Document::digest( $document ), 'revision' => (int) $report->draft_revision );
	}

	public function logo_bytes( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'report_forbidden' ); }
		$report = $this->get( $id );
		if ( ! $report ) { return new WP_Error( 'report_not_found' ); }
		if ( 'finalized' === $report->status ) {
			$document = FixReport_Report_Document::thaw( $report );
			if ( is_wp_error( $document ) ) { return $document; }
			if ( '7.1' === $document['contract_version'] ) { return new WP_Error( 'branding_logo_missing' ); }
			$asset = $this->branding->read_frozen_asset( $id, $document );
			return $asset ? ( is_wp_error( $asset ) ? $asset : $asset->image_bytes ) : new WP_Error( 'branding_logo_missing' );
		}
		$resolved = $this->branding->resolve( $report );
		return is_wp_error( $resolved ) ? $resolved : ( $resolved['logo'] ? $resolved['logo']['bytes'] : new WP_Error( 'branding_logo_missing' ) );
	}

	private function assemble( $report, $activities, $checklist, $branding ) {
		$switched = $report->report_locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $report->report_locale );
		try { return ( new FixReport_Report_Document() )->assemble( $report, $activities, $checklist, $branding ); }
		finally { if ( $switched ) { restore_previous_locale(); } }
	}

	public function save_draft( $id, $revision, $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'report_forbidden' ); }
		$began = false;
		try {
			$began = false !== $this->wpdb->query( 'START TRANSACTION' );
			if ( ! $began ) { return new WP_Error( 'report_persistence_failed' ); }
			$report = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->reports} WHERE report_id = %d FOR UPDATE", $id ) );
			if ( ! $report ) { return new WP_Error( 'report_not_found' ); }
			if ( 'draft' !== $report->status || (int) $report->draft_revision !== (int) $revision ) { return new WP_Error( 'report_draft_stale' ); }
			$title = isset( $input['title'] ) && is_string( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
			if ( '' === trim( $title ) || strlen( $title ) > 255 ) { return new WP_Error( 'report_input_invalid' ); }
			$locale = array_key_exists( 'report_locale', $input ) ? $input['report_locale'] : $report->report_locale;
			if ( ! FixReport_Report_Language::valid( $locale ) ) { return new WP_Error( 'report_locale_invalid' ); }
			$fields = array( 'title' => $title, 'report_locale' => $locale );
			foreach ( array( 'assessment_summary', 'findings', 'recommendations', 'final_notes' ) as $field ) {
				$raw = isset( $input[ $field ] ) && is_string( $input[ $field ] ) ? $input[ $field ] : '';
				$fields[ $field ] = sanitize_textarea_field( wp_unslash( $raw ) );
				if ( strlen( $fields[ $field ] ) > 100000 ) { return new WP_Error( 'report_input_invalid' ); }
			}
			if ( array_key_exists( 'branding_mode', $input ) || array_key_exists( 'branding_value', $input ) ) {
				$overrides = $this->branding->clean_overrides( $input['branding_mode'] ?? null, $input['branding_value'] ?? null );
				if ( is_wp_error( $overrides ) ) { return $overrides; }
				$fields['branding_overrides'] = $overrides ? wp_json_encode( $overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
				if ( $overrides && ! is_string( $fields['branding_overrides'] ) ) { return new WP_Error( 'branding_override_invalid' ); }
			}
			if ( 'maintenance' === $report->report_type ) {
				$ids = isset( $input['activities'] ) && is_array( $input['activities'] ) ? array_map( 'intval', $input['activities'] ) : array();
				$candidates = $this->candidates( $report );
				if ( is_wp_error( $candidates ) ) { return $candidates; }
				$allowed = array_fill_keys( array_map( static function ( $r ) { return (int) $r->activity_id; }, $candidates ), true );
				if ( count( $ids ) !== count( array_unique( $ids ) ) || array_diff( $ids, array_keys( $allowed ) ) ) { return new WP_Error( 'report_activity_invalid' ); }
				$start_id = isset( $input['start_snapshot_id'] ) ? absint( $input['start_snapshot_id'] ) : 0;
				$end_id = isset( $input['end_snapshot_id'] ) ? absint( $input['end_snapshot_id'] ) : 0;
				foreach ( array( $start_id, $end_id ) as $snapshot_id ) {
				if ( $snapshot_id ) {
					$snapshot = ( new FixReport_Snapshot_Repository() )->get_completed_snapshot( $snapshot_id );
					if ( is_wp_error( $snapshot ) || ! $snapshot ) { return new WP_Error( 'report_snapshot_invalid' ); }
				}
				}
				$fields['start_snapshot_id'] = $start_id ?: null;
				$fields['end_snapshot_id'] = $end_id ?: null;
				$fields['include_comparison'] = ! empty( $input['include_comparison'] ) ? 1 : 0;
				if ( $fields['include_comparison'] && ! $this->comparison_eligible( $start_id, $end_id ) ) { return new WP_Error( 'report_comparison_pair_invalid' ); }
				if ( false === $this->wpdb->delete( $this->links, array( 'report_id' => $id ), array( '%d' ) ) ) { return new WP_Error( 'report_persistence_failed' ); }
				$order = 0;
				foreach ( $candidates as $activity ) {
					if ( in_array( (int) $activity->activity_id, $ids, true ) && false === $this->wpdb->insert( $this->links, array( 'report_id' => $id, 'activity_id' => $activity->activity_id, 'display_order' => $order++ ), array( '%d','%d','%d' ) ) ) { return new WP_Error( 'report_persistence_failed' ); }
				}
				$included_ids = isset( $input['checklist_included'] ) && is_array( $input['checklist_included'] ) ? array_map( 'intval', $input['checklist_included'] ) : array();
				$statuses = isset( $input['checklist_status'] ) && is_array( $input['checklist_status'] ) ? $input['checklist_status'] : array();
				$notes = isset( $input['checklist_note'] ) && is_array( $input['checklist_note'] ) ? $input['checklist_note'] : array();
				foreach ( $this->checklist( $id ) as $check ) {
					$check_id = (int) $check->checklist_item_id;
					$status = isset( $statuses[ $check_id ] ) && is_string( $statuses[ $check_id ] ) ? sanitize_key( wp_unslash( $statuses[ $check_id ] ) ) : 'pending';
					$note = isset( $notes[ $check_id ] ) && is_string( $notes[ $check_id ] ) ? sanitize_textarea_field( wp_unslash( $notes[ $check_id ] ) ) : '';
					if ( ! in_array( $status, array( 'pending', 'completed', 'not_applicable', 'requires_attention' ), true ) || strlen( $note ) > 10000 ) { return new WP_Error( 'report_checklist_invalid' ); }
					if ( false === $this->wpdb->update( $this->checks, array( 'included' => in_array( $check_id, $included_ids, true ) ? 1 : 0, 'status' => $status, 'note' => $note ), array( 'checklist_item_id' => $check_id, 'report_id' => $id ), array( '%d','%s','%s' ), array( '%d','%d' ) ) ) { return new WP_Error( 'report_persistence_failed' ); }
				}
			}
			$fields['draft_revision'] = (int) $report->draft_revision + 1;
			$fields['updated_by'] = get_current_user_id();
			$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
			$changed = $this->wpdb->update( $this->reports, $fields, array( 'report_id' => $id, 'status' => 'draft', 'draft_revision' => $revision ) );
			if ( 1 !== $changed || false === $this->wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'report_persistence_failed' ); }
			$began = false;
			return (int) $fields['draft_revision'];
		} finally {
			if ( $began ) { $this->wpdb->query( 'ROLLBACK' ); }
		}
	}

	public function finalize( $id, $revision, $preview_hash ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_string( $preview_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $preview_hash ) ) { return new WP_Error( 'report_finalization_invalid' ); }
		$began = false;
		try {
			$began = false !== $this->wpdb->query( 'START TRANSACTION' );
			if ( ! $began ) { return new WP_Error( 'report_persistence_failed' ); }
			$report = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->reports} WHERE report_id = %d FOR UPDATE", $id ) );
			if ( ! $report || 'draft' !== $report->status || (int) $report->draft_revision !== (int) $revision ) { return new WP_Error( 'report_draft_stale' ); }
			$resolved = $this->branding->resolve( $report, true );
			if ( is_wp_error( $resolved ) ) { return $resolved; }
			$document = $this->assemble( $report, $this->linked_activities( $id, true ), $this->checklist( $id ), $this->branding->document_branding( $resolved ) );
			if ( is_wp_error( $document ) ) { return $document; }
			if ( 'maintenance' === $report->report_type && ( $document['coverage']['included_checklist'] < 1 || $document['coverage']['pending_checklist'] > 0 ) ) { return new WP_Error( 'report_checklist_incomplete' ); }
			$hash = FixReport_Report_Document::digest( $document );
			if ( ! $hash || ! hash_equals( $hash, $preview_hash ) ) { return new WP_Error( 'report_preview_stale' ); }
			if ( $resolved['logo'] ) {
				$asset_id = $this->branding->insert_asset( $id, $resolved['logo'] );
				if ( is_wp_error( $asset_id ) ) { return $asset_id; }
				$document['branding']['logo']['asset_id'] = $asset_id;
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			// The layout marker is added after the preview-hash check, like the rest of the finalization
			// metadata, so Draft preview binding is unchanged; it is covered by the frozen SHA-256 below.
			$document['finalization'] = array( 'at_utc' => $now, 'by_user_id' => get_current_user_id(), 'layout' => FixReport_Report_Layout::COMPACT );
			$json = FixReport_Report_Document::encode( $document );
			if ( ! is_string( $json ) || strlen( $json ) > 8 * 1024 * 1024 ) { return new WP_Error( 'report_payload_too_large' ); }
			$changed = $this->wpdb->update( $this->reports, array( 'status' => 'finalized', 'frozen_payload' => $json, 'frozen_payload_sha256' => hash( 'sha256', $json ), 'payload_schema_version' => FixReport_Report_Document::CONTRACT, 'finalized_at' => $now, 'finalized_by' => get_current_user_id(), 'updated_at' => $now ), array( 'report_id' => $id, 'status' => 'draft', 'draft_revision' => $revision ) );
			if ( 1 !== $changed || false === $this->wpdb->query( 'COMMIT' ) ) { return new WP_Error( 'report_persistence_failed' ); }
			$began = false;
			return true;
		} finally {
			if ( $began ) { $this->wpdb->query( 'ROLLBACK' ); }
		}
	}
}
