<?php
/**
 * Cart Page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.9.0
 *
 * TCBF Customization:
 * - Groups items by tc_group_id (pack grouping)
 * - Adds pack totals footer for groups with EB discount
 * - Uses event images for tour items
 * - Maintains all WooCommerce hooks
 */

defined( 'ABSPATH' ) || exit;

// Flag to prevent hook from also rendering pack footers (template handles it)
global $tcbf_cart_template_loaded;
$tcbf_cart_template_loaded = true;

// Debug marker to verify this template is being loaded
echo '<!-- TCBF Cart Template Loaded -->';

// Output pack styles once
if ( class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' ) ) {
	\TC_BF\Integrations\WooCommerce\Woo_OrderMeta::output_pack_styles_once();
}

do_action( 'woocommerce_before_cart' ); ?>

<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
	<?php do_action( 'woocommerce_before_cart_table' ); ?>

	<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents" cellspacing="0">
		<thead>
			<tr>
				<th class="product-remove"><span class="screen-reader-text"><?php esc_html_e( 'Remove item', 'woocommerce' ); ?></span></th>
				<th class="product-thumbnail"><span class="screen-reader-text"><?php esc_html_e( 'Thumbnail image', 'woocommerce' ); ?></span></th>
				<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
				<th class="product-price"><?php esc_html_e( 'Price', 'woocommerce' ); ?></th>
				<th class="product-quantity"><?php esc_html_e( 'Quantity', 'woocommerce' ); ?></th>
				<th class="product-subtotal"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php do_action( 'woocommerce_before_cart_contents' ); ?>

			<?php
			/**
			 * TCBF: Group cart items by pack for visual grouping.
			 */
			$tcbf_groups = [];
			$tcbf_ungrouped = [];
			$tcbf_enabled = class_exists( '\TC_BF\Integrations\WooCommerce\Woo_OrderMeta' );

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

			// Render grouped items first
			foreach ( $tcbf_groups as $group_id => $group_items ) :
				$group_items_array = array_values( $group_items );
				$pack_totals = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::calculate_cart_pack_totals( $group_items_array );
				$is_first_in_group = true;
				$group_count = count( $group_items );
				$current_idx = 0;
				?>
				<tr class="tcbf-pack-separator"><td colspan="6"></td></tr>
				<?php
				foreach ( $group_items as $cart_item_key => $cart_item ) :
					$current_idx++;
					$is_last_in_group = ( $current_idx === $group_count );
					$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
					$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
					$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

					if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
						$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );

						// Determine if parent or child
						$is_parent = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::is_cart_item_parent( $cart_item );
						$row_class = $is_parent ? 'tcbf-cart-row--parent' : 'tcbf-cart-row--child';

						// Get event image for parent items
						$event_id = isset( $cart_item['_event_id'] ) ? (int) $cart_item['_event_id'] : 0;
						$event_url = $event_id > 0 ? get_permalink( $event_id ) : '';
						$custom_thumb_url = '';
						if ( $is_parent && $event_id > 0 ) {
							$custom_thumb_url = \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::get_event_image_url( $event_id );
						}
						?>
						<tr class="woocommerce-cart-form__cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?> tcbf-pack-item <?php echo esc_attr( $row_class ); ?>" data-tcbf-group="<?php echo esc_attr( $group_id ); ?>">

							<td class="product-remove">
								<?php
								echo apply_filters(
									'woocommerce_cart_item_remove_link',
									sprintf(
										'<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
										esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
										/* translators: %s is the product name */
										esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) ),
										esc_attr( $product_id ),
										esc_attr( $_product->get_sku() )
									),
									$cart_item_key
								);
								?>
							</td>

							<td class="product-thumbnail">
								<?php
								$thumbnail = '';
								if ( $custom_thumb_url ) {
									// Use event featured image
									$thumbnail = '<img src="' . esc_url( $custom_thumb_url ) . '" class="tcbf-event-thumb" alt="' . esc_attr( $product_name ) . '" />';
								} else {
									$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image(), $cart_item, $cart_item_key );
								}

								if ( ! $product_permalink ) {
									echo $thumbnail;
								} else {
									printf( '<a href="%s">%s</a>', esc_url( $event_url ?: $product_permalink ), $thumbnail );
								}
								?>
							</td>

							<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'woocommerce' ); ?>">
								<?php
								$title_url = $event_url ?: $product_permalink;
								if ( ! $title_url ) {
									echo wp_kses_post( $product_name );
								} else {
									echo wp_kses_post( sprintf( '<a href="%s" class="tcbf-product-link">%s</a>', esc_url( $title_url ), $product_name ) );
								}

								do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );

								// Meta data.
								echo wc_get_formatted_cart_item_data( $cart_item );

								// Backorder notification.
								if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
									echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $product_id ) );
								}
								?>
							</td>

							<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'woocommerce' ); ?>">
								<?php
								echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
								?>
							</td>

							<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
								<?php
								if ( $_product->is_sold_individually() ) {
									$min_quantity = 1;
									$max_quantity = 1;
								} else {
									$min_quantity = 0;
									$max_quantity = $_product->get_max_purchase_quantity();
								}

								$product_quantity = woocommerce_quantity_input(
									array(
										'input_name'   => "cart[{$cart_item_key}][qty]",
										'input_value'  => $cart_item['quantity'],
										'max_value'    => $max_quantity,
										'min_value'    => $min_quantity,
										'product_name' => $product_name,
									),
									$_product,
									false
								);

								echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
								?>
							</td>

							<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>">
								<?php
								echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
								?>
							</td>
						</tr>
						<?php
					}
				endforeach;

				// Pack footer row (only if has EB)
				if ( $pack_totals['has_eb'] ) :
					?>
					<tr class="tcbf-pack-footer-row" data-tcbf-group="<?php echo esc_attr( $group_id ); ?>">
						<td colspan="6" class="tcbf-pack-footer-cell">
							<div class="tcbf-pack-footer tcbf-pack-footer--cart">
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
			endforeach;

			// Render ungrouped items
			foreach ( $tcbf_ungrouped as $cart_item_key => $cart_item ) :
				$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
				$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
				$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

				if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
					$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
					?>
					<tr class="woocommerce-cart-form__cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">

						<td class="product-remove">
							<?php
							echo apply_filters(
								'woocommerce_cart_item_remove_link',
								sprintf(
									'<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
									esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
									/* translators: %s is the product name */
									esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) ),
									esc_attr( $product_id ),
									esc_attr( $_product->get_sku() )
								),
								$cart_item_key
							);
							?>
						</td>

						<td class="product-thumbnail">
							<?php
							$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image(), $cart_item, $cart_item_key );

							if ( ! $product_permalink ) {
								echo $thumbnail;
							} else {
								printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail );
							}
							?>
						</td>

						<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'woocommerce' ); ?>">
							<?php
							if ( ! $product_permalink ) {
								echo wp_kses_post( $product_name );
							} else {
								echo wp_kses_post( sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $product_name ) );
							}

							do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );

							// Meta data.
							echo wc_get_formatted_cart_item_data( $cart_item );

							// Backorder notification.
							if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
								echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $product_id ) );
							}
							?>
						</td>

						<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'woocommerce' ); ?>">
							<?php
							echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
							?>
						</td>

						<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
							<?php
							if ( $_product->is_sold_individually() ) {
								$min_quantity = 1;
								$max_quantity = 1;
							} else {
								$min_quantity = 0;
								$max_quantity = $_product->get_max_purchase_quantity();
							}

							$product_quantity = woocommerce_quantity_input(
								array(
									'input_name'   => "cart[{$cart_item_key}][qty]",
									'input_value'  => $cart_item['quantity'],
									'max_value'    => $max_quantity,
									'min_value'    => $min_quantity,
									'product_name' => $product_name,
								),
								$_product,
								false
							);

							echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
							?>
						</td>

						<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>">
							<?php
							echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
							?>
						</td>
					</tr>
					<?php
				}
			endforeach;
			?>

			<?php do_action( 'woocommerce_cart_contents' ); ?>

			<tr>
				<td colspan="6" class="actions">

					<?php if ( wc_coupons_enabled() ) { ?>
						<div class="coupon">
							<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon:', 'woocommerce' ); ?></label>
							<input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" />
							<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>"><?php esc_html_e( 'Apply coupon', 'woocommerce' ); ?></button>
							<?php do_action( 'woocommerce_cart_coupon' ); ?>
						</div>
					<?php } ?>

					<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="update_cart" value="<?php esc_attr_e( 'Update cart', 'woocommerce' ); ?>"><?php esc_html_e( 'Update cart', 'woocommerce' ); ?></button>

					<?php do_action( 'woocommerce_cart_actions' ); ?>

					<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
				</td>
			</tr>

			<?php do_action( 'woocommerce_after_cart_contents' ); ?>
		</tbody>
	</table>
	<?php do_action( 'woocommerce_after_cart_table' ); ?>
</form>

<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

<div class="cart-collaterals">
	<?php
		/**
		 * Cart collaterals hook.
		 *
		 * @hooked woocommerce_cross_sell_display
		 * @hooked woocommerce_cart_totals - 10
		 */
		do_action( 'woocommerce_cart_collaterals' );
	?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>

<style>
/* TCBF Cart Pack Styling */
.tcbf-pack-separator td {
	padding: 9px 0 0 0 !important;
	border: none !important;
	background: transparent !important;
}
.tcbf-cart-row--parent {
	border-left: 3px solid var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) !important;
}
.tcbf-cart-row--child {
	border-left: 3px solid color-mix(in srgb, var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00))) 50%, transparent) !important;
}
.tcbf-pack-footer-row td {
	padding: 0 !important;
	border: none !important;
}
.tcbf-pack-footer-cell {
	padding: 0 12px 12px 12px !important;
}
.tcbf-pack-footer--cart {
	background: rgba(0, 0, 0, 0.02);
	border: 1px solid rgba(0, 0, 0, 0.06);
	border-radius: 6px;
	padding: 12px 16px;
	margin-left: 3px;
}
.tcbf-pack-footer-line {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 0;
	font-size: 13px;
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
	margin-top: 6px;
	padding-top: 8px;
}
.tcbf-pack-footer-total .tcbf-pack-footer-label {
	font-weight: 600;
	color: #374151;
}
.tcbf-pack-footer-total .tcbf-pack-footer-value {
	font-weight: 700;
	color: #111827;
	font-size: 14px;
}
.tcbf-product-link {
	color: var(--tcbf-accent, var(--shopkeeper-accent, var(--theme-accent, #434c00)));
}
.tcbf-event-thumb {
	width: 80px;
	height: auto;
	border-radius: 4px;
}
</style>
