<?php
/**
 * FixReport - Maintenance Logger
 *
 * Plugin Name:       FixReport - Maintenance Logger
 * Description:       Record WordPress maintenance, capture historical site snapshots, and prepare client reports with PDF export.
 * Version:           1.3.1
 * Author:            Mohamed Youssef
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fixreport-maintenance-logger
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FIXREPORT_VERSION', '1.3.1' );
define( 'FIXREPORT_DB_VERSION', '2.3.0' );
define( 'FIXREPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'FIXREPORT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Display a recoverable bootstrap error without triggering a fatal include.
 *
 * @param string $relative_path Missing plugin-relative path.
 */
function fixreport_register_missing_file_notice( $relative_path ) {
	add_action(
		'admin_notices',
		static function () use ( $relative_path ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: Missing plugin-relative file path. */
							__( 'FixReport cannot start because a required file is missing: %s', 'fixreport-maintenance-logger' ),
							$relative_path
						)
					)
				);
			}
		}
	);
}

$fixreport_required_files = array(
	'includes/class-fixreport-activator.php',
	'includes/class-fixreport-migrator.php',
	'includes/class-fixreport-deactivator.php',
	'includes/class-fixreport-activity-repository.php',
	'includes/class-fixreport-activity-service.php',
	'includes/class-fixreport-automatic-recorder.php',
	'includes/class-fixreport-automatic-tracker.php',
	'includes/class-fixreport-snapshot-collector.php',
	'includes/class-fixreport-snapshot-validator.php',
	'includes/class-fixreport-snapshot-consistency.php',
	'includes/class-fixreport-snapshot-repository.php',
	'includes/class-fixreport-snapshot-service.php',
	'includes/class-fixreport-checkpoint-coordinator.php',
	'includes/class-fixreport-comparison-normalizer.php',
	'includes/class-fixreport-comparison-engine.php',
	'includes/class-fixreport-comparison-presenter.php',
	'includes/class-fixreport-baseline-presenter.php',
	'includes/class-fixreport-checklist-template.php',
	'includes/class-fixreport-checklist-categories.php',
	'includes/class-fixreport-checklist-event-repository.php',
	'includes/class-fixreport-checklist-events.php',
	'includes/class-fixreport-checklist-admin.php',
	'includes/class-fixreport-branding.php',
	'includes/class-fixreport-report-language.php',
	'includes/class-fixreport-report-document.php',
	'includes/class-fixreport-report-layout.php',
	'includes/class-fixreport-report-service.php',
	'includes/class-fixreport-report-pdf.php',
	'includes/class-fixreport-report-admin.php',
	'admin/views/report-document.php',
	'admin/views/report-document-compact.php',
	'admin/views/reports-list.php',
	'admin/views/dashboard.php',
	'templates/report-pdf.php',
	'templates/report-pdf-compact.php',
	'templates/report-compact-body.php',
	'admin/views/checklist-template-page.php',
	'admin/views/baseline-page.php',
	'admin/views/snapshot-history-page.php',
	'admin/views/snapshot-compare-page.php',
	'admin/views/automatic-activity-detail.php',
	'includes/class-fixreport-admin.php',
	'includes/class-fixreport.php',
);

foreach ( $fixreport_required_files as $fixreport_required_file ) {
	if ( ! file_exists( FIXREPORT_PATH . $fixreport_required_file ) ) {
		fixreport_register_missing_file_notice( $fixreport_required_file );
		return;
	}
}

require_once FIXREPORT_PATH . 'includes/class-fixreport-activator.php';
require_once FIXREPORT_PATH . 'includes/class-fixreport-migrator.php';
require_once FIXREPORT_PATH . 'includes/class-fixreport-deactivator.php';
require_once FIXREPORT_PATH . 'includes/class-fixreport.php';

if ( file_exists( FIXREPORT_PATH . 'vendor/autoload.php' ) ) {
	require_once FIXREPORT_PATH . 'vendor/autoload.php';
} else {
	fixreport_register_missing_file_notice( 'vendor/autoload.php' );
}

register_activation_hook( __FILE__, 'fixreport_activate_plugin' );
register_deactivation_hook( __FILE__, 'fixreport_deactivate_plugin' );

function fixreport_activate_plugin() {
	FixReport_Activator::activate();
}

function fixreport_deactivate_plugin() {
	FixReport_Deactivator::deactivate();
}

/**
 * Run pending database upgrades during normal loading.
 */
function fixreport_maybe_upgrade_database() {
	FixReport_Activator::maybe_upgrade();
}
add_action( 'plugins_loaded', 'fixreport_maybe_upgrade_database', 5 );

/**
 * Initialize the plugin after all plugins are available.
 */
function fixreport_run_plugin() {
	$plugin = new FixReport();
	$plugin->run();
}
add_action( 'plugins_loaded', 'fixreport_run_plugin', 10 );
