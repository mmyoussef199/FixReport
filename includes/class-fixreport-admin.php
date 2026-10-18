<?php
/**
 * WordPress-admin controller for the FixReport Activities workflow.
 */
class FixReport_Admin {
	const DASHBOARD_PAGE = 'fixreport-dashboard';

	private $plugin_name_slug;
	private $version;
	private $repository;
	private $service;
	private $baseline_hook;
	private $snapshots_hook;
	private $checklist_hook;
	private $dashboard_hook;
	private $activities_hook;

	public function __construct( $plugin_name_slug, $version ) {
		$this->plugin_name_slug = $plugin_name_slug;
		$this->version          = $version;
		$this->repository       = new FixReport_Activity_Repository();
		$this->service          = new FixReport_Activity_Service( $this->repository );
	}

	public function add_plugin_admin_menu() {
		$this->dashboard_hook = add_menu_page( __( 'FixReport Dashboard', 'fixreport-maintenance-logger' ), __( 'FixReport', 'fixreport-maintenance-logger' ), 'manage_options', self::DASHBOARD_PAGE, array( $this, 'display_dashboard_page' ), 'dashicons-hammer', 76 );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Dashboard', 'fixreport-maintenance-logger' ), __( 'Dashboard', 'fixreport-maintenance-logger' ), 'manage_options', self::DASHBOARD_PAGE, array( $this, 'display_dashboard_page' ) );
		$this->activities_hook = add_submenu_page( self::DASHBOARD_PAGE, __( 'Activities', 'fixreport-maintenance-logger' ), __( 'Activities', 'fixreport-maintenance-logger' ), 'manage_options', $this->plugin_name_slug, array( $this, 'display_activities_page' ) );
		$this->snapshots_hook = add_submenu_page( self::DASHBOARD_PAGE, __( 'Site Snapshots', 'fixreport-maintenance-logger' ), __( 'Site Snapshots', 'fixreport-maintenance-logger' ), 'manage_options', 'fixreport-site-snapshots', array( $this, 'display_snapshots_page' ) );
		$this->baseline_hook = add_submenu_page( self::DASHBOARD_PAGE, __( 'Site Baseline', 'fixreport-maintenance-logger' ), __( 'Site Baseline', 'fixreport-maintenance-logger' ), 'manage_options', 'fixreport-site-baseline', array( $this, 'display_baseline_page' ) );
		$this->checklist_hook = add_submenu_page( self::DASHBOARD_PAGE, __( 'Maintenance Checklist', 'fixreport-maintenance-logger' ), __( 'Maintenance Checklist', 'fixreport-maintenance-logger' ), 'manage_options', 'fixreport-maintenance-checklist', array( $this, 'display_checklist_template_page' ) );
	}

	public function enqueue_styles( $hook_suffix ) {
		if ( $this->dashboard_hook && $this->dashboard_hook === $hook_suffix ) {
			wp_enqueue_style( 'fixreport-dashboard', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fixreport-dashboard.css', array(), $this->version );
		}
		if ( $this->activities_hook && $this->activities_hook === $hook_suffix ) {
			wp_enqueue_style( $this->plugin_name_slug, plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fixreport-activities-admin.css', array(), $this->version );
		}
		if ( ( $this->baseline_hook && $this->baseline_hook === $hook_suffix ) || ( $this->snapshots_hook && $this->snapshots_hook === $hook_suffix ) || ( $this->checklist_hook && $this->checklist_hook === $hook_suffix ) ) {
			wp_enqueue_style( $this->plugin_name_slug . '-baseline', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fixreport-baseline-admin.css', array(), $this->version );
		}
		if ( $this->checklist_hook && $this->checklist_hook === $hook_suffix ) {
			wp_enqueue_style( 'fixreport-checklist', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fixreport-checklist-admin.css', array(), $this->version );
		}
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( $this->checklist_hook && $this->checklist_hook === $hook_suffix ) {
			wp_enqueue_script( 'fixreport-checklist', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/fixreport-checklist-admin.js', array(), $this->version, true );
			wp_localize_script( 'fixreport-checklist', 'fixreportChecklistLabels', array(
				'completeTitle' => __( 'Mark complete: ', 'fixreport-maintenance-logger' ),
				'reopenTitle' => __( 'Reopen: ', 'fixreport-maintenance-logger' ),
				'completeButton' => __( 'Record completion', 'fixreport-maintenance-logger' ),
				'reopenButton' => __( 'Reopen task', 'fixreport-maintenance-logger' ),
			) );
		}
		if ( $this->activities_hook && $this->activities_hook === $hook_suffix ) {
			wp_enqueue_script( $this->plugin_name_slug, plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/fixreport-admin-scripts.js', array(), $this->version, true );
			wp_localize_script( $this->plugin_name_slug, 'fixreportActivityLabels', array(
				'id' => __( 'Record ID', 'fixreport-maintenance-logger' ), 'date' => __( 'Activity date', 'fixreport-maintenance-logger' ),
				'category' => __( 'Category', 'fixreport-maintenance-logger' ), 'source' => __( 'Source', 'fixreport-maintenance-logger' ),
				'status' => __( 'Status', 'fixreport-maintenance-logger' ), 'actor' => __( 'Recorded actor', 'fixreport-maintenance-logger' ),
				'description' => __( 'Description', 'fixreport-maintenance-logger' ), 'note' => __( 'Internal note', 'fixreport-maintenance-logger' ),
				'object' => __( 'WordPress object', 'fixreport-maintenance-logger' ), 'previous' => __( 'Previous version', 'fixreport-maintenance-logger' ),
				'new' => __( 'New version', 'fixreport-maintenance-logger' ), 'event' => __( 'Event type', 'fixreport-maintenance-logger' ),
				'hook' => __( 'Recorded hook', 'fixreport-maintenance-logger' ), 'visible' => __( 'Eligible for client reports', 'fixreport-maintenance-logger' ),
				'yes' => __( 'Yes', 'fixreport-maintenance-logger' ), 'no' => __( 'No', 'fixreport-maintenance-logger' ),
			) );
		}
	}

	/** Historical dashboard summaries and shortcuts only; no capture or report creation. */
	public function display_dashboard_page() {
		$this->require_capability( __( 'view the FixReport Dashboard', 'fixreport-maintenance-logger' ) );
		global $wpdb;
		$snapshot = ( new FixReport_Snapshot_Repository() )->get_latest_completed_for_dashboard();
		$month_start = current_datetime()->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$month_last = $month_start->modify( '+1 month' )->modify( '-1 day' );
		$month_label = wp_date( 'F Y', $month_start->getTimestamp() );
		$month_filters = $this->service->normalize_filters( array( 'date_from' => $month_start->format( 'Y-m-d' ), 'date_to' => $month_last->format( 'Y-m-d' ) ) );
		$month_count = null;
		if ( ! is_wp_error( $month_filters ) ) {
			$wpdb->last_error = '';
			$month_count = $this->repository->count_activities( $month_filters );
			if ( '' !== $wpdb->last_error ) { $month_count = null; }
		}
		$recent_filters = $this->service->normalize_filters( array() );
		$recent_filters['per_page'] = 5;
		$recent_filters['offset'] = 0;
		$wpdb->last_error = '';
		$recent_activities = $this->repository->get_activities( $recent_filters );
		if ( '' !== $wpdb->last_error || ! is_array( $recent_activities ) ) { $recent_activities = new WP_Error( 'activity_read_failed' ); }
		$draft = ( new FixReport_Report_Service() )->latest_draft_for_dashboard();
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/dashboard.php';
	}

	/** Render list, add, or edit mode. */
	public function display_activities_page() {
		$this->require_capability( __( 'view activities', 'fixreport-maintenance-logger' ) );
		$this->display_admin_notices();
		if ( isset( $_GET['action'] ) && 'view' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
			$activity = $activity_id ? $this->repository->get_activity( $activity_id ) : null;
			if ( ! $activity || 'automatic' !== $activity->source ) {
				wp_die( esc_html__( 'Automatic Activity not found.', 'fixreport-maintenance-logger' ), esc_html__( 'Not Found', 'fixreport-maintenance-logger' ), array( 'response' => 404 ) );
			}
			require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/automatic-activity-detail.php';
			return;
		}

		$mode             = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$activity         = null;
		$activities       = array();
		$users            = array();
		$filters          = $this->empty_filters();
		$pagination_args  = array( 'total_items' => 0, 'total_pages' => 0, 'current_page' => 1, 'items_per_page' => 20 );
		$filter_error     = false;
		$overview         = null;
		$current_page_url = admin_url( 'admin.php?page=' . $this->plugin_name_slug );

		if ( 'edit' === $mode ) {
			$users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );
			$activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
			$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( $activity_id <= 0 || ! wp_verify_nonce( $nonce, 'fixreport_edit_activity_view_' . $activity_id ) ) {
				wp_die( esc_html__( 'Security check failed for the activity form.', 'fixreport-maintenance-logger' ), esc_html__( 'Security Check Failed', 'fixreport-maintenance-logger' ), array( 'response' => 403, 'back_link' => true ) );
			}
			$activity = $this->repository->get_activity( $activity_id );
			if ( ! $activity || 'manual' !== $activity->source ) {
				$this->redirect_with_notice( 'activity_not_found' );
			}
		} elseif ( 'add' !== $mode ) {
			$mode       = 'list';
			$overview   = $this->repository->overview();
			$normalized = $this->service->normalize_filters( $_GET );
			if ( is_wp_error( $normalized ) ) {
				$filter_error = true;
			} else {
				$filters = $normalized;
			}
			$current_page                     = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
			$query_args                       = $filters;
			$query_args['per_page']           = 20;
			$total_items                      = $this->repository->count_activities( $query_args );
			$total_pages                      = (int) ceil( $total_items / 20 );
			$current_page                     = $total_pages ? min( $current_page, $total_pages ) : 1;
			$query_args['offset']             = ( $current_page - 1 ) * 20;
			$activities                       = $this->repository->get_activities( $query_args );
			$pagination_args['total_items']   = $total_items;
			$pagination_args['total_pages']   = $total_pages;
			$pagination_args['current_page']  = $current_page;
		}

		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/activities-page.php';
	}

	/** Render only stored historical Snapshot evidence. */
	public function display_baseline_page() {
		$this->require_capability( __( 'view the Site Baseline', 'fixreport-maintenance-logger' ) );
		$this->display_admin_notices();
		$is_baseline_route = true;
		$baseline_state = 'empty';
		$baseline_data = null;
		$failure_code = null;
		$repository = new FixReport_Snapshot_Repository();
		$snapshot = $repository->get_completed_initial();
		if ( is_wp_error( $snapshot ) ) {
			$baseline_state = 'error';
		} elseif ( $snapshot ) {
			$items = $repository->get_snapshot_items( (int) $snapshot->snapshot_id );
			$baseline_data = is_wp_error( $items ) ? $items : ( new FixReport_Baseline_Presenter() )->present( $snapshot, $items );
			$baseline_state = is_wp_error( $baseline_data ) ? 'error' : 'complete';
		} else {
			$attempt = $repository->get_latest_initial_attempt();
			if ( is_wp_error( $attempt ) ) {
				$baseline_state = 'error';
			} elseif ( $attempt ) {
				$failure_code = $attempt->failure_code;
				if ( 'failed' === $attempt->generation_status ) {
					$baseline_state = 'failed';
				} elseif ( 'building' === $attempt->generation_status ) {
					$started = strtotime( $attempt->started_at . ' UTC' );
					$baseline_state = $started && time() - $started >= FixReport_Snapshot_Repository::STALE_SECONDS ? 'stale' : 'building';
				} else {
					$baseline_state = 'error';
				}
			}
		}
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/baseline-page.php';
	}

	/** Render only completed stored Snapshot evidence; no collection on GET. */
	public function display_snapshots_page() {
		$this->require_capability( __( 'view Site Snapshots', 'fixreport-maintenance-logger' ) );
		$this->display_admin_notices();
		$repository = new FixReport_Snapshot_Repository();
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'compare' === $action ) {
			$this->display_snapshot_comparison( $repository );
			return;
		}
		if ( 'view' === $action ) {
			$is_baseline_route = false;
			$id = $this->strict_snapshot_id( 'snapshot_id' );
			$snapshot = false === $id ? null : $repository->get_completed_snapshot( $id );
			if ( is_wp_error( $snapshot ) || ! $snapshot ) {
				wp_die( esc_html__( 'Completed Snapshot not found.', 'fixreport-maintenance-logger' ), esc_html__( 'Not Found', 'fixreport-maintenance-logger' ), array( 'response' => 404 ) );
			}
			$items = $repository->get_snapshot_items( $id );
			$baseline_data = is_wp_error( $items ) ? $items : ( new FixReport_Baseline_Presenter() )->present( $snapshot, $items );
			$baseline_state = is_wp_error( $baseline_data ) ? 'error' : 'complete';
			$previous = $repository->get_previous_completed( $id );
			$compare_url = ! is_wp_error( $previous ) && $previous ? add_query_arg( array( 'page' => 'fixreport-site-snapshots', 'action' => 'compare', 'to_id' => $id ), admin_url( 'admin.php' ) ) : null;
			require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/baseline-page.php';
			return;
		}
		$history_state = 'ready';
		$history_rows = array();
		$history_page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$history_pages = 0;
		$latest_checkpoint = $repository->get_latest_completed_checkpoint();
		$checkpoint_state = get_option( FixReport_Checkpoint_Coordinator::OPTION, false );
		$initial = $repository->get_completed_initial();
		$initial_exists = ! is_wp_error( $initial ) && (bool) $initial;
		$count = $repository->count_completed();
		if ( is_wp_error( $initial ) || is_wp_error( $count ) ) {
			$history_state = 'error';
		} else {
			$history_pages = (int) ceil( $count / 20 );
			$history_page = $history_pages ? min( $history_page, $history_pages ) : 1;
			$history_rows = $repository->list_completed( 20, ( $history_page - 1 ) * 20 );
			if ( is_wp_error( $history_rows ) ) {
				$history_state = 'error';
				$history_rows = array();
			}
		}
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/snapshot-history-page.php';
	}

	/** A GET-only controller over completed stored evidence; no collector or write service. */
	private function display_snapshot_comparison( $repository ) {
		if ( 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) {
			wp_die( esc_html__( 'Comparison requires a read-only GET request.', 'fixreport-maintenance-logger' ), esc_html__( 'Method Not Allowed', 'fixreport-maintenance-logger' ), array( 'response' => 405 ) );
		}
		$to_id = $this->strict_snapshot_id( 'to_id' );
		if ( false === $to_id ) {
			wp_die( esc_html__( 'Valid TO Snapshot ID required.', 'fixreport-maintenance-logger' ), esc_html__( 'Invalid Input', 'fixreport-maintenance-logger' ), array( 'response' => 400 ) );
		}
		$to = $repository->get_completed_snapshot( $to_id );
		if ( is_wp_error( $to ) || ! $to ) {
			wp_die( esc_html__( 'Completed TO Snapshot not found.', 'fixreport-maintenance-logger' ), esc_html__( 'Not Found', 'fixreport-maintenance-logger' ), array( 'response' => 404 ) );
		}
		$has_from = isset( $_GET['from_id'] );
		$from_id = $has_from ? $this->strict_snapshot_id( 'from_id' ) : false;
		if ( $has_from && false === $from_id ) {
			wp_die( esc_html__( 'Valid FROM Snapshot ID required.', 'fixreport-maintenance-logger' ), esc_html__( 'Invalid Input', 'fixreport-maintenance-logger' ), array( 'response' => 400 ) );
		}
		if ( ! $has_from ) {
			$previous = $repository->get_previous_completed( $to_id );
			$from_id = is_wp_error( $previous ) || ! $previous ? false : (int) $previous->snapshot_id;
		}
		$from = false === $from_id ? null : $repository->get_completed_snapshot( $from_id );
		if ( false !== $from_id && ( is_wp_error( $from ) || ! $from ) ) {
			wp_die( esc_html__( 'Completed FROM Snapshot not found.', 'fixreport-maintenance-logger' ), esc_html__( 'Not Found', 'fixreport-maintenance-logger' ), array( 'response' => 404 ) );
		}
		if ( $from && ( $from_id === $to_id || $from->completed_at > $to->completed_at || ( $from->completed_at === $to->completed_at && $from_id > $to_id ) ) ) {
			wp_die( esc_html__( 'Choose an older, different FROM Snapshot.', 'fixreport-maintenance-logger' ), esc_html__( 'Invalid Pair', 'fixreport-maintenance-logger' ), array( 'response' => 400 ) );
		}
		$comparison_data = null;
		$comparison_error = null;
		if ( $from ) {
			$from_items = $repository->get_snapshot_items( $from_id );
			$to_items = $repository->get_snapshot_items( $to_id );
			if ( is_wp_error( $from_items ) || is_wp_error( $to_items ) ) {
				$comparison_error = new WP_Error( 'comparison_evidence_unreadable' );
			} else {
				$normalizer = new FixReport_Comparison_Normalizer();
				$a = $normalizer->normalize( $from, $from_items );
				$b = $normalizer->normalize( $to, $to_items );
				if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
					$comparison_error = is_wp_error( $a ) ? $a : $b;
				} else {
					$result = ( new FixReport_Comparison_Engine() )->compare( $a, $b );
					$comparison_error = is_wp_error( $result ) ? $result : null;
					$comparison_data = is_wp_error( $result ) ? null : ( new FixReport_Comparison_Presenter() )->present( $result );
				}
			}
		}
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/snapshot-compare-page.php';
	}

	private function strict_snapshot_id( $key ) {
		$raw = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
		return preg_match( '/^[1-9][0-9]*$/', $raw ) ? filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
	}

	public function handle_add_activity_submission() {
		$this->require_post_request();
		check_admin_referer( 'fixreport_add_activity_action', 'fixreport_activity_nonce' );
		$this->require_capability( __( 'add activities', 'fixreport-maintenance-logger' ) );
		$result = $this->service->create_activity( $_POST );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_code(), array( 'action' => 'add' ) );
		}
		$this->redirect_with_notice( 'activity_added_success' );
	}

	public function handle_update_activity_submission() {
		$this->require_post_request();
		$activity_id = isset( $_POST['fixreport_activity_id'] ) ? absint( $_POST['fixreport_activity_id'] ) : 0;
		check_admin_referer( 'fixreport_update_activity_action_' . $activity_id, 'fixreport_activity_nonce' );
		$this->require_capability( __( 'edit activities', 'fixreport-maintenance-logger' ) );
		if ( $activity_id <= 0 ) {
			wp_die( esc_html__( 'Invalid activity ID.', 'fixreport-maintenance-logger' ), esc_html__( 'Invalid Input', 'fixreport-maintenance-logger' ), array( 'response' => 400 ) );
		}
		$result = $this->service->update_activity( $activity_id, $_POST );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_code(), array( 'action' => 'edit', 'activity_id' => $activity_id, '_wpnonce' => wp_create_nonce( 'fixreport_edit_activity_view_' . $activity_id ) ) );
		}
		$this->redirect_with_notice( 'activity_updated_success' );
	}

	public function handle_delete_activity_submission() {
		$this->require_post_request();
		$activity_id = isset( $_POST['fixreport_activity_id'] ) ? absint( $_POST['fixreport_activity_id'] ) : 0;
		check_admin_referer( 'fixreport_delete_activity_' . $activity_id, 'fixreport_activity_nonce' );
		$this->require_capability( __( 'delete activities', 'fixreport-maintenance-logger' ) );
		if ( $activity_id <= 0 ) {
			wp_die( esc_html__( 'Invalid activity ID.', 'fixreport-maintenance-logger' ), esc_html__( 'Invalid Input', 'fixreport-maintenance-logger' ), array( 'response' => 400 ) );
		}
		$result = $this->service->soft_delete_activity( $activity_id, get_current_user_id() );
		$this->redirect_with_notice( is_wp_error( $result ) ? $result->get_error_code() : 'activity_deleted_success' );
	}

	/** Production Initial Baseline POST action; the service owns collection and lifecycle. */
	public function handle_create_initial_baseline() {
		$this->require_post_request();
		check_admin_referer( 'fixreport_create_initial_baseline', 'fixreport_baseline_nonce' );
		$this->require_capability( __( 'create an Initial Baseline', 'fixreport-maintenance-logger' ) );
		$service = new FixReport_Snapshot_Service( new FixReport_Snapshot_Repository(), new FixReport_Snapshot_Collector(), new FixReport_Snapshot_Validator() );
		$result = $service->create_initial();
		$this->redirect_with_notice( is_wp_error( $result ) ? $result->get_error_code() : 'baseline_created_success', array( 'page' => 'fixreport-site-baseline' ) );
	}

	public function handle_create_manual_snapshot() {
		$this->require_post_request();
		check_admin_referer( 'fixreport_create_manual_snapshot', 'fixreport_snapshot_nonce' );
		$this->require_capability( __( 'create a Site Snapshot', 'fixreport-maintenance-logger' ) );
		$service = new FixReport_Snapshot_Service( new FixReport_Snapshot_Repository(), new FixReport_Snapshot_Collector(), new FixReport_Snapshot_Validator() );
		$result = $service->create_manual();
		$args = is_wp_error( $result ) ? array( 'page' => 'fixreport-site-snapshots' ) : array( 'page' => 'fixreport-site-snapshots', 'action' => 'view', 'snapshot_id' => $result );
		$this->redirect_with_notice( is_wp_error( $result ) ? $result->get_error_code() : 'snapshot_created_success', $args );
	}

	/** Manage recommendations only; report checklist rows are never touched here. */
	public function display_checklist_template_page() {
		$this->require_capability( __( 'manage the Maintenance Checklist', 'fixreport-maintenance-logger' ) );
		$this->display_admin_notices();
		( new FixReport_Checklist_Admin() )->display();
	}

	public function handle_checklist_template() {
		$this->require_post_request();
		$this->require_capability( __( 'manage the Maintenance Checklist', 'fixreport-maintenance-logger' ) );
		$operation = isset( $_POST['checklist_operation'] ) && is_string( $_POST['checklist_operation'] ) ? sanitize_key( wp_unslash( $_POST['checklist_operation'] ) ) : '';
		$id = isset( $_POST['checklist_id'] ) && is_string( $_POST['checklist_id'] ) ? wp_unslash( $_POST['checklist_id'] ) : '';
		if ( ! in_array( $operation, array( 'add', 'edit', 'delete', 'up', 'down', 'restore' ), true ) ) {
			wp_die( esc_html__( 'Invalid checklist action.', 'fixreport-maintenance-logger' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'fixreport_checklist_' . $operation . '_' . $id, 'checklist_nonce' );
		if ( in_array( $operation, array( 'delete', 'restore' ), true ) && ( ! isset( $_POST['checklist_confirm'] ) || 'yes' !== $_POST['checklist_confirm'] ) ) {
			$this->redirect_with_notice( 'checklist_confirmation_required', array( 'page' => 'fixreport-maintenance-checklist' ) );
		}
		$revision = isset( $_POST['checklist_revision'] ) && is_string( $_POST['checklist_revision'] ) ? wp_unslash( $_POST['checklist_revision'] ) : '';
		$result = ( new FixReport_Checklist_Template() )->mutate( $operation, $revision, array( 'id' => $id, 'label' => isset( $_POST['checklist_label'] ) ? $_POST['checklist_label'] : null, 'description' => isset( $_POST['checklist_description'] ) ? $_POST['checklist_description'] : null ) );
		$this->redirect_with_notice( is_wp_error( $result ) ? $result->get_error_code() : 'checklist_saved', array( 'page' => 'fixreport-maintenance-checklist' ) );
	}

	public function display_schema_upgrade_notice() {
		if ( current_user_can( 'manage_options' ) && get_option( FixReport_Activator::SCHEMA_FAILURE_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FixReport could not verify its database schema. Activity operations may be unavailable; review the WordPress database error log.', 'fixreport-maintenance-logger' ) . '</p></div>';
		}
	}

	public static function category_labels() {
		return array(
			'update' => __( 'Update', 'fixreport-maintenance-logger' ), 'backup' => __( 'Backup', 'fixreport-maintenance-logger' ),
			'security' => __( 'Security', 'fixreport-maintenance-logger' ), 'performance' => __( 'Performance', 'fixreport-maintenance-logger' ),
			'bug_fix' => __( 'Bug Fix', 'fixreport-maintenance-logger' ), 'content_configuration' => __( 'Content / Configuration', 'fixreport-maintenance-logger' ),
			'testing' => __( 'Testing', 'fixreport-maintenance-logger' ), 'monitoring' => __( 'Monitoring', 'fixreport-maintenance-logger' ),
			'optimization' => __( 'Optimization', 'fixreport-maintenance-logger' ), 'other' => __( 'Other', 'fixreport-maintenance-logger' ),
		);
	}

	public static function status_labels() {
		return array( 'completed' => __( 'Completed', 'fixreport-maintenance-logger' ), 'pending' => __( 'Pending', 'fixreport-maintenance-logger' ), 'requires_attention' => __( 'Requires Attention', 'fixreport-maintenance-logger' ) );
	}

	public static function priority_labels() {
		return array( 'low' => __( 'Low', 'fixreport-maintenance-logger' ), 'medium' => __( 'Medium', 'fixreport-maintenance-logger' ), 'high' => __( 'High', 'fixreport-maintenance-logger' ), 'critical' => __( 'Critical', 'fixreport-maintenance-logger' ) );
	}

	public static function source_labels() {
		return array( 'manual' => __( 'Manual', 'fixreport-maintenance-logger' ), 'automatic' => __( 'Automatic', 'fixreport-maintenance-logger' ) );
	}

	private function empty_filters() {
		return array( 'search' => '', 'source' => '', 'category' => '', 'status' => '', 'priority' => '', 'date_from' => '', 'date_to' => '', 'date_from_current' => '', 'date_to_current' => '', 'date_from_legacy' => '', 'date_to_legacy' => '' );
	}

	private function require_post_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $method ) ) {
			wp_die( esc_html__( 'This action requires a POST request.', 'fixreport-maintenance-logger' ), esc_html__( 'Method Not Allowed', 'fixreport-maintenance-logger' ), array( 'response' => 405 ) );
		}
	}

	private function require_capability( $operation ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			/* translators: %s: attempted operation. */
			wp_die( sprintf( esc_html__( 'You do not have permission to %s.', 'fixreport-maintenance-logger' ), esc_html( $operation ) ), esc_html__( 'Permissions Error', 'fixreport-maintenance-logger' ), array( 'response' => 403 ) );
		}
	}

	private function redirect_with_notice( $notice_code, $args = array() ) {
		$args = array_merge( array( 'page' => $this->plugin_name_slug, 'fixreport_notice' => sanitize_key( $notice_code ) ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function display_admin_notices() {
		if ( ! isset( $_GET['fixreport_notice'] ) ) {
			return;
		}
		$notices = array(
			'checklist_saved' => array( 'success', __( 'Maintenance Checklist saved.', 'fixreport-maintenance-logger' ) ),
			'checklist_stale' => array( 'error', __( 'The checklist changed in another request. Reload this page and try again.', 'fixreport-maintenance-logger' ) ),
			'checklist_malformed' => array( 'error', __( 'Stored checklist data is invalid. No changes were made; inspect or restore the data before editing.', 'fixreport-maintenance-logger' ) ),
			'checklist_missing' => array( 'error', __( 'The checklist could not be found.', 'fixreport-maintenance-logger' ) ),
			'checklist_save_failed' => array( 'error', __( 'The checklist could not be saved.', 'fixreport-maintenance-logger' ) ),
			'checklist_item_missing' => array( 'error', __( 'This checklist item no longer exists. Reload the page.', 'fixreport-maintenance-logger' ) ),
			'checklist_label_required' => array( 'error', __( 'Enter an item label.', 'fixreport-maintenance-logger' ) ),
			'checklist_invalid_text' => array( 'error', __( 'Enter valid text for the item.', 'fixreport-maintenance-logger' ) ),
			'checklist_text_too_long' => array( 'error', __( 'The label or description exceeds its length limit.', 'fixreport-maintenance-logger' ) ),
			'checklist_full' => array( 'error', __( 'The checklist cannot contain more than 100 items.', 'fixreport-maintenance-logger' ) ),
			'checklist_id_collision' => array( 'error', __( 'A unique item ID could not be generated. Please try again.', 'fixreport-maintenance-logger' ) ),
			'checklist_no_change' => array( 'info', __( 'No checklist change was needed.', 'fixreport-maintenance-logger' ) ),
			'checklist_confirmation_required' => array( 'error', __( 'Confirm this checklist action before submitting it.', 'fixreport-maintenance-logger' ) ),
			'checklist_completed' => array( 'success', __( 'Task completion recorded. This is manual documentation, not independent verification.', 'fixreport-maintenance-logger' ) ),
			'checklist_reopened' => array( 'success', __( 'Task reopened for review.', 'fixreport-maintenance-logger' ) ),
			'checklist_category_saved' => array( 'success', __( 'Task category saved.', 'fixreport-maintenance-logger' ) ),
			'checklist_custom_restored' => array( 'success', __( 'Retired task restored under its original key.', 'fixreport-maintenance-logger' ) ),
			'checklist_transition_stale' => array( 'error', __( 'This task changed in another request. Review its current status and try again.', 'fixreport-maintenance-logger' ) ),
			'checklist_transition_invalid_state' => array( 'error', __( 'This action no longer matches the task status. Reload and review it.', 'fixreport-maintenance-logger' ) ),
			'checklist_transition_invalid' => array( 'error', __( 'The submitted task action was invalid.', 'fixreport-maintenance-logger' ) ),
			'checklist_nonce_invalid' => array( 'error', __( 'The security token expired or was invalid. Reload the page.', 'fixreport-maintenance-logger' ) ),
			'checklist_token_conflict' => array( 'error', __( 'This request token was already used for a different action. Reload the page.', 'fixreport-maintenance-logger' ) ),
			'checklist_storage_unavailable' => array( 'error', __( 'Operational Checklist storage is unavailable. No status change was recorded.', 'fixreport-maintenance-logger' ) ),
			'checklist_categories_stale' => array( 'error', __( 'Categories changed in another request. Reload and try again.', 'fixreport-maintenance-logger' ) ),
			'checklist_category_invalid' => array( 'error', __( 'Select a valid category.', 'fixreport-maintenance-logger' ) ),
			'checklist_retired_item_missing' => array( 'error', __( 'This retired task could not be found.', 'fixreport-maintenance-logger' ) ),
			'checklist_history_unavailable' => array( 'error', __( 'Checklist history could not be loaded.', 'fixreport-maintenance-logger' ) ),
			'checklist_event_insert_failed' => array( 'error', __( 'The status change was not saved. Reload before retrying.', 'fixreport-maintenance-logger' ) ),
			'checklist_forbidden' => array( 'error', __( 'You do not have permission to change the Maintenance Checklist.', 'fixreport-maintenance-logger' ) ),
			'checklist_task_missing' => array( 'error', __( 'This task is not an active checklist task. Reload the page and review the current list.', 'fixreport-maintenance-logger' ) ),
			'checklist_note_too_long' => array( 'error', __( 'The note must be 2,000 characters or fewer. No status change was recorded.', 'fixreport-maintenance-logger' ) ),
			'checklist_transaction_failed' => array( 'error', __( 'The checklist change could not be completed safely and was not saved. Reload the page before retrying.', 'fixreport-maintenance-logger' ) ),
			'checklist_template_invalid' => array( 'error', __( 'Stored checklist task definitions are invalid. No status change was recorded.', 'fixreport-maintenance-logger' ) ),
			'checklist_categories_invalid' => array( 'error', __( 'Stored checklist category assignments are invalid. No change was saved.', 'fixreport-maintenance-logger' ) ),
			'checklist_categories_save_failed' => array( 'error', __( 'The task category could not be saved. Reload the page before retrying.', 'fixreport-maintenance-logger' ) ),
			'checklist_archive_invalid' => array( 'error', __( 'Stored retired-task data is invalid. No change was saved.', 'fixreport-maintenance-logger' ) ),
			'checklist_archive_save_failed' => array( 'error', __( 'Retired-task data could not be saved, so the change was not applied. Reload the page before retrying.', 'fixreport-maintenance-logger' ) ),
			'checklist_history_invalid' => array( 'error', __( 'Checklist history is invalid. No status change was recorded.', 'fixreport-maintenance-logger' ) ),
			'checklist_invalid_action' => array( 'error', __( 'The submitted checklist action was invalid.', 'fixreport-maintenance-logger' ) ),
			'baseline_created_success' => array( 'success', __( 'Initial Site Baseline created successfully.', 'fixreport-maintenance-logger' ) ),
			'snapshot_created_success' => array( 'success', __( 'Site Snapshot created successfully.', 'fixreport-maintenance-logger' ) ),
			'duplicate_initial' => array( 'info', __( 'A completed Initial Baseline already exists.', 'fixreport-maintenance-logger' ) ),
			'initial_required' => array( 'error', __( 'Create the Initial Baseline first.', 'fixreport-maintenance-logger' ) ),
			'lock_unavailable' => array( 'error', __( 'Snapshot creation is already in progress. Try again later.', 'fixreport-maintenance-logger' ) ),
			'snapshot_maintenance_active' => array( 'error', __( 'WordPress maintenance mode is active. Try capturing after maintenance finishes.', 'fixreport-maintenance-logger' ) ),
			'snapshot_consistency_failed' => array( 'error', __( 'Site inventory changed or could not be verified during capture. No completed Snapshot was saved.', 'fixreport-maintenance-logger' ) ),
			'collection_failed' => array( 'error', __( 'Baseline collection failed safely.', 'fixreport-maintenance-logger' ) ),
			'validation_failed' => array( 'error', __( 'Baseline validation failed safely.', 'fixreport-maintenance-logger' ) ),
			'persistence_failed' => array( 'error', __( 'Baseline persistence failed safely.', 'fixreport-maintenance-logger' ) ),
			'activity_added_success' => array( 'success', __( 'Activity added successfully.', 'fixreport-maintenance-logger' ) ),
			'activity_updated_success' => array( 'success', __( 'Activity updated successfully.', 'fixreport-maintenance-logger' ) ),
			'activity_deleted_success' => array( 'success', __( 'Activity deleted successfully.', 'fixreport-maintenance-logger' ) ),
			'activity_title_required' => array( 'error', __( 'Enter an activity title.', 'fixreport-maintenance-logger' ) ),
			'activity_description_required' => array( 'error', __( 'Enter an activity description.', 'fixreport-maintenance-logger' ) ),
			'activity_title_too_long' => array( 'error', __( 'The title must be 255 characters or fewer.', 'fixreport-maintenance-logger' ) ),
			'activity_category_invalid' => array( 'error', __( 'Select a valid category.', 'fixreport-maintenance-logger' ) ),
			'activity_status_invalid' => array( 'error', __( 'Select a valid status.', 'fixreport-maintenance-logger' ) ),
			'activity_priority_invalid' => array( 'error', __( 'Select a valid priority.', 'fixreport-maintenance-logger' ) ),
			'activity_date_invalid' => array( 'error', __( 'Enter a valid activity date and time.', 'fixreport-maintenance-logger' ) ),
			'activity_user_invalid' => array( 'error', __( 'Select an existing WordPress user.', 'fixreport-maintenance-logger' ) ),
			'activity_not_found' => array( 'error', __( 'The requested active Manual Activity was not found.', 'fixreport-maintenance-logger' ) ),
			'activity_no_changes' => array( 'info', __( 'No changes were detected.', 'fixreport-maintenance-logger' ) ),
			'activity_create_failed' => array( 'error', __( 'The activity could not be added. Please try again or review the WordPress database error log.', 'fixreport-maintenance-logger' ) ),
			'activity_update_failed' => array( 'error', __( 'The activity could not be updated. Please try again or review the WordPress database error log.', 'fixreport-maintenance-logger' ) ),
			'activity_delete_failed' => array( 'error', __( 'The activity could not be deleted. Please try again or review the WordPress database error log.', 'fixreport-maintenance-logger' ) ),
		);
		$code = sanitize_key( wp_unslash( $_GET['fixreport_notice'] ) );
		if ( isset( $notices[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $code ][0] ), esc_html( $notices[ $code ][1] ) );
		}
	}
}
