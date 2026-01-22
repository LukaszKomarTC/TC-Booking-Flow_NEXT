<?php
namespace TC_BF\Admin;

use TC_BF\Domain\ProductEBConfig;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Product Category EB Settings
 *
 * Adds Early Booking discount configuration to WooCommerce product category edit screens.
 * Uses term meta to store EB rules per product category.
 */
final class Admin_Product_Category_EB {

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

		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

		// AJAX handlers
		add_action( 'wp_ajax_tcbf_save_category_eb', [ __CLASS__, 'ajax_save' ] );
	}

	/**
	 * Render fields for "Add New Category" form
	 */
	public static function render_add_fields() : void {
		?>
		<div class="form-field">
			<label for="tcbf_eb_enabled">
				<input type="checkbox" name="tcbf_eb_enabled" id="tcbf_eb_enabled" value="1" />
				<?php esc_html_e( 'Enable Early Booking Discount', 'tc-booking-flow-next' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Enable EB discounts for booking products in this category.', 'tc-booking-flow-next' ); ?>
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
		$config = ProductEBConfig::get_category_config( $term->term_id );
		$enabled = ! empty( $config['enabled'] );
		$steps = $config['steps'] ?? [];
		$global_cap = $config['global_cap'] ?? [ 'enabled' => false, 'amount' => 0 ];
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="tcbf_eb_enabled"><?php esc_html_e( 'Early Booking Discount', 'tc-booking-flow-next' ); ?></label>
			</th>
			<td>
				<label>
					<input type="checkbox" name="tcbf_eb_enabled" id="tcbf_eb_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable EB discounts for booking products in this category', 'tc-booking-flow-next' ); ?>
				</label>
			</td>
		</tr>

		<tr class="form-field tcbf-eb-rules-row" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
			<th scope="row">
				<label><?php esc_html_e( 'EB Discount Steps', 'tc-booking-flow-next' ); ?></label>
			</th>
			<td>
				<div id="tcbf-eb-steps-container">
					<?php
					if ( empty( $steps ) ) {
						// Default step template
						self::render_step_row( 0, [ 'min_days_before' => 30, 'type' => 'percent', 'value' => 5, 'cap' => [ 'enabled' => false, 'amount' => 0 ] ] );
					} else {
						foreach ( $steps as $i => $step ) {
							self::render_step_row( $i, $step );
						}
					}
					?>
				</div>
				<p>
					<button type="button" class="button" id="tcbf-add-eb-step">
						<?php esc_html_e( '+ Add Step', 'tc-booking-flow-next' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Define discount tiers based on how many days before the booking start date. Steps are evaluated from highest days to lowest.', 'tc-booking-flow-next' ); ?>
				</p>
			</td>
		</tr>

		<tr class="form-field tcbf-eb-rules-row" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
			<th scope="row">
				<label><?php esc_html_e( 'Global Cap', 'tc-booking-flow-next' ); ?></label>
			</th>
			<td>
				<label>
					<input type="checkbox" name="tcbf_eb_global_cap_enabled" value="1" <?php checked( ! empty( $global_cap['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable global discount cap', 'tc-booking-flow-next' ); ?>
				</label>
				<br><br>
				<label>
					<?php esc_html_e( 'Max discount amount:', 'tc-booking-flow-next' ); ?>
					<input type="number" name="tcbf_eb_global_cap_amount" value="<?php echo esc_attr( (string) ( $global_cap['amount'] ?? 0 ) ); ?>" min="0" step="0.01" class="small-text" />
					<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Maximum EB discount amount regardless of percentage calculation.', 'tc-booking-flow-next' ); ?>
				</p>
			</td>
		</tr>

		<script type="text/html" id="tmpl-tcbf-eb-step">
			<?php self::render_step_row( '{{data.index}}', [ 'min_days_before' => 30, 'type' => 'percent', 'value' => 5, 'cap' => [ 'enabled' => false, 'amount' => 0 ] ], true ); ?>
		</script>
		<?php
	}

	/**
	 * Render a single EB step row
	 *
	 * @param int|string $index Row index
	 * @param array $step Step configuration
	 * @param bool $is_template Whether this is a template (for JS)
	 */
	private static function render_step_row( $index, array $step, bool $is_template = false ) : void {
		$prefix = "tcbf_eb_steps[{$index}]";
		$min_days = $step['min_days_before'] ?? 30;
		$type = $step['type'] ?? 'percent';
		$value = $step['value'] ?? 5;
		$cap_enabled = ! empty( $step['cap']['enabled'] );
		$cap_amount = $step['cap']['amount'] ?? 0;
		?>
		<div class="tcbf-eb-step" data-index="<?php echo esc_attr( (string) $index ); ?>" style="background: #f9f9f9; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd;">
			<div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
				<label>
					<?php esc_html_e( 'Days before:', 'tc-booking-flow-next' ); ?>
					<input type="number" name="<?php echo esc_attr( $prefix ); ?>[min_days_before]" value="<?php echo esc_attr( (string) $min_days ); ?>" min="0" step="1" class="small-text" />
				</label>

				<label>
					<?php esc_html_e( 'Type:', 'tc-booking-flow-next' ); ?>
					<select name="<?php echo esc_attr( $prefix ); ?>[type]">
						<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percent', 'tc-booking-flow-next' ); ?></option>
						<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'tc-booking-flow-next' ); ?></option>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Value:', 'tc-booking-flow-next' ); ?>
					<input type="number" name="<?php echo esc_attr( $prefix ); ?>[value]" value="<?php echo esc_attr( (string) $value ); ?>" min="0" step="0.01" class="small-text" />
				</label>

				<label>
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[cap_enabled]" value="1" <?php checked( $cap_enabled ); ?> />
					<?php esc_html_e( 'Cap:', 'tc-booking-flow-next' ); ?>
					<input type="number" name="<?php echo esc_attr( $prefix ); ?>[cap_amount]" value="<?php echo esc_attr( (string) $cap_amount ); ?>" min="0" step="0.01" class="small-text" />
					<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
				</label>

				<button type="button" class="button tcbf-remove-step" style="color: #a00;">
					<?php esc_html_e( 'Remove', 'tc-booking-flow-next' ); ?>
				</button>
			</div>
		</div>
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

		$enabled = isset( $_POST['tcbf_eb_enabled'] ) && $_POST['tcbf_eb_enabled'] === '1';

		// Parse steps
		$steps = [];
		if ( isset( $_POST['tcbf_eb_steps'] ) && is_array( $_POST['tcbf_eb_steps'] ) ) {
			foreach ( $_POST['tcbf_eb_steps'] as $step_data ) {
				if ( ! is_array( $step_data ) ) continue;

				$min_days = isset( $step_data['min_days_before'] ) ? absint( $step_data['min_days_before'] ) : 0;
				$type = isset( $step_data['type'] ) && $step_data['type'] === 'fixed' ? 'fixed' : 'percent';
				$value = isset( $step_data['value'] ) ? (float) $step_data['value'] : 0;

				if ( $value <= 0 ) continue;

				$cap = [
					'enabled' => ! empty( $step_data['cap_enabled'] ),
					'amount'  => isset( $step_data['cap_amount'] ) ? (float) $step_data['cap_amount'] : 0,
				];

				$steps[] = [
					'min_days_before' => $min_days,
					'type'            => $type,
					'value'           => $value,
					'cap'             => $cap,
				];
			}
		}

		// Global cap
		$global_cap = [
			'enabled' => ! empty( $_POST['tcbf_eb_global_cap_enabled'] ),
			'amount'  => isset( $_POST['tcbf_eb_global_cap_amount'] ) ? (float) $_POST['tcbf_eb_global_cap_amount'] : 0,
		];

		ProductEBConfig::save_category_config( $term_id, [
			'enabled'    => $enabled,
			'steps'      => $steps,
			'global_cap' => $global_cap,
		] );
	}

	/**
	 * Add EB column to category list
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public static function add_column( array $columns ) : array {
		$columns['tcbf_eb'] = __( 'EB Discount', 'tc-booking-flow-next' );
		return $columns;
	}

	/**
	 * Render EB column content
	 *
	 * @param string $content Column content
	 * @param string $column_name Column name
	 * @param int $term_id Term ID
	 * @return string Modified content
	 */
	public static function render_column( string $content, string $column_name, int $term_id ) : string {
		if ( $column_name !== 'tcbf_eb' ) {
			return $content;
		}

		$config = ProductEBConfig::get_category_config( $term_id );

		if ( empty( $config['enabled'] ) ) {
			return '<span style="color: #999;">&#8212;</span>';
		}

		$steps = $config['steps'] ?? [];
		if ( empty( $steps ) ) {
			return '<span style="color: #d63638;">&#9888; ' . esc_html__( 'No steps', 'tc-booking-flow-next' ) . '</span>';
		}

		// Show summary
		$summary = [];
		foreach ( $steps as $step ) {
			$value = $step['type'] === 'percent'
				? $step['value'] . '%'
				: get_woocommerce_currency_symbol() . $step['value'];
			$summary[] = $step['min_days_before'] . 'd: ' . $value;
		}

		return '<span style="color: #00a32a;">&#10003;</span> ' . esc_html( implode( ', ', array_slice( $summary, 0, 2 ) ) );
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Admin page hook
	 */
	public static function enqueue_scripts( string $hook ) : void {
		if ( ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) {
			return;
		}

		if ( ! isset( $_GET['taxonomy'] ) || $_GET['taxonomy'] !== 'product_cat' ) {
			return;
		}

		// Inline script for step management
		$script = <<<JS
jQuery(function($) {
	// Toggle rules visibility
	$('#tcbf_eb_enabled').on('change', function() {
		$('.tcbf-eb-rules-row').toggle(this.checked);
	});

	// Add step
	$('#tcbf-add-eb-step').on('click', function() {
		var container = $('#tcbf-eb-steps-container');
		var index = container.find('.tcbf-eb-step').length;
		var template = $('#tmpl-tcbf-eb-step').html();
		template = template.replace(/\{\{data\.index\}\}/g, index);
		container.append(template);
	});

	// Remove step
	$(document).on('click', '.tcbf-remove-step', function() {
		$(this).closest('.tcbf-eb-step').remove();
		// Reindex remaining steps
		$('#tcbf-eb-steps-container .tcbf-eb-step').each(function(i) {
			$(this).attr('data-index', i);
			$(this).find('input, select').each(function() {
				var name = $(this).attr('name');
				if (name) {
					$(this).attr('name', name.replace(/tcbf_eb_steps\[\d+\]/, 'tcbf_eb_steps[' + i + ']'));
				}
			});
		});
	});
});
JS;

		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * AJAX handler for saving category EB settings
	 */
	public static function ajax_save() : void {
		check_ajax_referer( 'tcbf_save_category_eb', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( $term_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid term ID' ], 400 );
		}

		// Parse config from POST
		$config = json_decode( wp_unslash( $_POST['config'] ?? '{}' ), true );
		if ( ! is_array( $config ) ) {
			wp_send_json_error( [ 'message' => 'Invalid config' ], 400 );
		}

		$success = ProductEBConfig::save_category_config( $term_id, $config );

		if ( $success ) {
			wp_send_json_success( [ 'message' => 'Saved' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Failed to save' ], 500 );
		}
	}
}
