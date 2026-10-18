<?php
/**
 * Template for the PDF report.
 * (Other comments as before)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure variables are set to avoid notices
$logs           = isset($logs) ? $logs : array();
$report_title   = isset($report_title) ? $report_title : esc_html__('Maintenance Log Report', 'fixreport-maintenance-logger'); 
$site_name      = isset($site_name) ? $site_name : get_bloginfo('name');
$site_url       = isset($site_url) ? $site_url : get_bloginfo('url');
$generated_date = isset($generated_date) ? $generated_date : date_i18n(get_option('date_format') . ' ' . get_option('time_format'));

?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html( $report_title ); ?></title>
    <style type="text/css">
        <?php
        // Load static styles from external file using the helper function
        if ( class_exists( 'FixReport_Admin' ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo FixReport_Admin::get_pdf_report_styles();
        }
        ?>
        /* Add the dynamic part here as inline style because it contains PHP */
        .footer .pagenum:before {
            content: "<?php echo esc_js( __( 'Page', 'fixreport-maintenance-logger' ) ); ?> " counter(page) " <?php echo esc_js( __( 'of', 'fixreport-maintenance-logger' ) ); ?> " counter(pages);
        }
    </style>
</head>
<body>

    <!-- Header for each page -->
    <div class="header">
        <p style="font-size: 10pt; font-weight: bold; margin:0;"><?php echo esc_html( $site_name ); ?></p>
        <p style="font-size: 8pt; margin:0;"><?php echo esc_html( $report_title ); ?></p>
    </div>

    <!-- Footer for each page -->
    <div class="footer">
        <span class="pagenum"></span> | <?php echo esc_html__( 'Generated on:', 'fixreport-maintenance-logger' ) . ' ' . esc_html( $generated_date ); ?>
    </div>

    <!-- Main Content -->
    <h1 class="report-main-title"><?php echo esc_html( $report_title ); ?></h1>
    <div class="report-meta">
        <p><?php echo esc_html__( 'Site:', 'fixreport-maintenance-logger' ); ?> <?php echo esc_html( $site_name ); ?> (<?php echo esc_url( $site_url ); ?>)</p>
        <p><?php echo esc_html__( 'Report Generated:', 'fixreport-maintenance-logger' ); ?> <?php echo esc_html( $generated_date ); ?></p>
    </div>

    <?php if ( ! empty( $logs ) ) : ?>
        <table class="logs-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'fixreport-maintenance-logger' ); ?></th>
                    <th><?php esc_html_e( 'User', 'fixreport-maintenance-logger' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'fixreport-maintenance-logger' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'fixreport-maintenance-logger' ); ?></th>
                    <th><?php esc_html_e( 'Priority', 'fixreport-maintenance-logger' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'fixreport-maintenance-logger' ); ?></th>
                    <th style="width: 35%;"><?php esc_html_e( 'Description', 'fixreport-maintenance-logger' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log_item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log_item->formatted_created_at ); ?></td>
                        <td><?php echo esc_html( $log_item->user_display_name ); ?></td>
                        <td><?php echo esc_html( $log_item->type ); ?></td>
                        <td><?php echo esc_html( $log_item->title ); ?></td>
                        <td><?php echo esc_html( $log_item->priority ); ?></td>
                        <td><?php echo esc_html( $log_item->status ); ?></td>
                        <td class="log-description-cell"><?php echo nl2br( esc_html( $log_item->description ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="no-logs"><?php esc_html_e( 'No log entries found to include in this report.', 'fixreport-maintenance-logger' ); ?></p>
    <?php endif; ?>

</body>
</html>