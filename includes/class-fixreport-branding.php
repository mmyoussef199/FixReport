<?php
/** Optional Report branding: revisioned defaults, tri-state Draft overrides, local logo evidence. */
class FixReport_Branding {
	const OPTION = 'fixreport_report_branding_v1';
	const CONTRACT = 1;
	const MAX_SOURCE_BYTES = 5242880;
	const MAX_ASSET_BYTES = 262144;
	private $wpdb;
	private $assets;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assets = $wpdb->prefix . 'fixreport_report_logo_assets';
	}

	public static function text_fields() {
		return array( 'company_name', 'website', 'email', 'phone', 'address', 'footer_text' );
	}

	public static function empty_values() {
		return array( 'company_name' => '', 'website' => '', 'email' => '', 'phone' => '', 'address' => '', 'footer_text' => '', 'logo_attachment_id' => 0 );
	}

	private function seed() {
		return array( 'schema_version' => self::CONTRACT, 'revision' => wp_generate_uuid4(), 'values' => self::empty_values() );
	}

	/** add_option is atomic; it also records autoload=false. */
	public function initialize() {
		$name = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT option_name FROM {$this->wpdb->options} WHERE option_name = %s", self::OPTION ) );
		return null !== $name || add_option( self::OPTION, $this->seed(), '', false );
	}

	private function valid_values( $values ) {
		if ( ! is_array( $values ) || array_keys( $values ) !== array_keys( self::empty_values() ) ) { return false; }
		foreach ( self::text_fields() as $key ) {
			if ( ! is_string( $values[ $key ] ) || strlen( $values[ $key ] ) > $this->limit( $key ) ) { return false; }
		}
		return is_int( $values['logo_attachment_id'] ) && $values['logo_attachment_id'] >= 0;
	}

	private function limit( $key ) {
		return array( 'company_name' => 255, 'website' => 2048, 'email' => 254, 'phone' => 64, 'address' => 1000, 'footer_text' => 2000 )[ $key ];
	}

	private function valid_settings( $settings ) {
		return is_array( $settings ) && array_keys( $settings ) === array( 'schema_version', 'revision', 'values' )
			&& self::CONTRACT === $settings['schema_version'] && is_string( $settings['revision'] )
			&& (bool) preg_match( '/^[a-f0-9-]{36}$/', $settings['revision'] ) && $this->valid_values( $settings['values'] );
	}

	public function get_settings( $lock = false ) {
		if ( ! $this->initialize() ) { return new WP_Error( 'branding_option_unavailable' ); }
		$sql = $this->wpdb->prepare( "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", self::OPTION );
		$this->wpdb->last_error = '';
		$raw = $this->wpdb->get_var( $sql . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( '' !== $this->wpdb->last_error || null === $raw ) { return new WP_Error( 'branding_option_unavailable' ); }
		$settings = maybe_unserialize( $raw );
		return $this->valid_settings( $settings ) ? $settings : new WP_Error( 'branding_option_invalid' );
	}

	public function clean_value( $key, $raw ) {
		if ( ! in_array( $key, self::text_fields(), true ) || ! is_string( $raw ) ) { return new WP_Error( 'branding_value_invalid' ); }
		$value = in_array( $key, array( 'address', 'footer_text' ), true ) ? sanitize_textarea_field( wp_unslash( $raw ) ) : sanitize_text_field( wp_unslash( $raw ) );
		if ( strlen( $value ) > $this->limit( $key ) ) { return new WP_Error( 'branding_value_too_long' ); }
		if ( 'email' === $key && '' !== $value && ( sanitize_email( $value ) !== $value || ! is_email( $value ) ) ) { return new WP_Error( 'branding_email_invalid' ); }
		if ( 'website' === $key && '' !== $value ) {
			$clean = esc_url_raw( $value, array( 'http', 'https' ) );
			$parts = wp_parse_url( $clean );
			if ( $clean !== $value || ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return new WP_Error( 'branding_website_invalid' ); }
		}
		if ( 'phone' === $key && '' !== trim( wp_unslash( $raw ) ) && ( '' === $value || ! preg_match( '/^[+0-9() .\-xextEXT\/]+$/D', $value ) ) ) { return new WP_Error( 'branding_phone_invalid' ); }
		return $value;
	}

	public function clean_logo_id( $raw ) {
		if ( ! is_scalar( $raw ) || ! ctype_digit( (string) $raw ) || (string) $raw === '' ) { return new WP_Error( 'branding_logo_invalid' ); }
		$id = (int) $raw;
		if ( $id < 0 || (string) $id !== ltrim( (string) $raw, '0' ) && 0 !== $id ) { return new WP_Error( 'branding_logo_invalid' ); }
		if ( $id ) {
			$logo = $this->prepare_logo( $id );
			if ( is_wp_error( $logo ) ) { return $logo; }
		}
		return $id;
	}

	public function save_settings( $revision, $input ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) { return new WP_Error( 'branding_forbidden' ); }
		$old = $this->get_settings();
		if ( is_wp_error( $old ) ) { return $old; }
		if ( ! is_string( $revision ) || ! hash_equals( $old['revision'], $revision ) ) { return new WP_Error( 'branding_settings_stale' ); }
		$values = array();
		foreach ( self::text_fields() as $key ) {
			$values[ $key ] = $this->clean_value( $key, $input[ $key ] ?? null );
			if ( is_wp_error( $values[ $key ] ) ) { return $values[ $key ]; }
		}
		$values['logo_attachment_id'] = $this->clean_logo_id( $input['logo_attachment_id'] ?? null );
		if ( is_wp_error( $values['logo_attachment_id'] ) ) { return $values['logo_attachment_id']; }
		$next = array( 'schema_version' => self::CONTRACT, 'revision' => wp_generate_uuid4(), 'values' => $values );
		$before = maybe_serialize( $old );
		$changed = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), self::OPTION, $before ) );
		if ( 1 !== $changed ) { return new WP_Error( 'branding_settings_stale' ); }
		wp_cache_delete( self::OPTION, 'options' );
		return $next['revision'];
	}

	/** Only present keys override. Empty text and zero logo are explicit blanks. */
	public function clean_overrides( $modes, $values ) {
		if ( ! is_array( $modes ) || ! is_array( $values ) ) { return new WP_Error( 'branding_override_invalid' ); }
		$overrides = array();
		foreach ( array_merge( self::text_fields(), array( 'logo_attachment_id' ) ) as $key ) {
			$mode = $modes[ $key ] ?? null;
			if ( ! in_array( $mode, array( 'inherit', 'blank', 'override' ), true ) ) { return new WP_Error( 'branding_override_invalid' ); }
			if ( 'inherit' === $mode ) { continue; }
			if ( 'blank' === $mode ) { $overrides[ $key ] = 'logo_attachment_id' === $key ? 0 : ''; continue; }
			$overrides[ $key ] = 'logo_attachment_id' === $key ? $this->clean_logo_id( $values[ $key ] ?? null ) : $this->clean_value( $key, $values[ $key ] ?? null );
			if ( is_wp_error( $overrides[ $key ] ) || 'logo_attachment_id' === $key && 0 === $overrides[ $key ] ) { return new WP_Error( 'branding_override_invalid' ); }
		}
		return $overrides;
	}

	public function read_overrides( $json ) {
		if ( null === $json ) { return array(); }
		$values = json_decode( $json, true );
		if ( ! is_array( $values ) || json_last_error() !== JSON_ERROR_NONE || array_diff( array_keys( $values ), array_keys( self::empty_values() ) ) ) { return new WP_Error( 'branding_override_corrupt' ); }
		foreach ( $values as $key => $value ) {
			if ( 'logo_attachment_id' === $key ? ! is_int( $value ) || $value < 0 : ! is_string( $value ) || strlen( $value ) > $this->limit( $key ) ) { return new WP_Error( 'branding_override_corrupt' ); }
		}
		return $values;
	}

	public function resolve( $report, $lock = false ) {
		$settings = $this->get_settings( $lock );
		if ( is_wp_error( $settings ) ) { return $settings; }
		$overrides = $this->read_overrides( $report->branding_overrides );
		if ( is_wp_error( $overrides ) ) { return $overrides; }
		$effective = array_replace( $settings['values'], $overrides );
		$logo = $effective['logo_attachment_id'] ? $this->prepare_logo( $effective['logo_attachment_id'] ) : null;
		if ( is_wp_error( $logo ) ) { return $logo; }
		unset( $effective['logo_attachment_id'] );
		return array( 'fields' => $effective, 'logo' => $logo, 'settings_revision' => $settings['revision'] );
	}

	/** Decode/re-encode locally; no URL resolution or remote fetch is permitted. */
	public function prepare_logo( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_mime_type, array( 'image/png', 'image/jpeg' ), true ) ) { return new WP_Error( 'branding_logo_invalid' ); }
		$path = get_attached_file( $id, true );
		$uploads = wp_get_upload_dir();
		$base = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		$actual = is_string( $path ) ? realpath( $path ) : false;
		if ( ! $base || ! $actual || 0 !== strncasecmp( $actual, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR, strlen( rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) || ! is_file( $actual ) ) { return new WP_Error( 'branding_logo_not_local' ); }
		$size = filesize( $actual );
		if ( false === $size || $size < 1 || $size > self::MAX_SOURCE_BYTES ) { return new WP_Error( 'branding_logo_source_size' ); }
		$kind = wp_check_filetype_and_ext( $actual, basename( $actual ), array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' ) );
		$dimensions = @getimagesize( $actual );
		if ( ! is_array( $kind ) || ! in_array( $kind['type'] ?? '', array( 'image/png', 'image/jpeg' ), true ) || $kind['type'] !== $post->post_mime_type || ! is_array( $dimensions ) || $dimensions['mime'] !== $kind['type'] || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 || $dimensions[0] * $dimensions[1] > 16000000 ) { return new WP_Error( 'branding_logo_invalid' ); }
		$editor = wp_get_image_editor( $actual );
		if ( is_wp_error( $editor ) ) { return new WP_Error( 'branding_logo_decode_failed' ); }
		if ( $dimensions[0] > 1200 || $dimensions[1] > 1200 ) {
			$resized = $editor->resize( 1200, 1200, false );
			if ( is_wp_error( $resized ) ) { return new WP_Error( 'branding_logo_decode_failed' ); }
		}
		$tempdir = $this->private_temp_directory();
		if ( is_wp_error( $tempdir ) ) { return $tempdir; }
		$temp = tempnam( $tempdir, 'fr-logo-' );
		if ( ! $temp ) { return new WP_Error( 'branding_private_temp_unavailable' ); }
		$output = $temp . '.png';
		try {
			$saved = $editor->save( $output, 'image/png' );
			if ( is_wp_error( $saved ) || ! is_array( $saved ) || 'image/png' !== ( $saved['mime-type'] ?? '' ) || ! is_file( $output ) ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			$length = filesize( $output );
			if ( false === $length || $length < 1 || $length > self::MAX_ASSET_BYTES ) { return new WP_Error( 'branding_logo_asset_size' ); }
			$bytes = file_get_contents( $output );
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			$bytes = self::canonical_png( $bytes, (int) $saved['width'], (int) $saved['height'] );
			if ( is_wp_error( $bytes ) ) { return $bytes; }
			return array( 'source_attachment_id' => (int) $id, 'mime_type' => 'image/png', 'width' => (int) $saved['width'], 'height' => (int) $saved['height'], 'byte_length' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
		} finally {
			if ( is_file( $output ) ) { unlink( $output ); }
			if ( is_file( $temp ) ) { unlink( $temp ); }
		}
	}

	/** Remove non-visual metadata before hashing or freezing editor output. */
	private static function canonical_png( $bytes, $width, $height ) {
		$signature = "\x89PNG\r\n\x1a\n";
		$total = is_string( $bytes ) ? strlen( $bytes ) : 0;
		if ( $total < 45 || $total > self::MAX_ASSET_BYTES || substr( $bytes, 0, 8 ) !== $signature ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
		$offset = 8;
		$canonical = $signature;
		$seen_header = false;
		$seen_data = false;
		$seen_end = false;
		$chunks = 0;
		while ( $offset < $total ) {
			if ( ++$chunks > 4096 || $total - $offset < 12 ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			$length = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
			if ( $length > $total - $offset - 12 ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			$type = substr( $bytes, $offset + 4, 4 );
			$data = substr( $bytes, $offset + 8, $length );
			$crc = substr( $bytes, $offset + 8 + $length, 4 );
			if ( ! preg_match( '/^[A-Za-z]{2}[A-Z][A-Za-z]$/D', $type ) || ! hash_equals( pack( 'H*', hash( 'crc32b', $type . $data ) ), $crc ) ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			if ( ! $seen_header ) {
				if ( 'IHDR' !== $type || 13 !== $length ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
				$dimensions = unpack( 'Nwidth/Nheight', $data );
				if ( $dimensions['width'] !== $width || $dimensions['height'] !== $height || $width < 1 || $height < 1 ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
				$seen_header = true;
			} elseif ( 'IHDR' === $type || $seen_end ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
			if ( 'IDAT' === $type ) { $seen_data = true; }
			if ( 'IEND' === $type ) {
				if ( 0 !== $length || ! $seen_data || $offset + 12 !== $total ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
				$seen_end = true;
			}
			// Imagick adds a fresh tIME chunk on each save. Text/EXIF chunks are
			// also non-visual and may contain volatile or private source metadata.
			if ( ! in_array( $type, array( 'tIME', 'tEXt', 'zTXt', 'iTXt', 'eXIf' ), true ) ) {
				$canonical .= substr( $bytes, $offset, $length + 12 );
			}
			$offset += $length + 12;
		}
		if ( ! $seen_end || strlen( $canonical ) > self::MAX_ASSET_BYTES ) { return new WP_Error( 'branding_logo_normalize_failed' ); }
		return $canonical;
	}

	/** Use only writable local directories outside the public WordPress document root. */
	private function private_temp_directory() {
		$webroot = realpath( ABSPATH );
		if ( ! $webroot ) { return new WP_Error( 'branding_private_temp_unavailable' ); }
		$candidates = array( sys_get_temp_dir(), dirname( rtrim( ABSPATH, '/\\' ) ) . DIRECTORY_SEPARATOR . 'fixreport-private-tmp' );
		foreach ( $candidates as $index => $candidate ) {
			if ( 1 === $index && ! is_dir( $candidate ) && ! @mkdir( $candidate, 0700, true ) ) { continue; }
			$path = realpath( $candidate );
			if ( ! $path || ! is_dir( $path ) || ! is_writable( $path ) ) { continue; }
			$root = rtrim( $webroot, '/\\' );
			if ( 0 === strncasecmp( $path, $root . DIRECTORY_SEPARATOR, strlen( $root . DIRECTORY_SEPARATOR ) ) || 0 === strcasecmp( $path, $root ) ) { continue; }
			return $path;
		}
		return new WP_Error( 'branding_private_temp_unavailable' );
	}

	public function document_branding( $resolved ) {
		$logo = $resolved['logo'];
		return array( 'settings_revision' => $resolved['settings_revision'], 'fields' => $resolved['fields'], 'logo' => $logo ? array( 'asset_id' => null, 'source_attachment_id' => $logo['source_attachment_id'], 'mime_type' => $logo['mime_type'], 'width' => $logo['width'], 'height' => $logo['height'], 'byte_length' => $logo['byte_length'], 'sha256' => $logo['sha256'] ) : null );
	}

	/** Called inside the Report finalization transaction. */
	public function insert_asset( $report_id, $logo ) {
		if ( ! $logo ) { return null; }
		$ok = $this->wpdb->insert( $this->assets, array( 'report_id' => $report_id, 'mime_type' => 'image/png', 'width' => $logo['width'], 'height' => $logo['height'], 'byte_length' => $logo['byte_length'], 'sha256' => $logo['sha256'], 'image_bytes' => $logo['bytes'], 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
		return false === $ok ? new WP_Error( 'branding_asset_persistence_failed' ) : (int) $this->wpdb->insert_id;
	}

	/** The frozen payload selects this one report-owned binary; never consult Media here. */
	public function read_frozen_asset( $report_id, $document ) {
		$logo = $document['branding']['logo'] ?? null;
		if ( null === $logo ) { return null; }
		if ( ! is_array( $logo ) || empty( $logo['asset_id'] ) || ! ctype_digit( (string) $logo['asset_id'] ) ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->assets} WHERE asset_id = %d AND report_id = %d", $logo['asset_id'], $report_id ) );
		if ( ! $row || 'image/png' !== $row->mime_type || $row->mime_type !== ( $logo['mime_type'] ?? null ) || (int) $row->byte_length < 1 || (int) $row->byte_length > self::MAX_ASSET_BYTES || (int) $row->byte_length !== strlen( $row->image_bytes ) || (int) $row->byte_length !== ( $logo['byte_length'] ?? null ) || (int) $row->width !== ( $logo['width'] ?? null ) || (int) $row->height !== ( $logo['height'] ?? null ) || ! is_string( $logo['sha256'] ?? null ) || ! hash_equals( $row->sha256, $logo['sha256'] ) || ! hash_equals( $row->sha256, hash( 'sha256', $row->image_bytes ) ) ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
		return $row;
	}
}
