<?php
/**
 * Guided Report Builder for one Draft. Rendered by FixReport_Report_Admin::edit_page().
 *
 * One <form> carries every applicable Draft field to the existing save route.
 * Panels are only shown or hidden; controls are never disabled or removed, so a
 * save from any step preserves every other step. Draft PDF and Finalize use their
 * own forms, bound to the saved revision and server preview hash.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$post_url = admin_url( 'admin-post.php' );
$list_url = add_query_arg( array( 'page' => 'fixreport-reports' ), admin_url( 'admin.php' ) );
$preview_url = add_query_arg( array( 'page' => 'fixreport-reports', 'mode' => 'preview', 'report_id' => $id ), admin_url( 'admin.php' ) );
$type_labels = array( 'maintenance' => __( 'Maintenance Report', 'fixreport-maintenance-logger' ), 'initial_assessment' => __( 'Initial Assessment', 'fixreport-maintenance-logger' ) );
$type_label = isset( $type_labels[ $report->report_type ] ) ? $type_labels[ $report->report_type ] : $report->report_type;
$check_statuses = array(
	'pending' => __( 'Pending', 'fixreport-maintenance-logger' ),
	'completed' => __( 'Completed', 'fixreport-maintenance-logger' ),
	'not_applicable' => __( 'Not applicable', 'fixreport-maintenance-logger' ),
	'requires_attention' => __( 'Requires attention', 'fixreport-maintenance-logger' ),
);
$activity_statuses = FixReport_Admin::status_labels();
$activity_sources = FixReport_Admin::source_labels();
$snapshot_types = array( 'initial' => __( 'Initial Baseline', 'fixreport-maintenance-logger' ), 'manual' => __( 'Manual', 'fixreport-maintenance-logger' ), 'checkpoint' => __( 'Checkpoint', 'fixreport-maintenance-logger' ) );
$brand_labels = array(
	'company_name' => __( 'Provider / company name', 'fixreport-maintenance-logger' ),
	'website' => __( 'Website', 'fixreport-maintenance-logger' ),
	'email' => __( 'Email', 'fixreport-maintenance-logger' ),
	'phone' => __( 'Phone', 'fixreport-maintenance-logger' ),
	'address' => __( 'Address', 'fixreport-maintenance-logger' ),
	'footer_text' => __( 'Footer text', 'fixreport-maintenance-logger' ),
	'logo_attachment_id' => __( 'Logo', 'fixreport-maintenance-logger' ),
);
$brand_limits = array( 'company_name' => 255, 'website' => 2048, 'email' => 254, 'phone' => 64, 'address' => 1000, 'footer_text' => 2000 );
$mode_labels = array(
	'inherit' => __( 'Use site default', 'fixreport-maintenance-logger' ),
	'blank' => __( 'Leave blank in this report', 'fixreport-maintenance-logger' ),
	'override' => __( 'Override for this report', 'fixreport-maintenance-logger' ),
);
$brand_mode = static function ( $key ) use ( $overrides ) {
	if ( ! array_key_exists( $key, $overrides ) ) { return 'inherit'; }
	return ( '' === $overrides[ $key ] || 0 === $overrides[ $key ] ) ? 'blank' : 'override';
};
$included_activities = 0;
foreach ( $candidate_ids as $candidate_id ) { if ( isset( $linked[ $candidate_id ] ) ) { $included_activities++; } }
$excluded_activities = count( $candidate_ids ) - $included_activities;
$steps = array(
	1 => array( __( 'Report Setup', 'fixreport-maintenance-logger' ), $period ? sprintf( '%1$s – %2$s · %3$s', $period['from'], $period['through'], $type_label ) : $type_label ),
	2 => array( __( 'Client & Branding', 'fixreport-maintenance-logger' ), __( 'Branding, logo & report language', 'fixreport-maintenance-logger' ) ),
	/* translators: 1: included activity count, 2: excluded activity count. */
	3 => array( __( 'Maintenance Work', 'fixreport-maintenance-logger' ), $is_maintenance ? ( is_wp_error( $candidates ) ? __( 'Activities unavailable', 'fixreport-maintenance-logger' ) : sprintf( __( '%1$d included · %2$d excluded', 'fixreport-maintenance-logger' ), $included_activities, $excluded_activities ) ) : __( 'Not applicable', 'fixreport-maintenance-logger' ) ),
	4 => array( __( 'Summary & Recommendations', 'fixreport-maintenance-logger' ), __( 'Executive narrative & next steps', 'fixreport-maintenance-logger' ) ),
	5 => array( __( 'Technical Details', 'fixreport-maintenance-logger' ), $is_maintenance ? __( 'Site snapshots & comparison', 'fixreport-maintenance-logger' ) : __( 'Initial Baseline snapshot', 'fixreport-maintenance-logger' ) ),
	6 => array( __( 'Preview & Finalize', 'fixreport-maintenance-logger' ), __( 'Review & finalize report', 'fixreport-maintenance-logger' ) ),
);
$step_names = array();
foreach ( $steps as $number => $meta ) { $step_names[ $number ] = $meta[0]; }
$saved_at = strtotime( $report->updated_at . ' UTC' );
$vocabulary = array();
foreach ( array( 'en_US', 'ar' ) as $locale_key ) {
	$words = FixReport_Report_Language::vocabulary( $locale_key );
	$vocabulary[ $locale_key ] = array_intersect_key( $words['ui'], array_flip( array( 'report', 'maintenance', 'initial_assessment', 'period', 'through', 'draft' ) ) );
}
$logo_urls = array();
foreach ( array( (int) $defaults['values']['logo_attachment_id'], isset( $overrides['logo_attachment_id'] ) ? (int) $overrides['logo_attachment_id'] : 0 ) as $logo_id ) {
	if ( $logo_id ) {
		$url = wp_get_attachment_image_url( $logo_id, 'medium' );
		if ( $url ) { $logo_urls[ $logo_id ] = $url; }
	}
}
$cover_data = array(
	'reportId' => $id,
	'type' => $report->report_type,
	'vocabulary' => $vocabulary,
	'defaults' => $defaults['values'],
	'logoUrls' => (object) $logo_urls,
	'period' => $period ? array( 'start' => $period['start_utc'], 'end' => $period['end_utc'] ) : null,
);
$step_heading = static function ( $number ) use ( $steps ) {
	/* translators: 1: step number, 2: total steps. */
	echo '<p class="frb-step-kicker">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'fixreport-maintenance-logger' ), $number, count( $steps ) ) ) . '</p>';
};
?>
<div class="fixreport-builder" data-active-step="<?php echo esc_attr( $step ); ?>" data-step-names="<?php echo esc_attr( wp_json_encode( $step_names ) ); ?>">
	<h1 class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %d: report ID. */ __( 'Edit report draft #%d', 'fixreport-maintenance-logger' ), $id ) ); ?></h1>
	<hr class="wp-header-end">
	<p class="frb-back"><a href="<?php echo esc_url( $list_url ); ?>"><span class="dashicons dashicons-arrow-left-alt frb-flip" aria-hidden="true"></span><?php esc_html_e( 'Back to All Reports', 'fixreport-maintenance-logger' ); ?></a></p>

	<form id="frb-form" class="frb-form" method="post" action="<?php echo esc_url( $post_url ); ?>" novalidate>
		<?php wp_nonce_field( 'fixreport_report_save_' . $id, 'report_nonce' ); ?>
		<input type="hidden" name="action" value="fixreport_report_save">
		<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
		<input type="hidden" name="draft_revision" value="<?php echo esc_attr( $report->draft_revision ); ?>">
		<input type="hidden" name="builder_step" value="<?php echo esc_attr( $step ); ?>">

		<div class="frb-card frb-header">
			<div class="frb-header-title">
				<label class="screen-reader-text" for="frb-title"><?php esc_html_e( 'Report title', 'fixreport-maintenance-logger' ); ?></label>
				<input id="frb-title" class="frb-title-input" type="text" name="title" maxlength="255" required value="<?php echo esc_attr( $report->title ); ?>" data-frb-step="1" data-frb-label="<?php esc_attr_e( 'Report title', 'fixreport-maintenance-logger' ); ?>">
				<span class="frb-badge frb-badge-draft"><?php esc_html_e( 'Draft', 'fixreport-maintenance-logger' ); ?></span>
			</div>
			<div class="frb-header-actions">
				<span class="frb-saved"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php
					echo esc_html( $saved_at ? sprintf( /* translators: 1: relative time, 2: draft revision. */ __( 'Saved %1$s ago · revision %2$d', 'fixreport-maintenance-logger' ), human_time_diff( $saved_at, time() ), (int) $report->draft_revision ) : sprintf( /* translators: %d: draft revision. */ __( 'Revision %d', 'fixreport-maintenance-logger' ), (int) $report->draft_revision ) );
					?>
				</span>
				<button type="submit" class="button button-primary frb-save"><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php esc_html_e( 'Save Draft', 'fixreport-maintenance-logger' ); ?></button>
			</div>
		</div>

		<div class="frb-errors notice notice-error" role="alert" tabindex="-1" hidden></div>
		<div class="frb-unsaved notice notice-warning" hidden><p></p></div>

		<div class="frb-layout">
			<div class="frb-card frb-stepper">
				<div class="frb-stepper-head">
					<h2><?php esc_html_e( 'Report Steps', 'fixreport-maintenance-logger' ); ?></h2>
					<span class="frb-step-count"><span class="frb-current-step"><?php echo esc_html( $step ); ?></span> / <?php echo esc_html( count( $steps ) ); ?></span>
				</div>
				<span class="frb-chip"><?php echo esc_html( $type_label ); ?></span>
				<nav aria-label="<?php esc_attr_e( 'Report builder steps', 'fixreport-maintenance-logger' ); ?>">
					<ol class="frb-steps">
						<?php foreach ( $steps as $number => $meta ) : ?>
							<li>
								<button type="button" class="frb-step<?php echo $number === $step ? ' is-active' : ''; ?>" data-step="<?php echo esc_attr( $number ); ?>" aria-controls="frb-panel-<?php echo esc_attr( $number ); ?>"<?php echo $number === $step ? ' aria-current="step"' : ''; ?>>
									<span class="frb-step-number" aria-hidden="true"><?php echo esc_html( $number ); ?></span>
									<span class="frb-step-text">
										<span class="frb-step-name"><?php echo esc_html( $number . '. ' . $meta[0] ); ?></span>
										<span class="frb-step-summary" data-step-summary="<?php echo esc_attr( $number ); ?>"><?php echo esc_html( $meta[1] ); ?></span>
									</span>
								</button>
							</li>
						<?php endforeach; ?>
					</ol>
				</nav>
			</div>

			<div class="frb-panels">
				<?php /* ---------- Step 1: Report Setup ---------- */ ?>
				<section id="frb-panel-1" class="frb-card frb-panel" data-step="1" aria-labelledby="frb-heading-1">
					<?php $step_heading( 1 ); ?>
					<h2 id="frb-heading-1" tabindex="-1"><?php esc_html_e( 'Report Setup', 'fixreport-maintenance-logger' ); ?></h2>
					<p class="frb-lead"><?php esc_html_e( 'Confirm the report identity. The title is edited in the header above; the report type and reporting period were fixed when this draft was created.', 'fixreport-maintenance-logger' ); ?></p>
					<div class="frb-group">
						<dl class="frb-facts">
							<div><dt><?php esc_html_e( 'Report title', 'fixreport-maintenance-logger' ); ?></dt><dd><button type="button" class="button-link frb-focus-title" data-frb-title-mirror><?php echo esc_html( $report->title ); ?></button></dd></div>
							<div><dt><?php esc_html_e( 'Report type', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( $type_label ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Report ID', 'fixreport-maintenance-logger' ); ?></dt><dd>#<?php echo esc_html( $id ); ?></dd></div>
							<?php if ( $period ) : ?>
								<div><dt><?php esc_html_e( 'Reporting period', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: first day, 2: last day, 3: time zone. */ __( '%1$s through %2$s (%3$s)', 'fixreport-maintenance-logger' ), $period['from'], $period['through'], $period['timezone'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Stored period (UTC)', 'fixreport-maintenance-logger' ); ?></dt><dd class="frb-ltr" dir="ltr"><?php echo esc_html( $period['start_utc'] . ' → ' . $period['end_utc'] ); ?> <?php esc_html_e( '(end exclusive)', 'fixreport-maintenance-logger' ); ?></dd></div>
							<?php elseif ( ! $is_maintenance ) : ?>
								<div><dt><?php esc_html_e( 'Reporting period', 'fixreport-maintenance-logger' ); ?></dt><dd><?php esc_html_e( 'Not applicable: this report documents the Initial Baseline snapshot.', 'fixreport-maintenance-logger' ); ?></dd></div>
							<?php endif; ?>
						</dl>
					</div>
					<?php if ( $is_maintenance ) : ?>
						<p class="description"><?php esc_html_e( 'To report on a different period, create a new draft from All Reports.', 'fixreport-maintenance-logger' ); ?> <a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'All Reports', 'fixreport-maintenance-logger' ); ?></a></p>
					<?php endif; ?>
				</section>

				<?php /* ---------- Step 2: Client & Branding ---------- */ ?>
				<section id="frb-panel-2" class="frb-card frb-panel" data-step="2" aria-labelledby="frb-heading-2">
					<?php $step_heading( 2 ); ?>
					<h2 id="frb-heading-2" tabindex="-1"><?php esc_html_e( 'Branding & Language', 'fixreport-maintenance-logger' ); ?></h2>
					<p class="frb-lead"><?php esc_html_e( 'Choose the provider identity, logo and language printed on this report. Each field can use the site default, be left blank, or be overridden for this report only.', 'fixreport-maintenance-logger' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-report-branding' ) ); ?>"><?php esc_html_e( 'Edit site-wide defaults', 'fixreport-maintenance-logger' ); ?></a></p>
					<div class="frb-split">
						<div class="frb-split-main">
							<div class="frb-group">
								<h3><span class="dashicons dashicons-id" aria-hidden="true"></span><?php esc_html_e( 'Provider Identification', 'fixreport-maintenance-logger' ); ?></h3>
								<?php foreach ( array( 'company_name', 'website', 'email', 'phone', 'address', 'footer_text' ) as $key ) :
									$mode = $brand_mode( $key );
									$value = 'override' === $mode ? $overrides[ $key ] : '';
									$default = (string) $defaults['values'][ $key ];
									$field_id = 'frb-brand-' . $key;
									?>
									<fieldset class="frb-brand-field" data-brand-key="<?php echo esc_attr( $key ); ?>">
										<legend><?php echo esc_html( $brand_labels[ $key ] ); ?></legend>
										<div class="frb-brand-row">
											<label class="screen-reader-text" for="<?php echo esc_attr( $field_id . '-mode' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: field label. */ __( '%s source', 'fixreport-maintenance-logger' ), $brand_labels[ $key ] ) ); ?></label>
											<select id="<?php echo esc_attr( $field_id . '-mode' ); ?>" class="frb-brand-mode" name="branding_mode[<?php echo esc_attr( $key ); ?>]">
												<?php foreach ( $mode_labels as $choice => $choice_label ) : ?><option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $mode, $choice ); ?>><?php echo esc_html( $choice_label ); ?></option><?php endforeach; ?>
											</select>
											<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $brand_labels[ $key ] ); ?></label>
											<?php if ( in_array( $key, array( 'address', 'footer_text' ), true ) ) : ?>
												<textarea id="<?php echo esc_attr( $field_id ); ?>" class="frb-brand-value" rows="2" name="branding_value[<?php echo esc_attr( $key ); ?>]" data-frb-step="2" data-frb-max="<?php echo esc_attr( $brand_limits[ $key ] ); ?>" data-frb-label="<?php echo esc_attr( $brand_labels[ $key ] ); ?>" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
											<?php else : ?>
												<input id="<?php echo esc_attr( $field_id ); ?>" class="frb-brand-value" type="<?php echo esc_attr( 'email' === $key ? 'email' : ( 'website' === $key ? 'url' : 'text' ) ); ?>" name="branding_value[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-frb-step="2" data-frb-max="<?php echo esc_attr( $brand_limits[ $key ] ); ?>" data-frb-label="<?php echo esc_attr( $brand_labels[ $key ] ); ?>" data-frb-format="<?php echo esc_attr( in_array( $key, array( 'email', 'website' ), true ) ? $key : '' ); ?>" placeholder="<?php echo esc_attr( $default ); ?>">
											<?php endif; ?>
										</div>
										<p class="frb-hint"><?php echo esc_html( sprintf( /* translators: %s: current site default value. */ __( 'Site default: %s', 'fixreport-maintenance-logger' ), '' !== $default ? $default : '—' ) ); ?></p>
									</fieldset>
								<?php endforeach; ?>
							</div>

							<div class="frb-group">
								<h3><span class="dashicons dashicons-format-image" aria-hidden="true"></span><?php esc_html_e( 'Provider Logo', 'fixreport-maintenance-logger' ); ?></h3>
								<?php
								$logo_mode = $brand_mode( 'logo_attachment_id' );
								$logo_value = 'override' === $logo_mode ? (int) $overrides['logo_attachment_id'] : 0;
								?>
								<fieldset class="frb-brand-field frb-logo-field" data-brand-key="logo_attachment_id">
									<legend><?php echo esc_html( $brand_labels['logo_attachment_id'] ); ?></legend>
									<label class="screen-reader-text" for="frb-brand-logo-mode"><?php esc_html_e( 'Logo source', 'fixreport-maintenance-logger' ); ?></label>
									<select id="frb-brand-logo-mode" class="frb-brand-mode" name="branding_mode[logo_attachment_id]">
										<?php foreach ( $mode_labels as $choice => $choice_label ) : ?><option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $logo_mode, $choice ); ?>><?php echo esc_html( $choice_label ); ?></option><?php endforeach; ?>
									</select>
									<p class="frb-logo-row">
										<span class="frb-logo-thumb" aria-hidden="true"><img alt="" hidden></span>
										<label class="screen-reader-text" for="frb-brand-logo-id"><?php esc_html_e( 'Logo Media Library attachment ID', 'fixreport-maintenance-logger' ); ?></label>
										<input id="frb-brand-logo-id" class="small-text fixreport-logo-id" type="number" min="0" name="branding_value[logo_attachment_id]" value="<?php echo esc_attr( $logo_value ); ?>" data-frb-step="2">
										<button type="button" class="button fixreport-select-logo"><span class="dashicons dashicons-format-gallery" aria-hidden="true"></span><?php esc_html_e( 'Choose PNG/JPEG', 'fixreport-maintenance-logger' ); ?></button>
										<button type="button" class="button-link fixreport-clear-logo frb-remove"><?php esc_html_e( 'Remove', 'fixreport-maintenance-logger' ); ?></button>
									</p>
									<p class="frb-hint"><?php echo esc_html( $defaults['values']['logo_attachment_id'] ? sprintf( /* translators: %d: attachment ID. */ __( 'Site default: Media Library attachment #%d. Use a clear PNG or JPEG image.', 'fixreport-maintenance-logger' ), (int) $defaults['values']['logo_attachment_id'] ) : __( 'Site default: no logo. Use a clear PNG or JPEG image.', 'fixreport-maintenance-logger' ) ); ?></p>
								</fieldset>
							</div>

							<div class="frb-group">
								<h3><span class="dashicons dashicons-translation" aria-hidden="true"></span><?php esc_html_e( 'Report Language', 'fixreport-maintenance-logger' ); ?></h3>
								<fieldset class="frb-locale">
									<legend class="screen-reader-text"><?php esc_html_e( 'Report language', 'fixreport-maintenance-logger' ); ?></legend>
									<label class="frb-choice"><input type="radio" name="report_locale" value="en_US" <?php checked( $report->report_locale, 'en_US' ); ?>><span><strong>English (LTR)</strong><span><?php esc_html_e( 'Left-to-right layout and English report headings.', 'fixreport-maintenance-logger' ); ?></span></span></label>
									<label class="frb-choice"><input type="radio" name="report_locale" value="ar" <?php checked( $report->report_locale, 'ar' ); ?>><span><strong lang="ar" dir="rtl">العربية (RTL)</strong><span><?php esc_html_e( 'Right-to-left layout and Arabic report headings.', 'fixreport-maintenance-logger' ); ?></span></span></label>
								</fieldset>
								<p class="frb-hint"><?php esc_html_e( 'Independent of the WordPress dashboard language. Report dates are printed as UTC timestamps.', 'fixreport-maintenance-logger' ); ?></p>
							</div>
						</div>

						<aside class="frb-split-side" aria-labelledby="frb-cover-heading">
							<div class="frb-cover-head">
								<h3 id="frb-cover-heading"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Live Cover Preview', 'fixreport-maintenance-logger' ); ?></h3>
								<span class="frb-hint"><?php esc_html_e( 'A4 aspect ratio', 'fixreport-maintenance-logger' ); ?></span>
							</div>
							<div class="frb-cover-frame">
								<div class="frb-cover" data-cover-config="<?php echo esc_attr( wp_json_encode( $cover_data ) ); ?>" dir="<?php echo 'ar' === $report->report_locale ? 'rtl' : 'ltr'; ?>" lang="<?php echo 'ar' === $report->report_locale ? 'ar' : 'en'; ?>">
									<div class="frb-cover-brand">
										<img class="frb-cover-logo" alt="" hidden>
										<span class="frb-cover-company" data-cover="company_name"></span>
									</div>
									<div class="frb-cover-contact">
										<span data-cover="website" class="frb-ltr" dir="ltr"></span>
										<span data-cover="email" class="frb-ltr" dir="ltr"></span>
										<span data-cover="phone" class="frb-ltr" dir="ltr"></span>
										<span data-cover="address"></span>
									</div>
									<div class="frb-cover-accent" aria-hidden="true"></div>
									<p class="frb-cover-title" data-cover="title"><?php echo esc_html( $report->title ); ?></p>
									<p class="frb-cover-meta"><span data-cover-word="report"></span> #<?php echo esc_html( $id ); ?> · <span data-cover-word="<?php echo esc_attr( $report->report_type ); ?>"></span></p>
									<p class="frb-cover-banner" data-cover-word="draft"></p>
									<?php if ( $period ) : ?>
										<p class="frb-cover-meta"><span data-cover-word="period"></span>: <span class="frb-ltr" dir="ltr"><?php echo esc_html( $period['start_utc'] ); ?> UTC</span> <span data-cover-word="through"></span> <span class="frb-ltr" dir="ltr"><?php echo esc_html( $period['end_utc'] ); ?> UTC</span></p>
									<?php endif; ?>
									<div class="frb-cover-footer" data-cover="footer_text"></div>
								</div>
							</div>
							<p class="frb-hint"><?php esc_html_e( 'Approximate preview of unsaved values. The authoritative layout is the server Preview and PDF in step 6.', 'fixreport-maintenance-logger' ); ?></p>
						</aside>
					</div>
				</section>

				<?php /* ---------- Step 3: Maintenance Work ---------- */ ?>
				<section id="frb-panel-3" class="frb-card frb-panel" data-step="3" aria-labelledby="frb-heading-3">
					<?php $step_heading( 3 ); ?>
					<h2 id="frb-heading-3" tabindex="-1"><?php esc_html_e( 'Maintenance Work', 'fixreport-maintenance-logger' ); ?></h2>
					<?php if ( ! $is_maintenance ) : ?>
						<div class="notice notice-info inline"><p><?php esc_html_e( 'Not applicable to Initial Assessment reports. They document the Initial Baseline snapshot only, without activities or checklist results.', 'fixreport-maintenance-logger' ); ?></p></div>
					<?php else : ?>
						<p class="frb-lead"><?php esc_html_e( 'Choose the recorded activities to include and record this report\'s checklist results. Activity status and evidence are never changed here.', 'fixreport-maintenance-logger' ); ?></p>
						<div class="frb-group">
							<h3><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Activities in this period', 'fixreport-maintenance-logger' ); ?> <span class="frb-count" data-activity-counts></span></h3>
							<?php if ( is_wp_error( $candidates ) ) : ?>
								<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: %s: error code. */ __( 'Activities could not be loaded (%s). Saving is blocked until this is resolved.', 'fixreport-maintenance-logger' ), $candidates->get_error_code() ) ); ?></p></div>
							<?php elseif ( ! $candidates ) : ?>
								<p class="frb-empty"><?php esc_html_e( 'No client-visible activities were recorded in this period.', 'fixreport-maintenance-logger' ); ?></p>
							<?php else : ?>
								<ul class="frb-activity-list">
									<?php foreach ( $candidates as $activity ) : $activity_id = (int) $activity->activity_id; ?>
										<li>
											<label class="frb-activity">
												<input type="checkbox" class="frb-activity-toggle" name="activities[]" value="<?php echo esc_attr( $activity_id ); ?>" <?php checked( isset( $linked[ $activity_id ] ) ); ?>>
												<span class="frb-activity-body">
													<strong>#<?php echo esc_html( $activity_id ); ?> <?php echo esc_html( $activity->title ); ?></strong>
													<span class="frb-hint">
														<?php echo esc_html( isset( $activity_statuses[ $activity->status ] ) ? $activity_statuses[ $activity->status ] : $activity->status ); ?> ·
														<?php echo esc_html( isset( $activity_sources[ $activity->source ] ) ? $activity_sources[ $activity->source ] : $activity->source ); ?> ·
														<span class="frb-ltr" dir="ltr"><?php echo esc_html( $activity->activity_date ); ?></span>
														<?php echo esc_html( $activity->legacy_source ? __( '(legacy local clock)', 'fixreport-maintenance-logger' ) : 'UTC' ); ?>
													</span>
												</span>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( $stale_links ) : ?>
								<div class="notice notice-warning inline"><p><?php esc_html_e( 'These linked activities are no longer eligible for this period (deleted, hidden from client reports, or moved). Saving will remove them from the draft:', 'fixreport-maintenance-logger' ); ?></p>
									<ul><?php foreach ( $stale_links as $stale_id => $stale_row ) : ?><li>#<?php echo esc_html( $stale_id ); ?> <?php echo esc_html( isset( $stale_row->title ) ? (string) $stale_row->title : '' ); ?></li><?php endforeach; ?></ul>
								</div>
							<?php endif; ?>
						</div>

						<div class="frb-group">
							<h3><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Report checklist results', 'fixreport-maintenance-logger' ); ?></h3>
							<p class="frb-hint"><?php esc_html_e( 'These results are stored in this report only. They do not change the operational Maintenance Checklist or its history.', 'fixreport-maintenance-logger' ); ?></p>
							<p class="frb-gate" data-checklist-gate role="status"></p>
							<?php if ( ! $checks ) : ?>
								<p class="frb-empty"><?php esc_html_e( 'This draft has no checklist rows.', 'fixreport-maintenance-logger' ); ?></p>
							<?php endif; ?>
							<?php foreach ( $checks as $check ) : $check_id = (int) $check->checklist_item_id; ?>
								<fieldset class="frb-check" data-check-row>
									<legend><label><input type="checkbox" class="frb-check-included" name="checklist_included[]" value="<?php echo esc_attr( $check_id ); ?>" <?php checked( (int) $check->included, 1 ); ?>> <?php echo esc_html( sprintf( /* translators: %s: checklist item label. */ __( 'Include %s', 'fixreport-maintenance-logger' ), $check->displayed_label ) ); ?></label></legend>
									<?php if ( $check->displayed_description ) : ?><p class="frb-hint"><?php echo esc_html( $check->displayed_description ); ?></p><?php endif; ?>
									<div class="frb-check-row">
										<label><?php esc_html_e( 'Result', 'fixreport-maintenance-logger' ); ?>
											<select class="frb-check-status" name="checklist_status[<?php echo esc_attr( $check_id ); ?>]">
												<?php foreach ( $check_statuses as $status_key => $status_label ) : ?><option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $check->status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option><?php endforeach; ?>
											</select>
										</label>
										<label class="frb-check-note"><?php esc_html_e( 'Report note', 'fixreport-maintenance-logger' ); ?>
											<textarea rows="2" name="checklist_note[<?php echo esc_attr( $check_id ); ?>]" data-frb-step="3" data-frb-max="10000" data-frb-label="<?php echo esc_attr( sprintf( /* translators: %s: checklist item label. */ __( 'Note for %s', 'fixreport-maintenance-logger' ), $check->displayed_label ) ); ?>"><?php echo esc_textarea( $check->note ); ?></textarea>
										</label>
									</div>
								</fieldset>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<?php /* ---------- Step 4: Summary & Recommendations ---------- */ ?>
				<section id="frb-panel-4" class="frb-card frb-panel" data-step="4" aria-labelledby="frb-heading-4">
					<?php $step_heading( 4 ); ?>
					<h2 id="frb-heading-4" tabindex="-1"><?php esc_html_e( 'Summary & Recommendations', 'fixreport-maintenance-logger' ); ?></h2>
					<p class="frb-lead"><?php esc_html_e( 'Write the narrative printed in the report. Empty sections are shown as a dash.', 'fixreport-maintenance-logger' ); ?></p>
					<?php
					foreach ( array(
						'assessment_summary' => __( 'Executive / Assessment Summary', 'fixreport-maintenance-logger' ),
						'findings' => __( 'Findings / Professional Notes', 'fixreport-maintenance-logger' ),
						'recommendations' => __( 'Recommendations / Attention Required', 'fixreport-maintenance-logger' ),
						'final_notes' => __( 'Final Notes', 'fixreport-maintenance-logger' ),
					) as $field => $field_label ) :
						?>
						<div class="frb-group frb-field">
							<label for="frb-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_label ); ?></label>
							<textarea id="frb-<?php echo esc_attr( $field ); ?>" rows="6" name="<?php echo esc_attr( $field ); ?>" data-frb-step="4" data-frb-max="100000" data-frb-label="<?php echo esc_attr( $field_label ); ?>"><?php echo esc_textarea( $report->$field ); ?></textarea>
						</div>
					<?php endforeach; ?>
				</section>

				<?php /* ---------- Step 5: Technical Details ---------- */ ?>
				<section id="frb-panel-5" class="frb-card frb-panel" data-step="5" aria-labelledby="frb-heading-5">
					<?php $step_heading( 5 ); ?>
					<h2 id="frb-heading-5" tabindex="-1"><?php esc_html_e( 'Technical Details', 'fixreport-maintenance-logger' ); ?></h2>
					<?php if ( ! $is_maintenance ) : ?>
						<div class="notice notice-info inline"><p><?php echo esc_html( $report->start_snapshot_id ? sprintf( /* translators: %d: snapshot ID. */ __( 'This Initial Assessment documents Initial Baseline snapshot #%d. Snapshot selection and comparison do not apply.', 'fixreport-maintenance-logger' ), (int) $report->start_snapshot_id ) : __( 'Snapshot selection and comparison do not apply to Initial Assessment reports.', 'fixreport-maintenance-logger' ) ); ?></p></div>
					<?php else : ?>
						<p class="frb-lead"><?php esc_html_e( 'Select the site state captured at the start and end of the period. Core, plugin and theme changes appear in the comparison. Capture times are actual UTC times; missing evidence is permitted.', 'fixreport-maintenance-logger' ); ?></p>
						<div class="frb-group frb-snapshots">
							<?php foreach ( array( 'start_snapshot_id' => __( 'Starting site state', 'fixreport-maintenance-logger' ), 'end_snapshot_id' => __( 'Ending site state', 'fixreport-maintenance-logger' ) ) as $field => $field_label ) : ?>
								<label class="frb-field" for="frb-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_label ); ?>
									<select id="frb-<?php echo esc_attr( $field ); ?>" class="frb-snapshot" name="<?php echo esc_attr( $field ); ?>" data-saved="<?php echo esc_attr( (int) $report->$field ); ?>">
										<option value="0"><?php esc_html_e( 'Omit', 'fixreport-maintenance-logger' ); ?></option>
										<?php foreach ( $snapshots as $snapshot ) : ?>
											<option value="<?php echo esc_attr( $snapshot->snapshot_id ); ?>" <?php selected( (int) $report->$field, (int) $snapshot->snapshot_id ); ?>>#<?php echo esc_html( $snapshot->snapshot_id ); ?> <?php echo esc_html( isset( $snapshot_types[ $snapshot->snapshot_type ] ) ? $snapshot_types[ $snapshot->snapshot_type ] : $snapshot->snapshot_type ); ?> · <?php echo esc_html( $snapshot->completed_at ); ?> UTC</option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php endforeach; ?>
						</div>
						<div class="frb-group">
							<label class="frb-inline-check"><input type="checkbox" name="include_comparison" value="1" <?php checked( (int) $report->include_comparison, 1 ); ?><?php disabled( ! $comparison_ready ); ?>> <?php esc_html_e( 'Include snapshot comparison', 'fixreport-maintenance-logger' ); ?></label>
							<p class="frb-hint"><?php echo esc_html( $comparison_ready ? __( 'The saved Start/End pair is eligible for comparison.', 'fixreport-maintenance-logger' ) : __( 'Save a valid, distinct, chronological Start/End pair first to enable comparison.', 'fixreport-maintenance-logger' ) ); ?></p>
							<p class="frb-hint frb-pair-changed" hidden></p>
						</div>
					<?php endif; ?>
				</section>

				<?php /* ---------- Step 6: Preview & Finalize ---------- */ ?>
				<section id="frb-panel-6" class="frb-card frb-panel" data-step="6" aria-labelledby="frb-heading-6">
					<?php $step_heading( 6 ); ?>
					<h2 id="frb-heading-6" tabindex="-1"><?php esc_html_e( 'Preview & Finalize', 'fixreport-maintenance-logger' ); ?></h2>
					<p class="frb-lead"><?php esc_html_e( 'This preview, the Draft PDF and Finalize all use the last saved revision. Finalizing freezes the report permanently.', 'fixreport-maintenance-logger' ); ?></p>
					<?php if ( ! $document ) : ?>
						<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: %s: error code. */ __( 'The saved draft cannot be previewed (%s). Draft PDF and Finalize are unavailable until this is resolved.', 'fixreport-maintenance-logger' ), $preview->get_error_code() ) ); ?></p></div>
					<?php else : ?>
						<?php if ( ! $gate ) : ?>
							<div class="notice notice-warning inline"><p><?php esc_html_e( 'Finalize blocked: include at least one resolved checklist item and resolve every included pending item, then save.', 'fixreport-maintenance-logger' ); ?></p></div>
						<?php endif; ?>
						<div class="frb-actions">
							<a class="button" href="<?php echo esc_url( $preview_url ); ?>" data-requires-saved><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e( 'Open full-page preview', 'fixreport-maintenance-logger' ); ?></a>
							<button type="submit" form="frb-pdf-form" class="button" data-requires-saved><span class="dashicons dashicons-pdf" aria-hidden="true"></span><?php esc_html_e( 'Download Draft PDF', 'fixreport-maintenance-logger' ); ?></button>
							<button type="submit" form="frb-finalize-form" class="button button-primary frb-finalize" data-requires-saved data-gate="<?php echo $gate ? '1' : '0'; ?>"<?php disabled( ! $gate ); ?>><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Finalize immutable report', 'fixreport-maintenance-logger' ); ?></button>
						</div>
						<div class="frb-document">
							<?php
							$compact_draft = true;
							require plugin_dir_path( __FILE__ ) . ( FixReport_Report_Layout::COMPACT === FixReport_Report_Layout::version( $document, true ) ? 'report-document-compact.php' : 'report-document.php' );
							?>
						</div>
					<?php endif; ?>
				</section>
			</div>
		</div>

		<div class="frb-card frb-toolbar">
			<button type="button" class="button frb-prev" hidden><span class="dashicons dashicons-arrow-left-alt frb-flip" aria-hidden="true"></span><span class="frb-prev-label"></span></button>
			<a class="button frb-preview-link" href="<?php echo esc_url( $preview_url ); ?>" data-requires-saved><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Preview Report', 'fixreport-maintenance-logger' ); ?></a>
			<button type="button" class="button button-primary frb-next" hidden><span class="frb-next-label"></span><span class="dashicons dashicons-arrow-right-alt frb-flip" aria-hidden="true"></span></button>
		</div>
	</form>

	<?php if ( $document ) : ?>
		<form id="frb-pdf-form" class="frb-hidden-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
			<input type="hidden" name="report_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fixreport_report_pdf_' . $id ) ); ?>">
			<input type="hidden" name="action" value="fixreport_report_pdf">
			<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="expected_status" value="<?php echo esc_attr( $report->status ); ?>">
			<input type="hidden" name="draft_revision" value="<?php echo esc_attr( $preview['revision'] ); ?>">
			<input type="hidden" name="model_hash" value="<?php echo esc_attr( $preview['model_hash'] ); ?>">
		</form>
		<form id="frb-finalize-form" class="frb-hidden-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
			<input type="hidden" name="report_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fixreport_report_finalize_' . $id ) ); ?>">
			<input type="hidden" name="action" value="fixreport_report_finalize">
			<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="draft_revision" value="<?php echo esc_attr( $preview['revision'] ); ?>">
			<input type="hidden" name="model_hash" value="<?php echo esc_attr( $preview['model_hash'] ); ?>">
		</form>
	<?php endif; ?>
</div>
