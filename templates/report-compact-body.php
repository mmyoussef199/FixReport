<?php
/**
 * Compact layout 9.2 body, shared by the PDF template and the admin HTML preview
 * so both show the same content in the same order. Expects $layout from
 * FixReport_Report_Layout::build(), $logo_src (validated logo URL or data URI,
 * or ''), and $logo_inline (true when $logo_src is a data URI).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$fr_text = static function ( $value ) { return nl2br( esc_html( (string) $value ) ); };
$fr_cell = static function ( $cell ) use ( $fr_text ) {
	$out = $cell['ltr'] ? '<span class="technical" dir="ltr">' . $fr_text( $cell['text'] ) . '</span>' : $fr_text( $cell['text'] );
	if ( $cell['strong'] ) { $out = '<strong>' . $out . '</strong>'; }
	foreach ( $cell['sub'] as $line ) { $out .= '<br><span class="sub">' . $fr_text( $line ) . '</span>'; }
	return $out;
};
$fr_brand = $layout['brand'];
?>
<?php if ( $fr_brand ) : ?>
	<table class="masthead"><tr>
		<td class="masthead-brand">
			<?php if ( $logo_src ) : ?><img class="brand-logo" src="<?php echo $logo_inline ? esc_attr( $logo_src ) : esc_url( $logo_src ); ?>" alt=""><br><?php endif; ?>
			<?php if ( $fr_brand['company_name'] ) : ?><strong class="company"><?php echo esc_html( $fr_brand['company_name'] ); ?></strong><?php endif; ?>
		</td>
		<td class="masthead-contact">
			<?php foreach ( array( 'website', 'email', 'phone', 'address' ) as $field ) : if ( empty( $fr_brand[ $field ] ) ) { continue; } ?><span class="meta"<?php echo 'address' === $field ? '' : ' dir="ltr"'; ?>><?php echo $fr_text( $fr_brand[ $field ] ); ?></span><br><?php endforeach; ?>
		</td>
	</tr></table>
<?php endif; ?>
<h1><?php echo esc_html( $layout['title'] ); ?></h1>
<div class="banner"><?php echo esc_html( $layout['banner'] ); ?></div>
<table class="facts-head"><tbody>
	<?php foreach ( $layout['facts'] as $fact ) : ?><tr><th><?php echo esc_html( $fact[0] ); ?></th><td><?php echo $fr_cell( $fact[1] ); ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php foreach ( $layout['sections'] as $section ) : ?>
	<h2><?php echo esc_html( $section['title'] ); ?></h2>
	<?php foreach ( $section['blocks'] as $block ) : ?>
		<?php if ( 'text' === $block['type'] ) : ?><p><?php echo $fr_text( $block['text'] ); ?></p>
		<?php elseif ( 'note' === $block['type'] ) : ?><p class="meta"><?php echo $fr_text( $block['text'] ); ?></p>
		<?php elseif ( 'warning' === $block['type'] ) : ?><p class="warning"><?php echo $fr_text( $block['text'] ); ?></p>
		<?php elseif ( 'heading' === $block['type'] ) : ?><h3><?php echo esc_html( $block['text'] ); ?></h3>
		<?php elseif ( 'table' === $block['type'] ) : $fr_span = max( 2, count( $block['head'] ) ); ?>
			<table class="grid <?php echo esc_attr( $block['class'] ); ?>">
				<?php if ( $block['head'] ) : ?><thead><tr><?php foreach ( $block['head'] as $head ) : ?><th><?php echo esc_html( $head ); ?></th><?php endforeach; ?></tr></thead><?php endif; ?>
				<tbody>
				<?php foreach ( $block['rows'] as $row ) : ?>
					<?php if ( isset( $row['group'] ) ) : ?><tr class="group"><td colspan="<?php echo (int) $fr_span; ?>"><?php echo esc_html( $row['group'] ); ?></td></tr>
					<?php else : ?><tr<?php echo ! empty( $row['mark'] ) ? ' class="mark"' : ''; ?>><?php foreach ( $row['cells'] as $cell ) : ?><td><?php echo $fr_cell( $cell ); ?></td><?php endforeach; ?></tr><?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endforeach; ?>
<?php endforeach; ?>
<?php if ( $layout['footer'] ) : ?><div class="brand-footer"><?php echo $fr_text( $layout['footer'] ); ?></div><?php endif; ?>
