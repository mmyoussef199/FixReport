<?php
/** Bounded, read-only capture guard; it is not a transaction over WordPress files. */
class FixReport_Snapshot_Consistency {
	/** Return a deterministic inventory signature or a safe error. */
	public function signature() {
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return new WP_Error( 'snapshot_maintenance_active' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		clearstatcache( true );
		global $wp_version;
		$core_hash = $this->file_hash( ABSPATH . 'wp-includes/version.php' );
		if ( is_wp_error( $core_hash ) ) {
			return $core_hash;
		}
		$state = array( 'core' => array( (string) $wp_version, $core_hash ), 'plugins' => array(), 'mu_plugins' => array(), 'themes' => array() );
		$plugins = get_plugins();
		ksort( $plugins, SORT_STRING );
		foreach ( $plugins as $key => $data ) {
			$hash = $this->file_hash( WP_PLUGIN_DIR . '/' . $key );
			if ( is_wp_error( $hash ) ) {
				return $hash;
			}
			$state['plugins'][ $key ] = array( (string) ( $data['Version'] ?? '' ), (int) is_plugin_active( $key ), $hash );
		}
		$mu_plugins = get_mu_plugins();
		ksort( $mu_plugins, SORT_STRING );
		foreach ( $mu_plugins as $key => $data ) {
			$hash = $this->file_hash( WPMU_PLUGIN_DIR . '/' . $key );
			if ( is_wp_error( $hash ) ) {
				return $hash;
			}
			$state['mu_plugins'][ $key ] = array( (string) ( $data['Version'] ?? '' ), $hash );
		}
		$themes = wp_get_themes( array( 'errors' => null ) );
		ksort( $themes, SORT_STRING );
		foreach ( $themes as $key => $theme ) {
			$broken = (bool) $theme->errors();
			$hash = $broken ? null : $this->file_hash( $theme->get_stylesheet_directory() . '/style.css' );
			if ( is_wp_error( $hash ) ) {
				return $hash;
			}
			$state['themes'][ $key ] = array( $broken, $broken ? null : (string) $theme->get( 'Version' ), $broken || ! $theme->parent() ? null : $theme->parent()->get_stylesheet(), $hash );
		}
		$state['active_stylesheet'] = get_stylesheet();
		$state['active_template'] = get_template();
		$state['active_plugins'] = get_option( 'active_plugins', array() );
		$state['network_plugins'] = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'snapshot_consistency_failed' );
		}
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return new WP_Error( 'snapshot_maintenance_active' );
		}
		return hash( 'sha256', $encoded );
	}

	private function file_hash( $path ) {
		$hash = is_file( $path ) && is_readable( $path ) ? hash_file( 'sha256', $path ) : false;
		return false === $hash ? new WP_Error( 'snapshot_consistency_failed' ) : $hash;
	}
}
