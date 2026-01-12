<?php
namespace TC_BF\Admin;

if ( ! defined('ABSPATH') ) exit;

final class Settings {

	const OPT_FORM_ID = 'tc_bf_form_id';
	const OPT_DEBUG = 'tc_bf_debug';
	const OPT_LOGS  = 'tc_bf_logs';

	// TCBF-11+: Global fallback participation product (bookable products only)
	const OPT_DEFAULT_PARTICIPATION_PRODUCT_ID = 'tcbf_default_participation_product_id';

	public static function init() : void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		// AJAX logging endpoint for admin-only frontend diagnostics.
		add_action('wp_ajax_tc_bf_log', [__CLASS__, 'ajax_log']);
	}

	/**
	 * Admin-only AJAX log endpoint.
	 *
	 * Used by frontend diagnostics (e.g., Stage-3 repairs) when debug mode is enabled.
	 */
	public static function ajax_log() : void {
		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['message' => 'forbidden'], 403);
		}
		if ( ! self::is_debug() ) {
			wp_send_json_error(['message' => 'debug_off'], 400);
		}
		check_ajax_referer('tc_bf_log', 'nonce');

		$context = isset($_POST['context']) ? sanitize_text_field((string) wp_unslash($_POST['context'])) : 'frontend';
		$data_raw = isset($_POST['data']) ? (string) wp_unslash($_POST['data']) : '';
		$level = isset($_POST['level']) ? sanitize_text_field((string) wp_unslash($_POST['level'])) : 'info';

		$data = [];
		if ( $data_raw !== '' ) {
			$decoded = json_decode($data_raw, true);
			if ( is_array($decoded) ) {
				$data = $decoded;
			} else {
				$data = ['raw' => $data_raw];
			}
		}

		// Route through the plugin logger (writes to WC logs + keeps last 50 in options page).
		\TC_BF\Support\Logger::log($context, $data, $level);
		wp_send_json_success(['ok' => true]);
	}

	public static function menu() : void {
		add_options_page(
			'TC Booking Flow',
			'TC Booking Flow',
			'manage_options',
			'tc-booking-flow',
			[__CLASS__, 'render_page']
		);
	}

	public static function register_settings() : void {
		register_setting('tc_bf_settings', self::OPT_FORM_ID, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return (int) $v; },
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
	}

	public static function get_form_id() : int {
		$v = (int) get_option(self::OPT_FORM_ID, 44);
		return $v > 0 ? $v : 44;
	}

	public static function is_debug() : bool {
		return (int) get_option(self::OPT_DEBUG, 0) === 1;
	}


/**
 * Global default participation product ID.
 * Only booking (bookable) products are considered valid.
 */
public static function get_default_participation_product_id() : int {
	$pid = absint( get_option(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID, 0) );
	if ( $pid <= 0 ) return 0;

	if ( function_exists('wc_get_product') ) {
		$p = wc_get_product($pid);
		if ( ! $p ) return 0;
		if ( function_exists('is_wc_booking_product') && ! is_wc_booking_product($p) ) return 0;
	}
	return $pid;
}

/**
 * Return [product_id => "Title (#ID)"] for bookable products only.
 */
public static function get_bookable_products_for_select() : array {
	if ( ! function_exists('wc_get_product') ) return [];

	$q = new \WP_Query([
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'tax_query'      => [[
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => ['booking'],
		]],
	]);

	$out = [];
	foreach ( (array) $q->posts as $pid ) {
		$pid = (int) $pid;
		if ( $pid <= 0 ) continue;
		$p = wc_get_product($pid);
		if ( ! $p ) continue;
		if ( function_exists('is_wc_booking_product') && ! is_wc_booking_product($p) ) continue;
		$out[$pid] = get_the_title($pid) . ' (#' . $pid . ')';
	}

	return $out;
}


	public static function get_logs() : array {
		$logs = get_option(self::OPT_LOGS, []);
		return is_array($logs) ? $logs : [];
	}

	public static function append_log( array $row, int $max = 50 ) : void {
		$logs = self::get_logs();
		$logs[] = $row;
		if ( count($logs) > $max ) {
			$logs = array_slice($logs, -1 * $max);
		}
		update_option(self::OPT_LOGS, $logs, false);
	}

	public static function clear_logs() : void {
		delete_option(self::OPT_LOGS);
	}

	public static function render_page() : void {

		if ( ! current_user_can('manage_options') ) return;

		$form_id = self::get_form_id();

		$forms = [];
		if ( class_exists('GFAPI') ) {
			try {
				$forms = \GFAPI::get_forms();
			} catch ( \Exception $e ) {
				$forms = [];
			}
		}

		echo '<div class="wrap">';
		echo '<h1>TC Booking Flow</h1>';
		echo '<p>Select the Gravity Form that should trigger the Booking Flow (GF → Cart → Order).</p>';

		echo '<form method="post" action="options.php">';
		settings_fields('tc_bf_settings');

		echo '<table class="form-table" role="presentation">';
		echo '<tr>';
		echo '<th scope="row"><label for="'.esc_attr(self::OPT_FORM_ID).'">Booking form</label></th>';
		echo '<td>';

		echo '<select name="'.esc_attr(self::OPT_FORM_ID).'" id="'.esc_attr(self::OPT_FORM_ID).'">';
		echo '<option value="44"'.selected($form_id, 44, false).'>Form #44 (default)</option>';

		if ( is_array($forms) ) {
			foreach ( $forms as $f ) {
				$id = isset($f['id']) ? (int) $f['id'] : 0;
				if ( $id <= 0 ) continue;
				$title = isset($f['title']) ? (string) $f['title'] : ('Form '.$id);
				echo '<option value="'.esc_attr($id).'"'.selected($form_id, $id, false).'>#'.esc_html($id).' — '.esc_html($title).'</option>';
			}
		}

		echo '</select>';

		if ( ! class_exists('GFAPI') ) {
			echo '<p class="description">Gravity Forms not detected. Install/activate Gravity Forms to use this plugin.</p>';
		}

		
// Default participation product (bookable products only)
$default_pid = self::get_default_participation_product_id();
$bookables = self::get_bookable_products_for_select();
echo '<tr>';
echo '<th scope="row"><label for="'.esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID).'">Default participation product</label></th>';
echo '<td>';
echo '<select name="'.esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID).'" id="'.esc_attr(self::OPT_DEFAULT_PARTICIPATION_PRODUCT_ID).'" style="max-width:520px">';
echo '<option value="0"'.selected($default_pid, 0, false).'>— None (use per-event / mapping fallback) —</option>';
foreach ( $bookables as $pid => $label ) {
	echo '<option value="'.esc_attr($pid).'"'.selected($default_pid, (int)$pid, false).'>'.esc_html($label).'</option>';
}
echo '</select>';
echo '<p class="description">Used when an event does not set a participation product. List contains only WooCommerce Bookings products.</p>';
echo '</td></tr>';

echo '</td></tr>';

		// Debug mode
		$debug = self::is_debug();
		echo '<tr>';
		echo '<th scope="row"><label for="'.esc_attr(self::OPT_DEBUG).'">Debug mode</label></th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_DEBUG).'" id="'.esc_attr(self::OPT_DEBUG).'" value="1" '.checked($debug, true, false).' /> Enable logging & diagnostics</label>';
		echo '<p class="description">When enabled, decision logs are written to WooCommerce logs and kept here (last 50).</p>';
		echo '</td></tr>';

		echo '</table>';

		submit_button('Save settings');

		echo '</form>';

		// Diagnostics
		echo '<hr/>';
		echo '<h2>Diagnostics</h2>';
		if ( ! $debug ) {
			echo '<p><em>Debug mode is currently off. Enable it above to collect logs.</em></p>';
		} else {
			// Clear logs
			if ( isset($_POST['tc_bf_clear_logs']) && check_admin_referer('tc_bf_clear_logs') ) {
				self::clear_logs();
				echo '<div class="notice notice-success"><p>Logs cleared.</p></div>';
			}
			$logs = array_reverse( self::get_logs() );
			echo '<form method="post" style="margin:0 0 12px 0;">';
			wp_nonce_field('tc_bf_clear_logs');
			echo '<input type="hidden" name="tc_bf_clear_logs" value="1" />';
			submit_button('Clear logs', 'secondary', 'submit', false);
			echo '</form>';
			if ( empty($logs) ) {
				echo '<p><em>No logs yet.</em> Submit the configured Gravity Form once, then return here.</p>';
			} else {
				echo '<table class="widefat striped" style="max-width: 1200px;">';
				echo '<thead><tr><th style="width:170px;">Time</th><th style="width:220px;">Context</th><th>Data</th></tr></thead><tbody>';
				foreach ( $logs as $row ) {
					$time = isset($row['time']) ? (string) $row['time'] : '';
					$ctx  = isset($row['context']) ? (string) $row['context'] : '';
					$data = isset($row['data']) ? (string) $row['data'] : '';
					echo '<tr>';
					echo '<td>'.esc_html($time).'</td>';
					echo '<td><code>'.esc_html($ctx).'</code></td>';
					echo '<td><pre style="white-space:pre-wrap; margin:0;">'.esc_html($data).'</pre></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';

				$bundle = [
					'site' => home_url(),
					'time' => gmdate('c'),
					'plugin' => 'tc-booking-flow',
					'version' => defined('TC_BF_VERSION') ? TC_BF_VERSION : '',
					'logs' => array_reverse($logs),
				];
				$json = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				echo '<h3 style="margin-top:16px;">Copy debug bundle</h3>';
				echo '<textarea readonly style="width:100%; max-width:1200px; height:240px; font-family:monospace;">'.esc_textarea($json).'</textarea>';
			}
		}

		echo '</div>';
	}
}
