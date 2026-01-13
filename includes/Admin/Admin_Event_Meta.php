<?php
namespace TC_BF\Admin;

use TC_BF\Domain\EventMeta;

if ( ! defined('ABSPATH') ) exit;

/**
 * Consolidated Event Meta Box (TCBF-11)
 *
 * Replaces:
 * - Sc_Event_Extras meta box (pricing, rentals, header, content)
 * - Admin_Event_EB meta box (early booking rules)
 *
 * Tabs: Pricing / Rentals / Early Booking / Products / Header
 *
 * Uses canonical schema (tcbf_*) with automatic mirror-write to legacy keys.
 *
 * EB Rules: Saved in PURE legacy format [{days,pct}] for maximum compatibility.
 * Global cap: Saved as separate tc_ebd_cap meta key (EventConfig supports this).
 */
final class Admin_Event_Meta {

	const NONCE_KEY = 'tcbf_event_meta_nonce';

	public static function init() : void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'save_post_sc_event', [ __CLASS__, 'save_meta_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
	}

	public static function add_meta_box() : void {
		add_meta_box(
			'tcbf_event_meta',
			__( 'TC Booking Flow — Event Configuration', 'tc-booking-flow-next' ),
			[ __CLASS__, 'render_meta_box' ],
			'sc_event',
			'normal',
			'high'
		);
	}

	public static function render_meta_box( \WP_Post $post ) : void {
		wp_nonce_field( self::NONCE_KEY, self::NONCE_KEY );

		// Read all values using canonical-first + legacy fallback
		$get = function( string $key, $default = '' ) use ( $post ) {
			return EventMeta::get( $post->ID, $key, $default );
		};

		// Pricing
		$participation_price = $get( 'participation_price', '' );
		$member_price        = $get( 'member_price', '' );

		// Rentals
		$rental_price_road    = $get( 'rental_price_road', '' );
		$rental_price_mtb     = $get( 'rental_price_mtb', '' );
		$rental_price_ebike   = $get( 'rental_price_ebike', '' );
		$rental_price_gravel  = $get( 'rental_price_gravel', '' );
		$rental_default_class = $get( 'rental_default_class', '' );

		// Early Booking
		$eb_enabled               = $get( 'eb_enabled', '0' ) === '1';
		$eb_participation_enabled = $get( 'eb_participation_enabled', '1' );
		$eb_rental_enabled        = $get( 'eb_rental_enabled', '1' );
		$eb_rules_json            = $get( 'eb_rules_json', '' );

		// Parse EB rules (pure legacy format [{days,pct}])
		$eb_steps = [];
		if ( $eb_rules_json !== '' ) {
			$rules = json_decode( $eb_rules_json, true );
			if ( is_array( $rules ) ) {
				// Support both legacy array and schema v1
				if ( isset( $rules['steps'] ) ) {
					// Schema v1 - extract steps and convert to {days,pct}
					foreach ( $rules['steps'] as $s ) {
						$eb_steps[] = [
							'days' => $s['min_days_before'] ?? $s['days'] ?? 0,
							'pct'  => $s['value'] ?? $s['pct'] ?? 0,
						];
					}
				} else {
					// Pure legacy [{days,pct}]
					$eb_steps = $rules;
				}
			}
		}

		// Default EB steps if none exist
		if ( empty( $eb_steps ) ) {
			$eb_steps = [
				[ 'days' => 90, 'pct' => 15 ],
				[ 'days' => 30, 'pct' => 5 ],
			];
		}

		// Global cap (separate meta key - legacy approach)
		$eb_global_cap = (string) get_post_meta( $post->ID, 'tc_ebd_cap', true );

		// Products
		$participation_product_id = absint( $get( 'participation_product_id', 0 ) );

		// Partners (TCBF-12)
		$partners_enabled = (string) $get( 'partners_enabled', '' );
		$partners_default = (bool) get_option( 'tcbf_partners_enabled_default', 1 );

		// Header Display
		$header_title_mode          = $get( 'header_title_mode', 'default' );
		$header_title_custom        = $get( 'header_title_custom', '' );
		$header_subtitle            = $get( 'header_subtitle', '' );
		$header_logo_mode           = $get( 'header_logo_mode', 'none' );
		$header_logo_id             = absint( $get( 'header_logo_id', 0 ) );
		$header_logo_url            = $get( 'header_logo_url', '' );
		$header_show_divider        = $get( 'header_show_divider', '1' );
		$header_show_back_link      = $get( 'header_show_back_link', '0' );
		$header_back_link_url       = $get( 'header_back_link_url', '' );
		$header_back_link_label     = $get( 'header_back_link_label', '' );
		$header_details_position    = $get( 'header_details_position', 'content' );
		$header_show_shopkeeper_meta = $get( 'header_show_shopkeeper_meta', '' );

		// Header CSS Variables
		$header_subtitle_size      = absint( $get( 'header_subtitle_size', 0 ) );
		$header_padding_bottom     = absint( $get( 'header_padding_bottom', 0 ) );
		$header_details_bottom     = absint( $get( 'header_details_bottom', 0 ) );
		$header_logo_margin_bottom = absint( $get( 'header_logo_margin_bottom', 0 ) );
		$header_logo_max_width     = absint( $get( 'header_logo_max_width', 0 ) );
		$header_title_max_size     = absint( $get( 'header_title_max_size', 0 ) );

		// Content/Display
		$feat_img     = $get( 'feat_img', '' );
		$inscription  = $get( 'inscription', 'No' );
		$participants = $get( 'participants', 'No' );

		// Logo preview
		$logo_preview = '';
		if ( $header_logo_id ) {
			$src = wp_get_attachment_image_url( $header_logo_id, 'medium' );
			if ( $src ) {
				$logo_preview = '<img src="' . esc_url( $src ) . '" style="max-width:200px;height:auto" alt="" />';
			}
		}

		?>
		<style>
			.tcbf-tabs { display: flex; gap: 0; border-bottom: 1px solid #c3c4c7; margin: 0 0 20px; }
			.tcbf-tabs button { background: #f0f0f1; border: 1px solid #c3c4c7; border-bottom: none; padding: 8px 16px; cursor: pointer; font-size: 13px; font-weight: 600; position: relative; top: 1px; }
			.tcbf-tabs button:hover { background: #fff; }
			.tcbf-tabs button.active { background: #fff; border-bottom: 1px solid #fff; z-index: 1; }
			.tcbf-tab-content { display: none; }
			.tcbf-tab-content.active { display: block; }
			.tcbf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 900px; }
			.tcbf-grid .wide { grid-column: 1 / -1; }
			.tcbf-grid label { font-weight: 600; display: block; margin-bottom: 4px; }
			.tcbf-grid input[type=text], .tcbf-grid input[type=number], .tcbf-grid select, .tcbf-grid textarea { width: 100%; }
			.tcbf-muted { opacity: 0.8; font-size: 12px; margin-top: 4px; }
			.tcbf-divider { margin: 16px 0; border-top: 1px solid #ddd; }
			.tcbf-flex { display: flex; gap: 8px; align-items: center; }
			.tcbf-flex > * { flex: 0 0 auto; }

			/* EB Rules Table */
			.tcbf-eb-rules-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
			.tcbf-eb-rules-table th { text-align: left; padding: 8px; background: #f0f0f1; border: 1px solid #c3c4c7; font-weight: 600; }
			.tcbf-eb-rules-table td { padding: 8px; border: 1px solid #ddd; }
			.tcbf-eb-rules-table input[type=number] { width: 100px; }
			.tcbf-eb-rules-table .tcbf-remove-row { color: #b32d2e; cursor: pointer; text-decoration: none; }
			.tcbf-eb-rules-table .tcbf-remove-row:hover { color: #dc3232; }
			.tcbf-add-eb-rule { margin-top: 8px; }
		</style>

		<div class="tcbf-tabs">
			<button type="button" class="tcbf-tab-btn active" data-tab="pricing"><?php esc_html_e( 'Pricing', 'tc-booking-flow-next' ); ?></button>
			<button type="button" class="tcbf-tab-btn" data-tab="rentals"><?php esc_html_e( 'Rentals', 'tc-booking-flow-next' ); ?></button>
			<button type="button" class="tcbf-tab-btn" data-tab="early-booking"><?php esc_html_e( 'Early Booking', 'tc-booking-flow-next' ); ?></button>
			<button type="button" class="tcbf-tab-btn" data-tab="products"><?php esc_html_e( 'Products', 'tc-booking-flow-next' ); ?></button>
			<button type="button" class="tcbf-tab-btn" data-tab="header"><?php esc_html_e( 'Header', 'tc-booking-flow-next' ); ?></button>
		</div>

		<!-- Pricing Tab -->
		<div class="tcbf-tab-content active" data-tab="pricing">
			<div class="tcbf-grid">
				<div>
					<label for="tcbf_participation_price"><?php esc_html_e( 'Participation Price (base)', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_participation_price" name="tcbf_participation_price" value="<?php echo esc_attr( $participation_price ); ?>" placeholder="<?php esc_attr_e( 'e.g. 20,00', 'tc-booking-flow-next' ); ?>" />
					<div class="tcbf-muted"><?php esc_html_e( 'Base price for event participation (decimal comma allowed).', 'tc-booking-flow-next' ); ?></div>
				</div>

				<div>
					<label for="tcbf_member_price"><?php esc_html_e( 'Member Price (optional)', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_member_price" name="tcbf_member_price" value="<?php echo esc_attr( $member_price ); ?>" placeholder="<?php esc_attr_e( 'e.g. 15,00', 'tc-booking-flow-next' ); ?>" />
					<div class="tcbf-muted"><?php esc_html_e( 'Special price for members (if applicable).', 'tc-booking-flow-next' ); ?></div>
				</div>

				<div class="wide tcbf-divider"></div>

				<div>
					<label for="tcbf_feat_img"><?php esc_html_e( 'Featured Header Image', 'tc-booking-flow-next' ); ?></label>
					<select id="tcbf_feat_img" name="tcbf_feat_img">
						<option value="" <?php selected( $feat_img, '' ); ?>><?php esc_html_e( 'Default (theme setting)', 'tc-booking-flow-next' ); ?></option>
						<option value="Yes" <?php selected( $feat_img, 'Yes' ); ?>><?php esc_html_e( 'Show', 'tc-booking-flow-next' ); ?></option>
						<option value="No" <?php selected( $feat_img, 'No' ); ?>><?php esc_html_e( 'Hide', 'tc-booking-flow-next' ); ?></option>
					</select>
				</div>

				<div>
					<label><?php esc_html_e( 'Append to Content', 'tc-booking-flow-next' ); ?></label>
					<label style="font-weight:400;display:block"><input type="checkbox" name="tcbf_inscription" value="Yes" <?php checked( $inscription, 'Yes' ); ?> /> <?php esc_html_e( 'Inscription form', 'tc-booking-flow-next' ); ?></label>
					<label style="font-weight:400;display:block"><input type="checkbox" name="tcbf_participants" value="Yes" <?php checked( $participants, 'Yes' ); ?> /> <?php esc_html_e( 'Participants list', 'tc-booking-flow-next' ); ?></label>
				</div>
			</div>
		</div>

		<!-- Rentals Tab -->
		<div class="tcbf-tab-content" data-tab="rentals">
			<div class="tcbf-grid">
				<div>
					<label for="tcbf_rental_price_road"><?php esc_html_e( 'Rental Price — Road', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_rental_price_road" name="tcbf_rental_price_road" value="<?php echo esc_attr( $rental_price_road ); ?>" placeholder="<?php esc_attr_e( 'e.g. 30,00', 'tc-booking-flow-next' ); ?>" />
				</div>

				<div>
					<label for="tcbf_rental_price_gravel"><?php esc_html_e( 'Rental Price — Gravel', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_rental_price_gravel" name="tcbf_rental_price_gravel" value="<?php echo esc_attr( $rental_price_gravel ); ?>" placeholder="<?php esc_attr_e( 'e.g. 30,00', 'tc-booking-flow-next' ); ?>" />
				</div>

				<div>
					<label for="tcbf_rental_price_mtb"><?php esc_html_e( 'Rental Price — MTB', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_rental_price_mtb" name="tcbf_rental_price_mtb" value="<?php echo esc_attr( $rental_price_mtb ); ?>" placeholder="<?php esc_attr_e( 'e.g. 30,00', 'tc-booking-flow-next' ); ?>" />
				</div>

				<div>
					<label for="tcbf_rental_price_ebike"><?php esc_html_e( 'Rental Price — eBike', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_rental_price_ebike" name="tcbf_rental_price_ebike" value="<?php echo esc_attr( $rental_price_ebike ); ?>" placeholder="<?php esc_attr_e( 'e.g. 30,00', 'tc-booking-flow-next' ); ?>" />
				</div>

				<div class="wide tcbf-divider"></div>

				<div class="wide">
					<label for="tcbf_rental_default_class"><?php esc_html_e( 'Default Rental Class (optional)', 'tc-booking-flow-next' ); ?></label>
					<select id="tcbf_rental_default_class" name="tcbf_rental_default_class">
						<option value="" <?php selected( $rental_default_class, '' ); ?>><?php esc_html_e( '— None —', 'tc-booking-flow-next' ); ?></option>
						<option value="road" <?php selected( $rental_default_class, 'road' ); ?>><?php esc_html_e( 'Road', 'tc-booking-flow-next' ); ?></option>
						<option value="gravel" <?php selected( $rental_default_class, 'gravel' ); ?>><?php esc_html_e( 'Gravel', 'tc-booking-flow-next' ); ?></option>
						<option value="mtb" <?php selected( $rental_default_class, 'mtb' ); ?>><?php esc_html_e( 'MTB', 'tc-booking-flow-next' ); ?></option>
						<option value="ebike" <?php selected( $rental_default_class, 'ebike' ); ?>><?php esc_html_e( 'eBike', 'tc-booking-flow-next' ); ?></option>
					</select>
					<div class="tcbf-muted"><?php esc_html_e( 'Used as the default selection in the form when applicable.', 'tc-booking-flow-next' ); ?></div>
				</div>
			</div>
		</div>

		<!-- Early Booking Tab -->
		<div class="tcbf-tab-content" data-tab="early-booking">
			<div class="tcbf-grid">
				<div class="wide">
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_eb_enabled" value="1" <?php checked( $eb_enabled ); ?> />
						<?php esc_html_e( 'Enable Early Booking discount for this event', 'tc-booking-flow-next' ); ?>
					</label>
					<div class="tcbf-muted"><?php esc_html_e( 'If disabled, Early Booking is not applied regardless of rules.', 'tc-booking-flow-next' ); ?></div>
				</div>

				<div>
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_eb_participation_enabled" value="1" <?php checked( $eb_participation_enabled, '1' ); ?> />
						<?php esc_html_e( 'Apply EB to participation', 'tc-booking-flow-next' ); ?>
					</label>
				</div>

				<div>
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_eb_rental_enabled" value="1" <?php checked( $eb_rental_enabled, '1' ); ?> />
						<?php esc_html_e( 'Apply EB to rentals', 'tc-booking-flow-next' ); ?>
					</label>
				</div>

				<div class="wide tcbf-divider"></div>

				<div class="wide">
					<label><?php esc_html_e( 'Early Booking Rules (days before → %)', 'tc-booking-flow-next' ); ?></label>
					<table class="tcbf-eb-rules-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Min days before', 'tc-booking-flow-next' ); ?></th>
								<th><?php esc_html_e( 'Discount %', 'tc-booking-flow-next' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'tc-booking-flow-next' ); ?></th>
							</tr>
						</thead>
						<tbody id="tcbf-eb-rules-body">
							<?php foreach ( $eb_steps as $step ) : ?>
								<tr>
									<td><input type="number" name="tcbf_eb_days[]" value="<?php echo esc_attr( (string) (int) ( $step['days'] ?? 0 ) ); ?>" min="0" required /></td>
									<td><input type="number" name="tcbf_eb_pct[]" value="<?php echo esc_attr( (string) (float) ( $step['pct'] ?? 0 ) ); ?>" min="0" max="100" step="0.01" required /></td>
									<td><a href="#" class="tcbf-remove-row"><?php esc_html_e( 'Remove', 'tc-booking-flow-next' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<button type="button" class="button tcbf-add-eb-rule"><?php esc_html_e( 'Add rule', 'tc-booking-flow-next' ); ?></button>

					<div class="tcbf-divider"></div>

					<label for="tcbf_eb_global_cap"><?php esc_html_e( 'Global EB cap (optional)', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_eb_global_cap" name="tcbf_eb_global_cap" value="<?php echo esc_attr( $eb_global_cap ); ?>" placeholder="<?php esc_attr_e( 'e.g. 25,00', 'tc-booking-flow-next' ); ?>" />
					<div class="tcbf-muted"><?php esc_html_e( 'Caps the EB discount amount (stored in legacy tc_ebd_cap).', 'tc-booking-flow-next' ); ?></div>
				</div>
			</div>
		</div>

		<!-- Products Tab -->
		<div class="tcbf-tab-content" data-tab="products">
			<div class="tcbf-grid">
				<div class="wide">
					<label for="tcbf_participation_product_id"><?php esc_html_e( 'Participation Product', 'tc-booking-flow-next' ); ?></label>
					<?php
					$products = get_posts([
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'posts_per_page' => 500,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'tax_query'      => [[
							'taxonomy' => 'product_type',
							'field'    => 'slug',
							'terms'    => ['booking'],
						]],
					]);
					?>
					<select id="tcbf_participation_product_id" name="tcbf_participation_product_id">
						<option value="0"><?php esc_html_e( '— Use plugin default —', 'tc-booking-flow-next' ); ?></option>
						<?php foreach ( $products as $p ) : ?>
							<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( $participation_product_id, (int) $p->ID ); ?>>
								<?php echo esc_html( $p->post_title . ' (#' . $p->ID . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<div class="tcbf-muted"><?php esc_html_e( 'Leave as default to use plugin-level fallback participation product.', 'tc-booking-flow-next' ); ?></div>
				</div>

				<div class="wide tcbf-divider"></div>

				<div class="wide">
					<label><?php esc_html_e( 'Partner program', 'tc-booking-flow-next' ); ?></label>

					<label style="font-weight:400;display:block">
						<input type="radio" name="tcbf_partners_enabled" value="" <?php checked( $partners_enabled, '' ); ?> />
						<?php esc_html_e( 'Use default', 'tc-booking-flow-next' ); ?>
						<span class="tcbf-muted">(<?php echo $partners_default ? esc_html__( 'currently: ON', 'tc-booking-flow-next' ) : esc_html__( 'currently: OFF', 'tc-booking-flow-next' ); ?>)</span>
					</label>

					<label style="font-weight:400;display:block">
						<input type="radio" name="tcbf_partners_enabled" value="1" <?php checked( $partners_enabled, '1' ); ?> />
						<?php esc_html_e( 'Enabled', 'tc-booking-flow-next' ); ?>
					</label>

					<label style="font-weight:400;display:block">
						<input type="radio" name="tcbf_partners_enabled" value="0" <?php checked( $partners_enabled, '0' ); ?> />
						<?php esc_html_e( 'Disabled', 'tc-booking-flow-next' ); ?>
					</label>

					<div class="tcbf-muted">
						<?php esc_html_e( 'When disabled: partner coupon auto-apply is off, partner discounts are not applied, and no partner commission is calculated. Direct booking is unaffected.', 'tc-booking-flow-next' ); ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Header Tab -->
		<div class="tcbf-tab-content" data-tab="header">
			<div class="tcbf-grid">
				<div>
					<label for="tcbf_header_title_mode"><?php esc_html_e( 'Title Mode', 'tc-booking-flow-next' ); ?></label>
					<select id="tcbf_header_title_mode" name="tcbf_header_title_mode">
						<option value="default" <?php selected( $header_title_mode, 'default' ); ?>><?php esc_html_e( 'Default (event title)', 'tc-booking-flow-next' ); ?></option>
						<option value="custom" <?php selected( $header_title_mode, 'custom' ); ?>><?php esc_html_e( 'Custom title', 'tc-booking-flow-next' ); ?></option>
						<option value="hide" <?php selected( $header_title_mode, 'hide' ); ?>><?php esc_html_e( 'Hide title', 'tc-booking-flow-next' ); ?></option>
					</select>
				</div>

				<div>
					<label for="tcbf_header_title_custom"><?php esc_html_e( 'Custom Title', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_header_title_custom" name="tcbf_header_title_custom" value="<?php echo esc_attr( $header_title_custom ); ?>" />
				</div>

				<div class="wide">
					<label for="tcbf_header_subtitle"><?php esc_html_e( 'Subtitle', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_header_subtitle" name="tcbf_header_subtitle" value="<?php echo esc_attr( $header_subtitle ); ?>" />
				</div>

				<div class="wide tcbf-divider"></div>

				<div>
					<label for="tcbf_header_logo_mode"><?php esc_html_e( 'Logo Mode', 'tc-booking-flow-next' ); ?></label>
					<select id="tcbf_header_logo_mode" name="tcbf_header_logo_mode">
						<option value="none" <?php selected( $header_logo_mode, 'none' ); ?>><?php esc_html_e( 'None', 'tc-booking-flow-next' ); ?></option>
						<option value="media" <?php selected( $header_logo_mode, 'media' ); ?>><?php esc_html_e( 'Media Library', 'tc-booking-flow-next' ); ?></option>
						<option value="url" <?php selected( $header_logo_mode, 'url' ); ?>><?php esc_html_e( 'External URL', 'tc-booking-flow-next' ); ?></option>
					</select>
				</div>

				<div>
					<label for="tcbf_header_logo_id"><?php esc_html_e( 'Logo Attachment ID', 'tc-booking-flow-next' ); ?></label>
					<div class="tcbf-flex">
						<input type="number" min="0" id="tcbf_header_logo_id" name="tcbf_header_logo_id" value="<?php echo (int) $header_logo_id; ?>" />
						<button type="button" class="button" id="tcbf-logo-pick"><?php esc_html_e( 'Pick', 'tc-booking-flow-next' ); ?></button>
						<button type="button" class="button" id="tcbf-logo-clear"><?php esc_html_e( 'Clear', 'tc-booking-flow-next' ); ?></button>
					</div>
					<div id="tcbf-logo-preview" style="margin-top:8px;"><?php echo $logo_preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</div>

				<div class="wide">
					<label for="tcbf_header_logo_url"><?php esc_html_e( 'Logo URL (if mode = URL)', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_header_logo_url" name="tcbf_header_logo_url" value="<?php echo esc_attr( $header_logo_url ); ?>" />
				</div>

				<div class="wide tcbf-divider"></div>

				<div>
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_header_show_divider" value="1" <?php checked( $header_show_divider, '1' ); ?> />
						<?php esc_html_e( 'Show divider under header', 'tc-booking-flow-next' ); ?>
					</label>
				</div>

				<div>
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_header_show_shopkeeper_meta" value="1" <?php checked( $header_show_shopkeeper_meta, '1' ); ?> />
						<?php esc_html_e( 'Show Shopkeeper meta bar', 'tc-booking-flow-next' ); ?>
					</label>
				</div>

				<div class="wide">
					<label style="font-weight:400;display:block">
						<input type="checkbox" name="tcbf_header_show_back_link" value="1" <?php checked( $header_show_back_link, '1' ); ?> />
						<?php esc_html_e( 'Show back link', 'tc-booking-flow-next' ); ?>
					</label>
				</div>

				<div>
					<label for="tcbf_header_back_link_url"><?php esc_html_e( 'Back link URL', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_header_back_link_url" name="tcbf_header_back_link_url" value="<?php echo esc_attr( $header_back_link_url ); ?>" />
				</div>

				<div>
					<label for="tcbf_header_back_link_label"><?php esc_html_e( 'Back link label', 'tc-booking-flow-next' ); ?></label>
					<input type="text" id="tcbf_header_back_link_label" name="tcbf_header_back_link_label" value="<?php echo esc_attr( $header_back_link_label ); ?>" />
				</div>

				<div class="wide">
					<label for="tcbf_header_details_position"><?php esc_html_e( 'Details position', 'tc-booking-flow-next' ); ?></label>
					<select id="tcbf_header_details_position" name="tcbf_header_details_position">
						<option value="content" <?php selected( $header_details_position, 'content' ); ?>><?php esc_html_e( 'Under content', 'tc-booking-flow-next' ); ?></option>
						<option value="header" <?php selected( $header_details_position, 'header' ); ?>><?php esc_html_e( 'Inside header', 'tc-booking-flow-next' ); ?></option>
					</select>
				</div>

				<div class="wide tcbf-divider"></div>

				<div>
					<label for="tcbf_header_subtitle_size"><?php esc_html_e( 'Subtitle Size (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_subtitle_size" name="tcbf_header_subtitle_size" value="<?php echo (int) $header_subtitle_size; ?>" />
				</div>

				<div>
					<label for="tcbf_header_padding_bottom"><?php esc_html_e( 'Header Padding Bottom (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_padding_bottom" name="tcbf_header_padding_bottom" value="<?php echo (int) $header_padding_bottom; ?>" />
				</div>

				<div>
					<label for="tcbf_header_details_bottom"><?php esc_html_e( 'Details Bottom Offset (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_details_bottom" name="tcbf_header_details_bottom" value="<?php echo (int) $header_details_bottom; ?>" />
				</div>

				<div>
					<label for="tcbf_header_logo_margin_bottom"><?php esc_html_e( 'Logo Margin Bottom (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_logo_margin_bottom" name="tcbf_header_logo_margin_bottom" value="<?php echo (int) $header_logo_margin_bottom; ?>" />
				</div>

				<div>
					<label for="tcbf_header_logo_max_width"><?php esc_html_e( 'Logo Max Width (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_logo_max_width" name="tcbf_header_logo_max_width" value="<?php echo (int) $header_logo_max_width; ?>" />
				</div>

				<div>
					<label for="tcbf_header_title_max_size"><?php esc_html_e( 'Title Max Font Size (px)', 'tc-booking-flow-next' ); ?></label>
					<input type="number" min="0" id="tcbf_header_title_max_size" name="tcbf_header_title_max_size" value="<?php echo (int) $header_title_max_size; ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ) : void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( $post->post_type !== 'sc_event' ) return;

		if ( ! isset( $_POST[self::NONCE_KEY] ) || ! wp_verify_nonce( $_POST[self::NONCE_KEY], self::NONCE_KEY ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Helper: save number (decimal comma support)
		$save_num = function( string $key ) use ( $post_id ) : void {
			if ( ! isset( $_POST[$key] ) ) return;
			$raw = trim( (string) $_POST[$key] );
			if ( $raw === '' ) {
				EventMeta::delete( $post_id, str_replace( 'tcbf_', '', $key ) );
				return;
			}

			// Normalize to dot decimal for storage
			$normalized = str_replace( [' ', '€'], '', $raw );
			if ( strpos( $normalized, ',' ) !== false && strpos( $normalized, '.' ) !== false ) {
				$normalized = str_replace( '.', '', $normalized );
				$normalized = str_replace( ',', '.', $normalized );
			} else {
				$normalized = str_replace( ',', '.', $normalized );
			}

			EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), (string) floatval( $normalized ) );
		};

		// Helper: save text
		$save_text = function( string $key ) use ( $post_id ) : void {
			if ( ! isset( $_POST[$key] ) ) return;
			$v = trim( (string) $_POST[$key] );
			if ( $v === '' ) {
				EventMeta::delete( $post_id, str_replace( 'tcbf_', '', $key ) );
				return;
			}
			EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), sanitize_text_field( $v ) );
		};

		// Helper: save int
		$save_int = function( string $key ) use ( $post_id ) : void {
			if ( ! isset( $_POST[$key] ) ) return;
			$v = absint( $_POST[$key] );
			EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), $v );
		};

		// Helper: save checkbox (1/0)
		$save_checkbox = function( string $key ) use ( $post_id ) : void {
			$v = isset( $_POST[$key] ) ? '1' : '0';
			EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), $v );
		};

		// Helper: save Yes/No checkbox
		$save_yesno = function( string $key ) use ( $post_id ) : void {
			if ( ! isset( $_POST[$key] ) ) {
				EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), 'No' );
				return;
			}
			$v = ( $_POST[$key] === 'Yes' ) ? 'Yes' : 'No';
			EventMeta::set( $post_id, str_replace( 'tcbf_', '', $key ), $v );
		};

		// Pricing
		$save_num( 'tcbf_participation_price' );
		$save_num( 'tcbf_member_price' );

		// Rentals
		$save_num( 'tcbf_rental_price_road' );
		$save_num( 'tcbf_rental_price_mtb' );
		$save_num( 'tcbf_rental_price_ebike' );
		$save_num( 'tcbf_rental_price_gravel' );

		if ( isset( $_POST['tcbf_rental_default_class'] ) ) {
			$v = (string) $_POST['tcbf_rental_default_class'];
			if ( ! in_array( $v, [ '', 'road', 'mtb', 'ebike', 'gravel' ], true ) ) $v = '';
			EventMeta::set( $post_id, 'rental_default_class', $v );
		}

		// Early Booking
		$save_checkbox( 'tcbf_eb_enabled' );
		$save_checkbox( 'tcbf_eb_participation_enabled' );
		$save_checkbox( 'tcbf_eb_rental_enabled' );

		// EB Rules: build PURE LEGACY JSON from table inputs [{days,pct}]
		$days = isset( $_POST['tcbf_eb_days'] ) ? (array) $_POST['tcbf_eb_days'] : [];
		$pct  = isset( $_POST['tcbf_eb_pct'] ) ? (array) $_POST['tcbf_eb_pct'] : [];

		$steps = [];
		foreach ( $days as $idx => $d ) {
			if ( ! isset( $pct[$idx] ) ) continue;
			$steps[] = [
				'days' => absint( $d ),
				'pct'  => (float) $pct[$idx],
			];
		}

		// Sort by days DESC
		usort( $steps, function( $a, $b ) {
			return $b['days'] <=> $a['days'];
		} );

		// Save as pure legacy array [{days,pct}]
		EventMeta::set( $post_id, 'eb_rules_json', wp_json_encode( $steps, JSON_UNESCAPED_SLASHES ) );

		// Global cap: separate tc_ebd_cap meta key (legacy approach)
		if ( isset( $_POST['tcbf_eb_global_cap'] ) ) {
			$raw = trim( (string) $_POST['tcbf_eb_global_cap'] );
			if ( $raw === '' ) {
				delete_post_meta( $post_id, 'tc_ebd_cap' );
				// Also delete canonical mirror if it exists
				delete_post_meta( $post_id, 'tcbf_eb_global_cap' );
			} else {
				$normalized = str_replace( [' ', '€', ','], ['', '', '.'], $raw );
				$cap_value  = (float) $normalized;
				// Save to legacy meta key
				update_post_meta( $post_id, 'tc_ebd_cap', $cap_value );
				// Also mirror to canonical (for future migration)
				update_post_meta( $post_id, 'tcbf_eb_global_cap', $cap_value );
			}
		}

		// Products
		$save_int( 'tcbf_participation_product_id' );

		// Partners (TCBF-12)
		if ( isset( $_POST['tcbf_partners_enabled'] ) ) {
			$v = (string) $_POST['tcbf_partners_enabled'];
			if ( ! in_array( $v, [ '', '0', '1' ], true ) ) $v = '';
			EventMeta::set( $post_id, 'partners_enabled', $v );
		}

		// Header
		if ( isset( $_POST['tcbf_header_title_mode'] ) ) {
			$v = (string) $_POST['tcbf_header_title_mode'];
			if ( ! in_array( $v, [ 'default', 'custom', 'hide' ], true ) ) $v = 'default';
			EventMeta::set( $post_id, 'header_title_mode', $v );
		}

		$save_text( 'tcbf_header_title_custom' );
		$save_text( 'tcbf_header_subtitle' );

		if ( isset( $_POST['tcbf_header_logo_mode'] ) ) {
			$v = (string) $_POST['tcbf_header_logo_mode'];
			if ( ! in_array( $v, [ 'none', 'media', 'url' ], true ) ) $v = 'none';
			EventMeta::set( $post_id, 'header_logo_mode', $v );
		}

		$save_int( 'tcbf_header_logo_id' );
		$save_text( 'tcbf_header_logo_url' );

		$save_checkbox( 'tcbf_header_show_divider' );
		$save_checkbox( 'tcbf_header_show_shopkeeper_meta' );
		$save_checkbox( 'tcbf_header_show_back_link' );

		$save_text( 'tcbf_header_back_link_url' );
		$save_text( 'tcbf_header_back_link_label' );

		if ( isset( $_POST['tcbf_header_details_position'] ) ) {
			$v = (string) $_POST['tcbf_header_details_position'];
			if ( ! in_array( $v, [ 'content', 'header' ], true ) ) $v = 'content';
			EventMeta::set( $post_id, 'header_details_position', $v );
		}

		// Header CSS variables
		$save_int( 'tcbf_header_subtitle_size' );
		$save_int( 'tcbf_header_padding_bottom' );
		$save_int( 'tcbf_header_details_bottom' );
		$save_int( 'tcbf_header_logo_margin_bottom' );
		$save_int( 'tcbf_header_logo_max_width' );
		$save_int( 'tcbf_header_title_max_size' );

		// Content/Display
		if ( isset( $_POST['tcbf_feat_img'] ) ) {
			$v = (string) $_POST['tcbf_feat_img'];
			if ( ! in_array( $v, [ '', 'Yes', 'No' ], true ) ) $v = '';
			EventMeta::set( $post_id, 'feat_img', $v );
		}

		$save_yesno( 'tcbf_inscription' );
		$save_yesno( 'tcbf_participants' );
	}

	public static function admin_assets( string $hook ) : void {
		if ( ! is_admin() ) return;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== 'sc_event' ) return;
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

		wp_enqueue_media();

		$js = <<<'JS'
(function($){
  $(function(){
    // Tab switching
    $('.tcbf-tab-btn').on('click', function(){
      var tab = $(this).data('tab');
      $('.tcbf-tab-btn').removeClass('active');
      $(this).addClass('active');
      $('.tcbf-tab-content').removeClass('active');
      $('.tcbf-tab-content[data-tab="'+tab+'"]').addClass('active');
    });

    // Logo picker
    var frame;
    var $id = $('#tcbf_header_logo_id');
    var $preview = $('#tcbf-logo-preview');

    $('#tcbf-logo-pick').on('click', function(e){
      e.preventDefault();
      if(frame){ frame.open(); return; }
      frame = wp.media({ title: 'Select header logo', button: { text: 'Use this logo' }, multiple: false });
      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        if(!att || !att.id){ return; }
        $id.val(att.id);
        var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
        $preview.html('<img src="'+url+'" style="max-width:200px;height:auto" alt="" />');
      });
      frame.open();
    });

    $('#tcbf-logo-clear').on('click', function(e){
      e.preventDefault();
      $id.val('0');
      $preview.empty();
    });

    // EB Rules Table
    $('#tcbf-eb-rules-body').on('click', '.tcbf-remove-row', function(e){
      e.preventDefault();
      $(this).closest('tr').remove();
    });

    $('.tcbf-add-eb-rule').on('click', function(){
      var row = '<tr>' +
        '<td><input type="number" name="tcbf_eb_days[]" value="0" min="0" required /></td>' +
        '<td><input type="number" name="tcbf_eb_pct[]" value="0" min="0" max="100" step="0.01" required /></td>' +
        '<td><a href="#" class="tcbf-remove-row">Remove</a></td>' +
        '</tr>';
      $('#tcbf-eb-rules-body').append(row);
    });
  });
})(jQuery);
JS;

		wp_add_inline_script( 'jquery', $js );
	}
}
