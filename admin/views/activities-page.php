<?php
/** FixReport Activities list and manual add/edit routes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$category_labels = FixReport_Admin::category_labels();
$status_labels = FixReport_Admin::status_labels();
$priority_labels = FixReport_Admin::priority_labels();
$source_labels = FixReport_Admin::source_labels();
$is_edit = 'edit' === $mode && $activity;
$is_form = 'add' === $mode || $is_edit;
$selected_user = $is_edit ? (int) $activity->performed_by : get_current_user_id();
$activity_date = $is_edit ? $this->service->activity_date_input_value( $activity ) : current_datetime()->format( 'Y-m-d\TH:i' );
?>
<div class="wrap fixreport-activities">
	<?php if ( $is_form ) : ?>
		<p><a href="<?php echo esc_url( $current_page_url ); ?>"><?php esc_html_e( '← Back to Maintenance Activities', 'fixreport-maintenance-logger' ); ?></a></p>
		<h1><?php echo esc_html( $is_edit ? __( 'Edit Manual Activity', 'fixreport-maintenance-logger' ) : __( 'Add Manual Activity', 'fixreport-maintenance-logger' ) ); ?></h1>
		<div class="fixreport-activity-form-card"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php if ( $is_edit ) : ?><?php wp_nonce_field( 'fixreport_update_activity_action_' . $activity->activity_id, 'fixreport_activity_nonce' ); ?><input type="hidden" name="action" value="fixreport_update_activity"><input type="hidden" name="fixreport_activity_id" value="<?php echo esc_attr( $activity->activity_id ); ?>">
			<?php else : ?><?php wp_nonce_field( 'fixreport_add_activity_action', 'fixreport_activity_nonce' ); ?><input type="hidden" name="action" value="fixreport_add_activity"><?php endif; ?>
			<div class="fixreport-form-grid">
				<label class="fixreport-field-wide"><span><?php esc_html_e( 'Activity Title', 'fixreport-maintenance-logger' ); ?> *</span><input type="text" name="fixreport_title" maxlength="255" required value="<?php echo esc_attr( $is_edit ? $activity->title : '' ); ?>"></label>
				<label><span><?php esc_html_e( 'Date & Time (site timezone)', 'fixreport-maintenance-logger' ); ?> *</span><input type="datetime-local" name="fixreport_activity_date" required value="<?php echo esc_attr( $activity_date ); ?>"></label>
				<label><span><?php esc_html_e( 'Category', 'fixreport-maintenance-logger' ); ?> *</span><select name="fixreport_category" required><?php foreach ( $category_labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $is_edit ? $activity->category : 'other', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label class="fixreport-field-wide"><span><?php esc_html_e( 'Description / Work Performed', 'fixreport-maintenance-logger' ); ?> *</span><textarea name="fixreport_description" rows="5" required><?php echo esc_textarea( $is_edit ? $activity->description : '' ); ?></textarea></label>
				<?php if ( $is_edit ) : ?><label><span><?php esc_html_e( 'Performed By', 'fixreport-maintenance-logger' ); ?></span><select name="fixreport_performed_by" required><?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $selected_user, $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option><?php endforeach; ?></select></label><?php else : ?><div><span><?php esc_html_e( 'Documented By', 'fixreport-maintenance-logger' ); ?></span><strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong></div><?php endif; ?>
				<label><span><?php esc_html_e( 'Status', 'fixreport-maintenance-logger' ); ?> *</span><select name="fixreport_status" required><?php foreach ( $status_labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $is_edit ? $activity->status : 'completed', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><span><?php esc_html_e( 'Priority', 'fixreport-maintenance-logger' ); ?> *</span><select name="fixreport_priority" required><?php foreach ( $priority_labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $is_edit ? $activity->priority : 'medium', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label class="fixreport-visibility"><input type="checkbox" name="fixreport_client_visible" value="1" <?php checked( $is_edit ? (int) $activity->client_visible : 1, 1 ); ?>> <?php esc_html_e( 'Eligible for Client Reports', 'fixreport-maintenance-logger' ); ?></label>
				<p class="fixreport-field-wide description"><?php esc_html_e( 'Report eligibility does not force inclusion in any particular report.', 'fixreport-maintenance-logger' ); ?></p>
				<label class="fixreport-field-wide"><span><?php esc_html_e( 'Internal Note (not client-facing)', 'fixreport-maintenance-logger' ); ?></span><textarea name="fixreport_internal_note" rows="3"><?php echo esc_textarea( $is_edit ? $activity->internal_note : '' ); ?></textarea></label>
			</div>
			<p class="fixreport-form-actions"><button class="button button-primary" type="submit"><?php echo esc_html( $is_edit ? __( 'Update Activity', 'fixreport-maintenance-logger' ) : __( 'Save Manual Activity', 'fixreport-maintenance-logger' ) ); ?></button> <a class="button" href="<?php echo esc_url( $current_page_url ); ?>"><?php esc_html_e( 'Cancel', 'fixreport-maintenance-logger' ); ?></a></p>
		</form></div>
	<?php else : ?>
		<p class="fixreport-activity-back"><a href="<?php echo esc_url( $current_page_url ); ?>"><?php esc_html_e( '← FixReport home', 'fixreport-maintenance-logger' ); ?></a></p>
		<div class="fixreport-page-header"><h1><?php esc_html_e( 'Maintenance Activities', 'fixreport-maintenance-logger' ); ?></h1><button type="button" class="button button-primary fixreport-open-add"><?php esc_html_e( '+ Add Manual Activity', 'fixreport-maintenance-logger' ); ?></button></div><hr class="wp-header-end">
		<?php require __DIR__ . '/activities-workspace.php'; ?>
	<?php endif; ?>
</div>
