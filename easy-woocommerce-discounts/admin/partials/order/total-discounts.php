<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<tr>
    <td class="label"><?php echo esc_html( $view_args['label'] ); ?>:</td>
    <td width="1%"></td>
    <td class="total">
        <?php echo $view_args['value']; // WPCS: XSS ok. ?>
    </td>
</tr>
