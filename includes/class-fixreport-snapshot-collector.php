<?php
/**
 * Read-only, local collectors for FixReport snapshots. No collector persists data.
 */
class FixReport_Snapshot_Collector {
	const CONTRACT_VERSION = '3a.1';
	const UPDATE_MAX_AGE  = 43200; // Twelve hours: shorter than a reporting day, without refreshing caches.
	const HEALTH_POLICY   = 'known_updates_v1';

	/** @var wpdb */
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/** Collect a complete, normalized result without database writes or network checks. */
	public function collect() {
		$site    = $this->site();
		$core    = $this->core();
		$env     = $this->environment();
		$plugins = $this->plugins();
		$themes  = $this->themes();
		$content = $this->content();
		$update  = $core['update_state'];
		$states  = array( 'core' => $update, 'plugins' => $plugins['update_state'], 'themes' => $themes['update_state'] );
		$health  = $this->health( $states, $core['summary']['core_update_available'], $plugins['summary']['plugin_update_count'], $themes['summary']['theme_update_count'] );

		$summary = array_merge( $site, $core['summary'], $env['summary'], $plugins['summary'], $themes['summary'], $content['summary'], $health['summary'] );
		$summary['pending_update_count'] = null;
		if ( null !== $summary['core_update_available'] && null !== $summary['plugin_update_count'] && null !== $summary['theme_update_count'] ) {
			$summary['pending_update_count'] = $summary['core_update_available'] + $summary['plugin_update_count'] + $summary['theme_update_count'];
		}
		$summary['summary_metadata'] = array(
			'contract_version'     => self::CONTRACT_VERSION,
			'content_policy'       => 'published_posts_pages_and_public_cpts',
			'comment_policy'       => 'approved_plus_pending',
			'update_max_age_sec'   => self::UPDATE_MAX_AGE,
			'update_state'         => $states,
			'plugin_policy'        => 'regular_counts_mu_items_dropins_excluded',
			'theme_policy'         => 'valid_and_errored_enumerated',
			'health_policy'        => self::HEALTH_POLICY,
			'health_coverage'      => $health['coverage'],
			'memory_state'         => $env['memory_state'],
		);
		return array( 'summary' => $summary, 'items' => array_merge( $plugins['items'], $themes['items'], $content['items'], $health['items'] ) );
	}

	private function site() {
		$site_url = $this->safe_url( site_url() );
		$home_url = $this->safe_url( home_url() );
		return array(
			'site_name'     => $this->bounded_text( get_bloginfo( 'name' ), 255 ),
			'site_url'      => $site_url,
			'home_url'      => $home_url,
			'site_language' => $this->bounded_text( get_locale(), 32 ),
			'timezone'      => $this->bounded_text( wp_timezone_string(), 64 ),
			'is_https'      => (int) ( 'https' === wp_parse_url( $site_url, PHP_URL_SCHEME ) && 'https' === wp_parse_url( $home_url, PHP_URL_SCHEME ) ),
			'is_multisite'  => (int) is_multisite(),
		);
	}

	private function core() {
		global $wp_version;
		$version = $this->bounded_text( $wp_version, 64 );
		$state   = $this->update_state( get_site_transient( 'update_core' ), $version, 'core' );
		$available = null;
		$has_update = null;
		if ( 'known' === $state['status'] ) {
			$has_update = 0;
			$cache = get_site_transient( 'update_core' );
			foreach ( $cache->updates as $offer ) {
				if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && version_compare( $offer->current, $version, '>' ) ) {
					$has_update = 1;
					$available  = $this->bounded_text( $offer->current, 64 );
					break;
				}
			}
		}
		return array( 'summary' => array( 'wordpress_version' => $version, 'core_update_available' => $has_update, 'available_wordpress_version' => $available ), 'update_state' => $state );
	}

	private function environment() {
		$wp_memory  = $this->memory( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null );
		$php_memory = $this->memory( ini_get( 'memory_limit' ) );
		$db_version = $this->wpdb->db_version();
		$server     = null;
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && preg_match( '/^(Apache|nginx|LiteSpeed|Microsoft-IIS|Caddy)(\/[0-9A-Za-z._-]+)?/i', (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ), $server_match ) ) {
			$server = $this->bounded_text( $server_match[0], 255 );
		}
		return array(
			'summary' => array(
				'php_version' => $this->bounded_text( PHP_VERSION, 64 ),
				'database_engine' => false !== stripos( $this->wpdb->db_server_info(), 'mariadb' ) ? 'MariaDB' : 'MySQL',
				'database_version' => $this->bounded_text( $db_version, 64 ),
				'web_server' => $server,
				'wp_memory_limit_bytes' => $wp_memory['bytes'],
				'php_memory_limit_bytes' => $php_memory['bytes'],
				'wp_debug' => (int) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'wp_debug_log' => (int) ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
				'wp_debug_display' => (int) ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
			),
			'memory_state' => array( 'wordpress' => $wp_memory['state'], 'php' => $php_memory['state'] ),
		);
	}

	private function plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$regular = get_plugins();
		ksort( $regular, SORT_STRING );
		$cache = get_site_transient( 'update_plugins' );
		$versions = array();
		foreach ( $regular as $key => $data ) {
			$versions[ $key ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		$state = $this->update_state( $cache, $versions, 'plugins' );
		$items = array();
		$active = 0;
		$updates = 0;
		foreach ( $regular as $key => $data ) {
			$is_active = is_plugin_active( $key ) || ( is_multisite() && is_plugin_active_for_network( $key ) );
			$active += (int) $is_active;
			$offer = 'known' === $state['status'] && isset( $cache->response[ $key ] ) ? $cache->response[ $key ] : null;
			$has_update = 'known' === $state['status'] ? (int) ( null !== $offer ) : null;
			$updates += (int) $has_update;
			$items[] = $this->item( 'plugin', $key, isset( $data['Name'] ) ? $data['Name'] : $key, array(
				'installed_version' => $this->nullable_text( isset( $data['Version'] ) ? $data['Version'] : null, 64 ),
				'available_version' => $offer && isset( $offer->new_version ) ? $this->nullable_text( $offer->new_version, 64 ) : null,
				'is_active' => (int) $is_active,
				'update_available' => $has_update,
				'item_payload' => array( 'plugin_kind' => 'regular' ),
			) );
		}
		foreach ( get_mu_plugins() as $key => $data ) {
			$items[] = $this->item( 'plugin', 'mu:' . $key, isset( $data['Name'] ) ? $data['Name'] : $key, array( 'installed_version' => $this->nullable_text( isset( $data['Version'] ) ? $data['Version'] : null, 64 ), 'item_payload' => array( 'plugin_kind' => 'mu' ) ) );
		}
		return array( 'summary' => array( 'plugin_count' => count( $regular ), 'active_plugin_count' => $active, 'inactive_plugin_count' => count( $regular ) - $active, 'plugin_update_count' => 'known' === $state['status'] ? $updates : null ), 'items' => $items, 'update_state' => $state );
	}

	private function themes() {
		$themes = wp_get_themes( array( 'errors' => null ) );
		ksort( $themes, SORT_STRING );
		$cache = get_site_transient( 'update_themes' );
		$versions = array();
		foreach ( $themes as $key => $theme ) {
			if ( ! $theme->errors() ) {
				$versions[ $key ] = (string) $theme->get( 'Version' );
			}
		}
		$state = $this->update_state( $cache, $versions, 'themes' );
		$items = array();
		$updates = 0;
		foreach ( $themes as $key => $theme ) {
			$broken = (bool) $theme->errors();
			$offer = ! $broken && 'known' === $state['status'] && isset( $cache->response[ $key ] ) ? $cache->response[ $key ] : null;
			$has_update = $broken ? null : ( 'known' === $state['status'] ? (int) ( null !== $offer ) : null );
			$updates += (int) $has_update;
			$items[] = $this->item( 'theme', $key, $broken ? $key : $theme->get( 'Name' ), array(
				'installed_version' => $broken ? null : $this->nullable_text( $theme->get( 'Version' ), 64 ),
				'available_version' => $offer && isset( $offer['new_version'] ) ? $this->nullable_text( $offer['new_version'], 64 ) : null,
				'is_active' => $broken ? null : (int) ( get_stylesheet() === $key ),
				'update_available' => $has_update,
				'parent_key' => $broken ? null : ( $theme->parent() ? $theme->parent()->get_stylesheet() : null ),
				'item_payload' => array( 'theme_state' => $broken ? 'errored' : 'valid' ),
			) );
		}
		return array( 'summary' => array( 'theme_count' => count( $themes ), 'theme_update_count' => 'known' === $state['status'] ? $updates : null ), 'items' => $items, 'update_state' => $state );
	}

	private function content() {
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$users = count_users();
		if ( ! is_object( $comments ) || ! isset( $comments->approved, $comments->moderated ) || ! is_array( $users ) || ! isset( $users['total_users'] ) ) {
			throw new RuntimeException( 'Count API unavailable' );
		}
		$items = array();
		$types = get_post_types( array( '_builtin' => false, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true ), 'objects' );
		ksort( $types, SORT_STRING );
		foreach ( $types as $key => $type ) {
			$count = wp_count_posts( $key );
			$items[] = $this->item( 'content_type', $key, $type->labels->name, array( 'item_payload' => array( 'count' => isset( $count->publish ) ? (int) $count->publish : 0, 'counted_status' => 'publish' ) ) );
		}
		return array( 'summary' => array( 'post_count' => isset( $posts->publish ) ? (int) $posts->publish : 0, 'page_count' => isset( $pages->publish ) ? (int) $pages->publish : 0, 'comment_count' => (int) $comments->approved + (int) $comments->moderated, 'user_count' => (int) $users['total_users'] ), 'items' => $items );
	}

	/** Only objectively known, cached pending updates trigger this first policy. */
	private function health( $states, $core_update, $plugin_updates, $theme_updates ) {
		$rules = array(
			'fixreport.core_update_available' => array( 'source' => 'core', 'count' => $core_update, 'label' => __( 'A WordPress core update is available', 'fixreport-maintenance-logger' ) ),
			'fixreport.plugin_updates_available' => array( 'source' => 'plugins', 'count' => $plugin_updates, 'label' => __( 'Plugin updates are available', 'fixreport-maintenance-logger' ) ),
			'fixreport.theme_updates_available' => array( 'source' => 'themes', 'count' => $theme_updates, 'label' => __( 'Theme updates are available', 'fixreport-maintenance-logger' ) ),
		);
		$items = array();
		$complete = true;
		foreach ( $rules as $key => $rule ) {
			if ( 'known' !== $states[ $rule['source'] ]['status'] ) {
				$complete = false;
				continue;
			}
			$count = (int) $rule['count'];
			if ( $count > 0 ) {
				$items[] = $this->item( 'site_health', $key, $rule['label'], array( 'severity' => 'recommended', 'item_payload' => array( 'known_pending_count' => $count ) ) );
			}
		}
		return array( 'summary' => array( 'site_health_critical_count' => $complete ? 0 : null, 'site_health_recommended_count' => $complete ? count( $items ) : null ), 'items' => $items, 'coverage' => $complete ? 'complete' : 'incomplete_update_evidence' );
	}

	/** Cache inspection only; no refresh. */
	private function update_state( $cache, $installed, $kind ) {
		$unknown = array( 'status' => 'unknown', 'reason' => 'missing_or_stale' );
		if ( ! is_object( $cache ) || ! isset( $cache->last_checked ) || ! is_numeric( $cache->last_checked ) || $cache->last_checked > time() + 300 || time() - $cache->last_checked > self::UPDATE_MAX_AGE ) {
			return $unknown;
		}
		if ( 'core' === $kind ) {
			if ( ! isset( $cache->version_checked, $cache->updates ) || $cache->version_checked !== $installed || ! is_array( $cache->updates ) ) {
				return array( 'status' => 'unknown', 'reason' => 'version_or_shape_mismatch' );
			}
			foreach ( $cache->updates as $offer ) {
				if ( ! is_object( $offer ) || ! isset( $offer->response, $offer->current ) || ! in_array( $offer->response, array( 'upgrade', 'latest', 'autoupdate', 'development' ), true ) ) {
					return array( 'status' => 'unknown', 'reason' => 'cache_shape_invalid' );
				}
			}
		} else {
			if ( ! isset( $cache->checked, $cache->response ) || ! is_array( $cache->checked ) || ! is_array( $cache->response ) ) {
				return array( 'status' => 'unknown', 'reason' => 'cache_shape_invalid' );
			}
			foreach ( $installed as $key => $version ) {
				if ( ! array_key_exists( $key, $cache->checked ) || (string) $cache->checked[ $key ] !== $version ) {
					return array( 'status' => 'unknown', 'reason' => 'inventory_mismatch' );
				}
			}
			foreach ( $cache->response as $key => $offer ) {
				if ( ! array_key_exists( $key, $installed ) || ( 'plugins' === $kind && ( ! is_object( $offer ) || ! isset( $offer->new_version ) ) ) || ( 'themes' === $kind && ( ! is_array( $offer ) || ! isset( $offer['new_version'] ) ) ) ) {
					return array( 'status' => 'unknown', 'reason' => 'cache_shape_invalid' );
				}
			}
		}
		return array( 'status' => 'known', 'reason' => 'fresh_matching_cache' );
	}

	private function item( $type, $key, $name, $extra = array() ) {
		return array_merge( array( 'item_type' => $type, 'item_key' => (string) $key, 'item_key_hash' => hash( 'sha256', (string) $key ), 'display_name' => $this->bounded_text( $name, 255 ), 'installed_version' => null, 'available_version' => null, 'is_active' => null, 'update_available' => null, 'parent_key' => null, 'severity' => null, 'item_payload' => array() ), $extra );
	}

	private function safe_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );
	}

	private function memory( $value ) {
		if ( '-1' === (string) $value ) {
			return array( 'bytes' => null, 'state' => 'unlimited' );
		}
		if ( ! is_scalar( $value ) || ! preg_match( '/^([1-9][0-9]*)([KMG])?$/i', trim( (string) $value ), $m ) ) {
			return array( 'bytes' => null, 'state' => 'unknown' );
		}
		$bytes = (float) $m[1] * pow( 1024, isset( $m[2] ) ? array_search( strtoupper( $m[2] ), array( '', 'K', 'M', 'G' ), true ) : 0 );
		if ( $bytes > PHP_INT_MAX ) {
			return array( 'bytes' => null, 'state' => 'unknown' );
		}
		return array( 'bytes' => (int) $bytes, 'state' => 'finite' );
	}

	private function nullable_text( $value, $limit ) {
		return null === $value || '' === (string) $value ? null : $this->bounded_text( $value, $limit );
	}

	private function bounded_text( $value, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $limit, 'UTF-8' ) : substr( sanitize_text_field( (string) $value ), 0, $limit );
	}
}
