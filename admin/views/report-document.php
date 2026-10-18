<?php
/** Readable view of the canonical draft/frozen document; no live evidence reads. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$labels = $document['labels'];
$presentation = FixReport_Report_Language::for_document( $document );
$word = static function ( $group, $key ) use ( $presentation ) { return FixReport_Report_Language::text( $presentation, $group, $key ); };
$is_arabic = '7.3' === $document['contract_version'] && 'ar' === $document['locale'];
?>
<article class="fixreport-report-document" lang="<?php echo $is_arabic ? 'ar' : 'en'; ?>" dir="<?php echo $is_arabic ? 'rtl' : 'ltr'; ?>">
	<?php if ( in_array( $document['contract_version'], array( '7.2', '7.3' ), true ) ) : $brand = $document['branding']['fields']; ?>
		<header class="fixreport-report-branding">
			<?php if ( $logo_url ) : ?><p><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand['company_name'] ?: 'Report logo' ); ?>" style="max-width:300px;max-height:180px"></p><?php endif; ?>
			<?php if ( $brand['company_name'] ) : ?><h2><?php echo esc_html( $brand['company_name'] ); ?></h2><?php endif; ?>
			<?php foreach ( array( 'website', 'email', 'phone', 'address' ) as $field ) : if ( ! $brand[ $field ] ) { continue; } ?><p><?php echo nl2br( esc_html( $brand[ $field ] ) ); ?></p><?php endforeach; ?>
		</header>
	<?php endif; ?>
	<h2><?php echo esc_html( $document['identity']['title'] ); ?></h2>
	<p><?php echo esc_html( $word( 'ui', 'report' ) ); ?> #<?php echo esc_html( $document['identity']['report_id'] ); ?> · <?php echo esc_html( $word( 'ui', $document['identity']['type'] ) ); ?> · <?php echo esc_html( $document['locale'] ); ?> · <?php echo esc_html( $word( 'ui', 'document_contract' ) ); ?> <?php echo esc_html( $document['contract_version'] ); ?></p>
	<?php if ( $document['period'] ) : ?><p><?php echo esc_html( $word( 'ui', 'period' ) ); ?>: <?php echo esc_html( $document['period']['start_utc'] ); ?> UTC <?php echo esc_html( $word( 'ui', 'through' ) ); ?> <?php echo esc_html( $document['period']['end_utc_exclusive'] ); ?> UTC (<?php echo esc_html( $word( 'ui', 'exclusive' ) ); ?>); <?php echo esc_html( $word( 'ui', 'timezone' ) ); ?> <?php echo esc_html( $document['period']['timezone'] ); ?>.</p><?php endif; ?>
	<?php foreach ( array( 'summary', 'findings', 'recommendations', 'final_notes' ) as $field ) : ?>
		<section><h3><?php echo esc_html( $labels[ $field ] ); ?></h3><p><?php echo nl2br( esc_html( $document['authored'][ $field ] ?: '—' ) ); ?></p></section>
	<?php endforeach; ?>
	<?php foreach ( array( 'start_state', 'end_state' ) as $role ) : ?>
		<?php $state = $document[ $role ]; if ( ! $state ) { continue; } $p = $state['presentation']; ?>
		<section><h3><?php echo esc_html( $labels[ $role ] ); ?></h3>
		<p><?php echo esc_html( $word( 'ui', 'snapshot' ) ); ?> #<?php echo esc_html( $state['id'] ); ?> · <?php echo esc_html( $state['type'] ); ?> · <?php echo esc_html( $word( 'ui', 'captured' ) ); ?> <?php echo esc_html( $state['completed_at'] ); ?> UTC · <?php echo esc_html( $word( 'relations', $state['relation'] ) ); ?><?php if ( 'late_after_start' === $state['relation'] || 'early_before_end' === $state['relation'] ) : ?> (<?php echo esc_html( $word( 'ui', 'approximation' ) ); ?>)<?php endif; ?></p>
		<?php if ( $p['integrity_warning'] ) : ?><p><?php echo esc_html( $word( 'ui', 'integrity_warning' ) ); ?></p><?php endif; ?>
		<?php foreach ( array( 'overview', 'site', 'environment', 'updates', 'content', 'plugins', 'themes', 'content_types', 'health_items', 'technical' ) as $key ) : ?>
			<?php if ( empty( $p[ $key ] ) ) { continue; } ?><h4><?php echo esc_html( $presentation['labels'][ $key ] ); ?></h4><table class="widefat striped"><tbody>
			<?php foreach ( $p[ $key ] as $item ) : ?><tr><th scope="row"><?php echo esc_html( $item['label'] ?? $item['name'] ?? $word( 'ui', 'evidence' ) ); ?></th><td><?php foreach ( $item as $k => $v ) { if ( in_array( $k, array( 'label', 'name', 'url' ), true ) || is_array( $v ) ) { continue; } echo '<span style="margin-right:1em">' . esc_html( $k ) . ': ' . esc_html( null === $v ? '—' : (string) $v ) . '</span>'; } ?></td></tr><?php endforeach; ?>
			</tbody></table>
		<?php endforeach; ?>
		<p><?php echo esc_html( $word( 'ui', 'health_coverage' ) ); ?>: <?php echo esc_html( $p['health_coverage'] ); ?>; <?php echo esc_html( $word( 'ui', 'critical_findings' ) ); ?>: <?php echo esc_html( $p['health_critical'] ); ?>; <?php echo esc_html( $word( 'ui', 'recommended_findings' ) ); ?>: <?php echo esc_html( $p['health_recommended'] ); ?>. <?php echo esc_html( $word( 'ui', 'health_caveat' ) ); ?></p>
		</section>
	<?php endforeach; ?>
	<?php if ( 'maintenance' === $document['identity']['type'] ) : ?>
		<section><h3><?php echo esc_html( $labels['activities'] ); ?></h3>
		<?php if ( ! $document['activities'] ) : ?><p><?php echo esc_html( $word( 'ui', 'no_activities' ) ); ?></p><?php endif; ?>
		<?php foreach ( $document['activities'] as $a ) : ?><div style="border-bottom:1px solid #ddd;padding:8px 0"><h4>#<?php echo esc_html( $a['id'] ); ?> <?php echo esc_html( $a['title'] ); ?></h4><p><?php echo esc_html( $word( 'statuses', $a['status'] ) ); ?> · <?php echo esc_html( $word( 'sources', $a['source'] ) ); ?> · <?php echo esc_html( $a['displayed_at'] ); ?> <?php echo esc_html( $document['period']['timezone'] ); ?><?php if ( 'legacy_local_clock_uncertain' === $a['time_basis'] ) : ?> · <?php echo esc_html( $word( 'ui', 'legacy_clock' ) ); ?><?php endif; ?></p><p><?php echo nl2br( esc_html( $a['description'] ) ); ?></p></div><?php endforeach; ?>
		</section>
		<section><h3><?php echo esc_html( $labels['checklist'] ); ?></h3>
		<?php if ( ! $document['checklist'] ) : ?><p><?php echo esc_html( $word( 'ui', 'no_checklist' ) ); ?></p><?php endif; ?>
		<?php foreach ( $document['checklist'] as $c ) : ?><div style="border-bottom:1px solid #ddd;padding:8px 0"><h4><?php echo esc_html( $c['label'] ); ?> — <?php echo esc_html( $word( 'statuses', $c['status'] ) ); ?></h4><p><?php echo esc_html( $c['description'] ); ?></p><p><?php echo nl2br( esc_html( $c['note'] ) ); ?></p></div><?php endforeach; ?>
		</section>
	<?php endif; ?>
	<?php if ( $document['comparison'] ) : ?><section><h3><?php echo esc_html( $labels['comparison'] ); ?></h3><p><?php echo esc_html( $word( 'ui', 'comparison_caveat' ) ); ?></p>
		<?php foreach ( $document['comparison']['groups'] as $group ) : ?><h4><?php echo esc_html( $group['name'] ); ?> · <?php echo esc_html( $group['comparability'] ); ?></h4><?php foreach ( array( 'changes', 'incomparable', 'unchanged', 'technical' ) as $bucket ) : ?><?php foreach ( $group[ $bucket ] as $entry ) : ?><p><?php echo esc_html( $entry['title'] . ' — ' . $entry['state'] . ': ' . $entry['before'] . ' → ' . $entry['after'] ); ?><?php foreach ( $entry['details'] as $detail ) : ?><br><?php echo esc_html( $detail ); ?><?php endforeach; ?></p><?php endforeach; ?><?php endforeach; ?><?php endforeach; ?>
		</section><?php endif; ?>
	<?php if ( $document['finalization'] ) : ?><p><?php echo esc_html( $word( 'ui', 'finalized' ) ); ?> <?php echo esc_html( $document['finalization']['at_utc'] ); ?> UTC <?php echo esc_html( $word( 'ui', 'by_user' ) ); ?> #<?php echo esc_html( $document['finalization']['by_user_id'] ); ?>. <?php echo esc_html( $word( 'ui', 'frozen_caveat' ) ); ?></p><?php endif; ?>
	<?php if ( in_array( $document['contract_version'], array( '7.2', '7.3' ), true ) && $document['branding']['fields']['footer_text'] ) : ?><footer><p><?php echo nl2br( esc_html( $document['branding']['fields']['footer_text'] ) ); ?></p></footer><?php endif; ?>
</article>
