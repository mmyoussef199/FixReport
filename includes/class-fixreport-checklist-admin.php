<?php
/** Native WordPress admin presentation for the site-wide operational Checklist. */
class FixReport_Checklist_Admin {
	const PAGE = 'fixreport-maintenance-checklist';
	private $template;
	private $categories;
	private $events;

	/** Production uses the defaults; isolated tests pass disposable-option and fixture-table services. */
	public function __construct( $template = null, $categories = null, $events = null ) {
		$this->template = $template ?: new FixReport_Checklist_Template();
		$this->categories = $categories ?: new FixReport_Checklist_Categories();
		$this->events = $events ?: new FixReport_Checklist_Events( $this->template, $this->categories );
	}

	public function register() {
		add_action( 'admin_post_fixreport_checklist_transition', array( $this, 'handle_transition' ) );
		add_action( 'admin_post_fixreport_checklist_category', array( $this, 'handle_category' ) );
		add_action( 'admin_post_fixreport_checklist_restore_custom', array( $this, 'handle_restore_custom' ) );
	}

	private function require_write_access() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'This action requires POST.', 'fixreport-maintenance-logger' ), '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You cannot manage the Maintenance Checklist.', 'fixreport-maintenance-logger' ), '', array( 'response' => 403 ) ); }
	}

	private function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'fixreport_notice' => sanitize_key( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_transition() {
		$this->require_write_access();
		$key = isset( $_POST['task_key'] ) && is_string( $_POST['task_key'] ) ? sanitize_key( wp_unslash( $_POST['task_key'] ) ) : '';
		$action = isset( $_POST['transition_action'] ) && is_string( $_POST['transition_action'] ) ? sanitize_key( wp_unslash( $_POST['transition_action'] ) ) : '';
		$raw_expected = isset( $_POST['expected_event_id'] ) && is_string( $_POST['expected_event_id'] ) ? wp_unslash( $_POST['expected_event_id'] ) : '';
		$expected = ctype_digit( $raw_expected ) ? (int) $raw_expected : -1;
		$token = isset( $_POST['request_token'] ) && is_string( $_POST['request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['request_token'] ) ) : '';
		$note = isset( $_POST['transition_note'] ) && is_string( $_POST['transition_note'] ) ? wp_unslash( $_POST['transition_note'] ) : '';
		$nonce = isset( $_POST['transition_nonce'] ) && is_string( $_POST['transition_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['transition_nonce'] ) ) : '';
		$result = $this->events->transition( $key, $action, $expected, $token, $note, $nonce );
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : ( 'completed' === $action ? 'checklist_completed' : 'checklist_reopened' ) );
	}

	public function handle_category() {
		$this->require_write_access();
		$key = isset( $_POST['task_key'] ) && is_string( $_POST['task_key'] ) ? sanitize_key( wp_unslash( $_POST['task_key'] ) ) : '';
		$category = isset( $_POST['category_key'] ) && is_string( $_POST['category_key'] ) ? sanitize_key( wp_unslash( $_POST['category_key'] ) ) : '';
		$revision = isset( $_POST['category_revision'] ) && is_string( $_POST['category_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['category_revision'] ) ) : '';
		check_admin_referer( 'fixreport_checklist_category_' . $key, 'category_nonce' );
		$result = $this->categories->assign( $key, $category, $revision, $this->template );
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'checklist_category_saved' );
	}

	public function handle_restore_custom() {
		$this->require_write_access();
		$key = isset( $_POST['task_key'] ) && is_string( $_POST['task_key'] ) ? sanitize_key( wp_unslash( $_POST['task_key'] ) ) : '';
		$revision = isset( $_POST['checklist_revision'] ) && is_string( $_POST['checklist_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['checklist_revision'] ) ) : '';
		check_admin_referer( 'fixreport_checklist_restore_custom_' . $key, 'restore_nonce' );
		if ( ! isset( $_POST['checklist_confirm'] ) || 'yes' !== $_POST['checklist_confirm'] ) { $this->redirect( 'checklist_confirmation_required' ); }
		$result = $this->template->mutate( 'restore_custom', $revision, array( 'id' => $key ) );
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'checklist_custom_restored' );
	}

	public static function display_time( $utc ) {
		return wp_date( 'M j, Y H:i T', strtotime( $utc . ' UTC' ) );
	}

	public function display() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You cannot view the Maintenance Checklist.', 'fixreport-maintenance-logger' ), '', array( 'response' => 403 ) ); }
		$template_service = $this->template;
		$template = $template_service->get();
		$category_service = $this->categories;
		$categories = $category_service->get();
		$archive = $template_service->get_archive();
		$events = $this->events;
		$summary = $events->summary();
		$latest = $events->latest_for_active();
		$error = null;
		foreach ( array( $template, $categories, $archive, $summary, $latest ) as $value ) { if ( is_wp_error( $value ) ) { $error = $value; break; } }
		$status_filter = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		if ( ! in_array( $status_filter, array( 'all', 'completed', 'pending' ), true ) ) { $status_filter = 'all'; }
		$category_filter = isset( $_GET['category'] ) && is_string( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : 'all';
		if ( 'all' !== $category_filter && ! in_array( $category_filter, FixReport_Checklist_Categories::allowed_keys(), true ) ) { $category_filter = 'all'; }
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
		$groups = array();
		$retired = array();
		$detail = null;
		$history = array();
		$history_next = 0;
		$edit = null;
		$visible_count = 0;
		if ( ! $error ) {
			foreach ( FixReport_Checklist_Categories::labels() as $category_key => $label ) { $groups[ $category_key ] = array( 'label' => $label, 'items' => array(), 'completed' => 0 ); }
			$active = array();
			foreach ( $template['items'] as $item ) {
				$resolved = FixReport_Checklist_Template::resolved( $item );
				$key = $resolved['id'];
				$event = isset( $latest[ $key ] ) ? $latest[ $key ] : null;
				$state = $event && 'completed' === $event['event_type'] ? 'completed' : 'pending';
				$category_key = FixReport_Checklist_Categories::category_for( $categories, $key );
				$record = array( 'definition' => $resolved, 'event' => $event, 'state' => $state, 'category' => $category_key, 'raw' => $item, 'retired' => false );
				$active[ $key ] = $record;
				if ( 'all' !== $status_filter && $status_filter !== $state ) { continue; }
				if ( 'all' !== $category_filter && $category_filter !== $category_key ) { continue; }
				if ( '' !== $search && false === stripos( $resolved['label'] . ' ' . $resolved['description'], $search ) ) { continue; }
				$groups[ $category_key ]['items'][] = $record;
				if ( 'completed' === $state ) { $groups[ $category_key ]['completed']++; }
				$visible_count++;
			}
			foreach ( FixReport_Checklist_Template::defaults() as $key => $copy ) {
				if ( isset( $active[ $key ] ) ) { continue; }
				$retired[ $key ] = array( 'definition' => array( 'id' => $key, 'label' => $copy[0], 'description' => $copy[1] ), 'builtin' => true );
			}
			foreach ( $archive['items'] as $key => $item ) {
				$retired[ $key ] = array( 'definition' => FixReport_Checklist_Template::resolved( $item ), 'builtin' => false );
			}
			$detail_key = isset( $_GET['task'] ) && is_string( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
			if ( isset( $active[ $detail_key ] ) ) { $detail = $active[ $detail_key ]; }
			elseif ( isset( $retired[ $detail_key ] ) ) {
				$detail = array( 'definition' => $retired[ $detail_key ]['definition'], 'event' => null, 'state' => 'retired', 'category' => FixReport_Checklist_Categories::category_for( $categories, $detail_key ), 'retired' => true );
			}
			if ( $detail ) {
				$before = isset( $_GET['history_before'] ) ? absint( $_GET['history_before'] ) : 0;
				$history = $events->history( $detail_key, $before, 11 );
				if ( is_wp_error( $history ) ) { $error = $history; $history = array(); }
				elseif ( count( $history ) > 10 ) { $history = array_slice( $history, 0, 10 ); $last_history_event = end( $history ); $history_next = (int) $last_history_event['event_id']; }
				if ( $detail['retired'] && ! $error ) {
					// history[0] is the newest row of the selected page; only the first page starts at the latest
					// event. An older page may be empty, so it always reads the latest event separately.
					$latest = 0 === $before ? array_slice( $history, 0, 1 ) : $events->history( $detail_key, 0, 1 );
					if ( is_wp_error( $latest ) ) { $error = $latest; }
					elseif ( $latest ) { $detail['event'] = $latest[0]; }
				}
			}
			$edit_key = isset( $_GET['edit_task'] ) && is_string( $_GET['edit_task'] ) ? sanitize_key( wp_unslash( $_GET['edit_task'] ) ) : '';
			if ( isset( $active[ $edit_key ] ) ) { $edit = $active[ $edit_key ]; }
		}
		require FIXREPORT_PATH . 'admin/views/checklist-template-page.php';
	}
}
