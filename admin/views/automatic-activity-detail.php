<?php
/** Read-only, bounded presentation of one automatic Activity. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$source_labels = FixReport_Admin::source_labels();
$category_labels = FixReport_Admin::category_labels();
$priority_labels = FixReport_Admin::priority_labels();
$status_labels = FixReport_Admin::status_labels();
$actor = $activity->performed_by_name ? $activity->performed_by_name : ( null === $activity->performed_by ? __( 'System', 'fixreport-maintenance-logger' ) : sprintf( /* translators: %d: unavailable user ID. */ __( 'Unavailable user (ID %d)', 'fixreport-maintenance-logger' ), $activity->performed_by ) );
$metadata = is_string( $activity->metadata ) ? json_decode( $activity->metadata, true ) : array();
$metadata = is_array( $metadata ) ? $metadata : array();
$metadata_labels = array(
	'mechanism' => __( 'Mechanism', 'fixreport-maintenance-logger' ),
	'bulk' => __( 'Bulk Operation', 'fixreport-maintenance-logger' ),
	'network_wide' => __( 'Network-wide', 'fixreport-maintenance-logger' ),
	'observed_version' => __( 'Observed Version', 'fixreport-maintenance-logger' ),
	'previous_stylesheet' => __( 'Previous Theme', 'fixreport-maintenance-logger' ),
	'previous_template' => __( 'Previous Parent', 'fixreport-maintenance-logger' ),
	'new_template' => __( 'New Parent', 'fixreport-maintenance-logger' ),
	'package_action' => __( 'Package Action', 'fixreport-maintenance-logger' ),
	'identity_changed' => __( 'Identity Changed', 'fixreport-maintenance-logger' ),
	'replacement_identity' => __( 'Replacement Identity', 'fixreport-maintenance-logger' ),
);
$facts = array(
	__( 'Source', 'fixreport-maintenance-logger' ) => $source_labels[ $activity->source ] ?? $activity->source,
	__( 'Event', 'fixreport-maintenance-logger' ) => ucwords( str_replace( '_', ' ', $activity->event_type ) ),
	__( 'Category', 'fixreport-maintenance-logger' ) => $category_labels[ $activity->category ] ?? $activity->category,
	__( 'Status', 'fixreport-maintenance-logger' ) => $status_labels[ $activity->status ] ?? $activity->status,
	__( 'Priority', 'fixreport-maintenance-logger' ) => $priority_labels[ $activity->priority ] ?? $activity->priority,
	__( 'Client Visible', 'fixreport-maintenance-logger' ) => $activity->client_visible ? __( 'Yes', 'fixreport-maintenance-logger' ) : __( 'No', 'fixreport-maintenance-logger' ),
	__( 'Object', 'fixreport-maintenance-logger' ) => $activity->object_name . ' (' . $activity->object_key . ')',
	__( 'Previous Version', 'fixreport-maintenance-logger' ) => $activity->previous_version ?: __( 'Not applicable', 'fixreport-maintenance-logger' ),
	__( 'New Version', 'fixreport-maintenance-logger' ) => $activity->new_version ?: __( 'Not applicable', 'fixreport-maintenance-logger' ),
	__( 'Performed By', 'fixreport-maintenance-logger' ) => $actor,
	__( 'Activity Date', 'fixreport-maintenance-logger' ) => $this->service->format_activity_date( $activity ),
);
foreach ( $metadata_labels as $key => $label ) {
	if ( ! array_key_exists( $key, $metadata ) ) {
		continue;
	}
	$value = $metadata[ $key ];
	if ( is_bool( $value ) ) {
		$value = $value ? __( 'Yes', 'fixreport-maintenance-logger' ) : __( 'No', 'fixreport-maintenance-logger' );
	} elseif ( ! is_string( $value ) || strlen( $value ) > 191 ) {
		continue;
	}
	$facts[ $label ] = $value;
}
?>
<div class="wrap">
	<h1><?php echo esc_html( $activity->title ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fixreport-maintenance-logger' ) ); ?>"><?php esc_html_e( '← Back to Activities', 'fixreport-maintenance-logger' ); ?></a></p>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'This automatic Activity is captured evidence and is read-only.', 'fixreport-maintenance-logger' ); ?></p></div>
	<p><?php echo esc_html( $activity->description ); ?></p>
	<table class="widefat striped" style="max-width:850px"><tbody>
		<?php foreach ( $facts as $label => $value ) : ?><tr><th scope="row" style="width:35%"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr><?php endforeach; ?>
	</tbody></table>
</div>
