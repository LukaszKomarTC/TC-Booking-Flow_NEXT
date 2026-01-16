<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce cart and pricing logic
 *
 * Handles:
 * - Cart item price snapshots
 * - Early booking discount application
 * - Cart item data display
 * - Booking cost calculations
 * - Rental pricing resolution
 * - Participation product resolution
 */
final class Woo {

	/* =========================================================
	 * Woo Bookings + Woo pricing
	 * ========================================================= */

	public static function woo_override_booking_cost( $cost, $book_obj, $posted ) {
		if ( isset($posted[\TC_BF\Plugin::BK_CUSTOM_COST]) ) {
			\TC_BF\Support\Logger::log('woo.bookings.override_cost', ['custom_cost'=>(float)$posted[\TC_BF\Plugin::BK_CUSTOM_COST]]);
			return (float) $posted[\TC_BF\Plugin::BK_CUSTOM_COST];
		}
		return $cost;
	}

	public static function woo_apply_eb_snapshot_to_cart( $cart ) {

		if ( is_admin() && ! defined('DOING_AJAX') ) return;
		if ( ! $cart || ! is_a($cart, 'WC_Cart') ) return;

		foreach ( $cart->get_cart() as $key => $item ) {

			if ( empty($item['booking']) || empty($item['booking'][\TC_BF\Plugin::BK_EVENT_ID]) ) continue;

			$booking = (array) $item['booking'];
			$scope   = isset($booking[\TC_BF\Plugin::BK_SCOPE]) ? (string) $booking[\TC_BF\Plugin::BK_SCOPE] : '';

			$product = $item['data'] ?? null;
			if ( ! $product || ! is_object($product) || ! method_exists($product, 'set_price') ) continue;

			// -------------------------------------------------
			// PRICE SNAPSHOT (authoritative, once)
			// -------------------------------------------------
			// Rental MUST be snapshotted at add-to-cart time so Woo Bookings pricing
			// cannot drift or double-count later.
			if ( $scope === 'rental' && empty($booking[\TC_BF\Plugin::BK_CUSTOM_COST]) ) {
				$cost = self::calculate_booking_cost_snapshot($product, $booking);
				if ( $cost !== null ) {
					$cart->cart_contents[$key]['booking'][\TC_BF\Plugin::BK_CUSTOM_COST] = wc_format_decimal((float)$cost, 2);
					$booking[\TC_BF\Plugin::BK_CUSTOM_COST] = (float) $cost;
				}
			}

			// If we have a snapshotted cost, enforce it on the cart item price.
			if ( isset($booking[\TC_BF\Plugin::BK_CUSTOM_COST]) && $booking[\TC_BF\Plugin::BK_CUSTOM_COST] !== '' ) {
				$product->set_price( (float) $booking[\TC_BF\Plugin::BK_CUSTOM_COST] );
				\TC_BF\Support\Logger::log('woo.cart.set_price_snapshot', ['key'=>$key,'scope'=>$scope,'event_id'=>(int)$booking[\TC_BF\Plugin::BK_EVENT_ID],'price'=>(float)$booking[\TC_BF\Plugin::BK_CUSTOM_COST]]);
			}

			// -------------------------------------------------
			// EARLY BOOKING (discount snapshot applied on top)
			// -------------------------------------------------
			$eligible = ! empty($booking[\TC_BF\Plugin::BK_EB_ELIGIBLE]);
			if ( ! $eligible ) continue;

			$pct = isset($booking[\TC_BF\Plugin::BK_EB_PCT]) ? (float) $booking[\TC_BF\Plugin::BK_EB_PCT] : 0.0;
			$amt = isset($booking[\TC_BF\Plugin::BK_EB_AMOUNT]) ? (float) $booking[\TC_BF\Plugin::BK_EB_AMOUNT] : 0.0;
			if ( $pct <= 0 && $amt <= 0 ) continue;

			// Base price snapshot per line (store in booking meta)
			if ( empty($booking[\TC_BF\Plugin::BK_EB_BASE]) ) {
				$base = isset($booking[\TC_BF\Plugin::BK_CUSTOM_COST]) ? (float) $booking[\TC_BF\Plugin::BK_CUSTOM_COST] : (float) $product->get_price();
				$cart->cart_contents[$key]['booking'][\TC_BF\Plugin::BK_EB_BASE] = wc_format_decimal($base, 2);
			} else {
				$base = (float) $booking[\TC_BF\Plugin::BK_EB_BASE];
			}

			if ( $amt > 0 ) {
				$disc = \TC_BF\Support\Money::money_round($amt);
				$new  = \TC_BF\Support\Money::money_round($base - $disc);
			} else {
				$disc = \TC_BF\Support\Money::money_round($base * ($pct/100));
				$new  = \TC_BF\Support\Money::money_round($base - $disc);
			}
			if ( $new < 0 ) $new = 0;

			$product->set_price( $new );
		}
	}


	/* =========================================================
	 * Cart display (frontend)
	 * ========================================================= */

	public static function woo_cart_item_data( array $item_data, array $cart_item ) : array {

		if ( empty($cart_item["booking"]) || ! is_array($cart_item["booking"]) ) return $item_data;
		$booking = (array) $cart_item["booking"];

		// Get scope and pack role to determine which fields to show
		$scope = isset($booking[\TC_BF\Plugin::BK_SCOPE]) ? (string) $booking[\TC_BF\Plugin::BK_SCOPE] : '';
		$is_pack_parent = isset($cart_item['tc_group_role']) && $cart_item['tc_group_role'] === 'parent';

		// Event title (show for both participation and rental)
		if ( ! empty($booking[\TC_BF\Plugin::BK_EVENT_TITLE]) ) {
			$item_data[] = [
				"name"  => __("Event", "tc-booking-flow"),
				"value" => wc_clean((string) $booking[\TC_BF\Plugin::BK_EVENT_TITLE]),
			];
		}

		// Participant name: Now shown as floating badge over pack group (via JavaScript in Plugin.php)
		// Not displayed in cart item meta to avoid duplication

		// Type field: HIDDEN (per user requirement - hide for both participation and rental in cart)
		// (kept as order meta via woo_checkout_create_order_line_item)

		// Bicycle label (rental line only)
		if ( $scope === 'rental' && ! empty($booking["_bicycle"]) ) {
			$item_data[] = [
				"name"  => __("Bike", "tc-booking-flow"),
				"value" => wc_clean((string) $booking["_bicycle"]),
			];
		}

		// Note: Booking date, Duration, Size are automatically added by WooCommerce Bookings
		// We'll filter those out via woocommerce_hidden_order_itemmeta for cart display

		return $item_data;
	}

	/**
	 * Calculate a stable booking cost snapshot for a cart line.
	 *
	 * We intentionally calculate once and then enforce via set_price() + BK_CUSTOM_COST.
	 * This prevents Woo Bookings from recalculating later in the funnel.
	 */
	private static function calculate_booking_cost_snapshot( $product, array $booking ) : ?float {

		// Strip our internal meta keys from the posted array.
		$posted = $booking;
		unset(
			$posted[\TC_BF\Plugin::BK_EVENT_ID],
			$posted[\TC_BF\Plugin::BK_EVENT_TITLE],
			$posted[\TC_BF\Plugin::BK_ENTRY_ID],
			$posted[\TC_BF\Plugin::BK_SCOPE],
			$posted[\TC_BF\Plugin::BK_EB_PCT],
			$posted[\TC_BF\Plugin::BK_EB_ELIGIBLE],
			$posted[\TC_BF\Plugin::BK_EB_DAYS],
			$posted[\TC_BF\Plugin::BK_EB_BASE],
			$posted[\TC_BF\Plugin::BK_EB_EVENT_TS],
			$posted[\TC_BF\Plugin::BK_CUSTOM_COST]
		);

		// Primary path: Woo Bookings cost calculator (if available).
		if ( class_exists('WC_Bookings_Cost_Calculation') && is_callable(['WC_Bookings_Cost_Calculation', 'calculate_booking_cost']) ) {
			try {
				$cost = \WC_Bookings_Cost_Calculation::calculate_booking_cost( $posted, $product );
				if ( is_numeric($cost) ) return (float) $cost;
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		// Fallback: if Woo already set a price for this cart item, we can snapshot it.
		// (Better than nothing; still locks the number.)
		if ( is_object($product) && method_exists($product, 'get_price') ) {
			$maybe = (float) $product->get_price();
			if ( $maybe > 0 ) return $maybe;
		}

		return null;
	}

	/**
	 * Localize multilingual strings for setups using qTranslate-X / qTranslate-XT.
	 * If no multilingual plugin is detected, returns the string unchanged.
	 */
	public static function localize_text( string $text ) : string {
		if ( $text === '' ) return '';
		if ( function_exists('qtranxf_useCurrentLanguageIfNotFound') ) {
			return (string) qtranxf_useCurrentLanguageIfNotFound( $text );
		}
		if ( function_exists('qtrans_useCurrentLanguageIfNotFound') ) {
			return (string) qtrans_useCurrentLanguageIfNotFound( $text );
		}
		return $text;
	}

	/**
	 * Get a post title in the current language when using multilingual plugins.
	 */
	public static function localize_post_title( int $post_id ) : string {
		if ( $post_id <= 0 ) return '';
		$title = get_the_title( $post_id );
		if ( ! is_string($title) ) $title = (string) $title;
		return self::localize_text( $title );
	}

	/**
	 * Resolve a rental price (fixed per event) based on GF rental type or rental product category.
	 * Event meta keys used by current snippets:
	 * - rental_price_road
	 * - rental_price_mtb
	 * - rental_price_ebike
	 * - rental_price_gravel
	 */
	public static function get_event_rental_price( int $event_id, $entry, int $rental_product_id ) : float {
		// 1) Prefer GF rental type select (field 106)
		$rental_raw = trim((string) rgar($entry, (string) \TC_BF\Plugin::GF_FIELD_RENTAL_TYPE));
		$key = '';
		if ( $rental_raw !== '' ) {
			$rt = strtoupper($rental_raw);
			if ( strpos($rt, 'ROAD') === 0 )       $key = 'rental_price_road';
			elseif ( strpos($rt, 'MTB') === 0 )    $key = 'rental_price_mtb';
			elseif ( strpos($rt, 'EMTB') === 0 )   $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E-MTB') === 0 )  $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E MTB') === 0 )  $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'GRAVEL') === 0 ) $key = 'rental_price_gravel';
		}

		// 2) Fallback: infer from product categories
		if ( $key === '' && $rental_product_id > 0 ) {
			$terms = get_the_terms( $rental_product_id, 'product_cat' );
			if ( is_array($terms) ) {
				$slugs = [ ];
				foreach ( $terms as $t ) { if ( isset($t->slug) ) $slugs[] = (string) $t->slug; }
				$slugs = array_unique($slugs);
				if ( in_array('rental_road', $slugs, true) )   $key = 'rental_price_road';
				elseif ( in_array('rental_mtb', $slugs, true) ) $key = 'rental_price_mtb';
				elseif ( in_array('rental_emtb', $slugs, true) )$key = 'rental_price_ebike';
				elseif ( in_array('rental_gravel', $slugs, true) )$key = 'rental_price_gravel';
			}
		}

		if ( $key === '' ) return 0.0;
		return \TC_BF\Support\Money::money_to_float( get_post_meta($event_id, $key, true) );
	}

	/* =========================================================
	 * Product resolution
	 * ========================================================= */

	/**
	 * Resolve participation product ID for an event.
	 *
	 * Keep your existing mapping logic here (categories → product IDs, rental/no rental, etc.).
	 *
	 * For now, this method returns the legacy tour product id if you stored it on the event,
	 * or 0 if nothing is available.
	 */
	public static function resolve_participation_product_id( int $event_id, $entry ) : int {

		// 1) Explicit per-event override (strongest)
		$pid = (int) get_post_meta($event_id, 'tc_participation_product_id', true);
		if ( $pid > 0 && self::is_valid_participation_product($pid) ) {
			\TC_BF\Support\Logger::log('resolver.participation.override_event', ['event_id'=>$event_id,'product_id'=>$pid]);
			return $pid;
		}

		// 2) Legacy fallback (if you stored a general product_id)
		$pid = (int) get_post_meta($event_id, 'tc_product_id', true);
		if ( $pid > 0 && self::is_valid_participation_product($pid) ) {
			\TC_BF\Support\Logger::log('resolver.participation.override_legacy_meta', ['event_id'=>$event_id,'product_id'=>$pid]);
			return $pid;
		}


// 2b) Global default (plugin settings) — bookable products only
$default_pid = (int) get_option('tcbf_default_participation_product_id', 0);
if ( $default_pid > 0 && self::is_valid_participation_product($default_pid) ) {
	\TC_BF\Support\Logger::log('resolver.participation.default_setting', ['event_id'=>$event_id,'product_id'=>$default_pid]);
	return $default_pid;
}


		// 3) Category slug → participation product meta mapping (recommended)
		$slugs = wp_get_post_terms( (int) $event_id, 'sc_event_category', [ 'fields' => 'slugs' ] );
		if ( is_wp_error($slugs) ) $slugs = [];
		$slugs = array_values(array_filter(array_map('strval', (array)$slugs)));

		if ( $slugs ) {
			$mapped = self::find_participation_product_by_category_slugs($slugs);
			if ( $mapped > 0 ) { \TC_BF\Support\Logger::log('resolver.participation.category_mapped', ['event_id'=>$event_id,'product_id'=>$mapped,'slugs'=>$slugs]); return $mapped; }
		}

		// 4) Legacy hardcoded fallback mapping (safe during transition).
		// NOTE: In the new model, rental does NOT affect participation product selection.
		$map = apply_filters('tc_bf_participation_product_map', [
			// Canonical "no rental" participation products:
			'guided' => 37916,
			'tdg'    => 48161,
		], $event_id, $slugs);

		$tdg_slugs    = apply_filters('tc_bf_tdg_category_slugs',    [ 'tour_de_girona' ]);
		$guided_slugs = apply_filters('tc_bf_guided_category_slugs', [ 'salidas_guiadas' ]);

		$is_tdg = (bool) array_intersect( (array) $tdg_slugs, (array) $slugs );
		$type   = $is_tdg ? 'tdg' : 'guided';

		if ( isset($map[$type]) && (int) $map[$type] > 0 && self::is_valid_participation_product((int)$map[$type]) ) {
			\TC_BF\Support\Logger::log('resolver.participation.legacy_map', ['event_id'=>$event_id,'type'=>$type,'product_id'=>(int)$map[$type],'slugs'=>$slugs]);
			return (int) $map[$type];
		}

		\TC_BF\Support\Logger::log('resolver.participation.not_found', ['event_id'=>$event_id,'slugs'=>$slugs]);
		return 0;
	}

	/**
	 * Validate participation product is a booking product (and exists).
	 */
	public static function is_valid_participation_product( int $product_id ) : bool {
		if ( $product_id <= 0 ) return false;
		$p = wc_get_product($product_id);
		if ( ! $p ) return false;
		return ( function_exists('is_wc_booking_product') && is_wc_booking_product($p) );
	}

	/**
	 * Find participation product by matching any sc_event_category slug against product meta.
	 * Meta key: tc_participation_category_key (set on the product).
	 */
	public static function find_participation_product_by_category_slugs( array $slugs ) : int {

		$slugs = array_values(array_filter(array_map(function($s){
			$s = strtolower((string)$s);
			return preg_replace('/[^a-z0-9_\-]/', '', $s);
		}, $slugs)));

		if ( ! $slugs ) return 0;

		static $cache = [];
		$ck = implode('|', $slugs);
		if ( isset($cache[$ck]) ) return (int) $cache[$ck];

		$q = new \WP_Query([
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => \TC_BF\Admin\Product_Meta::META_KEY,
					'value'   => $slugs,
					'compare' => 'IN',
				],
			],
		]);

		if ( $q->have_posts() ) {
			foreach ( $q->posts as $pid ) {
				$pid = (int) $pid;
				if ( self::is_valid_participation_product($pid) ) {
					return $cache[$ck] = $pid;
				}
			}
		}

		return $cache[$ck] = 0;
	}

}
