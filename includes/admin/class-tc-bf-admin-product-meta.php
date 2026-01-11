<?php
namespace TC_BF\Admin;

if ( ! defined('ABSPATH') ) exit;

/**
 * Adds a product-level meta field to map sc_event_category slugs to participation products.
 *
 * Meta key: tc_participation_category_key
 */
final class Product_Meta {

	const META_KEY = 'tc_participation_category_key';

	public static function init() : void {
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_field' ] );
	}

	public static function render_field() : void {

		echo '<div class="options_group">';

		woocommerce_wp_text_input( [
			'id'          => self::META_KEY,
			'label'       => 'Participation category key',
			'desc_tip'    => true,
			'description' => 'Match this participation product to a Sugar Calendar event category slug (taxonomy: sc_event_category). Example: tour_de_girona or salidas_guiadas.',
		] );

		echo '</div>';
	}

	public static function save_field( int $post_id ) : void {

		$raw = isset($_POST[self::META_KEY]) ? (string) wp_unslash($_POST[self::META_KEY]) : '';
		$raw = trim($raw);

		if ( $raw === '' ) {
			delete_post_meta($post_id, self::META_KEY);
			return;
		}

		// sanitize to slug-like (allow underscores)
		$san = strtolower($raw);
		$san = preg_replace('/[^a-z0-9_\-]/', '', $san);

		update_post_meta($post_id, self::META_KEY, $san);
	}
}
