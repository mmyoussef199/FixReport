<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * This file is responsible for cleaning up any data created by the plugin,
 * such as custom database tables, options, etc.
 *
 * @package FixReport
 */

// If uninstall.php is not called by WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Prevents direct access to this file.
}

// --- Clean up custom database table ---

global $wpdb; // Make the WordPress database object available.

// Define the table name (must match the name used during activation).
$table_name = $wpdb->prefix . 'fixreport_logs';

// Construct the SQL query to drop the table.
// We use backticks around the table name just in case, though usually not strictly necessary
// if the name doesn't contain special characters or reserved keywords.
$sql = "DROP TABLE IF EXISTS `$table_name`;";

// Execute the query.
$wpdb->query( $sql );


// --- Clean up plugin options (if any were added) ---
// Example: If you had stored options with update_option() or add_option()
/*
delete_option( 'fixreport_settings' ); // Example: if you stored all settings in one option array
delete_option( 'fixreport_db_version' ); // Example: if you stored a DB version
// Add any other options your plugin might have created.
*/


// --- Clean up other data (if any) ---
// For example, if your plugin created custom user roles or capabilities,
// you might want to remove them here.
// Or if it created files in the wp-content/uploads directory.

// Note: Be very careful when deleting data, especially user-generated content
// or options that might be shared by other plugins or themes.
// For FixReport, deleting the logs table is generally safe as it's specific to this plugin.