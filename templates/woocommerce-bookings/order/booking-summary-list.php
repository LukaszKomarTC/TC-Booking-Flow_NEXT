<?php
/**
 * TCBF Override: Clean booking summary list.
 *
 * This template is used as a safe fallback when booking-summary-list.php renders
 * (e.g., in admin views, certain emails, or other contexts).
 *
 * Main UX is now the grouped table in order-details.php, but this template
 * ensures any residual WooCommerce Bookings output is clean and consistent.
 *
 * Features:
 * - Transparent background (no "white patch")
 * - Event title linked to event page (using _event_id from order item meta)
 * - Event featured image as thumbnail (fallback to product image)
 * - Only essential fields: Tour, Participant, Bike + Size
 * - No internal meta keys exposed
 * - No commission data
 *
 * Original template from WooCommerce Bookings plugin.
 * This template can be overridden by copying it to yourtheme/woocommerce-bookings/order/booking-summary-list.php.
 *
 * @see     https://woocommerce.com/document/introduction-to-woocommerce-bookings/pages-and-emails-customization/
 * @author  Automattic / TCBF
 * @version 1.15.44
 * @since   1.10.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Variables expected from WooCommerce Bookings:
// $booking - WC_Booking object
// $booking_date - formatted booking date string
// $booking_timezone - timezone string
// $resource - WC_Product_Booking_Resource object (if applicable)
// $label - resource label
// $product - WC_Product_Booking object

/**
 * Try to get the order item for this booking to extract TCBF meta.
 */
$tcbf_event_id    = 0;
$tcbf_event_title = '';
$tcbf_event_url   = '';
$tcbf_participant = '';
$tcbf_bicycle     = '';
$tcbf_size        = '';
$tcbf_thumb_url   = '';

if ( $booking && method_exists( $booking, 'get_order_item_id' ) ) {
	$order_item_id = $booking->get_order_item_id();
	$order_id      = $booking->get_order_id();

	if ( $order_id && $order_item_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$item = $order->get_item( $order_item_id );
			if ( $item && $item instanceof WC_Order_Item_Product ) {
				// Get event ID (try multiple key formats)
				$tcbf_event_id = (int) $item->get_meta( '_event_id', true );
				if ( ! $tcbf_event_id ) {
					$tcbf_event_id = (int) $item->get_meta( 'event_id', true );
				}

				// Get event title
				$tcbf_event_title = (string) $item->get_meta( '_event_title', true );
				if ( $tcbf_event_title === '' ) {
					$tcbf_event_title = (string) $item->get_meta( 'event_title', true );
				}
				if ( $tcbf_event_title === '' && $tcbf_event_id > 0 ) {
					$tcbf_event_title = get_the_title( $tcbf_event_id );
				}

				// Get event URL
				if ( $tcbf_event_id > 0 ) {
					$tcbf_event_url = get_permalink( $tcbf_event_id );
				}

				// Get participant
				$tcbf_participant = (string) $item->get_meta( '_participant', true );
				if ( $tcbf_participant === '' ) {
					$tcbf_participant = (string) $item->get_meta( 'participant', true );
				}

				// Get bicycle
				$tcbf_bicycle = (string) $item->get_meta( '_bicycle', true );
				if ( $tcbf_bicycle === '' ) {
					$tcbf_bicycle = (string) $item->get_meta( 'bicycle', true );
				}

				// Get event featured image
				if ( $tcbf_event_id > 0 ) {
					$thumb_id = get_post_thumbnail_id( $tcbf_event_id );
					if ( $thumb_id ) {
						$tcbf_thumb_url = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
					}
				}

				// Fallback to product image
				if ( ! $tcbf_thumb_url && $product ) {
					$prod_thumb_id = $product->get_image_id();
					if ( $prod_thumb_id ) {
						$tcbf_thumb_url = wp_get_attachment_image_url( $prod_thumb_id, 'thumbnail' );
					}
				}
			}
		}
	}
}

// Get size from resource (if available)
if ( $resource && is_object( $resource ) && method_exists( $resource, 'get_name' ) ) {
	$resource_name = $resource->get_name();
	// Extract size token (S, M, L, XL, XXL)
	if ( preg_match( '/\b(XXL|XL|[SMLX]{1,2})\b/i', $resource_name, $matches ) ) {
		$tcbf_size = strtoupper( $matches[1] );
	} else {
		$tcbf_size = $resource_name;
	}
}

// Fallback title to product name if no event
if ( $tcbf_event_title === '' && $product ) {
	$tcbf_event_title = $product->get_name();
	$tcbf_event_url   = $product->get_permalink();
}
?>
<ul class="wc-booking-summary-list tcbf-booking-summary-list">
	<?php if ( $tcbf_event_title !== '' ) : ?>
	<li class="tcbf-summary-tour">
		<strong><?php esc_html_e( 'Tour', 'tc-booking-flow-next' ); ?>:</strong>
		<?php if ( $tcbf_event_url ) : ?>
			<a href="<?php echo esc_url( $tcbf_event_url ); ?>" class="tcbf-event-link"><?php echo esc_html( $tcbf_event_title ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $tcbf_event_title ); ?>
		<?php endif; ?>
	</li>
	<?php endif; ?>

	<li class="tcbf-summary-date">
		<?php
		// Use the original booking date from WooCommerce Bookings
		echo wp_kses(
			apply_filters( 'wc_bookings_summary_list_date', $booking_date, $booking->get_start(), $booking->get_end() ),
			array(
				'span' => array(
					'class'         => array(),
					'data-all-day'  => array(),
					'data-timezone' => array(),
				),
			)
		);
		if ( wc_should_convert_timezone( $booking ) ) :
			/* translators: %s: timezone name */
			echo ' ' . esc_html( sprintf( __( 'in timezone: %s', 'woocommerce-bookings' ), $booking_timezone ) );
		endif;
		?>
	</li>

	<?php if ( $tcbf_participant !== '' ) : ?>
	<li class="tcbf-summary-participant">
		<strong><?php esc_html_e( 'Participant', 'tc-booking-flow-next' ); ?>:</strong>
		<?php echo esc_html( $tcbf_participant ); ?>
	</li>
	<?php endif; ?>

	<?php if ( $tcbf_bicycle !== '' || $tcbf_size !== '' ) : ?>
	<li class="tcbf-summary-bike">
		<?php if ( $tcbf_bicycle !== '' ) : ?>
			<strong><?php esc_html_e( 'Bike', 'tc-booking-flow-next' ); ?>:</strong>
			<?php echo esc_html( $tcbf_bicycle ); ?>
			<?php if ( $tcbf_size !== '' ) : ?>
				(<?php esc_html_e( 'Size', 'tc-booking-flow-next' ); ?>: <?php echo esc_html( $tcbf_size ); ?>)
			<?php endif; ?>
		<?php elseif ( $tcbf_size !== '' ) : ?>
			<strong><?php esc_html_e( 'Size', 'tc-booking-flow-next' ); ?>:</strong>
			<?php echo esc_html( $tcbf_size ); ?>
		<?php endif; ?>
	</li>
	<?php endif; ?>
</ul>

<style>
/* TCBF Booking Summary List - Clean fallback styling */
.tcbf-booking-summary-list {
	list-style: none;
	margin: 0;
	padding: 12px 16px;
	background: transparent;
	border: 1px solid #e5e7eb;
	border-radius: 8px;
}
.tcbf-booking-summary-list li {
	padding: 6px 0;
	border-bottom: 1px solid #f3f4f6;
	font-size: 14px;
	color: #374151;
}
.tcbf-booking-summary-list li:last-child {
	border-bottom: none;
}
.tcbf-booking-summary-list strong {
	color: #6b7280;
	font-weight: 500;
	margin-right: 6px;
}
.tcbf-booking-summary-list .tcbf-event-link {
	color: #3d61aa;
	text-decoration: none;
	font-weight: 600;
}
.tcbf-booking-summary-list .tcbf-event-link:hover {
	text-decoration: underline;
}
</style>
