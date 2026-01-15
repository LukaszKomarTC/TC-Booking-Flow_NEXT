<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * Pack Grouping — Treats participation + rental as one atomic unit
 *
 * Handles:
 * - Adding pack metadata (tc_group_id, tc_group_role) to cart items
 * - Atomic removal (removing one item removes all items in the pack)
 * - Visual grouping in cart, checkout, and order views
 * - Pack integrity validation
 *
 * Key concepts:
 * - tc_group_id = GF entry ID (same for both participation and rental)
 * - tc_group_role = 'parent' (participation) or 'child' (rental)
 * - Parent item shows remove button, child does not
 * - Removing any item in a pack removes all items in that pack
 */
final class Pack_Grouping {

	/**
	 * Meta keys for pack grouping
	 */
	const META_GROUP_ID   = 'tc_group_id';
	const META_GROUP_ROLE = 'tc_group_role';
	const META_SCOPE      = 'tcbf_scope'; // Already exists: 'participation' or 'rental'

	/**
	 * Group roles
	 */
	const ROLE_PARENT = 'parent'; // Participation (main item)
	const ROLE_CHILD  = 'child';  // Rental (included item)

	/**
	 * Guard against recursion during atomic removal
	 */
	private static $processing_groups = [];

	/**
	 * Initialize pack grouping hooks
	 */
	public static function init() : void {

		// Add pack metadata to cart items when they are added
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'add_pack_metadata_to_cart_item' ], 10, 3 );

		// Persist pack metadata to cart session
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'get_pack_metadata_from_session' ], 10, 2 );

		// Atomic removal: when one item is removed, remove all items in the pack
		add_action( 'woocommerce_remove_cart_item', [ __CLASS__, 'atomic_remove_pack_items' ], 5, 2 );

		// Handle cart empty events
		add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'handle_cart_emptied' ], 10 );

		// Persist pack metadata to order items
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'add_pack_metadata_to_order_item' ], 10, 4 );

		// Hide remove button on child items (visual polish)
		add_filter( 'woocommerce_cart_item_remove_link', [ __CLASS__, 'hide_child_remove_button' ], 10, 2 );

		// Add visual indicators to cart items
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'add_pack_visual_indicators' ], 20, 2 );

		// Validate pack integrity before checkout
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_pack_integrity' ], 10 );

		// Lock quantities to 1 for pack items
		add_filter( 'woocommerce_cart_item_quantity', [ __CLASS__, 'lock_pack_quantity' ], 10, 3 );

		// Prevent quantity updates via AJAX
		add_filter( 'woocommerce_update_cart_validation', [ __CLASS__, 'prevent_pack_quantity_update' ], 10, 4 );
	}

	/**
	 * Add pack metadata when items are added to cart
	 *
	 * Reads the entry_id from booking meta and uses it as group_id.
	 * Determines role based on scope (participation = parent, rental = child).
	 *
	 * @param array $cart_item_data Existing cart item data
	 * @param int   $product_id     Product ID
	 * @param int   $variation_id   Variation ID
	 * @return array Modified cart item data with pack metadata
	 */
	public static function add_pack_metadata_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ) : array {

		// Only process items with booking data (our booking flow)
		if ( empty( $cart_item_data['booking'] ) || ! is_array( $cart_item_data['booking'] ) ) {
			return $cart_item_data;
		}

		$booking = $cart_item_data['booking'];

		// Extract entry ID (this is our group ID)
		$entry_id = isset( $booking[\TC_BF\Plugin::BK_ENTRY_ID] ) ? (int) $booking[\TC_BF\Plugin::BK_ENTRY_ID] : 0;
		if ( $entry_id <= 0 ) {
			// No entry ID means this isn't from our booking flow
			return $cart_item_data;
		}

		// Extract scope to determine role
		$scope = isset( $booking[\TC_BF\Plugin::BK_SCOPE] ) ? (string) $booking[\TC_BF\Plugin::BK_SCOPE] : '';

		// Determine role: participation = parent, rental = child
		$role = self::ROLE_PARENT;
		if ( $scope === 'rental' ) {
			$role = self::ROLE_CHILD;
		}

		// Add pack metadata
		$cart_item_data[ self::META_GROUP_ID ]   = $entry_id;
		$cart_item_data[ self::META_GROUP_ROLE ] = $role;
		$cart_item_data[ self::META_SCOPE ]      = $scope;

		\TC_BF\Support\Logger::log( 'pack.metadata.added', [
			'entry_id'   => $entry_id,
			'product_id' => $product_id,
			'scope'      => $scope,
			'role'       => $role,
		] );

		return $cart_item_data;
	}

	/**
	 * Restore pack metadata from cart session
	 *
	 * @param array $cart_item Session cart item data
	 * @param array $values    Stored values
	 * @return array Cart item with metadata restored
	 */
	public static function get_pack_metadata_from_session( array $cart_item, array $values ) : array {

		// Restore pack metadata if it exists
		if ( isset( $values[ self::META_GROUP_ID ] ) ) {
			$cart_item[ self::META_GROUP_ID ] = $values[ self::META_GROUP_ID ];
		}
		if ( isset( $values[ self::META_GROUP_ROLE ] ) ) {
			$cart_item[ self::META_GROUP_ROLE ] = $values[ self::META_GROUP_ROLE ];
		}
		if ( isset( $values[ self::META_SCOPE ] ) ) {
			$cart_item[ self::META_SCOPE ] = $values[ self::META_SCOPE ];
		}

		return $cart_item;
	}

	/**
	 * Atomic removal: Remove all items in a pack when any one is removed
	 *
	 * Guards against recursion and prevents removal during checkout completion.
	 *
	 * @param string $cart_item_key The cart item key being removed
	 * @param object $cart          WC_Cart instance
	 */
	public static function atomic_remove_pack_items( string $cart_item_key, $cart ) : void {

		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		// Get the cart item being removed (still available at this hook)
		$cart_contents = $cart->get_cart();
		if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$removed_item = $cart_contents[ $cart_item_key ];
		$group_id = isset( $removed_item[ self::META_GROUP_ID ] ) ? $removed_item[ self::META_GROUP_ID ] : null;

		// No group ID = not a pack item
		if ( ! $group_id ) {
			return;
		}

		// Guard against recursion: if we're already processing this group, skip
		if ( isset( self::$processing_groups[ $group_id ] ) ) {
			return;
		}

		// Mark this group as being processed
		self::$processing_groups[ $group_id ] = true;

		\TC_BF\Support\Logger::log( 'pack.atomic_remove.start', [
			'group_id'       => $group_id,
			'cart_item_key'  => $cart_item_key,
		] );

		// Find and remove all other items in this pack
		$removed_count = 0;
		foreach ( $cart_contents as $key => $item ) {
			// Skip the item that's already being removed
			if ( $key === $cart_item_key ) {
				continue;
			}

			$item_group_id = isset( $item[ self::META_GROUP_ID ] ) ? $item[ self::META_GROUP_ID ] : null;

			// If this item belongs to the same group, remove it
			if ( $item_group_id === $group_id ) {
				$cart->remove_cart_item( $key );
				$removed_count++;

				\TC_BF\Support\Logger::log( 'pack.atomic_remove.sibling', [
					'group_id'      => $group_id,
					'sibling_key'   => $key,
					'scope'         => $item[ self::META_SCOPE ] ?? '',
				] );
			}
		}

		\TC_BF\Support\Logger::log( 'pack.atomic_remove.complete', [
			'group_id'      => $group_id,
			'removed_count' => $removed_count,
		] );

		// Clear the processing guard
		unset( self::$processing_groups[ $group_id ] );
	}

	/**
	 * Handle cart emptied event
	 *
	 * When the entire cart is emptied, we need to identify all groups
	 * and potentially mark their GF entries as removed (Phase 2 integration).
	 */
	public static function handle_cart_emptied() : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$cart_contents = $cart->get_cart();

		if ( empty( $cart_contents ) ) {
			return;
		}

		$groups = [];
		foreach ( $cart_contents as $item ) {
			if ( isset( $item[ self::META_GROUP_ID ] ) ) {
				$groups[] = $item[ self::META_GROUP_ID ];
			}
		}

		$groups = array_unique( $groups );

		\TC_BF\Support\Logger::log( 'pack.cart_emptied', [
			'group_count' => count( $groups ),
			'groups'      => $groups,
		] );

		// Phase 2 will integrate with Entry_State here
		// Entry_State::mark_groups_removed( $groups, 'cart_emptied' );
	}

	/**
	 * Persist pack metadata to order items
	 *
	 * @param object $item          Order item
	 * @param string $cart_item_key Cart item key
	 * @param array  $values        Cart item values
	 * @param object $order         WC_Order instance
	 */
	public static function add_pack_metadata_to_order_item( $item, string $cart_item_key, array $values, $order ) : void {

		if ( ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		// Copy pack metadata from cart item to order item
		if ( isset( $values[ self::META_GROUP_ID ] ) ) {
			$item->add_meta_data( self::META_GROUP_ID, $values[ self::META_GROUP_ID ], true );
		}

		if ( isset( $values[ self::META_GROUP_ROLE ] ) ) {
			$item->add_meta_data( self::META_GROUP_ROLE, $values[ self::META_GROUP_ROLE ], true );
		}

		if ( isset( $values[ self::META_SCOPE ] ) ) {
			$item->add_meta_data( self::META_SCOPE, $values[ self::META_SCOPE ], true );
		}

		\TC_BF\Support\Logger::log( 'pack.order_meta.added', [
			'order_id'  => method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
			'group_id'  => $values[ self::META_GROUP_ID ] ?? null,
			'role'      => $values[ self::META_GROUP_ROLE ] ?? null,
		] );
	}

	/**
	 * Hide remove button on child items (visual polish)
	 *
	 * Only the parent item shows a remove button. Removing the parent
	 * automatically removes the child via atomic removal.
	 *
	 * @param string $link          Remove link HTML
	 * @param string $cart_item_key Cart item key
	 * @return string Modified or empty link
	 */
	public static function hide_child_remove_button( string $link, string $cart_item_key ) : string {

		if ( ! WC() || ! WC()->cart ) {
			return $link;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! $cart_item ) {
			return $link;
		}

		$role = isset( $cart_item[ self::META_GROUP_ROLE ] ) ? $cart_item[ self::META_GROUP_ROLE ] : '';

		// Hide remove button for child items
		if ( $role === self::ROLE_CHILD ) {
			return '';
		}

		return $link;
	}

	/**
	 * Add visual indicators to cart items (e.g., "Included in pack")
	 *
	 * @param array $item_data Existing item data
	 * @param array $cart_item Cart item
	 * @return array Modified item data
	 */
	public static function add_pack_visual_indicators( array $item_data, array $cart_item ) : array {

		$role = isset( $cart_item[ self::META_GROUP_ROLE ] ) ? $cart_item[ self::META_GROUP_ROLE ] : '';
		$group_id = isset( $cart_item[ self::META_GROUP_ID ] ) ? $cart_item[ self::META_GROUP_ID ] : '';

		// Add "Included" label for child items
		if ( $role === self::ROLE_CHILD ) {
			$item_data[] = [
				'name'    => '',
				'value'   => '<em>' . __( 'Included in pack', 'tc-booking-flow' ) . '</em>',
				'display' => '',
			];
		}

		return $item_data;
	}

	/**
	 * Validate pack integrity before checkout
	 *
	 * Ensures that if a rental exists, its corresponding participation also exists.
	 * Prevents checkout with orphaned rental items.
	 */
	public static function validate_pack_integrity() : void {

		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$cart_contents = $cart->get_cart();

		// Group items by group_id
		$groups = [];
		foreach ( $cart_contents as $item ) {
			$group_id = isset( $item[ self::META_GROUP_ID ] ) ? $item[ self::META_GROUP_ID ] : null;
			$scope = isset( $item[ self::META_SCOPE ] ) ? $item[ self::META_SCOPE ] : '';

			if ( $group_id ) {
				if ( ! isset( $groups[ $group_id ] ) ) {
					$groups[ $group_id ] = [];
				}
				$groups[ $group_id ][] = $scope;
			}
		}

		// Validate each group
		foreach ( $groups as $group_id => $scopes ) {
			// If rental exists, participation must also exist
			if ( in_array( 'rental', $scopes, true ) && ! in_array( 'participation', $scopes, true ) ) {
				wc_add_notice(
					__( 'Invalid booking configuration detected. Please remove the incomplete booking and try again.', 'tc-booking-flow' ),
					'error'
				);

				\TC_BF\Support\Logger::log( 'pack.validation.failed', [
					'group_id' => $group_id,
					'scopes'   => $scopes,
					'reason'   => 'rental without participation',
				] );
			}
		}
	}

	/**
	 * Lock pack item quantities to 1 (non-editable display)
	 *
	 * @param string $product_quantity Quantity HTML
	 * @param string $cart_item_key    Cart item key
	 * @param array  $cart_item        Cart item
	 * @return string Modified quantity HTML
	 */
	public static function lock_pack_quantity( string $product_quantity, string $cart_item_key, array $cart_item ) : string {

		// If this is a pack item, make quantity non-editable
		if ( isset( $cart_item[ self::META_GROUP_ID ] ) ) {
			$quantity = isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;
			return '<span class="quantity">' . $quantity . '</span>';
		}

		return $product_quantity;
	}

	/**
	 * Prevent quantity updates for pack items
	 *
	 * @param bool   $passed         Validation status
	 * @param string $cart_item_key  Cart item key
	 * @param array  $values         Cart item values
	 * @param int    $quantity       New quantity
	 * @return bool Validation status
	 */
	public static function prevent_pack_quantity_update( bool $passed, string $cart_item_key, array $values, int $quantity ) : bool {

		// If this is a pack item and quantity is being changed, prevent it
		if ( isset( $values[ self::META_GROUP_ID ] ) && $quantity != $values['quantity'] ) {
			wc_add_notice(
				__( 'Pack item quantities cannot be changed.', 'tc-booking-flow' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Get all cart items belonging to a specific group
	 *
	 * @param int $group_id Group ID (entry ID)
	 * @return array Array of cart item keys and data
	 */
	public static function get_group_items( int $group_id ) : array {

		if ( ! WC() || ! WC()->cart ) {
			return [];
		}

		$items = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$item_group_id = isset( $item[ self::META_GROUP_ID ] ) ? (int) $item[ self::META_GROUP_ID ] : 0;
			if ( $item_group_id === $group_id ) {
				$items[ $key ] = $item;
			}
		}

		return $items;
	}
}
