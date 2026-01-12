<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Event Meta Access Layer
 *
 * Provides canonical schema (tcbf_*) with automatic mirror-write to legacy keys.
 * Ensures child theme template compatibility by maintaining legacy meta keys.
 *
 * TCBF-11: Event Admin UX Consolidation
 * - Canonical keys = new SSOT (tcbf_*)
 * - Legacy keys = compatibility layer (tc_*, event_price, rental_price_*, etc.)
 * - Migration strategy: canonical-first read with legacy fallback
 * - Mirror-write happens ONLY in save handler (no hooks)
 */
final class EventMeta {

    /**
     * Canonical schema: friendly key => canonical meta key
     *
     * These are the NEW authoritative keys for event configuration.
     * All new code should use these via EventMeta::get() / EventMeta::set().
     */
    private static $CANONICAL_SCHEMA = [
        // Pricing
        'participation_price'       => 'tcbf_participation_price',
        'member_price'              => 'tcbf_member_price',
        'rental_price_road'         => 'tcbf_rental_price_road',
        'rental_price_mtb'          => 'tcbf_rental_price_mtb',
        'rental_price_ebike'        => 'tcbf_rental_price_ebike',
        'rental_price_gravel'       => 'tcbf_rental_price_gravel',

        // Products
        'participation_product_id'  => 'tcbf_participation_product_id',

        // Rentals
        'rental_default_class'      => 'tcbf_rental_default_class',

        // Early Booking
        'eb_enabled'                => 'tcbf_eb_enabled',
        'eb_participation_enabled'  => 'tcbf_eb_participation_enabled',
        'eb_rental_enabled'         => 'tcbf_eb_rental_enabled',
        'eb_rules_json'             => 'tcbf_eb_rules_json',

        // Header Display
        'header_title_mode'         => 'tcbf_header_title_mode',
        'header_title_custom'       => 'tcbf_header_title_custom',
        'header_subtitle'           => 'tcbf_header_subtitle',
        'header_logo_mode'          => 'tcbf_header_logo_mode',
        'header_logo_id'            => 'tcbf_header_logo_id',
        'header_logo_url'           => 'tcbf_header_logo_url',
        'header_show_divider'       => 'tcbf_header_show_divider',
        'header_show_back_link'     => 'tcbf_header_show_back_link',
        'header_back_link_url'      => 'tcbf_header_back_link_url',
        'header_back_link_label'    => 'tcbf_header_back_link_label',
        'header_details_position'   => 'tcbf_header_details_position',
        'header_show_shopkeeper_meta' => 'tcbf_header_show_shopkeeper_meta',

        // Header CSS Variables (sizing)
        'header_subtitle_size'      => 'tcbf_header_subtitle_size',
        'header_padding_bottom'     => 'tcbf_header_padding_bottom',
        'header_details_bottom'     => 'tcbf_header_details_bottom',
        'header_logo_margin_bottom' => 'tcbf_header_logo_margin_bottom',
        'header_logo_max_width'     => 'tcbf_header_logo_max_width',
        'header_title_max_size'     => 'tcbf_header_title_max_size',

        // Content/Display
        'feat_img'                  => 'tcbf_feat_img',
        'inscription'               => 'tcbf_inscription',
        'participants'              => 'tcbf_participants',
    ];

    /**
     * Mirror map: canonical key => legacy key
     *
     * When saving canonical meta, these legacy keys are ALSO updated
     * to maintain backward compatibility with child theme templates.
     */
    private static $MIRROR_MAP = [
        // Pricing
        'tcbf_participation_price'       => 'event_price',
        'tcbf_member_price'              => 'member_price',
        'tcbf_rental_price_road'         => 'rental_price_road',
        'tcbf_rental_price_mtb'          => 'rental_price_mtb',
        'tcbf_rental_price_ebike'        => 'rental_price_ebike',
        'tcbf_rental_price_gravel'       => 'rental_price_gravel',

        // Products (both canonical and legacy product IDs)
        'tcbf_participation_product_id'  => 'tc_participation_product_id',

        // Rentals
        'tcbf_rental_default_class'      => 'tc_default_rental_class',

        // Early Booking
        'tcbf_eb_enabled'                => 'tc_ebd_enabled',
        'tcbf_eb_participation_enabled'  => 'tc_ebd_participation_enabled',
        'tcbf_eb_rental_enabled'         => 'tc_ebd_rental_enabled',
        'tcbf_eb_rules_json'             => 'tc_ebd_rules_json',

        // Header Display
        'tcbf_header_title_mode'         => 'tc_header_title_mode',
        'tcbf_header_title_custom'       => 'tc_header_title_custom',
        'tcbf_header_subtitle'           => 'tc_header_subtitle',
        'tcbf_header_logo_mode'          => 'tc_header_logo_mode',
        'tcbf_header_logo_id'            => 'tc_header_logo_id',
        'tcbf_header_logo_url'           => 'tc_header_logo_url',
        'tcbf_header_show_divider'       => 'tc_header_show_divider',
        'tcbf_header_show_back_link'     => 'tc_header_show_back_link',
        'tcbf_header_back_link_url'      => 'tc_header_back_link_url',
        'tcbf_header_back_link_label'    => 'tc_header_back_link_label',
        'tcbf_header_details_position'   => 'tc_header_details_position',
        'tcbf_header_show_shopkeeper_meta' => 'tc_header_show_shopkeeper_meta',

        // Header CSS Variables
        'tcbf_header_subtitle_size'      => 'tc_header_subtitle_size',
        'tcbf_header_padding_bottom'     => 'tc_header_padding_bottom',
        'tcbf_header_details_bottom'     => 'tc_header_details_bottom',
        'tcbf_header_logo_margin_bottom' => 'tc_header_logo_margin_bottom',
        'tcbf_header_logo_max_width'     => 'tc_header_logo_max_width',
        'tcbf_header_title_max_size'     => 'tc_header_title_max_size',

        // Content/Display
        'tcbf_feat_img'                  => 'feat_img',
        'tcbf_inscription'               => 'inscription',
        'tcbf_participants'              => 'participants',
    ];

    /**
     * Get event meta with canonical-first read and legacy fallback.
     *
     * @param int    $event_id Event post ID
     * @param string $key      Friendly key from CANONICAL_SCHEMA
     * @param mixed  $default  Default value if both canonical and legacy are empty
     * @return mixed
     */
    public static function get( int $event_id, string $key, $default = '' ) {
        if ( $event_id <= 0 ) {
            return $default;
        }

        $canonical_key = self::$CANONICAL_SCHEMA[$key] ?? null;
        if ( ! $canonical_key ) {
            // Unknown key - return default
            return $default;
        }

        // Try canonical first
        $value = get_post_meta( $event_id, $canonical_key, true );

        // If canonical is empty, fall back to legacy
        if ( $value === '' && isset( self::$MIRROR_MAP[$canonical_key] ) ) {
            $legacy_key = self::$MIRROR_MAP[$canonical_key];
            $value = get_post_meta( $event_id, $legacy_key, true );
        }

        return $value === '' ? $default : $value;
    }

    /**
     * Set event meta with automatic mirror-write to legacy keys.
     *
     * IMPORTANT: This method performs BOTH canonical and legacy writes.
     * Use this ONLY in save handlers, NOT in hooks.
     *
     * @param int    $event_id Event post ID
     * @param string $key      Friendly key from CANONICAL_SCHEMA
     * @param mixed  $value    Value to save
     * @return void
     */
    public static function set( int $event_id, string $key, $value ) : void {
        if ( $event_id <= 0 ) {
            return;
        }

        $canonical_key = self::$CANONICAL_SCHEMA[$key] ?? null;
        if ( ! $canonical_key ) {
            // Unknown key - skip
            return;
        }

        // Write canonical
        if ( $value === '' || $value === null ) {
            delete_post_meta( $event_id, $canonical_key );
        } else {
            update_post_meta( $event_id, $canonical_key, $value );
        }

        // Mirror-write to legacy if mapped
        if ( isset( self::$MIRROR_MAP[$canonical_key] ) ) {
            $legacy_key = self::$MIRROR_MAP[$canonical_key];

            if ( $value === '' || $value === null ) {
                delete_post_meta( $event_id, $legacy_key );
            } else {
                update_post_meta( $event_id, $legacy_key, $value );
            }
        }
    }

    /**
     * Delete event meta (both canonical and legacy).
     *
     * @param int    $event_id Event post ID
     * @param string $key      Friendly key from CANONICAL_SCHEMA
     * @return void
     */
    public static function delete( int $event_id, string $key ) : void {
        self::set( $event_id, $key, '' );
    }

    /**
     * Get all canonical keys (for debugging / migration tools).
     *
     * @return array Friendly key => canonical meta key
     */
    public static function get_canonical_schema() : array {
        return self::$CANONICAL_SCHEMA;
    }

    /**
     * Get mirror map (for debugging / migration tools).
     *
     * @return array Canonical key => legacy key
     */
    public static function get_mirror_map() : array {
        return self::$MIRROR_MAP;
    }
}
