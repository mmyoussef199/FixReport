<?php
/**
 * The core plugin class.
 */
class FixReport {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin. Although we are adding hooks directly in this example, a loader class
	 * is a common practice in more complex plugins.
	 *
	 * @since    1.0.1
	 * @access   protected
	 * @var      object    $loader    Maintains and registers all hooks for the plugin.
	 */
	// protected $loader; // Example for a hook loader class

	/**
	 * The unique identifier (slug) of this plugin.
	 *
	 * @since    1.0.1
	 * @access   protected
	 * @var      string    $plugin_name_slug    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name_slug;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

    /**
     * The admin-specific functionality handler.
     *
     * @since    1.0.1
     * @access   protected
     * @var      FixReport_Admin    $admin    Instance of the admin class.
     */
    protected $admin;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since    1.0.1
	 */
	public function __construct() {
		if ( defined( 'FIXREPORT_VERSION' ) ) {
			$this->version = FIXREPORT_VERSION;
		} else {
			$this->version = '1.3.1';
		}
		$this->plugin_name_slug = 'fixreport-maintenance-logger';

		$this->load_dependencies();
		$this->set_locale();
        // Hooks are defined in the run() method now
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-activity-repository.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-activity-service.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-automatic-recorder.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-automatic-tracker.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-snapshot-collector.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-snapshot-validator.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-snapshot-consistency.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-snapshot-repository.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-snapshot-service.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checkpoint-coordinator.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-comparison-normalizer.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-comparison-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-comparison-presenter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-baseline-presenter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checklist-template.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checklist-categories.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checklist-event-repository.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checklist-events.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-checklist-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-branding.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-language.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-document.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-layout.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-service.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-pdf.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-report-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fixreport-admin.php';
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * @since    1.0.1
	 * @access   private
	 */
	private function set_locale() {
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.1
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'fixreport-maintenance-logger',
			false,
			dirname( FIXREPORT_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 */
	private function define_admin_hooks() {
		$this->admin = new FixReport_Admin( $this->get_plugin_name(), $this->get_version() );

		// Hook for adding the menu page
		add_action( 'admin_menu', array( $this->admin, 'add_plugin_admin_menu' ) );
        
        // Hook for enqueuing admin styles and scripts
        add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_scripts' ) );

        // Hooks for form submissions (admin-post actions)
		add_action( 'admin_post_fixreport_add_activity', array( $this->admin, 'handle_add_activity_submission' ) );
		add_action( 'admin_post_fixreport_update_activity', array( $this->admin, 'handle_update_activity_submission' ) );
		add_action( 'admin_post_fixreport_delete_activity', array( $this->admin, 'handle_delete_activity_submission' ) );
		add_action( 'admin_post_fixreport_create_initial_baseline', array( $this->admin, 'handle_create_initial_baseline' ) );
		add_action( 'admin_post_fixreport_create_manual_snapshot', array( $this->admin, 'handle_create_manual_snapshot' ) );
		add_action( 'admin_post_fixreport_checklist_template', array( $this->admin, 'handle_checklist_template' ) );
		add_action( 'admin_notices', array( $this->admin, 'display_schema_upgrade_notice' ) );
	}

	/**
	 * Run the plugin by defining all the hooks.
	 *
	 * @since    1.0.1
	 */
	public function run() {
		$this->define_admin_hooks();
		( new FixReport_Report_Admin() )->register();
		( new FixReport_Checklist_Admin() )->register();
		$checkpoints = new FixReport_Checkpoint_Coordinator();
		$checkpoints->register();
		( new FixReport_Automatic_Tracker( new FixReport_Automatic_Recorder( new FixReport_Activity_Repository(), $checkpoints ) ) )->register();
	}


	/**
	 * Retrieve the name (slug) of the plugin.
	 * @since     1.0.1
	 * @return    string    The slug of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name_slug;
	}

	/**
	 * Retrieve the version number of the plugin.
	 * @since     1.0.1
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
