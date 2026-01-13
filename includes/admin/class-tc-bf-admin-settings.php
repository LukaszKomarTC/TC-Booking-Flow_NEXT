<?php
namespace TC_BF\Admin;

if ( ! defined('ABSPATH') ) exit;

final class Settings {

	const OPT_FORM_ID = 'tc_bf_form_id';
	const OPT_DEBUG   = 'tc_bf_debug';
	const OPT_LOGS    = 'tc_bf_logs';

	// TCBF-11+: Global fallback participation product (bookable products only)
	const OPT_DEFAULT_PARTICIPATION_PRODUCT_ID = 'tcbf_default_participation_product_id';

	// TCBF-12: Partner program toggle default (enabled by default)
	const OPT_PARTNERS_ENABLED_DEFAULT = 'tcbf_partners_enabled_default';

	public static function init() : void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		// AJAX logging endpoint for admin-only diagnostics.
		add_action('wp_ajax_tc_bf_log', [__CLASS__, 'ajax_log']);
	}

	/**
	 * Compatibility shim (legacy callers rely on this).
	 *
	 * IMPORTANT:
	 * Older code calls append_log() with arrays/objects as the first argument.
	 * So we MUST accept mixed input and normalize safely.
	 *
	 * @param mixed $message string|array|object|int|etc
	 * @param mixed $context optional
	 */
	public static function append_log( $message, $context = null ) : void {
		if ( ! self::is_debug() ) {
			return;
		}

		// If legacy code passes an array/object as "message" and no context,
		// treat it as context and use a generic label.
		$label = 'log';
		if ( (is_array($message) || is_object($message)) && $context === null ) {
			$context = $message;
			$message = $label;
		}

		// Normalize message to string
		if ( is_array($message) || is_object($message) ) {
			$msg = wp_json_encode($message);
		} elseif ( $message === null ) {
			$msg = '';
		} else {
			$msg = (string) $message;
		}

		$line = gmdate('c') . ' ' . trim($msg);

		// Normalize context
		if ( $context !== null ) {
			if ( is_array($context) || is_object($context) ) {
				$line .= ' ' . wp_json_encode($context);
			} else {
				$line .= ' ' . (string) $context;
			}
		}

		// Server-side log (safe)
		error_log('[TC_BF] ' . $line);

		// Optional rolling buffer stored in wp_options (kept small)
		$logs = get_option(self::OPT_LOGS, []);
		if ( ! is_array($logs) ) {
			$logs = [];
		}

		$logs[] = $line;

		// Keep last 200 lines max to avoid bloating wp_options
		$max = 200;
		$count = count($logs);
		if ( $count > $max ) {
			$logs = array_slice($logs, $count - $max);
		}

		// No autoload
		update_option(self::OPT_LOGS, $logs, false);
	}

	public static function ajax_log() : void {
		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}

		$msg = isset($_POST['message']) ? sanitize_text_field( wp_unslash($_POST['message']) ) : '';
		$ctx = isset($_POST['context']) ? wp_unslash($_POST['context']) : '';

		if ( is_string($ctx) ) {
			$decoded = json_decode($ctx, true);
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$ctx = $decoded;
			}
		}

		error_log('[TC_BF] ajax_log: ' . $msg . ' ' . ( is_array($ctx) ? wp_json_encode($ctx) : (string) $ctx ));
		wp_send_json_success(['ok' => true]);
	}

	public static function menu() : void {
		add_options_page(
			'TC Booking Flow',
			'TC Booking Flow',
			'manage_options',
			'tc-bf-settings',
			[__CLASS__, 'render']
		);
	}

	public static function render() : void {
		if ( ! current_user_can('manage_options') ) return;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('TC Booking Flow — Settings', 'tc-booking-flow-next'); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields('tc_bf_settings');
				do_settings_sections('tc_bf_settings');
				?>

				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_FORM_ID); ?>"><?php echo esc_html__('Gravity Form ID', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_FORM_ID); ?>" id="<?php echo esc_attr(self::OPT_FORM_ID); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_FORM_ID, 44) ); ?>" min="1" step="1" />
								<p class="description"><?php echo esc_html__('The Gravity Form used for TC Booking Flow.', 'tc-booking-flow-next'); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>"><?php echo esc_html__('Fallback Participation Product', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<?php
								$val = (int) get_option(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, 0);

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
								<select name="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>" id="<?php echo esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID); ?>">
									<option value="0"><?php echo esc_html__('— None —', 'tc-booking-flow-next'); ?></option>
									<?php foreach ( $products as $p ) : ?>
										<option value="<?php echo esc_attr((string) $p->ID); ?>" <?php selected($val, (int)$p->ID); ?>>
											<?php echo esc_html($p->post_title . ' (#' . $p->ID . ')'); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php echo esc_html__('Used when an event does not have a custom participation product selected.', 'tc-booking-flow-next'); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>"><?php echo esc_html__('Partner program enabled by default', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<?php $enabled = (int) get_option(self::OPT_PARTNERS_ENABLED_DEFAULT, 1) === 1; ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>" id="<?php echo esc_attr(self::OPT_PARTNERS_ENABLED_DEFAULT); ?>" value="1" <?php checked($enabled); ?> />
									<?php echo esc_html__('Enable partner program for all events by default (unless disabled at event level).', 'tc-booking-flow-next'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('When disabled for an event: partner coupons will not apply and no commission will be calculated. Direct booking remains unaffected.', 'tc-booking-flow-next'); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_DEBUG); ?>"><?php echo esc_html__('Debug Mode', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<?php $debug = (int) get_option(self::OPT_DEBUG, 0) === 1; ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr(self::OPT_DEBUG); ?>" id="<?php echo esc_attr(self::OPT_DEBUG); ?>" value="1" <?php checked($debug); ?> />
									<?php echo esc_html__('Enable debug logging (server logs only).', 'tc-booking-flow-next'); ?>
								</label>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function register_settings() : void {
		register_setting('tc_bf_settings', self::OPT_FORM_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 44,
		]);

		register_setting('tc_bf_settings', self::OPT_DEBUG, [
			'type' => 'boolean',
			'sanitize_callback' => function($v){ return (int)(!empty($v)); },
			'default' => 0,
		]);

		register_setting('tc_bf_settings', self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 0,
		]);

		register_setting('tc_bf_settings', self::OPT_PARTNERS_ENABLED_DEFAULT, [
			'type' => 'boolean',
			'sanitize_callback' => function($v){ return (int)(!empty($v)); },
			'default' => 1,
		]);
	}

	public static function get_form_id() : int {
		$v = (int) get_option(self::OPT_FORM_ID, 44);
		return $v > 0 ? $v : 44;
	}

	public static function is_debug() : bool {
		return (int) get_option(self::OPT_DEBUG, 0) === 1;
	}
}
