<?php
/** Print-only view of one authoritative draft or integrity-verified frozen document. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$t = static function ( $group, $key ) use ( $presentation ) { return FixReport_Report_Language::text( $presentation, $group, $key ); };
$e = static function ( $value ) { return esc_html( null === $value ? '' : (string) $value ); };
$line = static function ( $value ) use ( $e ) { return nl2br( $e( $value ) ); };
$state_sections = array( 'overview', 'site', 'environment', 'updates', 'content', 'plugins', 'themes', 'content_types', 'health_items', 'technical' );
$brand = in_array( $document['contract_version'], array( '7.2', '7.3' ), true ) ? $document['branding']['fields'] : array();
?><!doctype html><html lang="<?php echo $arabic ? 'ar' : 'en'; ?>" dir="<?php echo $arabic ? 'rtl' : 'ltr'; ?>"><head><meta charset="utf-8"><style>
body { font-family: dejavusans; font-size: 9pt; line-height: 1.48; color: #243342; }
h1 { font-size: 19pt; color: #153a58; margin: 6mm 0 3mm; }
h2 { font-size: 12.5pt; color: #153a58; border-bottom: 1px solid #b5c5d4; padding-bottom: 2mm; margin: 8mm 0 3mm; }
h3 { font-size: 10pt; color: #244861; margin: 4mm 0 1mm; }
p { margin: 1mm 0 2mm; }
.meta { font-size: 8pt; color: #526475; margin: 2mm 0; }
.banner { background: #e8eef4; border: 1px solid #c2d0dc; padding: 3mm; margin: 4mm 0; }
.warning { background: #f7eee9; border-left: 2mm solid #a34839; padding: 2mm; }
.card { border-bottom: 1px solid #d5dde5; padding: 2mm 0 3mm; page-break-inside: auto; }
.state-item { border-bottom: 1px solid #dce4eb; padding: 1.5mm 0; }
.technical { direction: ltr; unicode-bidi: embed; word-wrap: break-word; }
table { width: 100%; border-collapse: collapse; font-size: 8pt; margin: 3mm 0; }
thead { display: table-header-group; }
th { color: #173a57; background: #e8eef4; }
th, td { border: 1px solid #bdcbd7; padding: 1.5mm; vertical-align: top; word-wrap: break-word; }
.brand-logo { max-width: 55mm; max-height: 30mm; }
.brand-footer { border-top: 1px solid #b5c5d4; margin-top: 7mm; padding-top: 2mm; color: #526475; }
</style></head><body>
<?php if ( $brand ) : ?>
	<?php if ( $logo_bytes ) : ?><p><img class="brand-logo" src="data:image/png;base64,<?php echo esc_attr( base64_encode( $logo_bytes ) ); ?>" alt=""></p><?php endif; ?>
	<?php if ( $brand['company_name'] ) : ?><h2><?php echo $e( $brand['company_name'] ); ?></h2><?php endif; ?>
	<?php foreach ( array( 'website', 'email', 'phone', 'address' ) as $field ) : if ( ! $brand[ $field ] ) { continue; } ?><p class="meta"><?php echo $line( $brand[ $field ] ); ?></p><?php endforeach; ?>
<?php endif; ?>
<h1><?php echo $e( $document['identity']['title'] ); ?></h1>
<p class="meta"><?php echo $e( $t( 'ui', 'report' ) ); ?> #<span class="technical" dir="ltr"><?php echo $e( $document['identity']['report_id'] ); ?></span> · <?php echo $e( $t( 'ui', $document['identity']['type'] ) ); ?> · <span class="technical" dir="ltr"><?php echo $e( $document['locale'] ); ?></span> · <?php echo $e( $t( 'ui', 'document_contract' ) ); ?> <span class="technical" dir="ltr"><?php echo $e( $document['contract_version'] ); ?></span></p>
<div class="banner"><?php echo $e( $t( 'ui', $draft ? 'draft' : 'historical' ) ); ?></div>
<?php if ( $document['period'] ) : ?><p class="meta"><?php echo $e( $t( 'ui', 'period' ) ); ?>: <span class="technical" dir="ltr"><?php echo $e( $document['period']['start_utc'] ); ?> UTC</span> <?php echo $e( $t( 'ui', 'through' ) ); ?> <span class="technical" dir="ltr"><?php echo $e( $document['period']['end_utc_exclusive'] ); ?> UTC</span> (<?php echo $e( $t( 'ui', 'exclusive' ) ); ?>); <?php echo $e( $t( 'ui', 'timezone' ) ); ?> <span class="technical" dir="ltr"><?php echo $e( $document['period']['timezone'] ); ?></span>.</p><?php endif; ?>

<h2><?php echo $e( $presentation['labels']['summary'] ); ?></h2><p><?php echo $line( $document['authored']['summary'] ?: '—' ); ?></p>

<?php if ( 'maintenance' === $document['identity']['type'] ) : ?>
<h2><?php echo $e( $presentation['labels']['activities'] ); ?></h2>
<?php if ( ! $document['activities'] ) : ?><p><?php echo $e( $t( 'ui', 'no_activities' ) ); ?></p><?php else : ?>
<table><thead><tr><th>#</th><th><?php echo $e( $t( 'ui', 'details' ) ); ?></th><th><?php echo $e( $t( 'ui', 'status' ) ); ?></th><th><?php echo $e( $t( 'ui', 'date' ) ); ?></th></tr></thead><tbody>
<?php foreach ( $document['activities'] as $a ) : ?><tr><td><span dir="ltr" class="technical"><?php echo $e( $a['id'] ); ?></span></td><td><?php echo $e( $a['title'] ); ?></td><td><?php echo $e( $t( 'statuses', $a['status'] ) ); ?></td><td><span dir="ltr" class="technical"><?php echo $e( $a['displayed_at'] ); ?></span></td></tr><?php endforeach; ?>
</tbody></table>
<?php foreach ( $document['activities'] as $a ) : ?><div class="card"><h3><span dir="ltr" class="technical">#<?php echo $e( $a['id'] ); ?></span> <?php echo $e( $a['title'] ); ?></h3><p class="meta"><?php echo $e( $t( 'statuses', $a['status'] ) ); ?> · <?php echo $e( $t( 'sources', $a['source'] ) ); ?> · <span class="technical" dir="ltr"><?php echo $e( $a['displayed_at'] . ' ' . $document['period']['timezone'] ); ?></span><?php if ( 'legacy_local_clock_uncertain' === $a['time_basis'] ) : ?> · <?php echo $e( $t( 'ui', 'legacy_clock' ) ); ?><?php endif; ?></p><p><?php echo $line( $a['description'] ); ?></p><?php if ( $a['object_name'] || $a['previous_version'] || $a['new_version'] ) : ?><p class="meta technical" dir="ltr"><?php echo $e( trim( (string) $a['object_name'] . ' ' . (string) $a['previous_version'] . ' → ' . (string) $a['new_version'] ) ); ?></p><?php endif; ?></div><?php endforeach; ?>
<?php endif; ?>

<h2><?php echo $e( $presentation['labels']['checklist'] ); ?></h2>
<?php if ( ! $document['checklist'] ) : ?><p><?php echo $e( $t( 'ui', 'no_checklist' ) ); ?></p><?php endif; ?>
<?php foreach ( $document['checklist'] as $c ) : ?><div class="card"><h3><?php echo $e( $c['label'] ); ?> — <?php echo $e( $t( 'statuses', $c['status'] ) ); ?></h3><p><?php echo $line( $c['description'] ); ?></p><?php if ( $c['note'] ) : ?><p><?php echo $line( $c['note'] ); ?></p><?php endif; ?></div><?php endforeach; ?>
<?php endif; ?>

<?php foreach ( array( 'start_state', 'end_state' ) as $role ) : $state = $document[ $role ]; if ( ! $state ) { continue; } $state_view = $state['presentation']; ?>
<h2><?php echo $e( $presentation['labels'][ $role ] ); ?></h2>
<p class="meta"><?php echo $e( $t( 'ui', 'snapshot' ) ); ?> #<span dir="ltr" class="technical"><?php echo $e( $state['id'] ); ?></span> · <?php echo $e( $t( 'ui', 'captured' ) ); ?> <span dir="ltr" class="technical"><?php echo $e( $state['completed_at'] ); ?> UTC</span> · <?php echo $e( $t( 'relations', $state['relation'] ) ); ?></p>
<?php if ( in_array( $state['relation'], array( 'late_after_start', 'early_before_end' ), true ) ) : ?><p class="warning"><?php echo $e( $t( 'ui', 'approximation' ) ); ?></p><?php endif; ?>
<?php if ( ! empty( $state_view['integrity_warning'] ) ) : ?><p class="warning"><?php echo $e( $t( 'ui', 'integrity_warning' ) ); ?></p><?php endif; ?>
<?php foreach ( $state_sections as $section ) : if ( empty( $state_view[ $section ] ) ) { continue; } ?><h3><?php echo $e( $presentation['labels'][ $section ] ); ?></h3>
<?php foreach ( $state_view[ $section ] as $item ) : ?><div class="state-item"><strong><?php echo $e( $item['label'] ?? $item['name'] ?? $t( 'ui', 'evidence' ) ); ?></strong><?php foreach ( $item as $key => $value ) : if ( in_array( $key, array( 'label', 'name', 'url' ), true ) ) { continue; } ?><p class="meta"><span class="technical" dir="ltr"><?php echo $e( $key ); ?></span>: <?php echo $e( is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : ( null === $value ? '—' : (string) $value ) ); ?></p><?php endforeach; ?></div><?php endforeach; ?>
<?php endforeach; ?>
<p class="meta"><?php echo $e( $t( 'ui', 'health_coverage' ) ); ?>: <?php echo $e( $state_view['health_coverage'] ); ?>; <?php echo $e( $t( 'ui', 'critical_findings' ) ); ?>: <?php echo $e( $state_view['health_critical'] ); ?>; <?php echo $e( $t( 'ui', 'recommended_findings' ) ); ?>: <?php echo $e( $state_view['health_recommended'] ); ?>. <?php echo $e( $t( 'ui', 'health_caveat' ) ); ?></p>
<?php endforeach; ?>

<?php if ( $document['comparison'] ) : ?><h2><?php echo $e( $presentation['labels']['comparison'] ); ?></h2><p class="meta"><?php echo $e( $t( 'ui', 'comparison_caveat' ) ); ?></p>
<?php foreach ( $document['comparison']['groups'] as $group ) : ?><h3><?php echo $e( $group['name'] ); ?> · <?php echo $e( $group['comparability'] ); ?></h3><?php foreach ( array( 'changes', 'incomparable', 'unchanged', 'technical' ) as $bucket ) : foreach ( $group[ $bucket ] as $entry ) : ?><div class="state-item"><strong><?php echo $e( $entry['title'] ); ?> · <?php echo $e( $entry['state'] ); ?></strong><p><?php echo $e( $entry['before'] ); ?> → <?php echo $e( $entry['after'] ); ?></p><?php foreach ( $entry['details'] as $detail ) : ?><p class="meta"><?php echo $e( $detail ); ?></p><?php endforeach; ?></div><?php endforeach; endforeach; endforeach; endif; ?>

<?php foreach ( array( 'findings', 'recommendations', 'final_notes' ) as $field ) : ?><h2><?php echo $e( $presentation['labels'][ $field ] ); ?></h2><p><?php echo $line( $document['authored'][ $field ] ?: '—' ); ?></p><?php endforeach; ?>
<?php if ( ! $draft && $document['finalization'] ) : ?><p class="meta"><?php echo $e( $t( 'ui', 'finalized' ) ); ?> <span dir="ltr" class="technical"><?php echo $e( $document['finalization']['at_utc'] ); ?> UTC</span> <?php echo $e( $t( 'ui', 'by_user' ) ); ?> #<span dir="ltr" class="technical"><?php echo $e( $document['finalization']['by_user_id'] ); ?></span>. <?php echo $e( $t( 'ui', 'frozen_caveat' ) ); ?></p><?php endif; ?>
<?php if ( ! empty( $brand['footer_text'] ) ) : ?><div class="brand-footer"><?php echo $line( $brand['footer_text'] ); ?></div><?php endif; ?>
</body></html>
