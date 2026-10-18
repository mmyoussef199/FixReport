<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Use statements for Dompdf classes for cleaner code
use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! class_exists( 'FixReport_Exporter' ) ) :

    class FixReport_Exporter {
        /**
         * Database handler instance.
         * @var FixReport_DB|null
         */
        private $db_handler = null;

        /**
         * Constructor.
         *
         * @param FixReport_DB $db_handler An instance of the database handler.
         */
        public function __construct( FixReport_DB $db_handler ) {
            $this->db_handler = $db_handler;
        }

        /**
         * Generates and outputs a PDF report of the logs.
         */
        public function generate_pdf_report() {
            if ( headers_sent( $file, $line ) ) {
                wp_die(
                    esc_html__( 'Cannot generate PDF because the server has already sent some output to the browser. This could be due to an error, a warning, or unexpected whitespace in PHP files. Please check your site\'s error logs or enable WP_DEBUG for more details.', 'fixreport-maintenance-logger' ),
                    esc_html__( 'PDF Generation Error - FixReport', 'fixreport-maintenance-logger' ),
                    array( 'response' => 500, 'back_link' => true )
                );
            }

            if ( ! class_exists( 'Dompdf\Dompdf' ) ) {
                wp_die(
                    esc_html__( 'The Dompdf library is not loaded. This is required for PDF generation. Please ensure Composer dependencies are installed and the autoloader is working correctly.', 'fixreport-maintenance-logger' ),
                    esc_html__( 'PDF Library Error - FixReport', 'fixreport-maintenance-logger' ),
                    array( 'response' => 500, 'back_link' => true )
                );
            }

            $logs = $this->db_handler->get_logs( array('number' => -1, 'orderby' => 'created_at', 'order' => 'ASC') );

            $prepared_logs = array();
            if ( ! empty( $logs ) ) {
                foreach ( $logs as $log_item ) {
                    $user_info = get_userdata( $log_item->user_id );
                    $log_item->user_display_name = $user_info ? esc_html( $user_info->display_name ) : esc_html__('Unknown User', 'fixreport-maintenance-logger');
                    
                    $date_format = get_option('date_format', 'F j, Y');
                    $time_format = get_option('time_format', 'g:i a');
                    $log_item->formatted_created_at = mysql2date($date_format . ' ' . $time_format, $log_item->created_at);

                    $prepared_logs[] = $log_item;
                }
            }
            
            $report_title = esc_html__('FixReport - Maintenance Log Report', 'fixreport-maintenance-logger');
            $site_name = get_bloginfo('name');
            $site_url = get_bloginfo('url');
            $generated_date = date_i18n(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));

            ob_start();
            
            $template_data_for_view = array(
                'logs'           => $prepared_logs,
                'report_title'   => $report_title,
                'site_name'      => esc_html($site_name),
                'site_url'       => esc_url($site_url),
                'generated_date' => esc_html($generated_date)
            );
            extract($template_data_for_view, EXTR_SKIP);

            if ( ! defined( 'FIXREPORT_DOING_PDF_EXPORT' ) ) {
                define( 'FIXREPORT_DOING_PDF_EXPORT', true );
            }
            
            $template_file = FIXREPORT_PATH . 'templates/pdf-report-template.php';
            
            if ( file_exists( $template_file ) ) {
                include $template_file;
            } else {
                ob_end_clean();
                /* translators: %s: The file path to the missing PDF template. */
                $error_message_raw = sprintf(
                    esc_html__('Error: PDF report template file is missing at path: %s', 'fixreport-maintenance-logger'),
                    '<code>' . esc_html($template_file) . '</code>'
                );
                wp_die( wp_kses_post( $error_message_raw ), esc_html__('PDF Template Error - FixReport', 'fixreport-maintenance-logger'), array('response' => 500, 'back_link' => true) );
            }
            $html = ob_get_clean();

            if (empty(trim($html))) {
                 wp_die(
                    esc_html__( 'The PDF template generated empty HTML content. Please check the template file and data provided to it.', 'fixreport-maintenance-logger' ),
                    esc_html__( 'PDF Generation Error - FixReport', 'fixreport-maintenance-logger' ),
                    array('response' => 500, 'back_link' => true)
                );
            }
            
            try {
                $options = new Options();
                $options->set('isRemoteEnabled', true); 
                $options->set('isHtml5ParserEnabled', true);
                $options->set('defaultFont', 'DejaVu Sans');

                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                $filename_base = sanitize_title_with_dashes( get_bloginfo('name') . '-fixreport-logs', 'fixreport-logs', 'download' );
                $filename = $filename_base . '-' . date_i18n('Y-m-d_H-i-s') . '.pdf';
                
                nocache_headers();
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');

                $dompdf->stream($filename, array("Attachment" => true));
                exit;

            } catch (Exception $e) {
                // As per our decision, the MissingTranslatorsComment error for 'Exception Details:'
                // is likely a false-positive, so we will keep the code as is.
                $error_message_user_raw = esc_html__('An error occurred while generating the PDF report. Please check the site error logs for more details or contact support.', 'fixreport-maintenance-logger');
                if ( defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY && current_user_can('manage_options') ) {
                    $error_message_user_raw .= '<br><pre style="white-space: pre-wrap; word-wrap: break-word;">' . esc_html__( 'Exception Details:', 'fixreport-maintenance-logger' ) . ' ' . esc_html($e->getMessage()) . '</pre>';
                }
                wp_die( wp_kses_post( $error_message_user_raw ), esc_html__('PDF Generation Error - FixReport', 'fixreport-maintenance-logger' ), array('response' => 500, 'back_link' => true));
            }
        }
    }

endif;