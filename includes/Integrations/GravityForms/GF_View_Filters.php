<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * GravityView Filters — Show only paid participants in views
 *
 * Handles:
 * - Filter GravityView queries to show only entries with tcbf_state = 'paid'
 * - Filter GFAPI queries for participant lists
 * - Provide admin UI to toggle filtering per view
 * - Support for manual GFAPI calls with state filtering
 *
 * Key concept: "WooCommerce order is source of truth"
 * Only entries that have been paid should appear as participants.
 */
final class GF_View_Filters {

	/**
	 * Meta key to enable filtering on a specific view
	 */
	const VIEW_META_ENABLE_FILTER = 'tcbf_filter_paid_only';

	/**
	 * Initialize GravityView filters
	 */
	public static function init() : void {

		// Filter GravityView search criteria (if GravityView is active)
		add_filter( 'gravityview_search_criteria', [ __CLASS__, 'filter_gravityview_paid_only' ], 10, 3 );

		// Filter GFAPI entries query (for custom participant lists)
		add_filter( 'gform_get_entries_args', [ __CLASS__, 'filter_gfapi_paid_only' ], 10, 2 );

		// Add admin UI to GravityView settings (if admin and GV active)
		if ( is_admin() ) {
			add_action( 'gravityview/metaboxes/edit', [ __CLASS__, 'add_view_settings_meta_box' ], 10, 1 );
			add_action( 'save_post', [ __CLASS__, 'save_view_settings' ], 10, 2 );
		}
	}

	/**
	 * Filter GravityView search criteria to show only paid entries
	 *
	 * This runs when GravityView queries entries for display.
	 *
	 * @param array  $search_criteria Current search criteria
	 * @param int    $form_id         GF form ID
	 * @param object $view            GravityView object
	 * @return array Modified search criteria
	 */
	public static function filter_gravityview_paid_only( array $search_criteria, int $form_id, $view ) : array {

		// Check if filtering is enabled for this view
		if ( ! self::is_filtering_enabled_for_view( $view ) ) {
			return $search_criteria;
		}

		// Add tcbf_state = 'paid' to field filters
		if ( ! isset( $search_criteria['field_filters'] ) || ! is_array( $search_criteria['field_filters'] ) ) {
			$search_criteria['field_filters'] = [];
		}

		// Ensure mode is set
		if ( ! isset( $search_criteria['field_filters']['mode'] ) ) {
			$search_criteria['field_filters']['mode'] = 'all';
		}

		// Add paid state filter
		$search_criteria['field_filters'][] = [
			'key'   => \TC_BF\Domain\Entry_State::META_STATE,
			'value' => \TC_BF\Domain\Entry_State::STATE_PAID,
		];

		\TC_BF\Support\Logger::log( 'gf_view.filter_applied', [
			'form_id' => $form_id,
			'view_id' => method_exists( $view, 'get_view_id' ) ? $view->get_view_id() : 0,
			'filter'  => 'paid_only',
		] );

		return $search_criteria;
	}

	/**
	 * Filter GFAPI queries to show only paid entries
	 *
	 * Applies to manual GFAPI::get_entries() calls in custom code.
	 *
	 * @param array $args    Query args
	 * @param int   $form_id GF form ID
	 * @return array Modified args
	 */
	public static function filter_gfapi_paid_only( array $args, int $form_id ) : array {

		// Only filter if explicitly requested via apply_filters context
		// This prevents breaking non-participant queries
		if ( ! apply_filters( 'tcbf_enable_gfapi_paid_filter', false, $form_id, $args ) ) {
			return $args;
		}

		// Add tcbf_state = 'paid' to search criteria
		if ( ! isset( $args['search_criteria'] ) || ! is_array( $args['search_criteria'] ) ) {
			$args['search_criteria'] = [];
		}

		if ( ! isset( $args['search_criteria']['field_filters'] ) || ! is_array( $args['search_criteria']['field_filters'] ) ) {
			$args['search_criteria']['field_filters'] = [];
		}

		if ( ! isset( $args['search_criteria']['field_filters']['mode'] ) ) {
			$args['search_criteria']['field_filters']['mode'] = 'all';
		}

		$args['search_criteria']['field_filters'][] = [
			'key'   => \TC_BF\Domain\Entry_State::META_STATE,
			'value' => \TC_BF\Domain\Entry_State::STATE_PAID,
		];

		\TC_BF\Support\Logger::log( 'gf_api.filter_applied', [
			'form_id' => $form_id,
			'filter'  => 'paid_only',
		] );

		return $args;
	}

	/**
	 * Check if filtering is enabled for a GravityView view
	 *
	 * @param object $view GravityView object
	 * @return bool Filtering enabled
	 */
	private static function is_filtering_enabled_for_view( $view ) : bool {

		if ( ! is_object( $view ) ) {
			return false;
		}

		// Get view ID
		$view_id = 0;
		if ( method_exists( $view, 'get_view_id' ) ) {
			$view_id = (int) $view->get_view_id();
		} elseif ( method_exists( $view, 'ID' ) ) {
			$view_id = (int) $view->ID;
		} elseif ( isset( $view->ID ) ) {
			$view_id = (int) $view->ID;
		}

		if ( $view_id <= 0 ) {
			// If we can't determine view ID, apply filter by default for safety
			return true;
		}

		// Check view meta
		$enabled = get_post_meta( $view_id, self::VIEW_META_ENABLE_FILTER, true );

		// Default: enabled (show only paid participants)
		// Can be disabled per view if needed for admin purposes
		return $enabled !== 'no';
	}

	/**
	 * Add settings meta box to GravityView edit screen
	 *
	 * Allows admins to toggle paid-only filtering per view.
	 *
	 * @param object $view GravityView object
	 */
	public static function add_view_settings_meta_box( $view ) : void {

		if ( ! is_object( $view ) || ! method_exists( $view, 'get_view_id' ) ) {
			return;
		}

		add_meta_box(
			'tcbf_view_filters',
			__( 'TC Booking Flow — Participant Filters', 'tc-booking-flow' ),
			[ __CLASS__, 'render_view_settings_meta_box' ],
			'gravityview',
			'side',
			'default'
		);
	}

	/**
	 * Render settings meta box content
	 *
	 * @param object $post Post object (GravityView)
	 */
	public static function render_view_settings_meta_box( $post ) : void {

		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}

		$view_id = (int) $post->ID;
		$enabled = get_post_meta( $view_id, self::VIEW_META_ENABLE_FILTER, true );

		// Default: enabled
		if ( $enabled === '' ) {
			$enabled = 'yes';
		}

		wp_nonce_field( 'tcbf_view_settings', 'tcbf_view_settings_nonce' );

		?>
		<p>
			<label>
				<input type="checkbox"
					   name="tcbf_filter_paid_only"
					   value="yes"
					   <?php checked( $enabled, 'yes' ); ?> />
				<?php esc_html_e( 'Show only paid participants', 'tc-booking-flow' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'When enabled, this view will only display entries that have completed payment. Entries in cart, removed, or expired will be hidden.', 'tc-booking-flow' ); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e( 'Recommended:', 'tc-booking-flow' ); ?></strong>
			<?php esc_html_e( 'Keep this enabled for participant lists. Disable only for admin/debugging views.', 'tc-booking-flow' ); ?>
		</p>
		<?php
	}

	/**
	 * Save view settings
	 *
	 * @param int    $post_id Post ID
	 * @param object $post    Post object
	 */
	public static function save_view_settings( int $post_id, $post ) : void {

		// Verify nonce
		if ( ! isset( $_POST['tcbf_view_settings_nonce'] ) || ! wp_verify_nonce( $_POST['tcbf_view_settings_nonce'], 'tcbf_view_settings' ) ) {
			return;
		}

		// Check post type
		if ( ! $post || $post->post_type !== 'gravityview' ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save setting
		$enabled = isset( $_POST['tcbf_filter_paid_only'] ) && $_POST['tcbf_filter_paid_only'] === 'yes' ? 'yes' : 'no';
		update_post_meta( $post_id, self::VIEW_META_ENABLE_FILTER, $enabled );

		\TC_BF\Support\Logger::log( 'gf_view.settings_saved', [
			'view_id'          => $post_id,
			'filter_paid_only' => $enabled,
		] );
	}

	/**
	 * Helper: Get paid participants for a form
	 *
	 * Convenience method for custom code.
	 *
	 * @param int   $form_id    GF form ID
	 * @param int   $page_size  Results per page
	 * @param int   $offset     Offset
	 * @param array $extra_criteria Additional search criteria
	 * @return array Array of entries
	 */
	public static function get_paid_participants( int $form_id, int $page_size = 50, int $offset = 0, array $extra_criteria = [] ) : array {

		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		// Build search criteria
		$search_criteria = array_merge([
			'field_filters' => [
				'mode' => 'all',
				[
					'key'   => \TC_BF\Domain\Entry_State::META_STATE,
					'value' => \TC_BF\Domain\Entry_State::STATE_PAID,
				],
			],
		], $extra_criteria);

		$sorting = [
			'key'        => 'date_created',
			'direction'  => 'DESC',
		];

		$paging = [
			'offset'    => $offset,
			'page_size' => $page_size,
		];

		$entries = \GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

		if ( is_wp_error( $entries ) ) {
			\TC_BF\Support\Logger::log( 'gf_view.query_error', [
				'form_id' => $form_id,
				'error'   => $entries->get_error_message(),
			] );
			return [];
		}

		return is_array( $entries ) ? $entries : [];
	}
}
