<?php
/** Print-only compact layout 9.2 of one Draft or one marked, integrity-verified frozen document. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$logo_src = $logo_bytes ? 'data:image/png;base64,' . base64_encode( $logo_bytes ) : '';
$logo_inline = true;
?><!doctype html><html lang="<?php echo $layout['rtl'] ? 'ar' : 'en'; ?>" dir="<?php echo $layout['rtl'] ? 'rtl' : 'ltr'; ?>"><head><meta charset="utf-8"><style>
body { font-family: dejavusans; font-size: 8.5pt; line-height: 1.4; color: #243342; }
h1 { font-size: 16pt; color: #153a58; margin: 3mm 0 2mm; }
h2 { font-size: 11pt; color: #153a58; border-bottom: 1px solid #b5c5d4; padding-bottom: 1mm; margin: 5mm 0 2mm; }
h3 { font-size: 9pt; color: #244861; margin: 3mm 0 1mm; }
p { margin: 1mm 0 1.5mm; }
.meta { font-size: 7.5pt; color: #526475; }
.sub { font-size: 7.5pt; color: #526475; }
.banner { background: #e8eef4; border: 1px solid #c2d0dc; padding: 1.5mm 2mm; margin: 2mm 0; font-size: 8pt; }
.warning { background: #f7eee9; border-left: 1.5mm solid #a34839; padding: 1.5mm 2mm; }
.technical { direction: ltr; unicode-bidi: embed; }
table { width: 100%; border-collapse: collapse; }
.masthead td { vertical-align: top; padding: 0; border: 0; }
.masthead-contact { text-align: right; }
.company { font-size: 11pt; color: #153a58; }
.brand-logo { max-width: 45mm; max-height: 22mm; }
.facts-head { font-size: 8pt; margin: 1mm 0 2mm; }
.facts-head th { text-align: left; width: 28%; color: #526475; font-weight: normal; padding: 0.6mm 1.5mm 0.6mm 0; border: 0; }
.facts-head td { padding: 0.6mm 0; border: 0; }
.grid { font-size: 7.8pt; margin: 1.5mm 0 2.5mm; }
.grid thead { display: table-header-group; }
.grid th { color: #173a57; background: #e8eef4; text-align: left; }
.grid th, .grid td { border: 1px solid #c9d5df; padding: 1mm 1.4mm; vertical-align: top; }
.grid tr.group td { background: #f2f5f8; color: #244861; font-weight: bold; }
.grid tr.mark td { background: #fbf6ea; }
.brand-footer { border-top: 1px solid #b5c5d4; margin-top: 5mm; padding-top: 1.5mm; color: #526475; font-size: 7.5pt; }
<?php if ( $layout['rtl'] ) : ?>
.masthead-contact { text-align: left; }
.facts-head th, .grid th { text-align: right; }
.facts-head th { padding: 0.6mm 0 0.6mm 1.5mm; }
.warning { border-left: 0; border-right: 1.5mm solid #a34839; }
<?php endif; ?>
</style></head><body>
<?php require FIXREPORT_PATH . 'templates/report-compact-body.php'; ?>
</body></html>
