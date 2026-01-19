<?php
/**
 * The template for displaying the list of bookings in the summary for customers.
 * It is used in:
 * - templates/order/booking-display.php
 * - templates/order/admin/booking-display.php
 * It will display in four places:
 * - After checkout,
 * - In the order confirmation email, and
 * - When customer reviews order in My Account > Orders,
 * - When reviewing a customer order in the admin area.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-bookings/order/booking-summary-list.php.
 *
 * TCBF Override: Clean UI with Tour + Bike grouping, deterministic extraction from booking's order item.
 *
 * @see https://woocommerce.com/document/introduction-to-woocommerce-bookings/pages-and-emails-customization/
 * @author Automattic
 * @version 1.15.44
 * @since 1.10.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Ensure booking object exists
if ( ! isset( $booking ) || ! is_object( $booking ) ) {
	return;
}

// Get the order and item deterministically from the booking
$order_id = $booking->get_order_id();
$item_id  = $booking->get_order_item_id();
$order    = $order_id ? wc_get_order( $order_id ) : null;
$item     = ( $order && $item_id ) ? $order->get_item( $item_id ) : null;

// Extract meta from the booking's own order item only (deterministic)
$event_title = '';
$participant = '';
$bicycle     = '';

if ( $item ) {
	$event_title = (string) $item->get_meta( '_event_title', true );
	if ( $event_title === '' ) {
		$event_title = (string) $item->get_meta( 'event', true );
	}

	$participant = (string) $item->get_meta( '_participant', true );
	if ( $participant === '' ) {
		$participant = (string) $item->get_meta( 'participant', true );
	}

	$bicycle = (string) $item->get_meta( '_bicycle', true );
}

// Get resource (bike size) from booking
$resource = $booking->get_resource();
$size     = '';
if ( $resource && is_object( $resource ) ) {
	$resource_name = $resource->get_name();
	// Extract size token (S, M, L, XL, XXL)
	if ( preg_match( '/\b(XXL|XL|[SMLX])\b/i', $resource_name, $matches ) ) {
		$size = strtoupper( $matches[1] );
	} else {
		$size = $resource_name;
	}
}

// Booking date
$booking_date_display = isset( $booking_date ) ? $booking_date : '';

// Translation helper
if ( ! function_exists( 'tcbf_tr' ) ) {
	function tcbf_tr( $text ) {
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			return tc_sc_event_tr( $text );
		}
		if ( function_exists( 'qtranxf_useCurrentLanguageIfNotFound' ) ) {
			return qtranxf_useCurrentLanguageIfNotFound( $text );
		}
		if ( function_exists( 'qtrans_useCurrentLanguageIfNotFound' ) ) {
			return qtrans_useCurrentLanguageIfNotFound( $text );
		}
		// Fallback: extract English
		if ( preg_match( '/\[:en\]([^\[]+)/', $text, $m ) ) {
			return $m[1];
		}
		return $text;
	}
}

// Output styles (only once)
static $tcbf_summary_styles_output = false;
if ( ! $tcbf_summary_styles_output ) {
	$tcbf_summary_styles_output = true;
	?>
	<style>
	.tcbf-order-summary-card {
		background: #fff;
		border: 1px solid #e5e7eb;
		border-radius: 10px;
		padding: 16px 18px;
		margin: 12px 0 18px;
	}
	.tcbf-order-summary-title {
		font-weight: 800;
		margin-bottom: 10px;
		font-size: 15px;
	}
	.tcbf-order-summary-grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px 16px;
		font-size: 14px;
	}
	.tcbf-order-summary-grid strong {
		display: block;
		font-size: 12px;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: #6b7280;
		margin-bottom: 2px;
	}
	.tcbf-order-summary-grid .tcbf-value {
		color: #111827;
	}
	/* Also hide default ul if we have our card */
	.tcbf-order-summary-card + .wc-booking-summary-list {
		display: none;
	}
	</style>
	<?php
}

// Check if we have meaningful data to display
$has_data = ( $event_title !== '' || $participant !== '' || $bicycle !== '' );

if ( $has_data ) :
?>
<div class="tcbf-order-summary-card">
	<div class="tcbf-order-summary-title"><?php echo esc_html( tcbf_tr( '[:es]Detalles de la reserva[:en]Booking details[:]' ) ); ?></div>
	<div class="tcbf-order-summary-grid">
		<?php if ( $event_title !== '' ) : ?>
		<div>
			<strong><?php echo esc_html( tcbf_tr( '[:es]Evento[:en]Tour[:]' ) ); ?></strong>
			<span class="tcbf-value"><?php echo esc_html( $event_title ); ?></span>
		</div>
		<?php endif; ?>

		<?php if ( $booking_date_display !== '' ) : ?>
		<div>
			<strong><?php echo esc_html( tcbf_tr( '[:es]Fecha[:en]Date[:]' ) ); ?></strong>
			<span class="tcbf-value"><?php
				echo wp_kses(
					apply_filters( 'wc_bookings_summary_list_date', $booking_date_display, $booking->get_start(), $booking->get_end() ),
					array(
						'span' => array(
							'class' => array(),
							'data-all-day' => array(),
							'data-timezone' => array(),
						),
					)
				);
			?></span>
		</div>
		<?php endif; ?>

		<?php if ( $participant !== '' ) : ?>
		<div>
			<strong><?php echo esc_html( tcbf_tr( '[:es]Participante[:en]Participant[:]' ) ); ?></strong>
			<span class="tcbf-value"><?php echo esc_html( $participant ); ?></span>
		</div>
		<?php endif; ?>

		<?php if ( $bicycle !== '' ) : ?>
		<div>
			<strong><?php echo esc_html( tcbf_tr( '[:es]Bicicleta[:en]Bike[:]' ) ); ?></strong>
			<span class="tcbf-value"><?php echo esc_html( $bicycle ); ?></span>
		</div>
		<?php endif; ?>

		<?php if ( $size !== '' && $bicycle !== '' ) : ?>
		<div>
			<strong><?php echo esc_html( tcbf_tr( '[:es]Talla[:en]Size[:]' ) ); ?></strong>
			<span class="tcbf-value"><?php echo esc_html( $size ); ?></span>
		</div>
		<?php endif; ?>
	</div>
</div>
<?php
endif;

// Fallback: Show minimal default list if no custom data
if ( ! $has_data ) :
?>
<ul class="wc-booking-summary-list">
	<?php if ( $booking_date_display !== '' ) : ?>
	<li>
		<?php echo wp_kses(
			apply_filters( 'wc_bookings_summary_list_date', $booking_date_display, $booking->get_start(), $booking->get_end() ),
			array(
				'span' => array(
					'class' => array(),
					'data-all-day' => array(),
					'data-timezone' => array(),
				),
			)
		); ?>
		<?php
		if ( function_exists( 'wc_should_convert_timezone' ) && wc_should_convert_timezone( $booking ) && isset( $booking_timezone ) ) :
			/* translators: %s: timezone name */
			echo esc_html( sprintf( __( 'in timezone: %s', 'woocommerce-bookings' ), $booking_timezone ) );
		endif;
		?>
	</li>
	<?php endif; ?>

	<?php if ( $resource && is_object( $resource ) ) : ?>
	<li>
		<?php
		$label = isset( $label ) && $label !== '' ? $label : __( 'Resource', 'woocommerce-bookings' );
		/* translators: 1: label 2: resource name */
		echo esc_html( sprintf( __( '%1$s: %2$s', 'woocommerce-bookings' ), $label, $resource->get_name() ) );
		?>
	</li>
	<?php endif; ?>

	<?php
	// Person types (if product has persons)
	if ( isset( $product ) && $product && method_exists( $product, 'has_persons' ) && $product->has_persons() ) {
		if ( method_exists( $product, 'has_person_types' ) && $product->has_person_types() ) {
			$person_types  = $product->get_person_types();
			$person_counts = $booking->get_person_counts();

			if ( ! empty( $person_types ) && is_array( $person_types ) ) {
				foreach ( $person_types as $person_type ) {
					if ( empty( $person_counts[ $person_type->get_id() ] ) ) {
						continue;
					}
					?>
					<li><?php echo esc_html( sprintf( '%s: %d', $person_type->get_name(), $person_counts[ $person_type->get_id() ] ) ); ?></li>
					<?php
				}
			}
		} else {
			?>
			<li>
			<?php
			/* translators: 1: person count */
			echo esc_html( sprintf( __( '%d Persons', 'woocommerce-bookings' ), array_sum( $booking->get_person_counts() ) ) );
			?>
			</li>
			<?php
		}
	}
	?>
</ul>
<?php endif; ?>
