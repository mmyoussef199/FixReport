<?php
/**
 * Compact layout 9.2 view of the canonical Draft or marked frozen document; same model and body as the PDF.
 * Callers set $compact_draft (true for a Draft) and $logo_url.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$layout = FixReport_Report_Layout::build( $document, (bool) $compact_draft );
$logo_src = $logo_url;
$logo_inline = false;
?>
<style>
.fixreport-report-compact { color: #243342; line-height: 1.45; }
.fixreport-report-compact h1 { font-size: 1.6em; margin: .6em 0 .4em; color: #153a58; }
.fixreport-report-compact h2 { font-size: 1.15em; margin: 1.6em 0 .5em; padding-bottom: .3em; border-bottom: 1px solid #b5c5d4; color: #153a58; }
.fixreport-report-compact h3 { font-size: 1em; margin: 1em 0 .3em; color: #244861; }
.fixreport-report-compact .meta, .fixreport-report-compact .sub { font-size: 12px; color: #526475; }
.fixreport-report-compact .banner { background: #e8eef4; border: 1px solid #c2d0dc; padding: 6px 10px; margin: 8px 0; }
.fixreport-report-compact .warning { background: #f7eee9; border-inline-start: 4px solid #a34839; padding: 6px 10px; }
.fixreport-report-compact .technical { direction: ltr; unicode-bidi: embed; word-break: break-word; }
.fixreport-report-compact table { width: 100%; border-collapse: collapse; }
.fixreport-report-compact .masthead td { vertical-align: top; border: 0; padding: 0; }
.fixreport-report-compact .masthead-contact { text-align: end; }
.fixreport-report-compact .company { font-size: 1.15em; color: #153a58; }
.fixreport-report-compact .brand-logo { max-width: 220px; max-height: 110px; }
.fixreport-report-compact .facts-head { margin: 4px 0 8px; }
.fixreport-report-compact .facts-head th { width: 28%; text-align: start; font-weight: 400; color: #526475; padding: 2px 8px 2px 0; }
.fixreport-report-compact .facts-head td { padding: 2px 0; }
.fixreport-report-compact .grid { margin: 6px 0 12px; font-size: 13px; }
.fixreport-report-compact .grid th { background: #e8eef4; color: #173a57; text-align: start; }
.fixreport-report-compact .grid th, .fixreport-report-compact .grid td { border: 1px solid #c9d5df; padding: 5px 7px; vertical-align: top; }
.fixreport-report-compact .grid tr.group td { background: #f2f5f8; color: #244861; font-weight: 600; }
.fixreport-report-compact .grid tr.mark td { background: #fbf6ea; }
.fixreport-report-compact .brand-footer { border-top: 1px solid #b5c5d4; margin-top: 20px; padding-top: 6px; color: #526475; }
</style>
<article class="fixreport-report-document fixreport-report-compact" lang="<?php echo $layout['rtl'] ? 'ar' : 'en'; ?>" dir="<?php echo $layout['rtl'] ? 'rtl' : 'ltr'; ?>">
	<?php require FIXREPORT_PATH . 'templates/report-compact-body.php'; ?>
</article>
