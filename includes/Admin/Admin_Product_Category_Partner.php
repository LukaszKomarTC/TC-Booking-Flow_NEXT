<?php
namespace TC_BF\Admin;

use TC_BF\Domain\ProductPartnerConfig;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Product Category Partner Settings
 *
 * Adds Partner program enable/disable toggle to WooCommerce product category edit screens.
 * Uses term meta to control whether the partner discount system is available per category.
 *
 * @since TCBF-14
 */
final class Admin_Product_Category_Partner {

	/**
	 * Initialize hooks
	 */
	public static function init() : void {
		// Add fields to product_cat taxonomy edit form
		add_action( 'product_cat_add_form_fields', [ __CLASS__, 'render_add_fields' ] );
		add_action( 'product_cat_edit_form_fields', [ __CLASS__, 'render_edit_fields' ], 10, 1 );

		// Save term meta
		add_action( 'created_product_cat', [ __CLASS__, 'save_fields' ], 10, 1 );
		add_action( 'edited_product_cat', [ __CLASS__, 'save_fields' ], 10, 1 );

		// Add column to category list
		add_filter( 'manage_edit-product_cat_columns', [ __CLASS__, 'add_column' ] );
		add_filter( 'manage_product_cat_custom_column', [ __CLASS__, 'render_column' ], 10, 3 );
	}

	/**
	 * Render fields for "Add New Category" form
	 */
	public static function render_add_fields() : void {
		$default = ProductPartnerConfig::get_global_default();
		?>
		<div class="form-field">
			<label for="tcbf_partners_enabled">
				<input type="checkbox" name="tcbf_partners_enabled" id="tcbf_partners_enabled" value="1" <?php checked( $default ); ?> />
				<?php esc_html_e( 'Enable Partner Program', 'tc-booking-flow-next' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Allow partner discounts for booking products in this category.', 'tc-booking-flow-next' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render fields for "Edit Category" form
	 *
	 * @param \WP_Term $term Current term object
	 */
	public static function render_edit_fields( $term ) : void {
		$enabled = ProductPartnerConfig::category_partners_enabled( $term->term_id );
		$meta_value = get_term_meta( $term->term_id, ProductPartnerConfig::TERM_META_PARTNERS_ENABLED, true );
		$is_inherited = $meta_value === '';
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="tcbf_partners_enabled"><?php esc_html_e( 'Partner Program', 'tc-booking-flow-next' ); ?></label>
			</th>
			<td>
				<label>
					<input type="hidden" name="tcbf_partners_enabled_set" value="1" />
					<input type="checkbox" name="tcbf_partners_enabled" id="tcbf_partners_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable partner discounts for booking products in this category', 'tc-booking-flow-next' ); ?>
				</label>
				<?php if ( $is_inherited ) : ?>
					<p class="description" style="color: #666;">
						<?php
						$global = ProductPartnerConfig::get_global_default();
						printf(
							/* translators: %s: enabled or disabled */
							esc_html__( 'Currently using global default: %s', 'tc-booking-flow-next' ),
							$global ? '<strong>' . esc_html__( 'Enabled', 'tc-booking-flow-next' ) . '</strong>' : '<strong>' . esc_html__( 'Disabled', 'tc-booking-flow-next' ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'When disabled, partner discounts will not be available for booking products in this category. The partner override dropdown and partner discount display will be hidden.', 'tc-booking-flow-next' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term meta
	 *
	 * @param int $term_id Term ID
	 */
	public static function save_fields( int $term_id ) : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check if this is an AJAX request (handled separately)
		if ( wp_doing_ajax() ) {
			return;
		}

		// Only process if our hidden field is set (indicates form was submitted)
		if ( ! isset( $_POST['tcbf_partners_enabled_set'] ) ) {
			return;
		}

		$enabled = isset( $_POST['tcbf_partners_enabled'] ) && $_POST['tcbf_partners_enabled'] === '1';

		ProductPartnerConfig::set_category_partners_enabled( $term_id, $enabled );
	}

	/**
	 * Add Partner column to category list
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public static function add_column( array $columns ) : array {
		$columns['tcbf_partners'] = __( 'Partners', 'tc-booking-flow-next' );
		return $columns;
	}

	/**
	 * Render Partner column content
	 *
	 * @param string $content Column content
	 * @param string $column_name Column name
	 * @param int $term_id Term ID
	 * @return string Modified content
	 */
	public static function render_column( string $content, string $column_name, int $term_id ) : string {
		if ( $column_name !== 'tcbf_partners' ) {
			return $content;
		}

		$enabled = ProductPartnerConfig::category_partners_enabled( $term_id );
		$meta_value = get_term_meta( $term_id, ProductPartnerConfig::TERM_META_PARTNERS_ENABLED, true );
		$is_inherited = $meta_value === '';

		if ( $enabled ) {
			$icon = '<span style="color: #00a32a;">&#10003;</span>';
			$text = $is_inherited
				? '<span style="color: #666;">' . esc_html__( '(default)', 'tc-booking-flow-next' ) . '</span>'
				: '';
			return $icon . ' ' . $text;
		} else {
			$icon = '<span style="color: #d63638;">&#10007;</span>';
			$text = $is_inherited
				? '<span style="color: #666;">' . esc_html__( '(default)', 'tc-booking-flow-next' ) . '</span>'
				: esc_html__( 'Disabled', 'tc-booking-flow-next' );
			return $icon . ' ' . $text;
		}
	}
}
