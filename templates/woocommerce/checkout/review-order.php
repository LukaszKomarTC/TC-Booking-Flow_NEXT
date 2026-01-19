<?php
/**
 * Review order table
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/review-order.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 5.2.0
 *
 * TCBF Customization:
 * - Groups items by tc_group_id using tbody elements
 * - Adds pack totals footer for groups with EB discount
 * - Visual separation between packs
 * - Maintains all WooCommerce hooks
 */

defined( 'ABSPATH' ) || exit;

// Check if TCBF is available
$tcbf_enabled = class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' );
?>

<table class="shop_table woocommerce-checkout-review-order-table tcbf-checkout-table">
	<thead>
		<tr>
			<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
			<th class="product-total"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
		</tr>
	</thead>

	<?php
	do_action( 'woocommerce_review_order_before_cart_contents' );

	/**
	 * TCBF: Group cart items by pack for visual grouping.
	 */
	$tcbf_groups = [];
	$tcbf_ungrouped = [];

	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		$group_id = isset( $cart_item['tc_group_id'] ) ? (int) $cart_item['tc_group_id'] : 0;

		if ( $tcbf_enabled && $group_id > 0 ) {
			if ( ! isset( $tcbf_groups[ $group_id ] ) ) {
				$tcbf_groups[ $group_id ] = [];
			}
			$tcbf_groups[ $group_id ][ $cart_item_key ] = $cart_item;
		} else {
			$tcbf_ungrouped[ $cart_item_key ] = $cart_item;
		}
	}

	// Render grouped items (each group in its own tbody)
	foreach ( $tcbf_groups as $group_id => $group_items ) :
		$group_items_array = array_values( $group_items );
		$pack_totals = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::calculate_cart_pack_totals( $group_items_array );
		?>
		<tbody class="tcbf-pack-group" data-tcbf-group="<?php echo esc_attr( $group_id ); ?>">
			<?php
			foreach ( $group_items as $cart_item_key => $cart_item ) :
				$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

				if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
					// Determine if parent or child
					$is_parent = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::is_cart_item_parent( $cart_item );
					$row_class = $is_parent ? 'tcbf-checkout-row--parent' : 'tcbf-checkout-row--child';

					// Get event info for linking
					$event_id = isset( $cart_item['_event_id'] ) ? (int) $cart_item['_event_id'] : 0;
					$event_url = $event_id > 0 ? get_permalink( $event_id ) : '';
					$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?> <?php echo esc_attr( $row_class ); ?>">
						<td class="product-name">
							<?php
							// Link to event page if available
							if ( $event_url && $is_parent ) {
								echo '<a href="' . esc_url( $event_url ) . '" class="tcbf-checkout-link">' . wp_kses_post( $product_name ) . '</a>';
							} else {
								echo wp_kses_post( $product_name );
							}
							echo '&nbsp;<strong class="product-quantity">&times;&nbsp;' . esc_html( $cart_item['quantity'] ) . '</strong>';

							do_action( 'woocommerce_checkout_cart_item_product_name', $cart_item, $cart_item_key );

							echo wc_get_formatted_cart_item_data( $cart_item );
							?>
						</td>
						<td class="product-total">
							<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); ?>
						</td>
					</tr>
					<?php
				}
			endforeach;

			// Pack footer row (only if has EB)
			if ( $pack_totals['has_eb'] ) :
				?>
				<tr class="tcbf-pack-footer-row">
					<td colspan="2" class="tcbf-pack-footer-cell">
						<div class="tcbf-pack-footer tcbf-pack-footer--checkout">
							<div class="tcbf-pack-footer-line tcbf-pack-footer-base">
								<span class="tcbf-pack-footer-label"><?php echo esc_html( $pack_totals['base_label'] ); ?></span>
								<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $pack_totals['base_price'] ) ); ?></span>
							</div>
							<?php if ( $pack_totals['eb_discount'] > 0 ) : ?>
							<div class="tcbf-pack-footer-line tcbf-pack-footer-eb">
								<span class="tcbf-pack-footer-label"><?php esc_html_e( 'Early booking discount', 'tc-booking-flow-next' ); ?></span>
								<span class="tcbf-pack-footer-value tcbf-pack-footer-discount">-<?php echo wp_kses_post( wc_price( $pack_totals['eb_discount'] ) ); ?></span>
							</div>
							<?php endif; ?>
							<div class="tcbf-pack-footer-line tcbf-pack-footer-total">
								<span class="tcbf-pack-footer-label"><?php esc_html_e( 'Pack total', 'tc-booking-flow-next' ); ?></span>
								<span class="tcbf-pack-footer-value"><?php echo wp_kses_post( wc_price( $pack_totals['pack_total'] ) ); ?></span>
							</div>
						</div>
					</td>
				</tr>
				<?php
			endif;
			?>
		</tbody>
		<?php
	endforeach;

	// Render ungrouped items in a single tbody
	if ( ! empty( $tcbf_ungrouped ) ) :
		?>
		<tbody class="tcbf-ungrouped">
			<?php
			foreach ( $tcbf_ungrouped as $cart_item_key => $cart_item ) :
				$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

				if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
						<td class="product-name">
							<?php
							echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) );
							echo '&nbsp;<strong class="product-quantity">&times;&nbsp;' . esc_html( $cart_item['quantity'] ) . '</strong>';

							do_action( 'woocommerce_checkout_cart_item_product_name', $cart_item, $cart_item_key );

							echo wc_get_formatted_cart_item_data( $cart_item );
							?>
						</td>
						<td class="product-total">
							<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); ?>
						</td>
					</tr>
					<?php
				}
			endforeach;
			?>
		</tbody>
		<?php
	endif;

	do_action( 'woocommerce_review_order_after_cart_contents' );
	?>

	<tfoot>

		<tr class="cart-subtotal">
			<th><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

			<?php wc_cart_totals_shipping_html(); ?>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee">
				<th><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th><?php echo esc_html( $tax->label ); ?></th>
						<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total">
					<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total">
			<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

	</tfoot>
</table>

<style>
/* TCBF Checkout Pack Styling */
.tcbf-checkout-table {
	border-collapse: separate;
	border-spacing: 0;
}
.tcbf-checkout-table tbody.tcbf-pack-group {
	display: table-row-group;
}
.tcbf-checkout-table tbody.tcbf-pack-group + tbody.tcbf-pack-group {
	border-top: 12px solid transparent;
}
.tcbf-checkout-table tbody.tcbf-pack-group + tbody.tcbf-pack-group > tr:first-child td,
.tcbf-checkout-table tbody.tcbf-pack-group + tbody.tcbf-pack-group > tr:first-child th {
	padding-top: 12px;
}
.tcbf-checkout-row--parent {
	border-left: 3px solid var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) !important;
}
.tcbf-checkout-row--child {
	border-left: 3px solid color-mix(in srgb, var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) 50%, transparent) !important;
}
.tcbf-checkout-row--parent td,
.tcbf-checkout-row--child td {
	padding-left: 12px !important;
}
.tcbf-pack-footer-row td {
	padding: 0 !important;
	border: none !important;
}
.tcbf-pack-footer-cell {
	padding: 8px 0 16px 3px !important;
}
.tcbf-pack-footer--checkout {
	background: rgba(0, 0, 0, 0.02);
	border: 1px solid rgba(0, 0, 0, 0.06);
	border-radius: 6px;
	padding: 10px 14px;
	margin-left: 0;
}
.tcbf-pack-footer-line {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 3px 0;
	font-size: 12px;
}
.tcbf-pack-footer-label {
	color: #6b7280;
}
.tcbf-pack-footer-value {
	font-weight: 600;
	color: #374151;
}
.tcbf-pack-footer-discount {
	color: #059669;
}
.tcbf-pack-footer-total {
	border-top: 1px solid rgba(0, 0, 0, 0.08);
	margin-top: 4px;
	padding-top: 6px;
}
.tcbf-pack-footer-total .tcbf-pack-footer-label {
	font-weight: 600;
	color: #374151;
}
.tcbf-pack-footer-total .tcbf-pack-footer-value {
	font-weight: 700;
	color: #111827;
	font-size: 13px;
}
.tcbf-checkout-link {
	color: var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00)));
}
</style>
