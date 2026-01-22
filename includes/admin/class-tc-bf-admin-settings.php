<?php
namespace TC_BF\Admin;

use TC_BF\Integrations\GravityForms\GF_Notification_Templates;

if ( ! defined('ABSPATH') ) exit;

final class Settings {

	const OPT_FORM_ID = 'tc_bf_form_id';
	const OPT_DEBUG   = 'tc_bf_debug';
	const OPT_LOGS    = 'tc_bf_logs';

	// TCBF-11+: Global fallback participation product (bookable products only)
	const OPT_DEFAULT_PARTICIPATION_PRODUCT_ID = 'tcbf_default_participation_product_id';

	// TCBF-12: Partner program toggle default (enabled by default)
	const OPT_PARTNERS_ENABLED_DEFAULT = 'tcbf_partners_enabled_default';

	// TCBF-13: Booking product form ID (for GF Product Add-ons integration)
	const OPT_BOOKING_FORM_ID = 'tcbf_booking_form_id';

	// TCBF Participants List settings
	const OPT_PARTICIPANTS_PRIVACY_MODE   = 'tcbf_participants_privacy_mode';
	const OPT_PARTICIPANTS_EVENT_UID_FIELD = 'tcbf_participants_event_uid_field_id';

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
								<label for="<?php echo esc_attr(self::OPT_FORM_ID); ?>"><?php echo esc_html__('Event Form ID', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_FORM_ID); ?>" id="<?php echo esc_attr(self::OPT_FORM_ID); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_FORM_ID, 44) ); ?>" min="1" step="1" />
								<p class="description"><?php echo esc_html__('The Gravity Form used for event registrations (Form 44).', 'tc-booking-flow-next'); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>"><?php echo esc_html__('Booking Product Form ID', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>" id="<?php echo esc_attr(self::OPT_BOOKING_FORM_ID); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_BOOKING_FORM_ID, 55) ); ?>" min="1" step="1" />
								<p class="description"><?php echo esc_html__('The Gravity Form used for booking products with GF Product Add-ons (rentals).', 'tc-booking-flow-next'); ?></p>
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

				<h2 style="margin-top: 2em;"><?php echo esc_html__('Participants List', 'tc-booking-flow-next'); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>"><?php echo esc_html__('Privacy Mode', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<?php $privacy_mode = get_option(self::OPT_PARTICIPANTS_PRIVACY_MODE, 'public_masked'); ?>
								<select name="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>" id="<?php echo esc_attr(self::OPT_PARTICIPANTS_PRIVACY_MODE); ?>">
									<option value="public_masked" <?php selected($privacy_mode, 'public_masked'); ?>><?php echo esc_html__('Public (masked names/emails)', 'tc-booking-flow-next'); ?></option>
									<option value="admin_only" <?php selected($privacy_mode, 'admin_only'); ?>><?php echo esc_html__('Admin only (hidden from public)', 'tc-booking-flow-next'); ?></option>
									<option value="full" <?php selected($privacy_mode, 'full'); ?>><?php echo esc_html__('Full (show all data publicly)', 'tc-booking-flow-next'); ?></option>
								</select>
								<p class="description">
									<?php echo esc_html__('Controls visibility of participant data. Admins (manage_options or manage_woocommerce) always see full data.', 'tc-booking-flow-next'); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>"><?php echo esc_html__('Event UID Field ID', 'tc-booking-flow-next'); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>" id="<?php echo esc_attr(self::OPT_PARTICIPANTS_EVENT_UID_FIELD); ?>" value="<?php echo esc_attr( (string) get_option(self::OPT_PARTICIPANTS_EVENT_UID_FIELD, 145) ); ?>" min="1" step="1" />
								<p class="description">
									<?php echo esc_html__('Gravity Forms field ID that stores the event unique identifier (default: 145).', 'tc-booking-flow-next'); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr/>
			<h2><?php echo esc_html__('Tools', 'tc-booking-flow-next'); ?></h2>

			<?php self::render_notification_tools(); ?>

			<hr/>
			<h2><?php echo esc_html__('Diagnostics', 'tc-booking-flow-next'); ?></h2>

			<?php
			$debug = self::is_debug();
			if ( ! $debug ) {
				echo '<p><em>' . esc_html__('Debug mode is currently off. Enable it above to collect logs.', 'tc-booking-flow-next') . '</em></p>';
			} else {
				// Clear logs handler
				if ( isset($_POST['tc_bf_clear_logs']) && check_admin_referer('tc_bf_clear_logs') ) {
					self::clear_logs();
					echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'tc-booking-flow-next') . '</p></div>';
				}

				$logs = array_reverse( self::get_logs() );
				?>
				<form method="post" style="margin:0 0 12px 0;">
					<?php wp_nonce_field('tc_bf_clear_logs'); ?>
					<input type="hidden" name="tc_bf_clear_logs" value="1" />
					<?php submit_button( esc_html__('Clear logs', 'tc-booking-flow-next'), 'secondary', 'submit', false ); ?>
				</form>

				<?php
				if ( empty($logs) ) {
					echo '<p><em>' . esc_html__('No logs yet.', 'tc-booking-flow-next') . '</em> ' . esc_html__('Submit the configured Gravity Form once, then return here.', 'tc-booking-flow-next') . '</p>';
				} else {
					echo '<table class="widefat striped" style="max-width: 1200px;">';
					echo '<thead><tr><th>' . esc_html__('Log Entry', 'tc-booking-flow-next') . '</th></tr></thead><tbody>';
					foreach ( $logs as $line ) {
						echo '<tr><td><pre style="white-space:pre-wrap; margin:0; font-family:monospace; font-size:12px;">' . esc_html($line) . '</pre></td></tr>';
					}
					echo '</tbody></table>';

					$bundle = [
						'site' => home_url(),
						'time' => gmdate('c'),
						'plugin' => 'tc-booking-flow-next',
						'version' => defined('TC_BF_VERSION') ? TC_BF_VERSION : '',
						'logs' => array_reverse($logs),
					];
					$json = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
					echo '<h3 style="margin-top:16px;">' . esc_html__('Copy debug bundle', 'tc-booking-flow-next') . '</h3>';
					echo '<textarea readonly style="width:100%; max-width:1200px; height:240px; font-family:monospace; font-size:12px;">' . esc_textarea($json) . '</textarea>';
				}
			}
			?>

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

		// Participants List settings
		register_setting('tc_bf_settings', self::OPT_PARTICIPANTS_PRIVACY_MODE, [
			'type'              => 'string',
			'sanitize_callback' => function($v){
				$valid = ['public_masked', 'admin_only', 'full'];
				return in_array($v, $valid, true) ? $v : 'public_masked';
			},
			'default'           => 'public_masked',
		]);

		register_setting('tc_bf_settings', self::OPT_PARTICIPANTS_EVENT_UID_FIELD, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return max(1, absint($v)); },
			'default'           => 145,
		]);

		// TCBF-13: Booking product form ID
		register_setting('tc_bf_settings', self::OPT_BOOKING_FORM_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return absint($v); },
			'default'           => 55,
		]);
	}

	public static function get_form_id() : int {
		$v = (int) get_option(self::OPT_FORM_ID, 44);
		return $v > 0 ? $v : 44;
	}

	/**
	 * Get the configured booking product form ID
	 *
	 * @return int Form ID (default 55)
	 */
	public static function get_booking_form_id() : int {
		$v = (int) get_option(self::OPT_BOOKING_FORM_ID, 55);
		return $v > 0 ? $v : 55;
	}

	public static function is_debug() : bool {
		return (int) get_option(self::OPT_DEBUG, 0) === 1;
	}

	public static function get_logs() : array {
		$logs = get_option(self::OPT_LOGS, []);
		return is_array($logs) ? $logs : [];
	}

	public static function clear_logs() : void {
		delete_option(self::OPT_LOGS);
	}

	/**
	 * Render notification sync tools section
	 */
	private static function render_notification_tools(): void {
		// Handle sync action
		if ( isset( $_POST['tcbf_sync_notifications'] ) && check_admin_referer( 'tcbf_sync_notifications' ) ) {
			$dry_run = isset( $_POST['tcbf_dry_run'] );
			$result = GF_Notification_Templates::sync_all( $dry_run );

			if ( $result['success'] ) {
				$msg = $dry_run
					? __( 'Dry run complete. No changes made.', 'tc-booking-flow-next' )
					: __( 'Notifications synced successfully.', 'tc-booking-flow-next' );
				echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Sync completed with errors:', 'tc-booking-flow-next' ) . '</p>';
				echo '<ul>';
				foreach ( $result['errors'] as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			}

			// Show details
			echo '<div class="tcbf-sync-details" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 15px 0; max-width: 800px;">';
			echo '<h4 style="margin-top: 0;">' . esc_html__( 'Sync Details', 'tc-booking-flow-next' ) . '</h4>';

			foreach ( $result['forms'] as $form_id => $form_result ) {
				echo '<p><strong>' . esc_html__( 'Form', 'tc-booking-flow-next' ) . ' ' . esc_html( (string) $form_id ) . ':</strong></p>';
				echo '<ul style="margin-left: 20px;">';

				if ( ! empty( $form_result['notifications'] ) ) {
					foreach ( $form_result['notifications'] as $id => $notif_result ) {
						$action = $notif_result['action'] ?? 'unknown';
						$icon = match( $action ) {
							'add'    => '+',
							'update' => '~',
							'skip'   => '-',
							'error'  => '!',
							default  => '?',
						};
						echo '<li><code>' . esc_html( $icon ) . '</code> ' . esc_html( $id ) . ': ' . esc_html( $notif_result['message'] ?? '' ) . '</li>';
					}
				}

				echo '</ul>';
			}

			echo '</div>';
		}

		// Get current status
		$status = GF_Notification_Templates::get_status();
		?>

		<h3><?php echo esc_html__( 'GF Notification Sync', 'tc-booking-flow-next' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'Sync TCBF notification templates to configured Gravity Forms. This will add or update notifications with tcbf_* IDs only.', 'tc-booking-flow-next' ); ?>
		</p>

		<?php if ( isset( $status['error'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p><?php echo esc_html( $status['error'] ); ?></p>
			</div>
		<?php else : ?>

			<table class="widefat striped" style="max-width: 800px; margin: 15px 0;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Form', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'TCBF Notifications', 'tc-booking-flow-next' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'tc-booking-flow-next' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['forms'] as $form_id => $form_status ) : ?>
						<tr>
							<td>
								<strong>#<?php echo esc_html( (string) $form_id ); ?></strong>
								<?php if ( $form_status['exists'] ) : ?>
									<br><small><?php echo esc_html( $form_status['title'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $form_status['exists'] ) : ?>
									<em><?php echo esc_html__( 'Form not found', 'tc-booking-flow-next' ); ?></em>
								<?php elseif ( empty( $form_status['notifications'] ) ) : ?>
									<em><?php echo esc_html__( 'None installed', 'tc-booking-flow-next' ); ?></em>
								<?php else : ?>
									<ul style="margin: 0; padding-left: 15px;">
										<?php foreach ( $form_status['notifications'] as $id => $notif ) : ?>
											<li>
												<?php echo esc_html( $notif['name'] ); ?>
												<small style="color: <?php echo $notif['isActive'] ? 'green' : 'gray'; ?>;">
													(<?php echo $notif['isActive'] ? 'active' : 'inactive'; ?>)
												</small>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $form_status['exists'] ) : ?>
									<span style="color: red;">&#10007;</span>
								<?php elseif ( empty( $form_status['missing'] ) ) : ?>
									<span style="color: green;">&#10003; <?php echo esc_html__( 'Up to date', 'tc-booking-flow-next' ); ?></span>
								<?php else : ?>
									<span style="color: orange;">&#9888; <?php echo esc_html( count( $form_status['missing'] ) ); ?> <?php echo esc_html__( 'missing', 'tc-booking-flow-next' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin: 15px 0;">
				<?php wp_nonce_field( 'tcbf_sync_notifications' ); ?>
				<p>
					<label>
						<input type="checkbox" name="tcbf_dry_run" value="1" />
						<?php echo esc_html__( 'Dry run (validate only, no changes)', 'tc-booking-flow-next' ); ?>
					</label>
				</p>
				<?php submit_button( esc_html__( 'Sync Notifications', 'tc-booking-flow-next' ), 'secondary', 'tcbf_sync_notifications', false ); ?>
			</form>

		<?php endif;
	}
}
