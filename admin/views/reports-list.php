<?php
/**
 * All Reports. Rendered by FixReport_Report_Admin::list_page().
 *
 * Every row and total comes from stored report records. Row actions use the
 * existing routes only: Drafts open the Report Builder (mode=edit) or the
 * Draft Preview (mode=preview); finalized reports open the Finalized View
 * (mode=view) or post to the existing nonce-protected PDF route. Finalized
 * reports never get an edit or reopen action. Creation uses the existing
 * nonce-protected create form.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$post_url = admin_url( 'admin-post.php' );
$back_url = add_query_arg( array( 'page' => 'fixreport-maintenance-logger' ), admin_url( 'admin.php' ) );
$type_labels = array( 'maintenance' => __( 'Maintenance Report', 'fixreport-maintenance-logger' ), 'initial_assessment' => __( 'Initial Assessment', 'fixreport-maintenance-logger' ) );
$status_labels = array( 'draft' => __( 'Draft', 'fixreport-maintenance-logger' ), 'finalized' => __( 'Finalized', 'fixreport-maintenance-logger' ) );
$date_format = get_option( 'date_format' );
$utc_time = static function ( $value ) {
	$time = is_string( $value ) && '' !== $value ? strtotime( $value . ' UTC' ) : false;
	return $time ? $time : 0;
};
$local_day = static function ( $ymd ) use ( $date_format ) {
	$time = is_string( $ymd ) ? strtotime( $ymd . ' 00:00:00 UTC' ) : false;
	return $time ? wp_date( $date_format, $time, new DateTimeZone( 'UTC' ) ) : '';
};
?>
<div class="fixreport-reports">
	<p class="frl-back"><a href="<?php echo esc_url( $back_url ); ?>"><span class="dashicons dashicons-arrow-left-alt frl-flip" aria-hidden="true"></span><?php esc_html_e( 'Back to Maintenance Activities', 'fixreport-maintenance-logger' ); ?></a></p>

	<div class="frl-head">
		<div class="frl-head-main">
			<h1><?php esc_html_e( 'All Reports', 'fixreport-maintenance-logger' ); ?></h1>
			<p class="frl-lead"><?php esc_html_e( 'Manage maintenance report drafts and finalized documents.', 'fixreport-maintenance-logger' ); ?></p>
		</div>
		<a class="button button-primary frl-create-link" href="#fixreport-create-report"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Create New Report', 'fixreport-maintenance-logger' ); ?></a>
	</div>
	<hr class="wp-header-end">

	<ul class="frl-stats" aria-label="<?php esc_attr_e( 'Report totals', 'fixreport-maintenance-logger' ); ?>">
		<li class="frl-stat">
			<span class="frl-stat-label"><?php esc_html_e( 'Total Reports', 'fixreport-maintenance-logger' ); ?></span>
			<span class="frl-stat-row"><strong class="frl-stat-value"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong><span class="frl-stat-note"><?php esc_html_e( 'stored', 'fixreport-maintenance-logger' ); ?></span></span>
		</li>
		<li class="frl-stat frl-stat-draft">
			<span class="frl-stat-label"><?php esc_html_e( 'Draft Reports', 'fixreport-maintenance-logger' ); ?></span>
			<span class="frl-stat-row"><strong class="frl-stat-value"><?php echo esc_html( number_format_i18n( $counts['draft'] ) ); ?></strong><span class="frl-stat-note"><?php esc_html_e( 'in progress', 'fixreport-maintenance-logger' ); ?></span></span>
		</li>
		<li class="frl-stat frl-stat-final">
			<span class="frl-stat-label"><?php esc_html_e( 'Finalized Reports', 'fixreport-maintenance-logger' ); ?></span>
			<span class="frl-stat-row"><strong class="frl-stat-value"><?php echo esc_html( number_format_i18n( $counts['finalized'] ) ); ?></strong><span class="frl-stat-note"><?php esc_html_e( 'immutable', 'fixreport-maintenance-logger' ); ?></span></span>
		</li>
	</ul>

	<div class="frl-panel">
		<div class="frl-panel-bar">
			<h2 id="frl-table-heading"><?php esc_html_e( 'Reports', 'fixreport-maintenance-logger' ); ?></h2>
			<p class="frl-count">
				<?php
				echo esc_html(
					$reports
						/* translators: 1: first report shown, 2: last report shown, 3: total number of stored reports. */
						? sprintf( __( 'Showing %1$s–%2$s of %3$s reports', 'fixreport-maintenance-logger' ), number_format_i18n( $first_shown ), number_format_i18n( $last_shown ), number_format_i18n( $counts['total'] ) )
						/* translators: %s: total number of stored reports. */
						: sprintf( __( 'Showing 0 of %s reports', 'fixreport-maintenance-logger' ), number_format_i18n( $counts['total'] ) )
				);
				?>
			</p>
		</div>
		<div class="frl-table-wrap">
			<table class="frl-table" aria-labelledby="frl-table-heading">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Report Title', 'fixreport-maintenance-logger' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reporting Period', 'fixreport-maintenance-logger' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Report Type', 'fixreport-maintenance-logger' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fixreport-maintenance-logger' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Updated / Finalized', 'fixreport-maintenance-logger' ); ?></th>
						<th scope="col" class="frl-col-actions"><?php esc_html_e( 'Actions', 'fixreport-maintenance-logger' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $reports && $out_of_range ) : ?>
					<tr><td colspan="6" class="frl-empty">
						<?php
						/* translators: %s: number of the last page. */
						echo esc_html( sprintf( _n( 'This page does not exist. The report list has %s page.', 'This page does not exist. The report list has %s pages.', $total_pages, 'fixreport-maintenance-logger' ), number_format_i18n( $total_pages ) ) );
						?>
						<a href="<?php echo esc_url( $page_url( 1 ) ); ?>"><?php esc_html_e( 'Go to the first page', 'fixreport-maintenance-logger' ); ?></a>
					</td></tr>
				<?php elseif ( ! $reports && 0 === (int) $counts['total'] ) : ?>
					<tr><td colspan="6" class="frl-empty"><?php esc_html_e( 'No reports have been created yet.', 'fixreport-maintenance-logger' ); ?></td></tr>
				<?php elseif ( ! $reports ) : ?>
					<tr><td colspan="6" class="frl-empty"><?php esc_html_e( 'No reports could be listed on this page. Reload the page to try again.', 'fixreport-maintenance-logger' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $reports as $row ) :
					$id = (int) $row->report_id;
					$is_draft = 'draft' === $row->status;
					$is_final = 'finalized' === $row->status;
					$title = '' !== trim( (string) $row->title ) ? (string) $row->title : sprintf( /* translators: %d: report ID. */ __( 'Report #%d', 'fixreport-maintenance-logger' ), $id );
					$period = $periods[ $id ] ?? null;
					$primary_url = add_query_arg( array( 'page' => 'fixreport-reports', 'mode' => $is_draft ? 'edit' : 'view', 'report_id' => $id ), admin_url( 'admin.php' ) );
					$changed = $utc_time( $is_final ? $row->finalized_at : $row->updated_at );
					?>
					<tr class="<?php echo $is_draft ? 'is-draft' : 'is-finalized'; ?>">
						<td class="frl-title" data-colname="<?php esc_attr_e( 'Report Title', 'fixreport-maintenance-logger' ); ?>">
							<span class="dashicons <?php echo $is_draft ? 'dashicons-media-document' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
							<span class="frl-title-text">
								<?php if ( $is_draft || $is_final ) : ?><a href="<?php echo esc_url( $primary_url ); ?>"><?php echo esc_html( $title ); ?></a><?php else : ?><?php echo esc_html( $title ); ?><?php endif; ?>
								<span class="frl-id">#<?php echo esc_html( $id ); ?></span>
							</span>
						</td>
						<td data-colname="<?php esc_attr_e( 'Reporting Period', 'fixreport-maintenance-logger' ); ?>">
							<?php if ( $period ) : ?>
								<?php
								/* translators: 1: first day, 2: last day of the reporting period. */
								echo esc_html( sprintf( __( '%1$s – %2$s', 'fixreport-maintenance-logger' ), $local_day( $period['from'] ), $local_day( $period['through'] ) ) );
								?>
								<span class="frl-sub"><?php echo esc_html( $period['timezone'] ); ?></span>
							<?php elseif ( 'initial_assessment' === $row->report_type ) : ?>
								<span class="frl-muted"><?php esc_html_e( 'Initial site snapshot', 'fixreport-maintenance-logger' ); ?></span>
							<?php else : ?>
								<span class="frl-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Report Type', 'fixreport-maintenance-logger' ); ?>">
							<span class="frl-type frl-type-<?php echo esc_attr( sanitize_html_class( (string) $row->report_type ) ); ?>"><?php echo esc_html( $type_labels[ $row->report_type ] ?? (string) $row->report_type ); ?></span>
						</td>
						<td data-colname="<?php esc_attr_e( 'Status', 'fixreport-maintenance-logger' ); ?>">
							<span class="frl-status frl-status-<?php echo esc_attr( sanitize_html_class( (string) $row->status ) ); ?>"><?php echo esc_html( $status_labels[ $row->status ] ?? (string) $row->status ); ?></span>
						</td>
						<td data-colname="<?php esc_attr_e( 'Last Updated / Finalized', 'fixreport-maintenance-logger' ); ?>">
							<?php if ( $changed && $is_final ) : ?>
								<?php
								/* translators: %s: finalization date. */
								echo esc_html( sprintf( __( 'Finalized %s', 'fixreport-maintenance-logger' ), wp_date( $date_format, $changed ) ) );
								?>
							<?php elseif ( $changed ) : ?>
								<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i', $changed ) . ' UTC' ); ?>">
									<?php
									/* translators: %s: relative time, for example "2 hours". */
									echo esc_html( sprintf( __( 'Updated %s ago', 'fixreport-maintenance-logger' ), human_time_diff( $changed, time() ) ) );
									?>
								</span>
							<?php else : ?>
								<span class="frl-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="frl-col-actions" data-colname="<?php esc_attr_e( 'Actions', 'fixreport-maintenance-logger' ); ?>">
							<div class="frl-actions">
								<?php if ( $is_draft ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( $primary_url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: report title. */ __( 'Continue editing %s', 'fixreport-maintenance-logger' ), $title ) ); ?>"><?php esc_html_e( 'Continue Editing', 'fixreport-maintenance-logger' ); ?></a>
									<a class="frl-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'fixreport-reports', 'mode' => 'preview', 'report_id' => $id ), admin_url( 'admin.php' ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: report title. */ __( 'Preview %s', 'fixreport-maintenance-logger' ), $title ) ); ?>"><?php esc_html_e( 'Preview', 'fixreport-maintenance-logger' ); ?></a>
								<?php elseif ( $is_final ) : ?>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>">
										<input type="hidden" name="report_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fixreport_report_pdf_' . $id ) ); ?>">
										<input type="hidden" name="action" value="fixreport_report_pdf">
										<input type="hidden" name="report_id" value="<?php echo esc_attr( $id ); ?>">
										<input type="hidden" name="expected_status" value="finalized">
										<button type="submit" class="button" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: report title. */ __( 'Download PDF of %s', 'fixreport-maintenance-logger' ), $title ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Download PDF', 'fixreport-maintenance-logger' ); ?></button>
									</form>
									<a class="frl-link" href="<?php echo esc_url( $primary_url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: report title. */ __( 'View report %s', 'fixreport-maintenance-logger' ), $title ) ); ?>"><?php esc_html_e( 'View Report', 'fixreport-maintenance-logger' ); ?></a>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $total_pages > 1 || $out_of_range ) : ?>
			<nav class="frl-pager" aria-label="<?php esc_attr_e( 'Report list pages', 'fixreport-maintenance-logger' ); ?>">
				<p class="frl-pager-info">
					<?php
					echo esc_html(
						$out_of_range
							/* translators: %s: number of pages. */
							? sprintf( __( 'Page out of range · %s pages in total', 'fixreport-maintenance-logger' ), number_format_i18n( $total_pages ) )
							/* translators: 1: current page, 2: number of pages. */
							: sprintf( __( 'Page %1$s of %2$s', 'fixreport-maintenance-logger' ), number_format_i18n( $current_page ), number_format_i18n( $total_pages ) )
					);
					?>
				</p>
				<div class="frl-pager-links">
					<?php $previous = $out_of_range ? $total_pages : $current_page - 1; ?>
					<?php if ( $previous >= 1 ) : ?>
						<a class="button" href="<?php echo esc_url( $page_url( $previous ) ); ?>" rel="prev"><span class="dashicons dashicons-arrow-left-alt2 frl-flip" aria-hidden="true"></span><?php esc_html_e( 'Previous', 'fixreport-maintenance-logger' ); ?></a>
					<?php else : ?>
						<span class="button disabled" aria-disabled="true"><span class="dashicons dashicons-arrow-left-alt2 frl-flip" aria-hidden="true"></span><?php esc_html_e( 'Previous', 'fixreport-maintenance-logger' ); ?></span>
					<?php endif; ?>
					<?php if ( ! $out_of_range && $current_page < $total_pages ) : ?>
						<a class="button" href="<?php echo esc_url( $page_url( $current_page + 1 ) ); ?>" rel="next"><?php esc_html_e( 'Next', 'fixreport-maintenance-logger' ); ?><span class="dashicons dashicons-arrow-right-alt2 frl-flip" aria-hidden="true"></span></a>
					<?php else : ?>
						<span class="button disabled" aria-disabled="true"><?php esc_html_e( 'Next', 'fixreport-maintenance-logger' ); ?><span class="dashicons dashicons-arrow-right-alt2 frl-flip" aria-hidden="true"></span></span>
					<?php endif; ?>
				</div>
			</nav>
		<?php endif; ?>
	</div>

	<section id="fixreport-create-report" class="frl-create" aria-labelledby="frl-create-heading" tabindex="-1">
		<h2 id="frl-create-heading"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Create New Report', 'fixreport-maintenance-logger' ); ?></h2>
		<p class="frl-create-lead"><?php esc_html_e( 'Creates a maintenance report draft for the chosen period. You complete it in the Report Builder.', 'fixreport-maintenance-logger' ); ?></p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'fixreport_report_create_0', 'report_nonce' ); ?>
			<input type="hidden" name="action" value="fixreport_report_create">
			<div class="frl-fields">
				<p class="frl-field frl-field-wide"><label for="frl-title"><?php esc_html_e( 'Title', 'fixreport-maintenance-logger' ); ?></label><input id="frl-title" required maxlength="255" name="title" class="regular-text"></p>
				<p class="frl-field"><label for="frl-from"><?php esc_html_e( 'From', 'fixreport-maintenance-logger' ); ?></label><input id="frl-from" required type="date" name="from"></p>
				<p class="frl-field"><label for="frl-through"><?php esc_html_e( 'Through', 'fixreport-maintenance-logger' ); ?></label><input id="frl-through" required type="date" name="through"></p>
				<p class="frl-field"><label for="frl-locale"><?php esc_html_e( 'Report language', 'fixreport-maintenance-logger' ); ?></label><select id="frl-locale" name="report_locale"><option value="en_US">English</option><option value="ar">العربية</option></select></p>
			</div>
			<p class="frl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Create populated Draft', 'fixreport-maintenance-logger' ); ?></button></p>
		</form>
	</section>

	<div class="frl-note" role="note">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<p><strong><?php esc_html_e( 'Compliance note:', 'fixreport-maintenance-logger' ); ?></strong> <?php esc_html_e( 'Finalized reports are immutable historical records. FixReport cannot edit, reopen, or delete them, and their view and PDF use only the integrity-verified document frozen at finalization. To document another maintenance period, create a new report.', 'fixreport-maintenance-logger' ); ?></p>
	</div>
</div>
