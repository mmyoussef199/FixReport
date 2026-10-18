<?php
/** Canonical, presentation-ready historical Report document (contracts 7.1-7.3). */
class FixReport_Report_Document {
	const CONTRACT = '7.3';
	private $snapshots;

	public function __construct() {
		$this->snapshots = new FixReport_Snapshot_Repository();
	}

	public static function encode( $document ) {
		return wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function digest( $document ) {
		$json = self::encode( $document );
		return is_string( $json ) ? hash( 'sha256', $json ) : false;
	}

	public static function thaw( $report ) {
		if ( ! is_object( $report ) || 'finalized' !== $report->status || ! in_array( $report->payload_schema_version, array( '7.1', '7.2', self::CONTRACT ), true ) || ! is_string( $report->frozen_payload ) || ! is_string( $report->frozen_payload_sha256 ) || ! hash_equals( $report->frozen_payload_sha256, hash( 'sha256', $report->frozen_payload ) ) ) {
			return new WP_Error( 'report_frozen_integrity_failed' );
		}
		$data = json_decode( $report->frozen_payload, true );
		if ( ! is_array( $data ) || $report->payload_schema_version !== ( $data['contract_version'] ?? null ) || (int) $report->report_id !== ( $data['identity']['report_id'] ?? null ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
		if ( in_array( $report->payload_schema_version, array( '7.2', self::CONTRACT ), true ) ) {
			$branding = $data['branding'] ?? null;
			if ( ! is_array( $branding ) || ! isset( $branding['fields'] ) || ! is_array( $branding['fields'] ) || array_keys( $branding['fields'] ) !== FixReport_Branding::text_fields() || ! array_key_exists( 'logo', $branding ) || ( isset( $branding['settings_revision'] ) && ! is_string( $branding['settings_revision'] ) ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
			foreach ( $branding['fields'] as $value ) { if ( ! is_string( $value ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); } }
		}
		if ( self::CONTRACT === $report->payload_schema_version ) {
			if ( ! FixReport_Report_Language::valid( $data['locale'] ?? null ) || ! isset( $data['presentation'] ) || ! is_array( $data['presentation'] ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
			$expected = FixReport_Report_Language::vocabulary( $data['locale'] );
			foreach ( $expected as $group => $entries ) {
				if ( ! isset( $data['presentation'][ $group ] ) || array_keys( $entries ) !== array_keys( $data['presentation'][ $group ] ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
				foreach ( $data['presentation'][ $group ] as $value ) { if ( ! is_string( $value ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); } }
			}
			if ( ( $data['labels'] ?? null ) !== $data['presentation']['labels'] ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
		}
		// Optional immutable layout marker (absent on reports finalized before layout 9.2). When present it
		// must be the only value ever written, and only on the current contract.
		$finalization = $data['finalization'] ?? null;
		if ( is_array( $finalization ) && array_key_exists( 'layout', $finalization ) && ( self::CONTRACT !== $report->payload_schema_version || FixReport_Report_Layout::COMPACT !== $finalization['layout'] ) ) { return new WP_Error( 'report_frozen_integrity_failed' ); }
		return $data;
	}

	public function assemble( $report, $activities, $checklist, $branding ) {
		if ( ! is_object( $report ) || ! in_array( $report->report_type, array( 'initial_assessment', 'maintenance' ), true ) || ! is_array( $activities ) || ! is_array( $checklist ) || ! is_array( $branding ) ) {
			return new WP_Error( 'report_model_invalid' );
		}
		if ( ! FixReport_Report_Language::valid( $report->report_locale ) ) { return new WP_Error( 'report_locale_invalid' ); }
		$maintenance = 'maintenance' === $report->report_type;
		if ( $maintenance && ( ! $report->period_start || ! $report->period_end || $report->period_start >= $report->period_end || ! $report->period_timezone ) ) {
			return new WP_Error( 'report_period_invalid' );
		}
		$zone = null;
		try {
			$zone = new DateTimeZone( $report->period_timezone ?: 'UTC' );
		} catch ( Throwable $error ) {
			return new WP_Error( 'report_timezone_invalid' );
		}
		$activity_model = array();
		foreach ( $activities as $row ) {
			if ( ! $maintenance || ! isset( $row->activity_id, $row->source, $row->client_visible, $row->activity_date ) || ! in_array( $row->source, array( 'manual', 'automatic' ), true ) || 1 !== (int) $row->client_visible || null !== $row->deleted_at ) {
				return new WP_Error( 'report_activity_stale' );
			}
			$legacy = ! empty( $row->legacy_source );
			$time = $legacy ? $row->activity_date : ( new DateTimeImmutable( $row->activity_date, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			$local_start = ( new DateTimeImmutable( $report->period_start, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			$local_end = ( new DateTimeImmutable( $report->period_end, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
			$inside = $legacy ? $row->activity_date >= $local_start && $row->activity_date < $local_end : $row->activity_date >= $report->period_start && $row->activity_date < $report->period_end;
			if ( ! $inside ) {
				return new WP_Error( 'report_activity_stale' );
			}
			$activity_model[] = array(
				'id' => (int) $row->activity_id, 'source' => $row->source, 'event_type' => $row->event_type,
				'title' => $row->title, 'description' => $row->description, 'status' => $row->status,
				'category' => $row->category, 'priority' => $row->priority, 'displayed_at' => $time,
				'time_basis' => $legacy ? 'legacy_local_clock_uncertain' : 'observed_utc_converted',
				'object_name' => $row->object_name, 'previous_version' => $row->previous_version,
				'new_version' => $row->new_version, 'provenance' => $row->metadata ? json_decode( $row->metadata, true ) : null,
			);
		}
		usort( $activity_model, static function ( $a, $b ) {
			return strcmp( $a['displayed_at'], $b['displayed_at'] ) ?: $a['id'] <=> $b['id'];
		} );
		$check_model = array();
		$included = 0;
		$pending = 0;
		foreach ( $checklist as $row ) {
			if ( ! $maintenance ) {
				return new WP_Error( 'report_initial_checklist_invalid' );
			}
			if ( ! in_array( (int) $row->included, array( 0, 1 ), true ) || ! in_array( $row->status, array( 'pending', 'completed', 'not_applicable', 'requires_attention' ), true ) ) {
				return new WP_Error( 'report_checklist_invalid' );
			}
			if ( 1 !== (int) $row->included ) {
				continue;
			}
			$included++;
			$pending += (int) ( 'pending' === $row->status );
			$check_model[] = array( 'key' => $row->check_key, 'label' => $row->displayed_label, 'description' => $row->displayed_description, 'status' => $row->status, 'note' => $row->note, 'order' => (int) $row->display_order );
		}
		$start = $this->state( $report->start_snapshot_id, 'initial_assessment' === $report->report_type ? 'initial' : 'start', $report );
		if ( is_wp_error( $start ) ) {
			return $start;
		}
		$end = $maintenance ? $this->state( $report->end_snapshot_id, 'end', $report ) : null;
		if ( is_wp_error( $end ) ) {
			return $end;
		}
		if ( ! $maintenance && ( ! $start || 'initial' !== $start['type'] || $activities || $checklist || (int) $report->include_comparison ) ) {
			return new WP_Error( 'report_initial_evidence_invalid' );
		}
		$comparison = null;
		if ( $maintenance && (int) $report->include_comparison ) {
			if ( ! $start || ! $end ) {
				return new WP_Error( 'report_comparison_pair_invalid' );
			}
			$a = $this->normal( $start['id'] );
			$b = $this->normal( $end['id'] );
			if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
				return new WP_Error( 'report_comparison_pair_invalid' );
			}
			$result = ( new FixReport_Comparison_Engine() )->compare( $a, $b );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$comparison = ( new FixReport_Comparison_Presenter() )->present( $result );
		}
		$presentation = FixReport_Report_Language::vocabulary( $report->report_locale );
		return array(
			'contract_version' => self::CONTRACT,
			'branding' => $branding,
			'labels' => $presentation['labels'],
			'presentation' => $presentation,
			'identity' => array( 'report_id' => (int) $report->report_id, 'type' => $report->report_type, 'title' => $report->title ),
			'locale' => $report->report_locale,
			'period' => $maintenance ? array( 'start_utc' => $report->period_start, 'end_utc_exclusive' => $report->period_end, 'timezone' => $report->period_timezone ) : null,
			'authored' => array( 'summary' => $report->assessment_summary, 'findings' => $report->findings, 'recommendations' => $report->recommendations, 'final_notes' => $report->final_notes ),
			'activities' => $activity_model, 'checklist' => $check_model,
			'start_state' => $start, 'end_state' => $end, 'comparison' => $comparison,
			'coverage' => array( 'included_checklist' => $included, 'pending_checklist' => $pending, 'legacy_clock_present' => (bool) count( array_filter( $activity_model, static function ( $item ) { return 'legacy_local_clock_uncertain' === $item['time_basis']; } ) ) ),
			'finalization' => null,
		);
	}

	private function normal( $id ) {
		$snapshot = $this->snapshots->get_completed_snapshot( $id );
		if ( is_wp_error( $snapshot ) || ! $snapshot ) {
			return new WP_Error( 'report_snapshot_stale' );
		}
		$items = $this->snapshots->get_snapshot_items( $id );
		return is_wp_error( $items ) ? $items : ( new FixReport_Comparison_Normalizer() )->normalize( $snapshot, $items );
	}

	private function state( $id, $role, $report ) {
		if ( ! $id ) {
			return null;
		}
		$snapshot = $this->snapshots->get_completed_snapshot( $id );
		if ( is_wp_error( $snapshot ) || ! $snapshot ) {
			return new WP_Error( 'report_snapshot_stale' );
		}
		$items = $this->snapshots->get_snapshot_items( $id );
		if ( is_wp_error( $items ) || is_wp_error( ( new FixReport_Comparison_Normalizer() )->normalize( $snapshot, $items ) ) ) {
			return new WP_Error( 'report_snapshot_corrupt' );
		}
		$presented = ( new FixReport_Baseline_Presenter() )->present( $snapshot, $items );
		if ( is_wp_error( $presented ) ) {
			return $presented;
		}
		$relation = 'initial' === $role ? 'initial' : ( 'start' === $role ? ( $snapshot->completed_at <= $report->period_start ? 'at_or_before_start' : 'late_after_start' ) : ( $snapshot->completed_at >= $report->period_end ? 'at_or_after_end' : 'early_before_end' ) );
		return array( 'id' => (int) $id, 'type' => $snapshot->snapshot_type, 'completed_at' => $snapshot->completed_at, 'relation' => $relation, 'presentation' => $presented );
	}
}
