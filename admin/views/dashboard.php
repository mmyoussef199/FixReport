<?php
/** Read-only FixReport landing page. Data is supplied by FixReport_Admin. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$activities_url = admin_url( 'admin.php?page=fixreport-maintenance-logger' );
$reports_url = admin_url( 'admin.php?page=fixreport-reports' );
$snapshot_available = ! is_wp_error( $snapshot ) && $snapshot;
$snapshot_time = $snapshot_available && ! empty( $snapshot->completed_at ) ? strtotime( $snapshot->completed_at . ' UTC' ) : false;
$draft_url = ! is_wp_error( $draft ) && $draft && (int) $draft->report_id > 0
	? add_query_arg( array( 'page' => 'fixreport-reports', 'mode' => 'edit', 'report_id' => (int) $draft->report_id ), admin_url( 'admin.php' ) )
	: '';
$cards = array(
	array( 'title' => __( 'Activities', 'fixreport-maintenance-logger' ), 'description' => __( 'Recorded maintenance and documented work', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-backup', 'url' => $activities_url ),
	array( 'title' => __( 'Site Snapshots', 'fixreport-maintenance-logger' ), 'description' => __( 'Historical environment captures', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-camera', 'url' => admin_url( 'admin.php?page=fixreport-site-snapshots' ) ),
	array( 'title' => __( 'Site Baseline', 'fixreport-maintenance-logger' ), 'description' => __( 'Recorded initial site state', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-chart-area', 'url' => admin_url( 'admin.php?page=fixreport-site-baseline' ) ),
	array( 'title' => __( 'Maintenance Checklist', 'fixreport-maintenance-logger' ), 'description' => __( 'Operational task documentation', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-yes-alt', 'url' => admin_url( 'admin.php?page=fixreport-maintenance-checklist' ) ),
	array( 'title' => __( 'All Reports', 'fixreport-maintenance-logger' ), 'description' => __( 'Drafts and immutable finalized reports', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-media-document', 'url' => $reports_url ),
	array( 'title' => __( 'Report Branding', 'fixreport-maintenance-logger' ), 'description' => __( 'Optional report identity defaults', 'fixreport-maintenance-logger' ), 'icon' => 'dashicons-admin-appearance', 'url' => admin_url( 'admin.php?page=fixreport-report-branding' ) ),
);
?>
<div class="wrap fixreport-dashboard-wrap">
	<div class="fixreport-dashboard">
		<header class="frd-header">
			<h1><?php esc_html_e( 'FixReport', 'fixreport-maintenance-logger' ); ?></h1>
			<p><?php esc_html_e( 'Recorded maintenance evidence and client report generator.', 'fixreport-maintenance-logger' ); ?></p>
		</header>

		<div class="frd-top">
			<section class="frd-panel" aria-labelledby="frd-overview-heading">
				<h2 id="frd-overview-heading"><?php esc_html_e( 'Site Overview', 'fixreport-maintenance-logger' ); ?></h2>
				<dl class="frd-facts">
					<div><dt><?php esc_html_e( 'Latest recorded snapshot', 'fixreport-maintenance-logger' ); ?></dt><dd>
						<?php if ( is_wp_error( $snapshot ) ) : ?><?php esc_html_e( 'Snapshot data unavailable', 'fixreport-maintenance-logger' ); ?>
						<?php elseif ( $snapshot_time ) : ?><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $snapshot_time, new DateTimeZone( 'UTC' ) ) ); ?> <span class="frd-nowrap">UTC</span>
						<?php elseif ( $snapshot_available ) : ?><?php esc_html_e( 'Capture time unavailable', 'fixreport-maintenance-logger' ); ?>
						<?php else : ?><?php esc_html_e( 'No completed snapshot recorded', 'fixreport-maintenance-logger' ); ?><?php endif; ?>
					</dd></div>
					<div><dt><?php esc_html_e( 'WordPress version at capture', 'fixreport-maintenance-logger' ); ?></dt><dd><?php if ( is_wp_error( $snapshot ) ) : ?><?php esc_html_e( 'Snapshot data unavailable', 'fixreport-maintenance-logger' ); ?><?php else : ?><?php echo $snapshot_available && ! empty( $snapshot->wordpress_version ) ? esc_html( $snapshot->wordpress_version ) : esc_html__( 'Not recorded', 'fixreport-maintenance-logger' ); ?><?php endif; ?></dd></div>
					<div><dt><?php esc_html_e( 'Recorded activities', 'fixreport-maintenance-logger' ); ?></dt><dd><?php if ( null === $month_count ) : ?><?php esc_html_e( 'Activity count unavailable', 'fixreport-maintenance-logger' ); ?><?php else : ?><?php echo esc_html( sprintf( /* translators: 1: activity count, 2: current month. */ __( '%1$s in %2$s', 'fixreport-maintenance-logger' ), number_format_i18n( $month_count ), $month_label ) ); ?><?php endif; ?></dd></div>
				</dl>
				<p class="frd-disclaimer"><?php esc_html_e( 'Historical records from this site. These are not live monitoring or a current health check.', 'fixreport-maintenance-logger' ); ?></p>
			</section>

			<section class="frd-panel" aria-labelledby="frd-actions-heading">
				<h2 id="frd-actions-heading"><?php esc_html_e( 'Reports & Actions', 'fixreport-maintenance-logger' ); ?></h2>
				<div class="frd-actions">
					<a class="button button-primary" href="<?php echo esc_url( $reports_url . '#fixreport-create-report' ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Create New Report', 'fixreport-maintenance-logger' ); ?></a>
					<?php if ( $draft_url ) : ?><a class="button" href="<?php echo esc_url( $draft_url ); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Continue Draft', 'fixreport-maintenance-logger' ); ?></a><?php endif; ?>
					<a class="button frd-all-reports" href="<?php echo esc_url( $reports_url ); ?>"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span><?php esc_html_e( 'View All Reports', 'fixreport-maintenance-logger' ); ?></a>
				</div>
				<?php if ( is_wp_error( $draft ) ) : ?><p class="frd-quiet"><?php esc_html_e( 'Draft shortcut unavailable. Use All Reports to review stored reports.', 'fixreport-maintenance-logger' ); ?></p><?php endif; ?>
			</section>
		</div>

		<section class="frd-access" aria-labelledby="frd-access-heading">
			<h2 id="frd-access-heading"><?php esc_html_e( 'Quick Access', 'fixreport-maintenance-logger' ); ?></h2>
			<div class="frd-card-grid">
				<?php foreach ( $cards as $card ) : ?>
					<a class="frd-card" href="<?php echo esc_url( $card['url'] ); ?>">
						<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?> frd-card-icon" aria-hidden="true"></span>
						<span class="frd-card-copy"><strong><?php echo esc_html( $card['title'] ); ?></strong><span><?php echo esc_html( $card['description'] ); ?></span></span>
						<span class="dashicons dashicons-arrow-right-alt2 frd-arrow" aria-hidden="true"></span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="frd-panel frd-recent" aria-labelledby="frd-recent-heading">
			<div class="frd-recent-head"><h2 id="frd-recent-heading"><?php esc_html_e( 'Recent Activity', 'fixreport-maintenance-logger' ); ?></h2><a href="<?php echo esc_url( $activities_url ); ?>"><?php esc_html_e( 'View all activities', 'fixreport-maintenance-logger' ); ?> <span aria-hidden="true">&rarr;</span></a></div>
			<?php if ( is_wp_error( $recent_activities ) ) : ?>
				<p class="frd-empty"><?php esc_html_e( 'Recorded activities could not be loaded.', 'fixreport-maintenance-logger' ); ?></p>
			<?php elseif ( ! $recent_activities ) : ?>
				<p class="frd-empty"><?php esc_html_e( 'No maintenance activities have been recorded yet.', 'fixreport-maintenance-logger' ); ?></p>
			<?php else : ?>
				<ul class="frd-activity-list">
					<?php foreach ( $recent_activities as $activity ) : ?>
						<li><span class="frd-activity-title"><?php echo esc_html( '' !== trim( (string) $activity->title ) ? $activity->title : sprintf( /* translators: %d: activity ID. */ __( 'Activity #%d', 'fixreport-maintenance-logger' ), (int) $activity->activity_id ) ); ?></span>
							<span class="frd-activity-time"><?php echo esc_html( (string) $activity->activity_date ); ?> <?php echo $activity->legacy_source ? esc_html__( '(legacy local clock)', 'fixreport-maintenance-logger' ) : 'UTC'; ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
</div>
