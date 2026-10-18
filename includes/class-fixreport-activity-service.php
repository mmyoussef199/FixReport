<?php
/**
 * Validation and application policy for manual activities.
 */
class FixReport_Activity_Service {

	const MANUAL_EVENT_TYPE = 'manual_activity';

	/** @var FixReport_Activity_Repository */
	private $repository;

	public function __construct( FixReport_Activity_Repository $repository ) {
		$this->repository = $repository;
	}

	public static function categories() {
		return array( 'update', 'backup', 'security', 'performance', 'bug_fix', 'content_configuration', 'testing', 'monitoring', 'optimization', 'other' );
	}

	public static function statuses() {
		return array( 'completed', 'pending', 'requires_attention' );
	}

	public static function priorities() {
		return array( 'low', 'medium', 'high', 'critical' );
	}

	public static function sources() {
		return array( 'manual', 'automatic' );
	}

	/**
	 * Normalize and validate posted manual-activity fields.
	 *
	 * @param array       $input Raw request values.
	 * @param object|null $existing Existing activity for edit semantics.
	 * @return array|WP_Error
	 */
	public function validate_activity_input( $input, $existing = null ) {
		$data = array(
			'title'          => isset( $input['fixreport_title'] ) ? sanitize_text_field( wp_unslash( $input['fixreport_title'] ) ) : '',
			'description'    => isset( $input['fixreport_description'] ) ? sanitize_textarea_field( wp_unslash( $input['fixreport_description'] ) ) : '',
			'category'       => isset( $input['fixreport_category'] ) ? sanitize_key( wp_unslash( $input['fixreport_category'] ) ) : '',
			'status'         => isset( $input['fixreport_status'] ) ? sanitize_key( wp_unslash( $input['fixreport_status'] ) ) : '',
			'priority'       => isset( $input['fixreport_priority'] ) ? sanitize_key( wp_unslash( $input['fixreport_priority'] ) ) : '',
			'activity_input' => isset( $input['fixreport_activity_date'] ) ? sanitize_text_field( wp_unslash( $input['fixreport_activity_date'] ) ) : '',
			'performed_by'   => isset( $input['fixreport_performed_by'] ) ? absint( $input['fixreport_performed_by'] ) : 0,
			'client_visible' => isset( $input['fixreport_client_visible'] ) && '1' === (string) wp_unslash( $input['fixreport_client_visible'] ) ? 1 : 0,
			'internal_note'  => isset( $input['fixreport_internal_note'] ) ? sanitize_textarea_field( wp_unslash( $input['fixreport_internal_note'] ) ) : '',
		);

		if ( '' === $data['title'] ) {
			return new WP_Error( 'activity_title_required' );
		}
		if ( '' === $data['description'] ) {
			return new WP_Error( 'activity_description_required' );
		}
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $data['title'], 'UTF-8' ) : strlen( $data['title'] );
		if ( $title_length > 255 ) {
			return new WP_Error( 'activity_title_too_long' );
		}
		if ( ! in_array( $data['category'], self::categories(), true ) ) {
			return new WP_Error( 'activity_category_invalid' );
		}
		if ( ! in_array( $data['status'], self::statuses(), true ) ) {
			return new WP_Error( 'activity_status_invalid' );
		}
		if ( ! in_array( $data['priority'], self::priorities(), true ) ) {
			return new WP_Error( 'activity_priority_invalid' );
		}
		if ( $data['performed_by'] <= 0 || ! get_user_by( 'id', $data['performed_by'] ) ) {
			return new WP_Error( 'activity_user_invalid' );
		}

		$date = $this->parse_site_date( $data['activity_input'] );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		if ( (int) $date->format( 'Y' ) < 1970 || (int) $date->format( 'Y' ) > 2100 ) {
			return new WP_Error( 'activity_date_invalid' );
		}

		$is_migrated          = $existing && ! empty( $existing->legacy_source );
		$data['activity_date'] = $is_migrated ? $date->format( 'Y-m-d H:i:s' ) : $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		unset( $data['activity_input'] );
		return $data;
	}

	/**
	 * Normalize and validate list filters.
	 *
	 * @param array $input Query values.
	 * @return array|WP_Error
	 */
	public function normalize_filters( $input ) {
		$filters = array(
			'search'   => isset( $input['s'] ) ? sanitize_text_field( wp_unslash( $input['s'] ) ) : '',
			'source'   => isset( $input['source'] ) ? sanitize_key( wp_unslash( $input['source'] ) ) : '',
			'category' => isset( $input['category'] ) ? sanitize_key( wp_unslash( $input['category'] ) ) : '',
			'status'   => isset( $input['status'] ) ? sanitize_key( wp_unslash( $input['status'] ) ) : '',
			'priority' => isset( $input['priority'] ) ? sanitize_key( wp_unslash( $input['priority'] ) ) : '',
			'date_from' => isset( $input['date_from'] ) ? sanitize_text_field( wp_unslash( $input['date_from'] ) ) : '',
			'date_to'   => isset( $input['date_to'] ) ? sanitize_text_field( wp_unslash( $input['date_to'] ) ) : '',
		);
		if ( strlen( $filters['search'] ) > 191 ) {
			return new WP_Error( 'activity_filters_invalid' );
		}
		$allowlists = array( 'source' => self::sources(), 'category' => self::categories(), 'status' => self::statuses(), 'priority' => self::priorities() );
		foreach ( $allowlists as $field => $allowed ) {
			if ( '' !== $filters[ $field ] && ! in_array( $filters[ $field ], $allowed, true ) ) {
				return new WP_Error( 'activity_filters_invalid' );
			}
		}

		$normalized = $filters;
		foreach ( array( 'date_from', 'date_to' ) as $field ) {
			if ( '' !== $filters[ $field ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters[ $field ] ) ) {
				return new WP_Error( 'activity_filters_invalid' );
			}
			if ( '' !== $filters[ $field ] ) {
				$time = 'date_from' === $field ? '00:00:00' : '23:59:59';
				$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $filters[ $field ] . ' ' . $time, wp_timezone() );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $filters[ $field ] ) {
					return new WP_Error( 'activity_filters_invalid' );
				}
				$normalized[ $field . '_current' ] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$normalized[ $field . '_legacy' ]  = $date->format( 'Y-m-d H:i:s' );
			} else {
				$normalized[ $field . '_current' ] = '';
				$normalized[ $field . '_legacy' ]  = '';
			}
		}
		if ( '' !== $filters['date_from'] && '' !== $filters['date_to'] && $filters['date_from'] > $filters['date_to'] ) {
			return new WP_Error( 'activity_filters_invalid' );
		}
		return $normalized;
	}

	public function create_activity( $input ) {
		// The creator is the authenticated administrator, never a trusted form value.
		$input['fixreport_performed_by'] = get_current_user_id();
		$data = $this->validate_activity_input( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$now                    = current_time( 'mysql', true );
		$data['source']         = 'manual';
		$data['event_type']     = self::MANUAL_EVENT_TYPE;
		$data['created_at']     = $now;
		$data['updated_at']     = $now;
		$data['internal_note']  = '' === $data['internal_note'] ? null : $data['internal_note'];
		$activity_id            = $this->repository->insert_activity( $data );
		return $activity_id ? $activity_id : new WP_Error( 'activity_create_failed' );
	}

	public function update_activity( $activity_id, $input ) {
		$existing = $this->repository->get_activity( $activity_id );
		if ( ! $existing || 'manual' !== $existing->source ) {
			return new WP_Error( 'activity_not_found' );
		}
		$data = $this->validate_activity_input( $input, $existing );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['internal_note'] = '' === $data['internal_note'] ? null : $data['internal_note'];
		$changed_fields        = array( 'title', 'description', 'category', 'status', 'priority', 'activity_date', 'performed_by', 'client_visible', 'internal_note' );
		$changed               = false;
		foreach ( $changed_fields as $field ) {
			if ( (string) $existing->{$field} !== (string) $data[ $field ] ) {
				$changed = true;
				break;
			}
		}
		if ( ! $changed ) {
			return new WP_Error( 'activity_no_changes' );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $this->repository->update_activity( $activity_id, $data );
		return false === $result || 0 === $result ? new WP_Error( 'activity_update_failed' ) : true;
	}

	public function soft_delete_activity( $activity_id, $user_id ) {
		$existing = $this->repository->get_activity( $activity_id );
		if ( ! $existing || 'manual' !== $existing->source ) {
			return new WP_Error( 'activity_not_found' );
		}
		$result = $this->repository->soft_delete_activity( $activity_id, $user_id, current_time( 'mysql', true ) );
		return 1 === $result ? true : new WP_Error( 'activity_delete_failed' );
	}

	public function format_activity_date( $activity ) {
		$value = $activity->activity_date;
		if ( empty( $activity->legacy_source ) ) {
			$value = get_date_from_gmt( $value, 'Y-m-d H:i:s' );
		}
		return mysql2date( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ), $value );
	}

	public function activity_date_input_value( $activity ) {
		$value = $activity->activity_date;
		if ( empty( $activity->legacy_source ) ) {
			$value = get_date_from_gmt( $value, 'Y-m-d H:i:s' );
		}
		return mysql2date( 'Y-m-d\TH:i', $value );
	}

	private function parse_site_date( $value ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return new WP_Error( 'activity_date_invalid' );
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d\TH:i' ) !== $value ) {
			return new WP_Error( 'activity_date_invalid' );
		}
		return $date;
	}
}
