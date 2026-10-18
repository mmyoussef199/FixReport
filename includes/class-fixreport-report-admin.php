<?php
/** Report Builder and optional branding controller. */
class FixReport_Report_Admin {
	private $service;
	private $branding;
	public function __construct() { $this->service = new FixReport_Report_Service(); $this->branding = new FixReport_Branding(); }
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'backfill' ) );
		foreach ( array( 'create', 'save', 'finalize', 'pdf' ) as $action ) {
			add_action( 'admin_post_fixreport_report_' . $action, array( $this, 'post_' . $action ) );
		}
		add_action( 'admin_post_fixreport_branding_save', array( $this, 'post_branding_save' ) );
		add_action( 'admin_post_fixreport_report_logo', array( $this, 'post_logo' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}
	public function menu() {
		add_submenu_page( 'fixreport-dashboard', 'Reports', 'Reports', 'manage_options', 'fixreport-reports', array( $this, 'page' ) );
		add_submenu_page( 'fixreport-dashboard', 'Report Branding', 'Report Branding', 'manage_options', 'fixreport-report-branding', array( $this, 'branding_page' ) );
	}
	public function backfill() {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( current_user_can( 'manage_options' ) && 'fixreport-dashboard' !== $page ) { $this->service->ensure_initial_draft(); $this->branding->initialize(); }
	}
	public function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( 'fixreport-reports', 'fixreport-report-branding' ), true ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'fixreport-branding', plugins_url( 'assets/js/fixreport-branding.js', dirname( __DIR__ ) . '/fixreport.php' ), array( 'jquery', 'media-views' ), FIXREPORT_VERSION, true );
		if ( 'fixreport-report-branding' === $page ) {
			wp_enqueue_style( 'fixreport-branding-admin', plugins_url( 'assets/css/fixreport-branding-admin.css', dirname( __DIR__ ) . '/fixreport.php' ), array(), FIXREPORT_VERSION );
		}
		$mode = isset( $_GET['mode'] ) && is_string( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		if ( 'fixreport-reports' === $page && ( '' === $mode || 'list' === $mode ) ) {
			wp_enqueue_style( 'fixreport-reports-list', plugins_url( 'assets/css/fixreport-reports-list.css', dirname( __DIR__ ) . '/fixreport.php' ), array(), FIXREPORT_VERSION );
		}
		if ( 'fixreport-reports' === $page && in_array( $mode, array( 'preview', 'view' ), true ) ) {
			wp_enqueue_style( 'fixreport-report-viewer', plugins_url( 'assets/css/fixreport-report-viewer.css', dirname( __DIR__ ) . '/fixreport.php' ), array(), FIXREPORT_VERSION );
		}
		if ( 'fixreport-reports' === $page && 'edit' === $mode ) {
			$base = dirname( __DIR__ ) . '/fixreport.php';
			wp_enqueue_style( 'fixreport-report-builder', plugins_url( 'assets/css/fixreport-report-builder.css', $base ), array(), FIXREPORT_VERSION );
			wp_enqueue_script( 'fixreport-report-builder', plugins_url( 'assets/js/fixreport-report-builder.js', $base ), array( 'fixreport-branding' ), FIXREPORT_VERSION, true );
			wp_localize_script( 'fixreport-report-builder', 'fixreportBuilderL10n', array(
				/* translators: 1: current step number, 2: total steps. */
				'stepOf' => __( 'Step %1$d of %2$d', 'fixreport-maintenance-logger' ),
				/* translators: %s: step name. */
				'previous' => __( 'Previous: %s', 'fixreport-maintenance-logger' ),
				/* translators: %s: step name. */
				'next' => __( 'Next: %s', 'fixreport-maintenance-logger' ),
				/* translators: 1: included count, 2: excluded count. */
				'activityCounts' => __( '%1$d included · %2$d excluded', 'fixreport-maintenance-logger' ),
				'gateReady' => __( 'Checklist gate met: at least one included item and no pending included items.', 'fixreport-maintenance-logger' ),
				/* translators: 1: included checklist items, 2: pending included items. */
				'gateBlocked' => __( 'Finalize needs at least one included checklist item and no pending included items (currently %1$d included, %2$d pending).', 'fixreport-maintenance-logger' ),
				'unsaved' => __( 'You have unsaved changes. Save Draft before previewing, downloading, or finalizing; those actions use the last saved revision.', 'fixreport-maintenance-logger' ),
				'leave' => __( 'You have unsaved changes in this report draft.', 'fixreport-maintenance-logger' ),
				'confirmFinalize' => __( 'Finalize this report? The saved draft becomes an immutable historical report and can no longer be edited.', 'fixreport-maintenance-logger' ),
				'errorsHeading' => __( 'Fix these fields before saving:', 'fixreport-maintenance-logger' ),
				'titleRequired' => __( 'Enter a report title.', 'fixreport-maintenance-logger' ),
				/* translators: 1: field label, 2: maximum length in bytes. */
				'tooLong' => __( '%1$s exceeds its limit of %2$d bytes.', 'fixreport-maintenance-logger' ),
				/* translators: %s: field label. */
				'emailInvalid' => __( '%s must be a valid email address.', 'fixreport-maintenance-logger' ),
				/* translators: %s: field label. */
				'websiteInvalid' => __( '%s must be a full http:// or https:// address.', 'fixreport-maintenance-logger' ),
				'logoRequired' => __( 'Choose a logo, or set the logo to inherit or blank.', 'fixreport-maintenance-logger' ),
				'pairChanged' => __( 'The snapshot pair changed. Save Draft to re-check comparison eligibility.', 'fixreport-maintenance-logger' ),
			) );
		}
	}
	public function post_branding_save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'fixreport_branding_save', 'branding_nonce' );
		$result = $this->branding->save_settings( isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? wp_unslash( $_POST['revision'] ) : '', $_POST );
		wp_safe_redirect( add_query_arg( array( 'page' => 'fixreport-report-branding', 'branding_notice' => is_wp_error( $result ) ? $result->get_error_code() : 'branding_saved' ), admin_url( 'admin.php' ) ) ); exit;
	}
	public function post_logo() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		$id = $this->id( $_GET['report_id'] ?? null );
		$bytes = $id ? $this->service->logo_bytes( $id ) : new WP_Error( 'report_not_found' );
		if ( is_wp_error( $bytes ) ) { status_header( 404 ); exit; }
		nocache_headers();
		header( 'Content-Type: image/png' ); header( 'Content-Disposition: inline; filename="report-logo.png"' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; exit;
	}
	public function branding_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		$settings = $this->branding->get_settings();
		echo '<div class="wrap fixreport-branding"><header class="frbg-header"><h1>' . esc_html__( 'Report Branding Defaults', 'fixreport-maintenance-logger' ) . '</h1><p>' . esc_html__( 'Set optional site-wide branding defaults. Each Draft can inherit, override, or clear them independently. Changes here never alter finalized reports.', 'fixreport-maintenance-logger' ) . '</p></header>';
		if ( is_wp_error( $settings ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $settings->get_error_code() ) . '</p></div></div>'; return; }
		if ( isset( $_GET['branding_notice'] ) ) { $notice = sanitize_key( wp_unslash( $_GET['branding_notice'] ) ); echo '<div class="notice ' . ( 'branding_saved' === $notice ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( str_replace( '_', ' ', $notice ) ) . '</p></div>'; }
		$logo_id = $settings['values']['logo_attachment_id'];
		$logo_url = '';
		if ( $logo_id > 0 ) {
			$attachment = get_post( $logo_id );
			$logo_file = get_attached_file( $logo_id );
			$attachment_url = wp_get_attachment_url( $logo_id );
			if ( $attachment && 'attachment' === $attachment->post_type && in_array( $attachment->post_mime_type, array( 'image/png', 'image/jpeg' ), true ) && is_string( $logo_file ) && is_file( $logo_file ) && is_string( $attachment_url ) ) {
				$logo_url = $attachment_url;
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="fixreport_branding_save"><input type="hidden" name="revision" value="' . esc_attr( $settings['revision'] ) . '">';
		wp_nonce_field( 'fixreport_branding_save', 'branding_nonce' );
		echo '<div class="frbg-grid">';
		echo '<section class="frbg-card" aria-labelledby="frbg-company-heading"><h2 id="frbg-company-heading">' . esc_html__( 'Company Information', 'fixreport-maintenance-logger' ) . '</h2><div class="frbg-fields">';
		foreach ( array( 'company_name', 'website', 'email', 'phone' ) as $key ) { $this->branding_input( $key, $settings['values'][ $key ], $key ); }
		echo '</div></section>';
		echo '<section class="frbg-card" aria-labelledby="frbg-logo-heading"><h2 id="frbg-logo-heading">' . esc_html__( 'Logo & Identity', 'fixreport-maintenance-logger' ) . '</h2>';
		$this->branding_logo_input( 'logo_attachment_id', $logo_id, $logo_url );
		echo '</section>';
		echo '<section class="frbg-card frbg-details" aria-labelledby="frbg-details-heading"><h2 id="frbg-details-heading">' . esc_html__( 'Report Details', 'fixreport-maintenance-logger' ) . '</h2><div class="frbg-fields">';
		foreach ( array( 'address', 'footer_text' ) as $key ) { $this->branding_input( $key, $settings['values'][ $key ], $key ); }
		echo '</div></section></div><div class="frbg-save">';
		submit_button( __( 'Save Defaults', 'fixreport-maintenance-logger' ) );
		echo '</div></form></div>';
	}
	private function branding_input( $key, $value, $name ) {
		$labels = array(
			'company_name' => __( 'Company Name', 'fixreport-maintenance-logger' ),
			'website' => __( 'Website', 'fixreport-maintenance-logger' ),
			'email' => __( 'Email', 'fixreport-maintenance-logger' ),
			'phone' => __( 'Phone', 'fixreport-maintenance-logger' ),
			'address' => __( 'Address', 'fixreport-maintenance-logger' ),
			'footer_text' => __( 'Footer Text', 'fixreport-maintenance-logger' ),
		);
		$id = 'frbg-' . str_replace( '_', '-', $key );
		echo '<div class="frbg-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $key ] ) . '</label>';
		if ( in_array( $key, array( 'address', 'footer_text' ), true ) ) { echo '<textarea id="' . esc_attr( $id ) . '" rows="4" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>'; }
		else { echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( 'email' === $key ? 'email' : ( 'website' === $key ? 'url' : 'text' ) ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '</div>';
	}
	private function branding_logo_input( $name, $value, $logo_url ) {
		echo '<div class="frbg-logo-preview">';
		if ( '' !== $logo_url ) {
			echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr__( 'Current saved report logo', 'fixreport-maintenance-logger' ) . '">';
		} else {
			echo '<p>' . esc_html( $value > 0 ? __( 'The saved logo is unavailable. Select a valid PNG/JPEG or clear the attachment ID.', 'fixreport-maintenance-logger' ) : __( 'No logo selected. Reports can be generated without one.', 'fixreport-maintenance-logger' ) ) . '</p>';
		}
		echo '</div><p class="frbg-logo-controls"><label for="frbg-logo-id">' . esc_html__( 'Logo Media Library attachment ID', 'fixreport-maintenance-logger' ) . '</label><input id="frbg-logo-id" class="small-text fixreport-logo-id" type="number" min="0" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><span class="frbg-logo-buttons"><button type="button" class="button fixreport-select-logo">' . esc_html__( 'Select PNG/JPEG', 'fixreport-maintenance-logger' ) . '</button><button type="button" class="button fixreport-clear-logo">' . esc_html__( 'Clear', 'fixreport-maintenance-logger' ) . '</button></span></p>';
	}
	private function authorize( $action, $id = 0 ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		if ( 'pdf' === $action ) {
			$nonce = $_POST['report_nonce'] ?? null;
			$valid_nonce = is_string( $nonce ) && wp_verify_nonce( wp_unslash( $nonce ), 'fixreport_report_pdf_' . $id );
			if ( ! $valid_nonce ) { wp_die( 'Invalid PDF nonce.', '', array( 'response' => 403 ) ); }
			return;
		}
		check_admin_referer( 'fixreport_report_' . $action . '_' . $id, 'report_nonce' );
	}
	private function id( $raw ) { return is_scalar( $raw ) && ctype_digit( (string) $raw ) && (int) $raw > 0 ? (int) $raw : 0; }
	private function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'fixreport-reports' ), $args ), admin_url( 'admin.php' ) ); }
	private function redirect( $args = array() ) { wp_safe_redirect( $this->url( $args ) ); exit; }
	private function result( $result, $id, $success, $mode = 'edit', $extra = array() ) {
		$this->redirect( array_merge( array( 'mode' => $mode, 'report_id' => $id, 'report_notice' => is_wp_error( $result ) ? $result->get_error_code() : $success ), $extra ) );
	}
	/** Guided Report Builder step (1-6); only selects the visible panel after a redirect. */
	private function step( $raw ) {
		$step = is_scalar( $raw ) && ctype_digit( (string) $raw ) ? (int) $raw : 1;
		return $step >= 1 && $step <= 6 ? $step : 1;
	}
	public function post_create() {
		$this->authorize( 'create' );
		$result = $this->service->create_maintenance( $_POST['title'] ?? '', $_POST['from'] ?? '', $_POST['through'] ?? '', $_POST['report_locale'] ?? 'en_US' );
		if ( is_wp_error( $result ) ) { $this->redirect( array( 'report_notice' => $result->get_error_code() ) ); }
		$this->result( $result, $result, 'report_created', 'edit', array( 'step' => 2 ) );
	}
	public function post_save() {
		$id = $this->id( $_POST['report_id'] ?? null );
		$this->authorize( 'save', $id );
		if ( ! $id ) { wp_die( 'Invalid report ID.', '', array( 'response' => 400 ) ); }
		$revision = $this->id( $_POST['draft_revision'] ?? null );
		$this->result( $this->service->save_draft( $id, $revision, $_POST ), $id, 'report_saved', 'edit', array( 'step' => $this->step( $_POST['builder_step'] ?? null ) ) );
	}
	public function post_finalize() {
		$id = $this->id( $_POST['report_id'] ?? null );
		$this->authorize( 'finalize', $id );
		if ( ! $id ) { wp_die( 'Invalid report ID.', '', array( 'response' => 400 ) ); }
		$revision = $this->id( $_POST['draft_revision'] ?? null );
		$hash = isset( $_POST['model_hash'] ) && is_string( $_POST['model_hash'] ) ? wp_unslash( $_POST['model_hash'] ) : '';
		$this->result( $this->service->finalize( $id, $revision, $hash ), $id, 'report_finalized', 'view' );
	}
	/** Render before sending headers; export never saves or changes report evidence. */
	public function post_pdf() {
		$id = $this->id( $_POST['report_id'] ?? null );
		$this->authorize( 'pdf', $id );
		if ( ! $id ) { wp_die( 'Invalid report ID.', '', array( 'response' => 400 ) ); }
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$report = $this->service->get( $id );
			if ( ! $report || ! in_array( $report->status, array( 'draft', 'finalized' ), true ) || ( $_POST['expected_status'] ?? null ) !== $report->status ) { wp_die( 'Report unavailable or stale.', '', array( 'response' => 409 ) ); }
			$preview = $this->service->preview( $id );
			if ( is_wp_error( $preview ) ) { wp_die( esc_html( $preview->get_error_code() ), '', array( 'response' => 409 ) ); }
			$draft = 'draft' === $report->status;
			$document = $draft ? $preview['document'] : $preview;
			if ( $draft ) {
				$revision = $this->id( $_POST['draft_revision'] ?? null );
				$hash = isset( $_POST['model_hash'] ) && is_string( $_POST['model_hash'] ) ? wp_unslash( $_POST['model_hash'] ) : '';
				if ( $revision !== $preview['revision'] || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! hash_equals( $preview['model_hash'], $hash ) ) { wp_die( 'Draft Preview is stale.', '', array( 'response' => 409 ) ); }
			}
			$logo = ! empty( $document['branding']['logo'] ) ? $this->service->logo_bytes( $id ) : null;
			if ( is_wp_error( $logo ) ) { wp_die( esc_html( $logo->get_error_code() ), '', array( 'response' => 409 ) ); }
			$bytes = ( new FixReport_Report_PDF() )->render( $document, $draft, $logo );
			if ( is_wp_error( $bytes ) ) { wp_die( esc_html( $bytes->get_error_code() ), '', array( 'response' => 409 ) ); }
			$unexpected = ob_get_clean();
			if ( '' !== $unexpected || headers_sent() || ( ob_get_level() && ob_get_length() ) ) { wp_die( 'PDF response could not be sent safely.', '', array( 'response' => 500 ) ); }
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="fixreport-report-' . $id . ( $draft ? '-draft' : '-finalized' ) . '.pdf"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Length: ' . strlen( $bytes ) );
			echo $bytes;
			exit;
		} finally {
			while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
		}
	}
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden.', '', array( 'response' => 403 ) ); }
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id = $this->id( $_GET['report_id'] ?? null );
		$report = $id ? $this->service->get( $id ) : null;
		$notice = isset( $_GET['report_notice'] ) ? sanitize_key( wp_unslash( $_GET['report_notice'] ) ) : '';
		$builder = $report && 'edit' === $mode && 'draft' === $report->status;
		$viewer = $report && in_array( $mode, array( 'preview', 'view' ), true );
		// The Guided Report Builder and the report viewer render their own heading, back link and header bar.
		if ( $builder ) { echo '<div class="wrap fixreport-builder-wrap">'; }
		elseif ( $viewer ) { echo '<div class="wrap fixreport-viewer-wrap">'; }
		elseif ( 'list' === $mode ) { echo '<div class="wrap fixreport-reports-wrap">'; }
		else { echo '<div class="wrap"><h1>FixReport Reports</h1><p><a href="' . esc_url( $this->url() ) . '">All Reports</a></p>'; }
		if ( $notice ) { echo '<div class="notice ' . ( in_array( $notice, array( 'report_created', 'report_saved', 'report_finalized' ), true ) ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( str_replace( '_', ' ', $notice ) ) . '</p></div>'; }
		if ( 'list' === $mode ) { $this->list_page(); }
		elseif ( ! $report ) { echo '<div class="notice notice-error"><p>Report not found.</p></div>'; }
		elseif ( 'edit' === $mode && 'draft' === $report->status ) { $this->edit_page( $report ); }
		elseif ( in_array( $mode, array( 'preview', 'view' ), true ) ) { $this->document_page( $report ); }
		else { echo '<div class="notice notice-error"><p>This action is unavailable for this Report.</p></div>'; }
		echo '</div>';
	}
	/** All Reports: stored report rows, real status totals, and the existing create form; actions use existing routes only. */
	private function list_page() {
		$counts = $this->service->status_counts();
		$per_page = 20;
		$total_pages = max( 1, (int) ceil( $counts['total'] / $per_page ) );
		// Missing, non-numeric, zero, or negative -> page 1. Larger than the last page -> out-of-range state, no query.
		$raw_page = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : '';
		$current_page = 1;
		if ( ctype_digit( $raw_page ) ) {
			$digits = ltrim( $raw_page, '0' );
			if ( '' !== $digits ) { $current_page = strlen( $digits ) > 9 ? $total_pages + 1 : (int) $digits; }
		}
		$out_of_range = $current_page > $total_pages;
		$reports = $out_of_range ? array() : $this->service->list_reports( $per_page, ( $current_page - 1 ) * $per_page );
		$reports = is_array( $reports ) ? $reports : array();
		$first_shown = $reports ? ( $current_page - 1 ) * $per_page + 1 : 0;
		$last_shown = $reports ? $first_shown + count( $reports ) - 1 : 0;
		// Navigation stays on this admin page and carries only the page number.
		$page_url = static function ( $number ) { return add_query_arg( array( 'page' => 'fixreport-reports', 'paged' => (int) $number ), admin_url( 'admin.php' ) ); };
		$periods = array();
		foreach ( $reports as $row ) { $periods[ (int) $row->report_id ] = $this->builder_period( $row ); }
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/reports-list.php';
	}
	/**
	 * Guided Report Builder: one complete save form for every applicable Draft
	 * field, split into six client-side panels. save_draft() treats omitted
	 * fields as empty, so no panel ever disables or removes its controls.
	 */
	private function edit_page( $report ) {
		$id = (int) $report->report_id;
		$is_maintenance = 'maintenance' === $report->report_type;
		$step = $this->step( $_GET['step'] ?? null );
		$defaults = $this->branding->get_settings();
		$overrides = $this->branding->read_overrides( $report->branding_overrides );
		if ( is_wp_error( $defaults ) || is_wp_error( $overrides ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Branding configuration is unavailable. Save and Finalize are blocked until corrected.', 'fixreport-maintenance-logger' ) . '</p></div>';
			return;
		}
		$checks = $is_maintenance ? $this->service->checklist( $id ) : array();
		$candidates = $is_maintenance ? $this->service->candidates( $report ) : array();
		$linked = array();
		foreach ( $is_maintenance ? $this->service->linked_activities( $id ) : array() as $row ) { $linked[ (int) $row->linked_activity_id ] = $row; }
		$candidate_ids = is_wp_error( $candidates ) ? array() : array_map( static function ( $a ) { return (int) $a->activity_id; }, $candidates );
		// Existing save behavior: links outside the current candidate set are dropped on save. Show them instead of hiding that.
		$stale_links = is_wp_error( $candidates ) ? array() : array_diff_key( $linked, array_flip( $candidate_ids ) );
		$snapshots = $is_maintenance ? $this->service->all_snapshot_options() : array();
		$comparison_ready = $is_maintenance && $this->service->comparison_eligible( (int) $report->start_snapshot_id, (int) $report->end_snapshot_id );
		$preview = $this->service->preview( $id );
		$document = is_wp_error( $preview ) ? null : $preview['document'];
		$logo_url = $document && ! empty( $document['branding']['logo'] ) ? add_query_arg( array( 'action' => 'fixreport_report_logo', 'report_id' => $id ), admin_url( 'admin-post.php' ) ) : '';
		$gate = $document && ( ! $is_maintenance || ( $document['coverage']['included_checklist'] > 0 && 0 === $document['coverage']['pending_checklist'] ) );
		$period = $this->builder_period( $report );
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/report-builder.php';
	}
	/** Read-only local presentation of the period fixed at creation. */
	private function builder_period( $report ) {
		if ( 'maintenance' !== $report->report_type || ! $report->period_start || ! $report->period_end ) { return null; }
		try {
			$zone = new DateTimeZone( $report->period_timezone ?: 'UTC' );
			$start = ( new DateTimeImmutable( $report->period_start, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone );
			$end = ( new DateTimeImmutable( $report->period_end, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->modify( '-1 day' );
		} catch ( Throwable $error ) {
			return null;
		}
		return array( 'from' => $start->format( 'Y-m-d' ), 'through' => $end->format( 'Y-m-d' ), 'timezone' => $zone->getName(), 'start_utc' => $report->period_start, 'end_utc' => $report->period_end );
	}
	/**
	 * Report viewer for mode=preview and mode=view. A Draft shows the server
	 * document of its last saved revision; a finalized report shows only its
	 * integrity-verified frozen document. The same existing PDF and Finalize
	 * routes are used; nothing here edits, reopens, or regenerates a report.
	 */
	private function document_page( $report ) {
		$id = (int) $report->report_id;
		$is_draft = 'draft' === $report->status;
		$preview = $this->service->preview( $id );
		$document = is_wp_error( $preview ) ? null : ( $is_draft ? $preview['document'] : $preview );
		$logo_url = $document && ! empty( $document['branding']['logo'] ) ? add_query_arg( array( 'action' => 'fixreport_report_logo', 'report_id' => $id ), admin_url( 'admin-post.php' ) ) : '';
		$gate = $is_draft && $document && ( 'maintenance' !== $report->report_type || ( $document['coverage']['included_checklist'] > 0 && 0 === $document['coverage']['pending_checklist'] ) );
		$period = $document && ! empty( $document['period'] ) ? $this->document_period( $document['period'] ) : null;
		require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/report-viewer.php';
	}
	/** Local inclusive dates of a document period (UTC start, exclusive UTC end, stored time zone). */
	private function document_period( $period ) {
		if ( ! is_array( $period ) || empty( $period['start_utc'] ) || empty( $period['end_utc_exclusive'] ) ) { return null; }
		try {
			$zone = new DateTimeZone( ! empty( $period['timezone'] ) ? $period['timezone'] : 'UTC' );
			$start = ( new DateTimeImmutable( $period['start_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone );
			$end = ( new DateTimeImmutable( $period['end_utc_exclusive'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->modify( '-1 day' );
		} catch ( Throwable $error ) {
			return null;
		}
		return array( 'from' => $start->format( 'Y-m-d' ), 'through' => $end->format( 'Y-m-d' ), 'timezone' => $zone->getName() );
	}
}
