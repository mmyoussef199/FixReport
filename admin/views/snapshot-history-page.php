<?php
/** Functional, read-only history plus the authorized manual-capture form. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap fixreport-baseline">
	<h1><?php esc_html_e( 'Site Snapshots', 'fixreport-maintenance-logger' ); ?></h1>
	<p><?php esc_html_e( 'Each completed snapshot is preserved as historical site evidence. Creating a new snapshot does not change an earlier one.', 'fixreport-maintenance-logger' ); ?></p>
	<h2><?php esc_html_e( 'Automatic Checkpoints', 'fixreport-maintenance-logger' ); ?></h2>
	<?php if ( is_wp_error( $latest_checkpoint ) ) : ?>
		<p><?php esc_html_e( 'Checkpoint status could not be read.', 'fixreport-maintenance-logger' ); ?></p>
	<?php elseif ( $latest_checkpoint ) : ?>
		<p><?php
			/* translators: 1: UTC capture time; 2: snapshot ID. */
			echo esc_html( sprintf( __( 'Latest captured state: %1$s UTC (checkpoint #%2$d).', 'fixreport-maintenance-logger' ), $latest_checkpoint->completed_at, (int) $latest_checkpoint->snapshot_id ) );
		?> <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fixreport-site-snapshots', 'action' => 'view', 'snapshot_id' => (int) $latest_checkpoint->snapshot_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View checkpoint', 'fixreport-maintenance-logger' ); ?></a></p>
	<?php else : ?>
		<p><?php esc_html_e( 'No automatic checkpoint has been captured yet.', 'fixreport-maintenance-logger' ); ?></p>
	<?php endif; ?>
	<?php if ( is_array( $checkpoint_state ) && ( ! empty( $checkpoint_state['pending'] ) || ! empty( $checkpoint_state['inflight'] ) ) ) : ?>
		<p><?php esc_html_e( 'Checkpoint work is pending. Its eventual capture time may differ from its scheduled time.', 'fixreport-maintenance-logger' ); ?></p>
	<?php endif; ?>
	<?php if ( 'error' === $history_state ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Snapshot History could not be read safely. No stored snapshot was changed.', 'fixreport-maintenance-logger' ); ?></p></div>
	<?php else : ?>
		<?php if ( $initial_exists ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fixreport_create_manual_snapshot', 'fixreport_snapshot_nonce' ); ?>
				<input type="hidden" name="action" value="fixreport_create_manual_snapshot">
				<?php submit_button( __( 'Create New Snapshot', 'fixreport-maintenance-logger' ), 'primary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'Create the Initial Baseline before capturing manual snapshots.', 'fixreport-maintenance-logger' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-site-baseline' ) ); ?>"><?php esc_html_e( 'Open Site Baseline', 'fixreport-maintenance-logger' ); ?></a></p>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Snapshot History', 'fixreport-maintenance-logger' ); ?></h2>
		<?php if ( ! $history_rows ) : ?>
			<p><?php esc_html_e( 'No completed snapshots are available.', 'fixreport-maintenance-logger' ); ?></p>
		<?php else : ?>
			<table class="widefat striped"><thead><tr>
				<th scope="col"><?php esc_html_e( 'Snapshot', 'fixreport-maintenance-logger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Site', 'fixreport-maintenance-logger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'WordPress', 'fixreport-maintenance-logger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Plugins', 'fixreport-maintenance-logger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Themes', 'fixreport-maintenance-logger' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'fixreport-maintenance-logger' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $history_rows as $snapshot ) :
				$view_url = 'initial' === $snapshot->snapshot_type ? admin_url( 'admin.php?page=fixreport-site-baseline' ) : add_query_arg( array( 'page' => 'fixreport-site-snapshots', 'action' => 'view', 'snapshot_id' => (int) $snapshot->snapshot_id ), admin_url( 'admin.php' ) );
			?>
				<tr>
					<td><?php echo esc_html( FixReport_Baseline_Presenter::display_label( $snapshot ) ); ?></td>
					<td><?php echo esc_html( $snapshot->site_name ); ?></td>
					<td><?php echo esc_html( $snapshot->wordpress_version ); ?></td>
					<td><?php echo esc_html( $snapshot->plugin_count ); ?></td>
					<td><?php echo esc_html( $snapshot->theme_count ); ?></td>
					<td><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'fixreport-maintenance-logger' ); ?></a><?php if ( 'manual' === $snapshot->snapshot_type ) : ?> · <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fixreport-site-snapshots', 'action' => 'compare', 'to_id' => (int) $snapshot->snapshot_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Compare', 'fixreport-maintenance-logger' ); ?></a><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php if ( $history_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'fixreport-site-snapshots', 'paged' => '%#%' ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $history_page, 'total' => $history_pages, 'prev_text' => __( '‹ Previous', 'fixreport-maintenance-logger' ), 'next_text' => __( 'Next ›', 'fixreport-maintenance-logger' ) ) ) ); ?></div></div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
