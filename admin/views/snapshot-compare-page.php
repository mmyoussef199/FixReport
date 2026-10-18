<?php
/** Read-only, derived comparison of two completed stored snapshots. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap fixreport-baseline">
	<h1><?php esc_html_e( 'Compare Site Snapshots', 'fixreport-maintenance-logger' ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-site-snapshots' ) ); ?>"><?php esc_html_e( '← Snapshot History', 'fixreport-maintenance-logger' ); ?></a></p>
	<p><?php esc_html_e( 'This read-only comparison uses stored evidence only. A difference does not prove when, why, or by whom a site change happened.', 'fixreport-maintenance-logger' ); ?></p>
	<p><strong><?php esc_html_e( 'TO:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( FixReport_Baseline_Presenter::display_label( $to ) ); ?></p>
	<?php if ( $from ) : ?><p><strong><?php esc_html_e( 'FROM:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( FixReport_Baseline_Presenter::display_label( $from ) ); ?></p><?php endif; ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="fixreport-site-snapshots">
		<input type="hidden" name="action" value="compare">
		<input type="hidden" name="to_id" value="<?php echo esc_attr( $to_id ); ?>">
		<label for="fixreport-from-id"><?php esc_html_e( 'Compare from another older completed Snapshot ID', 'fixreport-maintenance-logger' ); ?></label>
		<input id="fixreport-from-id" name="from_id" type="number" min="1" step="1" required value="<?php echo esc_attr( $from_id ? $from_id : '' ); ?>">
		<?php submit_button( __( 'Compare', 'fixreport-maintenance-logger' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php if ( ! $from ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No earlier completed Snapshot is available for this TO Snapshot.', 'fixreport-maintenance-logger' ); ?></p></div>
	<?php elseif ( $comparison_error ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'These stored Snapshots cannot be compared safely because their evidence is unreadable, corrupt, or uses an incompatible collection contract. No result was saved.', 'fixreport-maintenance-logger' ); ?></p></div>
	<?php elseif ( $comparison_data ) : ?>
		<p><?php echo esc_html( sprintf( /* translators: 1: comparison contract; 2: collection contract. */ __( 'Comparison contract %1$s · Snapshot collection contract %2$s', 'fixreport-maintenance-logger' ), $comparison_data['contract_version'], $comparison_data['from']['collection_contract'] ) ); ?></p>
		<p><?php echo esc_html( sprintf( /* translators: 1: groups with changes; 2: groups with incomparable evidence. */ __( '%1$d groups with factual changes; %2$d groups with incomparable evidence. These are counts, not a score.', 'fixreport-maintenance-logger' ), $comparison_data['summary']['changed_groups'], $comparison_data['summary']['incomparable_groups'] ) ); ?></p>
		<?php if ( $comparison_data['warnings'] ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Some evidence was Unknown at capture and cannot be compared. See the warnings in each group.', 'fixreport-maintenance-logger' ); ?></p></div><?php endif; ?>
		<?php foreach ( $comparison_data['groups'] as $group ) : ?>
			<section class="fixreport-compare-group">
				<h2><?php echo esc_html( $group['name'] ); ?></h2>
				<?php if ( ! $group['changes'] ) : ?><p><?php esc_html_e( 'No primary changes in this group; technical and unchanged evidence is available below.', 'fixreport-maintenance-logger' ); ?></p><?php endif; ?>
				<?php foreach ( $group['changes'] as $entry ) : ?>
					<div class="fixreport-baseline-card"><h3><?php echo esc_html( $entry['title'] ); ?> — <?php echo esc_html( $entry['state'] ); ?></h3><p><strong><?php esc_html_e( 'Before:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( $entry['before'] ); ?> <strong><?php esc_html_e( 'After:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( $entry['after'] ); ?></p><?php foreach ( $entry['details'] as $detail ) : ?><p><?php echo esc_html( $detail ); ?></p><?php endforeach; ?></div>
				<?php endforeach; ?>
				<?php if ( $group['incomparable'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Unknown or incomparable evidence in this group:', 'fixreport-maintenance-logger' ); ?></p></div>
				<?php foreach ( $group['incomparable'] as $entry ) : ?><p><strong><?php echo esc_html( $entry['title'] ); ?>:</strong> <?php echo esc_html( $entry['before'] ); ?> → <?php echo esc_html( $entry['after'] ); ?></p><?php endforeach; ?>
				<?php endif; ?>
				<?php if ( 'partial' === $group['comparability'] && ! $group['incomparable'] ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Some details of changed evidence were Unknown at capture; see the item details above.', 'fixreport-maintenance-logger' ); ?></p></div><?php endif; ?>
				<?php if ( $group['unchanged'] ) : ?><details><summary><?php echo esc_html( sprintf( /* translators: %d: unchanged entries. */ __( 'Unchanged evidence (%d)', 'fixreport-maintenance-logger' ), count( $group['unchanged'] ) ) ); ?></summary><?php foreach ( $group['unchanged'] as $entry ) : ?><p><strong><?php echo esc_html( $entry['title'] ); ?>:</strong> <?php echo esc_html( $entry['before'] ); ?> → <?php echo esc_html( $entry['after'] ); ?></p><?php endforeach; ?></details><?php endif; ?>
				<?php if ( $group['technical'] ) : ?><details><summary><?php esc_html_e( 'Technical details', 'fixreport-maintenance-logger' ); ?></summary><?php foreach ( $group['technical'] as $entry ) : ?><p><strong><?php echo esc_html( $entry['title'] ); ?> — <?php echo esc_html( $entry['state'] ); ?>:</strong> <?php echo esc_html( $entry['before'] ); ?> → <?php echo esc_html( $entry['after'] ); ?></p><?php endforeach; ?></details><?php endif; ?>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
