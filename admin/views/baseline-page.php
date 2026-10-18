<?php
/** Site Baseline admin presentation. All values are historical and view-model prepared. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap fixreport-baseline<?php echo ! empty( $is_baseline_route ) ? ' fixreport-baseline--redesigned' : ''; ?>">
	<h1><?php echo esc_html( 'complete' === $baseline_state && is_array( $baseline_data ) && ! $baseline_data['is_initial'] ? __( 'Site Snapshot', 'fixreport-maintenance-logger' ) : __( 'Site Baseline', 'fixreport-maintenance-logger' ) ); ?></h1>
	<?php if ( 'complete' !== $baseline_state || ( is_array( $baseline_data ) && $baseline_data['is_initial'] ) ) : ?><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-site-snapshots' ) ); ?>"><?php esc_html_e( 'Snapshot History', 'fixreport-maintenance-logger' ); ?></a></p><?php endif; ?>
	<?php if ( 'error' === $baseline_state ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'The stored snapshot could not be read safely. It has not been changed. Please review the WordPress error log or contact an administrator.', 'fixreport-maintenance-logger' ); ?></p></div>
	<?php elseif ( 'complete' !== $baseline_state ) : ?>
		<section class="fixreport-baseline-intro" aria-labelledby="fixreport-baseline-intro-title">
			<h2 id="fixreport-baseline-intro-title"><?php esc_html_e( 'Create your Initial Site Baseline', 'fixreport-maintenance-logger' ); ?></h2>
			<p><?php esc_html_e( 'Capture the website’s technical condition before maintenance work begins. The baseline records WordPress, environment, plugins, themes, content counts, update state, and maintenance-related technical evidence.', 'fixreport-maintenance-logger' ); ?></p>
			<p><?php esc_html_e( 'The Initial Baseline is a one-time historical snapshot and cannot be edited after completion.', 'fixreport-maintenance-logger' ); ?></p>
			<?php if ( 'building' === $baseline_state ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Initial Baseline creation is currently incomplete. Please allow the current attempt to finish before trying again.', 'fixreport-maintenance-logger' ); ?></p></div>
			<?php else : ?>
				<?php if ( 'failed' === $baseline_state || 'stale' === $baseline_state ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php esc_html_e( 'Baseline creation could not be completed. No incomplete attempt has been accepted as historical evidence.', 'fixreport-maintenance-logger' ); ?>
						<?php if ( 'collection_failed' === $failure_code ) : ?> <?php esc_html_e( 'Site information could not be collected.', 'fixreport-maintenance-logger' ); ?><?php endif; ?>
						<?php if ( 'validation_failed' === $failure_code ) : ?> <?php esc_html_e( 'The collected information did not pass validation.', 'fixreport-maintenance-logger' ); ?><?php endif; ?>
						<?php if ( 'persistence_failed' === $failure_code ) : ?> <?php esc_html_e( 'The baseline could not be saved.', 'fixreport-maintenance-logger' ); ?><?php endif; ?>
					</p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fixreport_create_initial_baseline', 'fixreport_baseline_nonce' ); ?>
					<input type="hidden" name="action" value="fixreport_create_initial_baseline">
					<?php submit_button( 'failed' === $baseline_state || 'stale' === $baseline_state ? __( 'Try Again', 'fixreport-maintenance-logger' ) : __( 'Create Initial Baseline', 'fixreport-maintenance-logger' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</section>
	<?php else : ?>
		<?php if ( ! $baseline_data['is_initial'] ) : ?><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-site-snapshots' ) ); ?>"><?php esc_html_e( '← Snapshot History', 'fixreport-maintenance-logger' ); ?></a></p><?php endif; ?>
		<?php if ( ! empty( $compare_url ) ) : ?><p><a class="button" href="<?php echo esc_url( $compare_url ); ?>"><?php esc_html_e( 'Compare with Previous Snapshot', 'fixreport-maintenance-logger' ); ?></a></p><?php endif; ?>
		<header class="fixreport-baseline-header">
			<h2><?php echo esc_html( $baseline_data['is_initial'] ? __( 'Initial Site Baseline', 'fixreport-maintenance-logger' ) : $baseline_data['display_label'] ); ?></h2>
			<p><?php echo esc_html( $baseline_data['is_initial'] ? __( 'A historical snapshot of the website’s technical state when FixReport began monitoring it.', 'fixreport-maintenance-logger' ) : __( 'A historical snapshot of the website’s technical state at capture.', 'fixreport-maintenance-logger' ) ); ?></p>
			<p class="fixreport-baseline-meta"><strong><?php esc_html_e( 'Status:', 'fixreport-maintenance-logger' ); ?></strong> <?php esc_html_e( 'Complete', 'fixreport-maintenance-logger' ); ?> <span aria-hidden="true">·</span> <strong><?php esc_html_e( 'Captured:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( $baseline_data['captured'] ); ?> <span aria-hidden="true">·</span> <?php echo esc_html( sprintf( /* translators: %d: snapshot ID. */ __( 'Snapshot #%d', 'fixreport-maintenance-logger' ), $baseline_data['id'] ) ); ?></p>
		</header>
		<div class="notice notice-info inline"><p><?php echo esc_html( $baseline_data['is_initial'] ? __( 'This baseline is historical and read-only. It will not change when the website changes.', 'fixreport-maintenance-logger' ) : __( 'This snapshot is historical and read-only. It will not change when the website changes.', 'fixreport-maintenance-logger' ) ); ?></p></div>
		<?php if ( $baseline_data['integrity_warning'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Some stored snapshot details could not be interpreted safely and are shown as Unknown. The historical snapshot has not been changed.', 'fixreport-maintenance-logger' ); ?></p></div>
		<?php endif; ?>

		<section aria-labelledby="fixreport-baseline-overview">
			<h2 id="fixreport-baseline-overview"><?php esc_html_e( 'Overview', 'fixreport-maintenance-logger' ); ?></h2>
			<div class="fixreport-baseline-overview">
				<?php foreach ( $baseline_data['overview'] as $card ) : ?>
					<div class="fixreport-baseline-card"><h3><?php echo esc_html( $card['label'] ); ?></h3><p class="fixreport-baseline-card-value"><?php echo esc_html( $card['value'] ); ?></p><p><?php echo esc_html( $card['detail'] ); ?></p></div>
				<?php endforeach; ?>
			</div>
		</section>

		<section aria-labelledby="fixreport-baseline-site">
			<h2 id="fixreport-baseline-site"><?php esc_html_e( 'Site Information', 'fixreport-maintenance-logger' ); ?></h2>
			<table class="widefat striped fixreport-baseline-facts"><tbody>
				<?php foreach ( $baseline_data['site'] as $fact ) : ?>
					<tr><th scope="row"><?php echo esc_html( $fact['label'] ); ?></th><td><?php if ( ! empty( $fact['url'] ) ) : ?><a href="<?php echo esc_url( $fact['url'] ); ?>" rel="noopener noreferrer"><?php echo esc_html( $fact['value'] ); ?></a><?php else : ?><?php echo esc_html( $fact['value'] ); ?><?php endif; ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</section>

		<section aria-labelledby="fixreport-baseline-environment">
			<h2 id="fixreport-baseline-environment"><?php esc_html_e( 'WordPress & Environment', 'fixreport-maintenance-logger' ); ?></h2>
			<table class="widefat striped fixreport-baseline-facts"><tbody>
				<?php foreach ( $baseline_data['environment'] as $fact ) : ?>
					<tr><th scope="row"><?php echo esc_html( $fact['label'] ); ?></th><td><?php echo esc_html( $fact['value'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</section>

		<section aria-labelledby="fixreport-baseline-updates">
			<h2 id="fixreport-baseline-updates"><?php echo esc_html( $baseline_data['is_initial'] ? __( 'Updates at Baseline', 'fixreport-maintenance-logger' ) : __( 'Updates at Capture', 'fixreport-maintenance-logger' ) ); ?></h2>
			<table class="widefat striped fixreport-baseline-facts"><tbody>
				<?php foreach ( $baseline_data['updates'] as $fact ) : ?>
					<tr><th scope="row"><?php echo esc_html( $fact['label'] ); ?></th><td><?php echo esc_html( $fact['value'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php echo esc_html( $baseline_data['is_initial'] ? __( 'Update information reflects the cached WordPress update state available when this baseline was captured. FixReport did not force a remote update check.', 'fixreport-maintenance-logger' ) : __( 'Update information reflects the cached WordPress update state available when this snapshot was captured. FixReport did not force a remote update check.', 'fixreport-maintenance-logger' ) ); ?></p>
		</section>

		<section aria-labelledby="fixreport-baseline-content">
			<h2 id="fixreport-baseline-content"><?php esc_html_e( 'Content', 'fixreport-maintenance-logger' ); ?></h2>
			<table class="widefat striped fixreport-baseline-facts"><tbody>
				<?php foreach ( $baseline_data['content'] as $fact ) : ?>
					<tr><th scope="row"><?php echo esc_html( $fact['label'] ); ?></th><td><?php echo esc_html( $fact['value'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Posts and Pages are published counts. Comments include approved and pending only; Users are the captured site total.', 'fixreport-maintenance-logger' ); ?></p>
		</section>

		<?php if ( $baseline_data['content_types'] ) : ?>
			<section aria-labelledby="fixreport-baseline-custom-content"><h2 id="fixreport-baseline-custom-content"><?php esc_html_e( 'Custom Content', 'fixreport-maintenance-logger' ); ?></h2>
				<div class="fixreport-baseline-table-scroll"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Content Type', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Published', 'fixreport-maintenance-logger' ); ?></th></tr></thead><tbody>
				<?php foreach ( $baseline_data['content_types'] as $type ) : ?><tr><td><?php echo esc_html( $type['name'] ); ?></td><td><?php echo esc_html( $type['count'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
		<?php endif; ?>

		<section aria-labelledby="fixreport-baseline-plugins"><h2 id="fixreport-baseline-plugins"><?php esc_html_e( 'Plugin Inventory', 'fixreport-maintenance-logger' ); ?></h2>
			<div class="fixreport-baseline-table-scroll"><table class="widefat striped frb-inventory-table"><thead><tr><th scope="col"><?php esc_html_e( 'Plugin', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Version', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Update', 'fixreport-maintenance-logger' ); ?></th></tr></thead><tbody>
			<?php foreach ( $baseline_data['plugins'] as $plugin ) : ?><tr><td data-label="<?php esc_attr_e( 'Plugin', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $plugin['name'] ); ?></td><td data-label="<?php esc_attr_e( 'Version', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $plugin['version'] ); ?></td><td data-label="<?php esc_attr_e( 'Status', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $plugin['status'] ); ?></td><td data-label="<?php esc_attr_e( 'Update', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $plugin['update'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		</section>

		<section aria-labelledby="fixreport-baseline-themes"><h2 id="fixreport-baseline-themes"><?php esc_html_e( 'Theme Inventory', 'fixreport-maintenance-logger' ); ?></h2>
			<div class="fixreport-baseline-table-scroll"><table class="widefat striped frb-inventory-table"><thead><tr><th scope="col"><?php esc_html_e( 'Theme', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Version', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Update', 'fixreport-maintenance-logger' ); ?></th></tr></thead><tbody>
			<?php foreach ( $baseline_data['themes'] as $theme ) : ?><tr><td data-label="<?php esc_attr_e( 'Theme', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $theme['name'] ); ?><?php if ( null !== $theme['parent'] ) : ?><span class="fixreport-baseline-parent"><?php echo esc_html( sprintf( /* translators: %s: parent theme stylesheet slug. */ __( 'Child of %s', 'fixreport-maintenance-logger' ), $theme['parent'] ) ); ?></span><?php endif; ?></td><td data-label="<?php esc_attr_e( 'Version', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $theme['version'] ); ?></td><td data-label="<?php esc_attr_e( 'Status', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $theme['status'] ); ?></td><td data-label="<?php esc_attr_e( 'Update', 'fixreport-maintenance-logger' ); ?>"><?php echo esc_html( $theme['update'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		</section>

		<section aria-labelledby="fixreport-baseline-health">
			<h2 id="fixreport-baseline-health"><?php echo esc_html( $baseline_data['is_initial'] ? __( 'FixReport Baseline Health Checks', 'fixreport-maintenance-logger' ) : __( 'FixReport Snapshot Health Checks', 'fixreport-maintenance-logger' ) ); ?></h2>
			<p><?php esc_html_e( 'These are bounded local maintenance checks, not the complete WordPress Site Health suite or an overall score.', 'fixreport-maintenance-logger' ); ?></p>
			<p><strong><?php esc_html_e( 'Critical findings:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( $baseline_data['health_critical'] ); ?> <span aria-hidden="true">·</span> <strong><?php esc_html_e( 'Recommended findings:', 'fixreport-maintenance-logger' ); ?></strong> <?php echo esc_html( $baseline_data['health_recommended'] ); ?></p>
			<?php if ( 'complete' !== $baseline_data['health_coverage'] ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $baseline_data['is_initial'] ? __( 'Baseline health check coverage is incomplete because some update information was unavailable when this snapshot was captured.', 'fixreport-maintenance-logger' ) : __( 'Snapshot health check coverage is incomplete because some update information was unavailable when this snapshot was captured.', 'fixreport-maintenance-logger' ) ); ?></p></div>
			<?php elseif ( $baseline_data['health_empty'] ) : ?>
				<p><?php echo esc_html( $baseline_data['is_initial'] ? __( 'No maintenance findings were triggered within the checks performed for this baseline.', 'fixreport-maintenance-logger' ) : __( 'No maintenance findings were triggered within the checks performed for this snapshot.', 'fixreport-maintenance-logger' ) ); ?></p>
			<?php endif; ?>
			<?php if ( $baseline_data['health_items'] ) : ?>
				<div class="fixreport-baseline-table-scroll"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Finding', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Severity', 'fixreport-maintenance-logger' ); ?></th><th scope="col"><?php esc_html_e( 'Evidence', 'fixreport-maintenance-logger' ); ?></th></tr></thead><tbody>
				<?php foreach ( $baseline_data['health_items'] as $finding ) : ?><tr><td><?php echo esc_html( $finding['name'] ); ?></td><td><?php echo esc_html( 'critical' === $finding['severity'] ? __( 'Requires Attention', 'fixreport-maintenance-logger' ) : ( 'recommended' === $finding['severity'] ? __( 'Recommended', 'fixreport-maintenance-logger' ) : __( 'Unknown', 'fixreport-maintenance-logger' ) ) ); ?></td><td><?php echo esc_html( $finding['evidence'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
		</section>

		<details class="fixreport-baseline-technical"><summary><?php esc_html_e( 'Technical Details', 'fixreport-maintenance-logger' ); ?></summary>
			<table class="widefat striped fixreport-baseline-facts"><tbody>
			<?php foreach ( $baseline_data['technical'] as $fact ) : ?><tr><th scope="row"><?php echo esc_html( $fact['label'] ); ?></th><td><?php echo esc_html( $fact['value'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		</details>
	<?php endif; ?>
</div>
