<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    FixReport
 * @subpackage FixReport/includes
 * @author     Code Admin LTD (https://www.code-admin.com/)
 */
class FixReport_Deactivator {

    /**
     * The code that runs during plugin deactivation.
     * (Currently does nothing, but the class and method must exist)
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Keep durable evidence state; only stop callbacks while inactive.
        wp_clear_scheduled_hook( 'fixreport_checkpoint_worker' );
        wp_clear_scheduled_hook( 'fixreport_checkpoint_reconcile' );
    }

} // End class FixReport_Deactivator
