<?php
/** WordPress hook adapter. Holds only request-local candidates until success is known. */
class FixReport_Automatic_Tracker {
	private $recorder;
	private $before = array();
	private $packages = array();
	private $install_stacks = array();
	private $auto = array();
	private $deactivations = array();
	private $deletions = array();
	private $recorded = array();
	private $old_core_version;

	public function __construct( $recorder ) {
		$this->recorder = $recorder;
		$this->old_core_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : null;
	}

	public function register() {
		add_action( '_core_updated_successfully', array( $this, 'core_updated' ), 10, 1 );
		add_action( 'pre_auto_update', array( $this, 'pre_auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 20, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'pre_install' ), 20, 2 );
		add_filter( 'upgrader_install_package_result', array( $this, 'package_result' ), 100, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'process_complete' ), 100, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'automatic_complete' ), 10, 1 );
		add_action( 'activated_plugin', array( $this, 'plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'plugin_deactivated' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'option_updated' ), 10, 3 );
		add_action( 'update_site_option_active_sitewide_plugins', array( $this, 'network_plugins_updated' ), 10, 4 );
		add_action( 'delete_plugin', array( $this, 'plugin_deleting' ), 10, 1 );
		add_action( 'deleted_plugin', array( $this, 'plugin_deleted' ), 10, 2 );
		add_action( 'delete_theme', array( $this, 'theme_deleting' ), 10, 1 );
		add_action( 'deleted_theme', array( $this, 'theme_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'theme_switched' ), 10, 3 );
	}

	public function core_updated( $new_version ) {
		if ( ! is_string( $new_version ) || ! is_string( $this->old_core_version ) ) {
			return;
		}
		$this->emit( 'wordpress_core_updated', 'wordpress', __( 'WordPress Core', 'fixreport-maintenance-logger' ), $this->old_core_version, $new_version, '_core_updated_successfully' );
	}

	public function pre_auto_update( $type, $item ) {
		if ( 'core' === $type ) {
			$this->auto['core:wordpress'] = true;
			return;
		}
		$key = 'plugin' === $type && isset( $item->plugin ) ? $item->plugin : ( 'theme' === $type && isset( $item->theme ) ? $item->theme : null );
		if ( is_string( $key ) ) {
			$this->auto[ $type . ':' . $key ] = true;
		}
	}

	/** Installation identity and prior existence, including a nested parent-theme install. */
	public function source_selection( $source, $remote_source, $upgrader, $extra ) {
		if ( is_wp_error( $source ) || ! is_string( $source ) || ! is_object( $upgrader ) || ! is_array( $extra ) ) {
			return $source;
		}
		$type = isset( $extra['type'], $extra['action'] ) && 'install' === $extra['action'] && in_array( $extra['type'], array( 'plugin', 'theme' ), true ) ? $extra['type'] : null;
		$id = spl_object_id( $upgrader );
		if ( ! $type && $upgrader instanceof Theme_Upgrader && ! $extra && ! empty( $this->install_stacks[ $id ] ) ) {
			$type = 'theme'; // WordPress's nested parent install omits hook_extra.
		}
		if ( ! $type ) {
			return $source;
		}
		$slug = basename( rtrim( $source, '/\\' ) );
		if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 191 || 0 !== validate_file( $slug ) || false !== strpos( $slug, '/' ) ) {
			return $source;
		}
		if ( 'plugin' === $type && function_exists( 'get_plugins' ) ) {
			wp_cache_delete( 'plugins', 'plugins' );
			$existed = ! empty( get_plugins( '/' . $slug ) );
		} else {
			$existed = 'theme' === $type && wp_get_theme( $slug )->exists();
		}
		$this->install_stacks[ $id ][] = array( 'type' => $type, 'slug' => $slug, 'existed' => $existed );
		return $source;
	}

	/** Observe the still-installed version before package replacement. */
	public function pre_install( $response, $extra ) {
		if ( is_wp_error( $response ) || ! is_array( $extra ) ) {
			return $response;
		}
		if ( isset( $extra['plugin'] ) && is_string( $extra['plugin'] ) ) {
			$data = $this->plugin_data( $extra['plugin'] );
			if ( $data ) {
				$this->before['plugin:' . $extra['plugin']] = $data;
			}
		} elseif ( isset( $extra['theme'] ) && is_string( $extra['theme'] ) ) {
			$data = $this->theme_data( $extra['theme'] );
			if ( $data ) {
				$this->before['theme:' . $extra['theme']] = $data;
			}
		}
		return $response;
	}

	/** Per-item result; an automatic updater may still roll this back later. */
	public function package_result( $result, $extra ) {
		if ( ! is_array( $extra ) ) {
			return $result;
		}
		$type = isset( $extra['plugin'] ) ? 'plugin' : ( isset( $extra['theme'] ) ? 'theme' : null );
		if ( $type ) {
			$key = $extra[ $type ];
			if ( is_string( $key ) ) {
				$this->packages[ $type . ':' . $key ] = is_array( $result ) ? $result : false;
			}
		}
		return $result;
	}

	public function process_complete( $upgrader, $extra ) {
		if ( ! is_object( $upgrader ) || ! is_array( $extra ) ) {
			return;
		}
		$id = spl_object_id( $upgrader );
		if ( ! empty( $this->install_stacks[ $id ] ) && ( 'install' === ( $extra['action'] ?? null ) || ! $extra ) ) {
			$context = array_pop( $this->install_stacks[ $id ] );
			$this->finalize_install( $upgrader, $context );
			return;
		}
		if ( 'update' !== ( $extra['action'] ?? null ) || ! in_array( $extra['type'] ?? null, array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		$type = $extra['type'];
		$keys = ! empty( $extra['bulk'] ) ? ( $extra[ $type . 's' ] ?? array() ) : array( $extra[ $type ] ?? null );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || isset( $this->auto[ $type . ':' . $key ] ) ) {
				continue;
			}
			$this->finalize_update( $type, $key, 'upgrader_process_complete', ! empty( $extra['bulk'] ) );
		}
	}

	public function automatic_complete( $results ) {
		if ( ! is_array( $results ) ) {
			return;
		}
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( $results[ $type ] ?? array() as $entry ) {
				$key = 'plugin' === $type ? ( $entry->item->plugin ?? null ) : ( $entry->item->theme ?? null );
				if ( is_string( $key ) && true === ( $entry->result ?? null ) && isset( $this->auto[ $type . ':' . $key ] ) ) {
					$this->finalize_update( $type, $key, 'automatic_updates_complete', false );
				}
			}
		}
	}

	private function finalize_update( $type, $key, $hook, $bulk ) {
		$index = $type . ':' . $key;
		$old = $this->before[ $index ] ?? null;
		$package = $this->packages[ $index ] ?? null;
		if ( ! $old || ! is_array( $package ) ) {
			return;
		}
		$destination = isset( $package['destination_name'] ) && is_string( $package['destination_name'] ) ? $package['destination_name'] : null;
		$new_key = 'theme' === $type && $destination ? $destination : $key;
		$new = 'plugin' === $type ? $this->plugin_data( $new_key ) : $this->theme_data( $new_key );
		if ( ! $new || empty( $old['version'] ) || empty( $new['version'] ) ) {
			return;
		}
		$extras = array( 'package_action' => 'update' );
		if ( $new_key !== $key ) {
			$extras['identity_changed'] = true;
			$extras['replacement_identity'] = $new_key;
		}
		$this->emit( $type . '_updated', $new_key, $new['name'], $old['version'], $new['version'], $hook, $bulk, $extras );
	}

	private function finalize_install( $upgrader, $context ) {
		if ( ! is_array( $upgrader->result ) || empty( $upgrader->result['destination_name'] ) || $context['slug'] !== $upgrader->result['destination_name'] ) {
			return;
		}
		$type = $context['type'];
		$slug = $context['slug'];
		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				return;
			}
			wp_cache_delete( 'plugins', 'plugins' );
			$plugins = get_plugins( '/' . $slug );
			if ( 1 !== count( $plugins ) ) {
				return; // Never guess among multiple main plugin files.
			}
			$file = array_key_first( $plugins );
			$key = $slug . '/' . $file;
			$new = $this->plugin_data( $key );
		} else {
			$key = $slug;
			$new = $this->theme_data( $key );
		}
		if ( ! $new || empty( $new['version'] ) || $context['existed'] ) {
			return; // Overwrite is not a fresh installation.
		}
		$this->emit( $type . '_installed', $key, $new['name'], null, $new['version'], 'upgrader_process_complete', false, array( 'package_action' => 'install' ) );
	}

	public function plugin_activated( $plugin, $network_wide ) {
		if ( ! is_string( $plugin ) || $this->is_self( $plugin ) || ! is_plugin_active( $plugin ) ) {
			return;
		}
		$data = $this->plugin_data( $plugin );
		if ( $data ) {
			$this->emit( 'plugin_activated', $plugin, $data['name'], null, null, 'activated_plugin', false, array( 'network_wide' => (bool) $network_wide, 'observed_version' => $data['version'] ) );
		}
	}

	public function plugin_deactivated( $plugin, $network_wide ) {
		if ( ! is_string( $plugin ) || $this->is_self( $plugin ) ) {
			return;
		}
		$data = $this->plugin_data( $plugin );
		if ( $data ) {
			$this->deactivations[ $plugin ] = array( 'data' => $data, 'network_wide' => (bool) $network_wide );
		}
	}

	public function option_updated( $option, $old, $new ) {
		if ( 'active_plugins' === $option && is_array( $old ) && is_array( $new ) ) {
			$this->confirm_deactivations( array_diff( $old, $new ), false, 'updated_option' );
		}
	}

	public function network_plugins_updated( $option, $new, $old, $network_id ) {
		if ( 'active_sitewide_plugins' === $option && is_array( $old ) && is_array( $new ) ) {
			$this->confirm_deactivations( array_diff( array_keys( $old ), array_keys( $new ) ), true, 'update_site_option_active_sitewide_plugins' );
		}
	}

	private function confirm_deactivations( $removed, $network_wide, $hook ) {
		foreach ( $removed as $plugin ) {
			$candidate = $this->deactivations[ $plugin ] ?? null;
			if ( $candidate && $candidate['network_wide'] === $network_wide ) {
				$this->emit( 'plugin_deactivated', $plugin, $candidate['data']['name'], null, null, $hook, false, array( 'network_wide' => $network_wide, 'observed_version' => $candidate['data']['version'] ) );
				unset( $this->deactivations[ $plugin ] );
			}
		}
	}

	public function plugin_deleting( $plugin ) {
		if ( is_string( $plugin ) && ! $this->is_self( $plugin ) ) {
			$this->deletions['plugin:' . $plugin] = $this->plugin_data( $plugin );
		}
	}

	public function plugin_deleted( $plugin, $deleted ) {
		$data = is_string( $plugin ) ? ( $this->deletions['plugin:' . $plugin] ?? null ) : null;
		if ( true === $deleted && $data ) {
			$this->emit( 'plugin_deleted', $plugin, $data['name'], $data['version'], null, 'deleted_plugin' );
		}
		unset( $this->deletions['plugin:' . $plugin] );
	}

	public function theme_deleting( $stylesheet ) {
		if ( is_string( $stylesheet ) ) {
			$this->deletions['theme:' . $stylesheet] = $this->theme_data( $stylesheet );
		}
	}

	public function theme_deleted( $stylesheet, $deleted ) {
		$data = is_string( $stylesheet ) ? ( $this->deletions['theme:' . $stylesheet] ?? null ) : null;
		if ( true === $deleted && $data ) {
			$this->emit( 'theme_deleted', $stylesheet, $data['name'], $data['version'], null, 'deleted_theme' );
		}
		unset( $this->deletions['theme:' . $stylesheet] );
	}

	public function theme_switched( $new_name, $new_theme, $old_theme ) {
		if ( ! $new_theme instanceof WP_Theme || ! $old_theme instanceof WP_Theme ) {
			return;
		}
		$new_key = $new_theme->get_stylesheet();
		$old_key = $old_theme->get_stylesheet();
		if ( $new_key === $old_key || get_option( 'stylesheet' ) !== $new_key || get_option( 'template' ) !== $new_theme->get_template() ) {
			return;
		}
		$this->emit( 'theme_switched', $new_key, (string) $new_theme->get( 'Name' ), null, null, 'switch_theme', false, array( 'previous_stylesheet' => $old_key, 'previous_template' => $old_theme->get_template(), 'new_template' => $new_theme->get_template() ) );
	}

	private function plugin_data( $plugin ) {
		if ( ! is_string( $plugin ) || $this->is_self( $plugin ) || ! function_exists( 'get_plugin_data' ) || 0 !== validate_file( $plugin ) || plugin_basename( $plugin ) !== $plugin ) {
			return null;
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin;
		if ( ! is_file( $path ) ) {
			return null;
		}
		$data = get_plugin_data( $path, false, false );
		return ! empty( $data['Name'] ) ? array( 'name' => $data['Name'], 'version' => ! empty( $data['Version'] ) ? $data['Version'] : null ) : null;
	}

	private function theme_data( $stylesheet ) {
		if ( ! is_string( $stylesheet ) || 0 !== validate_file( $stylesheet ) || false !== strpos( $stylesheet, '/' ) ) {
			return null;
		}
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() && $theme->get( 'Name' ) ? array( 'name' => (string) $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ? (string) $theme->get( 'Version' ) : null ) : null;
	}

	private function emit( $type, $key, $name, $old, $new, $hook, $bulk = false, $extra = array() ) {
		$identity = $type . ':' . $key . ':' . (string) $old . ':' . (string) $new;
		if ( isset( $this->recorded[ $identity ] ) ) {
			return;
		}
		$auto = 'automatic_updates_complete' === $hook || ( 'wordpress_core_updated' === $type && ( wp_doing_cron() || isset( $this->auto['core:wordpress'] ) ) );
		$mechanism = $auto ? 'wordpress_auto_updater' : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp_cli' : ( is_admin() && get_current_user_id() > 0 && ! wp_doing_cron() ? 'admin_request' : 'programmatic_unknown' ) );
		$actor = 'admin_request' === $mechanism ? get_current_user_id() : null;
		$metadata = array_merge( array( 'contract_version' => '4b.1', 'provenance_hook' => $hook, 'mechanism' => $mechanism, 'bulk' => (bool) $bulk ), $extra );
		$result = $this->recorder->record( array( 'event_type' => $type, 'object_key' => $key, 'object_name' => $name, 'previous_version' => $old, 'new_version' => $new, 'metadata' => $metadata, 'performed_by' => $actor, 'activity_date' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! is_wp_error( $result ) ) {
			$this->recorded[ $identity ] = true;
		}
	}

	private function is_self( $plugin ) {
		return defined( 'FIXREPORT_BASENAME' ) && FIXREPORT_BASENAME === $plugin;
	}
}
