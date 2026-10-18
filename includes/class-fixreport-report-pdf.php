<?php
/** Bounded PDF presentation of an already assembled Report Document. */
class FixReport_Report_PDF {
	const TEMPLATE = '9.1';
	const MAX_DOCUMENT_BYTES = 8388608;
	const MAX_OUTPUT_BYTES = 20971520;
	const MAX_PAGES = 120;

	public function render( $document, $draft, $logo_bytes = null ) {
		if ( ! class_exists( '\\Mpdf\\Mpdf' ) || ! extension_loaded( 'gd' ) || ! extension_loaded( 'mbstring' ) ) { return new WP_Error( 'report_pdf_unavailable' ); }
		if ( ! is_array( $document ) || ! in_array( $document['contract_version'] ?? '', array( '7.1', '7.2', '7.3' ), true ) || ! isset( $document['identity']['report_id'] ) || ! is_int( $document['identity']['report_id'] ) || ! is_bool( $draft ) || ! is_array( $document['activities'] ?? null ) || ! is_array( $document['checklist'] ?? null ) || ! is_array( $document['authored'] ?? null ) || ! is_array( $document['labels'] ?? null ) || ! array_key_exists( 'start_state', $document ) || ! array_key_exists( 'end_state', $document ) || ! array_key_exists( 'comparison', $document ) || ! array_key_exists( 'period', $document ) ) { return new WP_Error( 'report_pdf_model_invalid' ); }
		$encoded = FixReport_Report_Document::encode( $document );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_DOCUMENT_BYTES || count( $document['activities'] ?? array() ) > 1000 || count( $document['checklist'] ?? array() ) > 200 ) { return new WP_Error( 'report_pdf_limit' ); }
		$logo = $document['branding']['logo'] ?? null;
		if ( $logo ) {
			if ( ! is_string( $logo_bytes ) || strlen( $logo_bytes ) > FixReport_Branding::MAX_ASSET_BYTES || strlen( $logo_bytes ) !== ( $logo['byte_length'] ?? null ) || ! hash_equals( $logo['sha256'] ?? '', hash( 'sha256', $logo_bytes ) ) ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
			$dimensions = @getimagesizefromstring( $logo_bytes );
			if ( ! $dimensions || 'image/png' !== ( $dimensions['mime'] ?? null ) || $dimensions[0] !== ( $logo['width'] ?? null ) || $dimensions[1] !== ( $logo['height'] ?? null ) ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
		} elseif ( null !== $logo_bytes ) { return new WP_Error( 'branding_asset_integrity_failed' ); }
		$temp = $this->private_temp();
		if ( is_wp_error( $temp ) ) { return $temp; }
		$start = microtime( true );
		try {
			$presentation = FixReport_Report_Language::for_document( $document );
			$arabic = '7.3' === $document['contract_version'] && 'ar' === ( $document['locale'] ?? '' );
			// Layout 9.2 for Drafts and for reports frozen with the 9.2 marker; every other document keeps template 9.1.
			$layout_version = FixReport_Report_Layout::version( $document, $draft );
			$layout = FixReport_Report_Layout::COMPACT === $layout_version ? FixReport_Report_Layout::build( $document, $draft ) : null;
			$buffer_level = ob_get_level();
			ob_start();
			try {
				require FIXREPORT_PATH . ( $layout ? 'templates/report-pdf-compact.php' : 'templates/report-pdf.php' );
				$html = ob_get_clean();
			} finally {
				while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
			}
			if ( ! is_string( $html ) || strlen( $html ) > self::MAX_DOCUMENT_BYTES * 3 ) { return new WP_Error( 'report_pdf_limit' ); }
			$pdf = new \Mpdf\Mpdf( array( 'format' => 'A4', 'tempDir' => $temp, 'default_font' => 'dejavusans', 'margin_top' => 19, 'margin_bottom' => 19, 'margin_left' => 17, 'margin_right' => 17 ) );
			$pdf->SetTitle( $document['identity']['title'] );
			$pdf->SetAuthor( 'FixReport' );
			$pdf->SetKeywords( 'FixReport PDF template ' . ( $layout ? FixReport_Report_Layout::COMPACT : self::TEMPLATE ) );
			$pdf->SetDirectionality( $arabic ? 'rtl' : 'ltr' );
			$draft_text = FixReport_Report_Language::text( $presentation, 'ui', 'draft' );
			if ( $draft ) {
				$pdf->SetWatermarkText( $draft_text, 0.10 );
				$pdf->showWatermarkText = true;
				$pdf->SetHTMLHeader( '<div style="font-family:dejavusans;color:#8c3434;font-size:8pt;text-align:center">' . esc_html( $draft_text ) . '</div>' );
			}
			$footer = ( $draft ? esc_html( $draft_text ) . ' &nbsp; | &nbsp; ' : '' ) . esc_html( FixReport_Report_Language::text( $presentation, 'ui', 'page' ) ) . ' {PAGENO} ' . esc_html( FixReport_Report_Language::text( $presentation, 'ui', 'of' ) ) . ' {nbpg}';
			$pdf->SetHTMLFooter( '<div style="font-family:dejavusans;font-size:8pt;color:#526475;text-align:center">' . $footer . '</div>' );
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 60 ); }
			$pdf->WriteHTML( $html );
			if ( $pdf->page > self::MAX_PAGES || microtime( true ) - $start > 50 ) { return new WP_Error( 'report_pdf_limit' ); }
			$bytes = $pdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
			if ( ! is_string( $bytes ) || strlen( $bytes ) < 8 || strlen( $bytes ) > self::MAX_OUTPUT_BYTES || 0 !== strpos( $bytes, '%PDF-' ) || microtime( true ) - $start > 60 ) { return new WP_Error( 'report_pdf_limit' ); }
			return $bytes;
		} catch ( Throwable $error ) {
			return new WP_Error( 'report_pdf_render_failed' );
		} finally {
			$this->remove_private_temp( $temp );
		}
	}

	private function private_temp() {
		$webroot = realpath( ABSPATH );
		if ( ! $webroot ) { return new WP_Error( 'report_pdf_private_temp_unavailable' ); }
		try { $suffix = bin2hex( random_bytes( 12 ) ); } catch ( Throwable $error ) { return new WP_Error( 'report_pdf_private_temp_unavailable' ); }
		// Some Local web PHP workers cannot write to their system TEMP directory.
		// The WordPress app parent is a private fallback, outside the public webroot.
		foreach ( array( sys_get_temp_dir(), dirname( rtrim( ABSPATH, '/\\' ) ) ) as $candidate ) {
			$base = realpath( $candidate );
			$public_prefix = rtrim( $webroot, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( ! $base || ! is_writable( $base ) || 0 === strcasecmp( $base, $webroot ) || 0 === strncasecmp( $base, $public_prefix, strlen( $public_prefix ) ) ) { continue; }
			$path = $base . DIRECTORY_SEPARATOR . 'fixreport-pdf-' . $suffix;
			if ( @mkdir( $path, 0700 ) ) { return $path; }
		}
		return new WP_Error( 'report_pdf_private_temp_unavailable' );
	}

	private function remove_private_temp( $path ) {
		if ( ! is_string( $path ) || ! preg_match( '/[\\\\\/]fixreport-pdf-[a-f0-9]{24}$/', $path ) || ! is_dir( $path ) ) { return; }
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) { $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() ); }
		@rmdir( $path );
	}
}
