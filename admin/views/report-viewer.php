<?php
/**
 * Report Preview (Draft) and Finalized View. Rendered by FixReport_Report_Admin::document_page().
 *
 * The document area is the compact 9.2 renderer (report-document-compact.php) or,
 * for reports finalized without the 9.2 marker, the historical continuous renderer
 * (report-document.php), fed by FixReport_Report_Service::preview(): the saved Draft revision, or the
 * integrity-verified frozen document of a finalized report. Every figure in the
 * sidebar is read from that same document; nothing is estimated or invented.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$post_url = admin_url( 'admin-post.php' );
$list_url = add_query_arg( array( 'page' => 'fixreport-reports' ), admin_url( 'admin.php' ) );
$builder_url = add_query_arg( array( 'page' => 'fixreport-reports', 'mode' => 'edit', 'report_id' => $id, 'step' => 6 ), admin_url( 'admin.php' ) );
$type_labels = array( 'maintenance' => __( 'Maintenance Report', 'fixreport-maintenance-logger' ), 'initial_assessment' => __( 'Initial Assessment', 'fixreport-maintenance-logger' ) );
$type_key = $document['identity']['type'] ?? $report->report_type;
$type_label = isset( $type_labels[ $type_key ] ) ? $type_labels[ $type_key ] : $type_key;
$title = $document['identity']['title'] ?? $report->title;
$locale_key = $document['locale'] ?? $report->report_locale;
$locale_labels = array( 'en_US' => __( 'English (LTR)', 'fixreport-maintenance-logger' ), 'ar' => __( 'Arabic (RTL)', 'fixreport-maintenance-logger' ) );
$contract = $document['contract_version'] ?? $report->payload_schema_version;
$utc = static function ( $value ) {
	$time = $value ? strtotime( $value . ' UTC' ) : false;
	return $time ? gmdate( 'Y-m-d H:i', $time ) . ' UTC' : '';
};
$finalized_at = $document['finalization']['at_utc'] ?? $report->finalized_at;
// Historical field: show only the user ID frozen at finalization, never a live (mutable) account name.
$finalized_by = (int) ( $document['finalization']['by_user_id'] ?? $report->finalized_by );
/* translators: %d: WordPress user ID recorded at finalization. */
$finalizer_label = $finalized_by ? sprintf( __( 'User ID %d', 'fixreport-maintenance-logger' ), $finalized_by ) : '';
$saved_at = $report->updated_at ? strtotime( $report->updated_at . ' UTC' ) : false;
$check_labels = array(
	'completed' => __( 'completed', 'fixreport-maintenance-logger' ),
	'not_applicable' => __( 'not applicable', 'fixreport-maintenance-logger' ),
	'requires_attention' => __( 'requires attention', 'fixreport-maintenance-logger' ),
	'pending' => __( 'pending', 'fixreport-maintenance-logger' ),
);
$snapshot_types = array( 'initial' => __( 'Initial Baseline', 'fixreport-maintenance-logger' ), 'manual' => __( 'Manual', 'fixreport-maintenance-logger' ), 'checkpoint' => __( 'Checkpoint', 'fixreport-maintenance-logger' ) );
$activities = $document && is_array( $document['activities'] ?? null ) ? $document['activities'] : array();
$checklist = $document && is_array( $document['checklist'] ?? null ) ? $document['checklist'] : array();
$check_counts = array_fill_keys( array_keys( $check_labels ), 0 );
foreach ( $checklist as $row ) { if ( isset( $row['status'], $check_counts[ $row['status'] ] ) ) { $check_counts[ $row['status'] ]++; } }
$check_parts = array();
foreach ( $check_counts as $status_key => $status_count ) { if ( $status_count ) { $check_parts[] = $status_count . ' ' . $check_labels[ $status_key ]; } }
$state_text = static function ( $state ) use ( $snapshot_types, $utc ) {
	if ( ! is_array( $state ) || empty( $state['id'] ) ) { return __( 'Not included', 'fixreport-maintenance-logger' ); }
	$type = isset( $state['type'], $snapshot_types[ $state['type'] ] ) ? $snapshot_types[ $state['type'] ] : (string) ( $state['type'] ?? '' );
	/* translators: 1: snapshot ID, 2: snapshot type, 3: capture time. */
	return sprintf( __( 'Snapshot #%1$d · %2$s · %3$s', 'fixreport-maintenance-logger' ), (int) $state['id'], $type, $utc( $state['completed_at'] ?? '' ) );
};
$is_maintenance = 'maintenance' === $type_key;
?>
<div class="fixreport-viewer <?php echo $is_draft ? 'is-draft' : 'is-finalized'; ?>">
	<p class="frv-back"><a href="<?php echo esc_url( $list_url ); ?>"><span class="dashicons dashicons-arrow-left-alt frv-flip" aria-hidden="true"></span><?php esc_html_e( 'Back to All Reports', 'fixreport-maintenance-logger' ); ?></a></p>
	<hr class="wp-header-end">

	<div class="frv-head">
		<div class="frv-head-main">
			<div class="frv-title-row">
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php if ( $is_draft ) : ?>
					<span class="frv-badge frv-badge-draft"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Draft preview', 'fixreport-maintenance-logger' ); ?></span>
				<?php else : ?>
					<span class="frv-badge frv-badge-final"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Finalized', 'fixreport-maintenance-logger' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="frv-meta">
				<?php if ( $period ) : ?><span><?php esc_html_e( 'Reporting period:', 'fixreport-maintenance-logger' ); ?> <strong><?php echo esc_html( sprintf( /* translators: 1: first day, 2: last day. */ __( '%1$s through %2$s', 'fixreport-maintenance-logger' ), $period['from'], $period['through'] ) ); ?></strong></span><?php endif; ?>
				<span><?php esc_html_e( 'Type:', 'fixreport-maintenance-logger' ); ?> <strong><?php echo esc_html( $type_label ); ?></strong></span>
				<span><?php esc_html_e( 'Report ID:', 'fixreport-maintenance-logger' ); ?> <strong class="frv-code">#<?php echo esc_html( $id ); ?></strong></span>
			</p>
		</div>
		<div class="frv-head-actions">
			<?php if ( $is_draft ) : ?>
				<a class="button" href="<?php echo esc_url( $builder_url ); ?>"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span><?php esc_html_e( 'Back to Report Builder', 'fixreport-maintenance-logger' ); ?></a>
			<?php endif; ?>
			<?php if ( $document ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="report_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fixreport_report_pdf_' . $id ) ); ?>">
					<input type="hidden" name="action" value="fixreport_report_pdf">
					<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="expected_status" value="<?php echo esc_attr( $report->status ); ?>">
					<?php if ( $is_draft ) : ?>
						<input type="hidden" name="draft_revision" value="<?php echo esc_attr( $preview['revision'] ); ?>">
						<input type="hidden" name="model_hash" value="<?php echo esc_attr( $preview['model_hash'] ); ?>">
					<?php endif; ?>
					<button type="submit" class="button <?php echo $is_draft ? '' : 'button-primary'; ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php echo esc_html( $is_draft ? __( 'Download Draft PDF', 'fixreport-maintenance-logger' ) : __( 'Download Final PDF', 'fixreport-maintenance-logger' ) ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( $is_draft && $document ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="<?php echo esc_attr( 'return window.confirm(' . wp_json_encode( __( 'Finalize this report? The saved draft becomes an immutable historical report and can no longer be edited.', 'fixreport-maintenance-logger' ) ) . ');' ); ?>">
					<input type="hidden" name="report_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fixreport_report_finalize_' . $id ) ); ?>">
					<input type="hidden" name="action" value="fixreport_report_finalize">
					<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="draft_revision" value="<?php echo esc_attr( $preview['revision'] ); ?>">
					<input type="hidden" name="model_hash" value="<?php echo esc_attr( $preview['model_hash'] ); ?>">
					<button type="submit" class="button button-primary"<?php disabled( ! $gate ); ?>><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Finalize immutable report', 'fixreport-maintenance-logger' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! $document ) : ?>
		<div class="notice notice-error frv-banner"><p>
			<?php
			echo esc_html(
				$is_draft
					/* translators: %s: error code. */
					? sprintf( __( 'The saved draft cannot be previewed (%s). Draft PDF and Finalize are unavailable until this is resolved in the Report Builder.', 'fixreport-maintenance-logger' ), $preview->get_error_code() )
					/* translators: %s: error code. */
					: sprintf( __( 'Integrity verification of the finalized report failed (%s). The frozen document is not displayed or exported.', 'fixreport-maintenance-logger' ), $preview->get_error_code() )
			);
			?>
		</p></div>
	<?php elseif ( $is_draft ) : ?>
		<div class="frv-banner frv-banner-draft" role="note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p><strong><?php esc_html_e( 'Unfinalized draft.', 'fixreport-maintenance-logger' ); ?></strong>
				<?php echo esc_html( sprintf( /* translators: %d: draft revision. */ __( 'This preview shows saved revision %d. Unsaved changes in the Report Builder are not included, and this document is not a finalized record until you finalize it.', 'fixreport-maintenance-logger' ), (int) $preview['revision'] ) ); ?>
			</p>
		</div>
		<?php if ( ! $gate ) : ?>
			<div class="notice notice-warning inline frv-banner"><p><?php esc_html_e( 'Finalize blocked: include at least one resolved checklist item and resolve every included pending item in the Report Builder, then save.', 'fixreport-maintenance-logger' ); ?></p></div>
		<?php endif; ?>
	<?php else : ?>
		<div class="frv-banner frv-banner-final" role="note">
			<span class="dashicons dashicons-shield" aria-hidden="true"></span>
			<p><strong><?php esc_html_e( 'Immutable historical record.', 'fixreport-maintenance-logger' ); ?></strong>
				<?php
				echo esc_html(
					$finalized_at
						/* translators: 1: finalization time, 2: "User ID n" recorded at finalization. */
						? sprintf( __( 'Finalized %1$s by %2$s. This view and its PDF use only the frozen document stored at finalization, so later changes to activities, snapshots, branding, or settings do not change it.', 'fixreport-maintenance-logger' ), $utc( $finalized_at ), $finalizer_label )
						: __( 'This view and its PDF use only the frozen document stored at finalization, so later changes to activities, snapshots, branding, or settings do not change it.', 'fixreport-maintenance-logger' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="frv-layout">
		<div class="frv-main">
			<?php if ( $document ) : ?>
				<div class="frv-doc-bar">
					<span><span class="dashicons dashicons-media-document" aria-hidden="true"></span><?php echo esc_html( $is_draft ? __( 'Saved draft document', 'fixreport-maintenance-logger' ) : __( 'Frozen finalized document', 'fixreport-maintenance-logger' ) ); ?></span>
					<span class="frv-hint"><?php echo esc_html( isset( $locale_labels[ $locale_key ] ) ? $locale_labels[ $locale_key ] : (string) $locale_key ); ?></span>
				</div>
				<div class="frv-paper">
					<?php
					// Layout 9.2 for Drafts and for reports frozen with the 9.2 marker; historical 9.1 otherwise.
					$compact_draft = $is_draft;
					require plugin_dir_path( __FILE__ ) . ( FixReport_Report_Layout::COMPACT === FixReport_Report_Layout::version( $document, $is_draft ) ? 'report-document-compact.php' : 'report-document.php' );
					?>
				</div>
			<?php else : ?>
				<div class="frv-card frv-empty"><p><?php esc_html_e( 'No document is shown because it could not be produced safely.', 'fixreport-maintenance-logger' ); ?></p></div>
			<?php endif; ?>
		</div>

		<aside class="frv-side" aria-label="<?php esc_attr_e( 'Report information', 'fixreport-maintenance-logger' ); ?>">
			<section class="frv-card">
				<h2><span class="dashicons dashicons-media-text" aria-hidden="true"></span><?php esc_html_e( 'Document Details', 'fixreport-maintenance-logger' ); ?></h2>
				<dl class="frv-facts">
					<div><dt><?php esc_html_e( 'Document title', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( $title ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Report type', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( $type_label ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Reporting period', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( $period ? sprintf( /* translators: 1: first day, 2: last day, 3: time zone. */ __( '%1$s through %2$s (%3$s)', 'fixreport-maintenance-logger' ), $period['from'], $period['through'], $period['timezone'] ) : __( 'Not applicable', 'fixreport-maintenance-logger' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Report language', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( isset( $locale_labels[ $locale_key ] ) ? $locale_labels[ $locale_key ] : (string) $locale_key ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Document status', 'fixreport-maintenance-logger' ); ?></dt><dd class="frv-status"><?php echo esc_html( $is_draft ? __( 'Draft (editable in the Report Builder)', 'fixreport-maintenance-logger' ) : __( 'Finalized (read-only)', 'fixreport-maintenance-logger' ) ); ?></dd></div>
					<?php if ( $is_draft ) : ?>
						<div><dt><?php esc_html_e( 'Saved revision', 'fixreport-maintenance-logger' ); ?></dt><dd><?php echo esc_html( $document ? (int) $preview['revision'] : (int) $report->draft_revision ); ?><?php echo $saved_at ? esc_html( ' · ' . sprintf( /* translators: %s: relative time. */ __( 'saved %s ago', 'fixreport-maintenance-logger' ), human_time_diff( $saved_at, time() ) ) ) : ''; ?></dd></div>
					<?php else : ?>
						<div><dt><?php esc_html_e( 'Finalized', 'fixreport-maintenance-logger' ); ?></dt><dd class="frv-code"><?php echo esc_html( $utc( $finalized_at ) ?: '—' ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Finalized by User ID', 'fixreport-maintenance-logger' ); ?></dt><dd class="frv-code"><?php echo esc_html( $finalized_by ? (string) $finalized_by : '—' ); ?></dd></div>
					<?php endif; ?>
					<div><dt><?php esc_html_e( 'Report ID', 'fixreport-maintenance-logger' ); ?></dt><dd class="frv-code">#<?php echo esc_html( $id ); ?></dd></div>
					<?php if ( $contract ) : ?>
						<div><dt><?php esc_html_e( 'Document contract', 'fixreport-maintenance-logger' ); ?></dt><dd class="frv-code"><?php echo esc_html( $contract ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</section>

			<?php if ( $document ) : ?>
				<section class="frv-card">
					<h2><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Included Evidence', 'fixreport-maintenance-logger' ); ?></h2>
					<?php if ( $is_maintenance ) : ?>
						<ul class="frv-evidence">
							<li><span><?php esc_html_e( 'Activities', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( sprintf( /* translators: %d: number of activities. */ _n( '%d included', '%d included', count( $activities ), 'fixreport-maintenance-logger' ), count( $activities ) ) ); ?></strong></li>
							<li><span><?php esc_html_e( 'Checklist items', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( sprintf( /* translators: %d: number of checklist items. */ _n( '%d included', '%d included', count( $checklist ), 'fixreport-maintenance-logger' ), count( $checklist ) ) ); ?></strong></li>
							<?php if ( $check_parts ) : ?><li class="frv-evidence-sub"><span><?php echo esc_html( implode( ' · ', $check_parts ) ); ?></span></li><?php endif; ?>
							<li><span><?php esc_html_e( 'Starting state', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( $state_text( $document['start_state'] ?? null ) ); ?></strong></li>
							<li><span><?php esc_html_e( 'Ending state', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( $state_text( $document['end_state'] ?? null ) ); ?></strong></li>
							<li><span><?php esc_html_e( 'Snapshot comparison', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( ! empty( $document['comparison'] ) ? __( 'Included', 'fixreport-maintenance-logger' ) : __( 'Not included', 'fixreport-maintenance-logger' ) ); ?></strong></li>
						</ul>
						<?php if ( ! empty( $document['coverage']['legacy_clock_present'] ) ) : ?>
							<p class="frv-hint"><?php esc_html_e( 'Some included activities were migrated with a legacy local clock, so their exact chronology is uncertain.', 'fixreport-maintenance-logger' ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<ul class="frv-evidence">
							<li><span><?php esc_html_e( 'Initial Baseline', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( $state_text( $document['start_state'] ?? null ) ); ?></strong></li>
						</ul>
					<?php endif; ?>
					<p class="frv-hint"><?php esc_html_e( 'Counts are read from the document shown here.', 'fixreport-maintenance-logger' ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( ! $is_draft ) : ?>
				<section class="frv-card">
					<h2><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Historical Integrity', 'fixreport-maintenance-logger' ); ?></h2>
					<?php if ( $document ) : ?>
						<p><?php esc_html_e( 'When this page loaded, the stored frozen document matched its recorded SHA-256 digest, and any frozen logo matched its recorded hash. A mismatch would have blocked this view and the PDF.', 'fixreport-maintenance-logger' ); ?></p>
					<?php endif; ?>
					<?php if ( $report->frozen_payload_sha256 ) : ?>
						<p class="frv-label"><?php esc_html_e( 'Recorded SHA-256 of the frozen document', 'fixreport-maintenance-logger' ); ?></p>
						<code class="frv-hash" dir="ltr"><?php echo esc_html( $report->frozen_payload_sha256 ); ?></code>
					<?php endif; ?>
					<p class="frv-lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Finalized reports are read-only in FixReport. They cannot be edited or reopened.', 'fixreport-maintenance-logger' ); ?></p>
				</section>
			<?php elseif ( $document ) : ?>
				<section class="frv-card">
					<h2><span class="dashicons dashicons-flag" aria-hidden="true"></span><?php esc_html_e( 'Before Finalizing', 'fixreport-maintenance-logger' ); ?></h2>
					<p><?php echo esc_html( $gate ? __( 'The checklist gate is met. Finalizing freezes exactly the saved revision shown here.', 'fixreport-maintenance-logger' ) : __( 'Finalize is blocked until at least one included checklist item is resolved and no included item is pending.', 'fixreport-maintenance-logger' ) ); ?></p>
					<p class="frv-hint"><?php esc_html_e( 'If you change the draft, save it in the Report Builder and open this preview again before finalizing.', 'fixreport-maintenance-logger' ); ?></p>
				</section>
			<?php endif; ?>
		</aside>
	</div>
</div>
