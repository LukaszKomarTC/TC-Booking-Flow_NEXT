<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin: per-event Early Booking Discount (EB) rules.
 *
 * Stored as sc_event post meta:
 * - tc_ebd_enabled (0/1)
 * - tc_ebd_participation_enabled (0/1)
 * - tc_ebd_rental_enabled (0/1)
 * - tc_ebd_rules_json (JSON string, schema v1)
 */
final class Admin_Event_EB {

    const NONCE_ACTION = 'tc_bf_save_event_eb';
    const NONCE_FIELD  = '_tc_bf_event_eb_nonce';

    public static function init() : void {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_sc_event', [__CLASS__, 'save_meta'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'maybe_notice']);
    }

    public static function add_meta_box() : void {
        add_meta_box(
            'tc_bf_event_eb',
            __('TC — Early Booking Discount', 'tc-booking-flow'),
            [__CLASS__, 'render_meta_box'],
            'sc_event',
            'normal',
            'default'
        );
    }

    public static function render_meta_box( \WP_Post $post ) : void {
		$enabled = (string) get_post_meta($post->ID, 'tc_ebd_enabled', true) === '1';
		$p_en    = (string) get_post_meta($post->ID, 'tc_ebd_participation_enabled', true);
		$r_en    = (string) get_post_meta($post->ID, 'tc_ebd_rental_enabled', true);
		$rules   = (string) get_post_meta($post->ID, 'tc_ebd_rules_json', true);

        // Defaults: participation+rentals enabled if empty.
        $p_checked = ($p_en === '' ? true : in_array(strtolower($p_en), ['1','yes','true','on'], true));
        $r_checked = ($r_en === '' ? true : in_array(strtolower($r_en), ['1','yes','true','on'], true));

        if ( $rules === '' ) {
            $rules = wp_json_encode([
                'version'  => 1,
                'stacking' => 'before_partner_coupon',
                'basis'    => 'base_total',
                'currency' => get_woocommerce_currency(),
                'global_cap' => [ 'enabled' => false, 'amount' => 0 ],
                'steps' => [
                    [ 'min_days_before' => 90, 'type' => 'percent', 'value' => 15, 'cap' => [ 'enabled' => false, 'amount' => 0 ] ],
                    [ 'min_days_before' => 30, 'type' => 'percent', 'value' => 5,  'cap' => [ 'enabled' => false, 'amount' => 0 ] ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            // Pretty print if valid JSON.
            $decoded = json_decode($rules, true);
            if ( is_array($decoded) ) {
                $rules = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        echo '<p><label><input type="checkbox" name="tc_ebd_enabled" value="1" ' . checked($enabled, true, false) . '> ' . esc_html__('Enable Early Booking Discount for this event', 'tc-booking-flow') . '</label></p>';

        echo '<p style="margin-top:10px;">' . esc_html__('Apply EB to:', 'tc-booking-flow') . '<br>';
        echo '<label style="margin-right:16px;"><input type="checkbox" name="tc_ebd_participation_enabled" value="1" ' . checked($p_checked, true, false) . '> ' . esc_html__('Participation', 'tc-booking-flow') . '</label>';
        echo '<label><input type="checkbox" name="tc_ebd_rental_enabled" value="1" ' . checked($r_checked, true, false) . '> ' . esc_html__('Rental', 'tc-booking-flow') . '</label>';
        echo '</p>';

        echo '<p style="margin-top:10px;"><strong>' . esc_html__('Rules JSON (schema v1)', 'tc-booking-flow') . '</strong><br>';
        echo '<span style="color:#666;">' . esc_html__('Steps are evaluated by min_days_before (highest match wins). Supports percent or fixed amount, optional caps. Applied before partner coupon.', 'tc-booking-flow') . '</span></p>';

        echo '<textarea name="tc_ebd_rules_json" rows="14" style="width:100%;font-family:monospace;">' . esc_textarea($rules) . '</textarea>';
    }

    public static function save_meta( int $post_id, \WP_Post $post ) : void {

        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION) ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        $enabled = isset($_POST['tc_ebd_enabled']) ? '1' : '0';
		update_post_meta($post_id, 'tc_ebd_enabled', $enabled);

		update_post_meta($post_id, 'tc_ebd_participation_enabled', isset($_POST['tc_ebd_participation_enabled']) ? '1' : '0');
		update_post_meta($post_id, 'tc_ebd_rental_enabled',         isset($_POST['tc_ebd_rental_enabled']) ? '1' : '0');

        $rules_raw = isset($_POST['tc_ebd_rules_json']) ? (string) wp_unslash($_POST['tc_ebd_rules_json']) : '';
        $rules_raw = trim($rules_raw);

        if ( $rules_raw === '' ) {
			delete_post_meta($post_id, 'tc_ebd_rules_json');
            return;
        }

        $decoded = json_decode($rules_raw, true);
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) ) {
            // Store a transient notice and keep previous value unchanged.
            set_transient('tc_bf_event_eb_json_error_' . $post_id, (string) json_last_error_msg(), 60);
            return;
        }

        // Normalize: ensure version and steps array exist.
        if ( empty($decoded['version']) ) $decoded['version'] = 1;
        if ( empty($decoded['steps']) || ! is_array($decoded['steps']) ) $decoded['steps'] = [];

        // Sort steps by min_days_before DESC.
        usort($decoded['steps'], function($a,$b){
            return ((int)($b['min_days_before'] ?? 0)) <=> ((int)($a['min_days_before'] ?? 0));
        });

		update_post_meta($post_id, 'tc_ebd_rules_json', wp_json_encode($decoded, JSON_UNESCAPED_SLASHES));
    }

    public static function maybe_notice() : void {
        global $pagenow;
        if ( $pagenow !== 'post.php' ) return;
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if ( ! $post_id ) return;
        $msg = get_transient('tc_bf_event_eb_json_error_' . $post_id);
        if ( ! $msg ) return;
        delete_transient('tc_bf_event_eb_json_error_' . $post_id);
        echo '<div class="notice notice-error"><p>' . esc_html__('TC Booking Flow: EB rules JSON is invalid and was not saved. Error:', 'tc-booking-flow') . ' ' . esc_html($msg) . '</p></div>';
    }
}

// Boot in admin only
if ( is_admin() ) {
    Admin_Event_EB::init();
}
