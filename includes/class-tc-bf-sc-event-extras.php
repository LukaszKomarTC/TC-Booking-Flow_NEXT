<?php
namespace TC_BF;

use TC_BF\Admin\Settings;

if ( ! defined('ABSPATH') ) exit;

/**
 * SC Event Extras
 * Moves the key sc_event-related snippets into the plugin:
 * - Event meta box (event_price + rental_price_* + header options)
 * - Append Inscription (GF) + Participants (GravityView) blocks to sc_event content (optional per event)
 *
 * NOTE: If you still have the old Code Snippets versions active, DISABLE them to avoid duplicates.
 */
final class Sc_Event_Extras {

    /*
     * NOTE (Stage 3 - GF decimal comma protection):
     * Some GF Single Product base price inputs can be mis-parsed after conditional logic
     * hide/show cycles when values are locale-formatted (e.g. "30,00 €").
     * If GF treats comma as thousands separator, "30,00" becomes "3000,00" internally.
     * The Stage 3 JS repair in output_sc_event_inline_js() caches intended base prices
     * on first render and restores them after gform_post_conditional_logic when it detects
     * a clear x100/x1000 multiplier jump.
     *
     * This is intentionally narrow: it repairs only the separator-misparse symptom without
     * changing the business logic or the PHP ledger.
     */

    const NONCE_KEY = 'tc_bf_sc_event_meta_nonce';

    public static function init() : void {
        // Ensure sc_event_tag taxonomy exists (keeps Event Tags box visible)
        add_action('init', [__CLASS__, 'ensure_event_tag_taxonomy'], 5);

        // Admin meta box
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('save_post_sc_event', [__CLASS__, 'save_metabox'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);

        // Frontend content injections
        add_filter('the_content', [__CLASS__, 'filter_sc_event_content'], 11);

        // Per-event header CSS variables (migrated from snippet #163 style attribute)
        // This keeps your template + Additional CSS working even if the header wrapper no longer
        // carries an inline `style="--tc-..."` attribute.
        add_action('wp_head', [__CLASS__, 'output_sc_event_header_css_vars'], 5);

        // GF population + frontend JS (migrated from your "sc_event - add PHP and JS" snippet)
        add_action('wp', [__CLASS__, 'maybe_hook_gf_population']);
        add_action('wp_head', [__CLASS__, 'output_sc_event_inline_js'], 50);

        // Admin-only frontend diagnostics (helps verify injection/population quickly)
        add_action('wp_footer', [__CLASS__, 'output_admin_sc_event_diagnostics'], 5);
    }

    /**
     * Output per-event CSS variables used by your custom single-sc_event header.
     *
     * Legacy (snippet #163) used inline style attribute, e.g.
     *   style="--tc-logo-max:155px;--tc-title-max:70px;--tc-subtitle-max:40px;"
     *
     * After moving that logic into the plugin we keep the *same variables* by emitting
     * a tiny scoped <style> block.
     */
    public static function output_sc_event_header_css_vars() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) return;

        // Read meta (all are optional)
        $logo_max   = trim((string) get_post_meta($event_id, 'tc_header_logo_max_width', true));
        $title_max  = trim((string) get_post_meta($event_id, 'tc_header_title_max_size', true));
        $sub_max    = trim((string) get_post_meta($event_id, 'tc_header_subtitle_size', true));
        $pad_bottom = trim((string) get_post_meta($event_id, 'tc_header_padding_bottom', true));
        $details_bt = trim((string) get_post_meta($event_id, 'tc_header_details_bottom', true));
        $logo_mb    = trim((string) get_post_meta($event_id, 'tc_header_logo_margin_bottom', true));

        $vars = [];
        if ( $logo_max !== '' && is_numeric($logo_max) ) {
            $px = (int) $logo_max;
            if ( $px > 0 ) $vars[] = "--tc-logo-max:{$px}px";
        }
        if ( $title_max !== '' && is_numeric($title_max) ) {
            $px = (int) $title_max;
            if ( $px > 0 ) $vars[] = "--tc-title-max:{$px}px";
        }
        if ( $sub_max !== '' && is_numeric($sub_max) ) {
            $px = (int) $sub_max;
            if ( $px > 0 ) {
                // Keep both names (old/new) to stay compatible with any CSS variant you used.
                $vars[] = "--tc-subtitle-max:{$px}px";
                $vars[] = "--tc-subtitle-size:{$px}px";
            }
        }
        if ( $pad_bottom !== '' && is_numeric($pad_bottom) ) {
            $px = (int) $pad_bottom;
            if ( $px >= 0 ) $vars[] = "--tc-header-padding-bottom:{$px}px";
        }
        if ( $details_bt !== '' && is_numeric($details_bt) ) {
            $px = (int) $details_bt;
            if ( $px > 0 ) $vars[] = "--tc-details-bottom:{$px}px";
        }
        if ( $logo_mb !== '' && is_numeric($logo_mb) ) {
            $px = (int) $logo_mb;
            if ( $px >= 0 ) {
                // Again, keep multiple names to be safe.
                $vars[] = "--tc-logo-margin-bottom:{$px}px";
                $vars[] = "--tc-logo-mb:{$px}px";
            }
        }

        if ( empty($vars) ) return;

        // Scope to this event only.
        $selector = 'body.postid-' . $event_id . ' .single-post-header';
        echo "\n<style id=\"tc-bf-sc-event-header-vars\">{$selector}{" . esc_html(implode(';', $vars)) . ";}</style>\n";
    }

    /**
     * Lightweight logger for this class (uses TC Booking Flow debug mode).
     */
    private static function log( string $context, array $data = [], string $level = 'info' ) : void {
        if ( ! class_exists('TC_BF\\Admin\\Settings') || ! \TC_BF\Admin\Settings::is_debug() ) return;
        $row = [
            'time'    => (string) current_time('mysql'),
            'context' => $context,
            'data'    => (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES),
        ];
        \TC_BF\Admin\Settings::append_log($row, 50);
        if ( function_exists('wc_get_logger') ) {
            try {
                wc_get_logger()->log($level, $row['context'].' '.$row['data'], ['source' => 'tc-booking-flow']);
            } catch ( \Throwable $e ) {}
        }
    }

    /**
     * Translate qTranslate-style strings like:
     *   '[:es]Hola[:en]Hello[:]'.
     *
     * Uses qTranslate-X / qTranslate-xt if available, otherwise falls back to:
     * - current locale (es/en) extraction
     * - or a safe tag-stripped fallback.
     */
    private static function tr( string $text ) : string {
        $text = (string) $text;

        // Prefer qTranslate-X/xt helper if present.
        if ( function_exists( 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage' ) ) {
            try {
                return (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $text );
            } catch ( \Throwable $e ) {
                // fall through
            }
        }

        // Determine desired language code (es/en).
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $lang   = strtolower( substr( $locale, 0, 2 ) );
        if ( $lang !== 'es' && $lang !== 'en' ) {
            // Site is ES-first; use ES as default.
            $lang = 'es';
        }

        // Parse qTranslate blocks: [:xx]... (until next [:..] or [:])
        // Example: [:es]Hola[:en]Hello[:]
        $pattern = '/\[:([a-z]{2})\](.*?)(?=(\[:[a-z]{2}\])|\[:\]|\z)/si';
        if ( preg_match_all( $pattern, $text, $m, PREG_SET_ORDER ) ) {
            $map = [];
            foreach ( $m as $row ) {
                $code = strtolower( $row[1] );
                $map[$code] = $row[2];
            }
            if ( isset( $map[$lang] ) && $map[$lang] !== '' ) {
                return (string) $map[$lang];
            }
            // fallback to ES, then EN
            if ( isset( $map['es'] ) && $map['es'] !== '' ) return (string) $map['es'];
            if ( isset( $map['en'] ) && $map['en'] !== '' ) return (string) $map['en'];
        }

        // Final fallback: strip qTranslate markers.
        $text = preg_replace( '/\[:[a-z]{2}\]/i', '', $text );
        $text = str_replace( '[:]', '', $text );
        return trim( (string) $text );
    }

    /**
     * Hook GF population only on single sc_event pages.
     */
    public static function maybe_hook_gf_population() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        $form_id  = absint( get_option(Settings::OPT_FORM_ID, 44) );
        if ( $form_id <= 0 ) return;

        self::log('frontend.gf_population.attach', [
            'event_id' => $event_id,
            'form_id'  => $form_id,
            'hook'     => 'wp',
        ]);

        add_filter('gform_pre_render', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_pre_validation', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_admin_pre_render', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_pre_submission_filter', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
    }

    /**
     * Minimal local helper to produce the date range used for availability checks.
     * Uses start day 00:00 and end day +1 exclusive (matches your snippet's intention).
     */
    private static function get_event_date_range( int $event_id ) : array {
        if ( function_exists('tc_sc_event_dates') ) {
            $d = (array) tc_sc_event_dates($event_id);
            if ( ! empty($d['range_start_ts']) && ! empty($d['range_end_exclusive_ts']) ) {
                return [
                    'range_start_ts' => (int) $d['range_start_ts'],
                    'range_end_exclusive_ts' => (int) $d['range_end_exclusive_ts'],
                ];
            }
        }

        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        if ( $start_ts <= 0 ) {
            return [ 'range_start_ts' => 0, 'range_end_exclusive_ts' => 0 ];
        }

        // Use UTC timestamps stored by Sugar Calendar.
        $start_day = (int) strtotime(gmdate('Y-m-d 00:00:00', $start_ts) . ' UTC');
        $end_excl  = $start_day + DAY_IN_SECONDS;
        return [ 'range_start_ts' => $start_day, 'range_end_exclusive_ts' => $end_excl ];
    }

    /**
     * GF population callback (ported from Code Snippets).
     * NOTE: Field IDs are still the current GF44 IDs (Phase 3 will replace these with mapping).
     */
    private static function populate_choices( $form, int $form_id ) {
        if ( empty($form['id']) || (int) $form['id'] !== (int) $form_id ) {
            return $form;
        }

        self::log('frontend.gf_population.run', [
            'form_id'   => (int) $form['id'],
            'source'    => (string) current_filter(),
        ]);

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            $event_id = (int) get_the_ID();
        }
        if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) {
            self::log('frontend.gf_population.skip', [
                'reason'   => 'not_sc_event',
                'event_id' => $event_id,
                'post_type'=> $event_id ? get_post_type($event_id) : '',
            ], 'warning');
            return $form;
        }

        self::log('frontend.sc_event.detected', [
            'event_id' => $event_id,
            'title'    => get_the_title($event_id),
        ]);

        // --- Hotel user list for "inscription_for" field (unchanged logic) ---
        $hotel_users = get_users([
            'role'    => 'hotel',
            'orderby' => 'user_nicename',
            'order'   => 'ASC',
        ]);
        $hotel_users_array = [];
        $current_user = wp_get_current_user();
        $hotel_users_array[] = [
            'text'  => $current_user->display_name . ' (' . __( '[:es]Reserva directa[:en]Direct booking[:]', 'tc-booking-flow' ) . ')',
            'value' => '',
        ];
        foreach ( $hotel_users as $user ) {
            $discount_code = (string) get_user_meta($user->ID, 'discount__code', true);
            $hotel_users_array[] = [
                'text'  => $user->display_name . ' (' . $discount_code . ')',
                'value' => $discount_code,
            ];
        }

        $cat_array = get_the_terms($event_id, 'sc_event_category');
        if ( empty($cat_array) || is_wp_error($cat_array) ) {
            self::log('frontend.gf_population.no_categories', [
                'event_id' => $event_id,
            ], 'warning');
            return $form;
        }

        $range = self::get_event_date_range($event_id);
        $range_start_ts = (int) ($range['range_start_ts'] ?? 0);
        $range_end_exclusive_ts = (int) ($range['range_end_exclusive_ts'] ?? 0);

        $dateTimeStart = new \DateTime('@' . $range_start_ts);
        $dateTimeEnd   = new \DateTime('@' . $range_end_exclusive_ts);

        // Category->product category mapping (same as snippet)
        foreach ( $cat_array as $cat_id_obj ) {

            $cat_slug = (string) $cat_id_obj->slug;

            if ( $cat_slug === 'rental_road' )      { $category_id = 208; }
            else if ( $cat_slug === 'rental_mtb' )  { $category_id = 207; }
            else if ( $cat_slug === 'rental_emtb' ) { $category_id = 209; }
            else if ( $cat_slug === 'rental_gravel' ){ $category_id = 219; }
            else { continue; }

            $the_query = new \WP_Query([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'tax_query'      => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'product_cat',
                        'terms'    => $category_id,
                    ],
                    [
                        'taxonomy' => 'product_type',
                        'terms'    => ['booking'],
                        'field'    => 'slug',
                    ],
                ],
            ]);

            if ( $the_query->have_posts() ) {

                $choices = [];

                while ( $the_query->have_posts() ) {

                    $the_query->the_post();

                    $_product = wc_get_product(get_the_ID());
                    $prod_id  = get_the_ID();

                    if ( ! $_product || ! method_exists($_product,'has_resources') || ! $_product->has_resources() ) {
                        continue;
                    }

                    foreach ( $_product->get_resources() as $resource ) {

                        $start_ts = (int) $dateTimeStart->getTimestamp();
                        $end_ts   = (int) $dateTimeEnd->getTimestamp();

                        // 1) Start from configured capacity (resource qty > product qty fallback)
                        $available_qty = $resource->has_qty() ? (int) $resource->get_qty() : (int) $_product->get_qty();

                        // 2) HARD GATE: apply Woo Bookings availability rules (global + product + resource)
                        if ( class_exists( 'WC_Product_Booking_Rule_Manager' ) ) {
                            $rules_ok = \WC_Product_Booking_Rule_Manager::check_range_availability_rules(
                                $_product,
                                (int) $resource->ID,
                                $start_ts,
                                $end_ts
                            );
                            if ( ! $rules_ok ) {
                                $available_qty = 0;
                            }
                        }

                        // 3) If still potentially available, subtract booked qty (INCLUDING in-cart holds)
                        if ( $available_qty > 0 && class_exists( 'WC_Bookings_Controller' ) ) {

                            $booking_ids = \WC_Bookings_Controller::get_bookings_in_date_range(
                                $start_ts,
                                $end_ts,
                                (int) $resource->ID,
                                true // include in-cart bookings
                            );

                            $booked_qty_resource = 0;

                            if ( is_array($booking_ids) ) {
                                foreach ( $booking_ids as $booking_id ) {
                                    $booking = function_exists('get_wc_booking') ? get_wc_booking($booking_id) : null;
                                    if ( ! $booking ) continue;

                                    $qty = 1;

                                    if ( method_exists($booking,'get_persons') ) {
                                        $persons = (array) $booking->get_persons();
                                        $sum = 0;
                                        foreach ( $persons as $p ) { $sum += (int) $p; }
                                        if ( $sum > 0 ) $qty = $sum;
                                    }

                                    if ( method_exists($booking,'get_qty') ) {
                                        $q = (int) $booking->get_qty();
                                        if ( $q > 0 ) $qty = $q;
                                    }

                                    $booked_qty_resource += max(1, $qty);
                                }
                            }

                            $available_qty = max(0, $available_qty - $booked_qty_resource);
                        }

                        $featured_image = wp_get_attachment_url($_product->get_image_id());

                        $size_line =
                            "<br><p style='font-size:x-large;font-weight:bolder;margin-bottom:0'>" .
                            __( '[:es]Talla:[:en]Size[:]', 'tc-booking-flow' ) .
                            "<span style='display:inline-block;width:6px'></span>" .
                            esc_html($resource->get_title()) .
                            "</p>";

                        $left_line = "";
                        if ( $available_qty > 0 ) {
                            $left_line = "<p style='margin-top:6px;margin-bottom:0;opacity:.85;font-size:14px;'>"
                                . (int) $available_qty . " " . __( '[:es]disponibles[:en]left[:]', 'tc-booking-flow' ) .
                                "</p>";
                        }

                        // NOT AVAILABLE
                        if ( $available_qty <= 0 ) {

                            $choice = [];
                            $choice['value'] = 'not_avail_' . $resource->ID;
                            $choice['imageChoices_image'] = $featured_image;
                            $choice['imageChoices_imageID'] = $_product->get_image_id();
                            $choice['imageChoices_largeImage'] = $featured_image;

                            $choice['text'] =
                                get_the_title() .
                                $size_line .
                                "<p style='background-color:red;color:white;margin-bottom:-25px;position:relative;top:-150px;z-index:1;transform:rotate(-45deg)'>"
                                . __( '[:es]NO DISPONIBLE[:en]NOT AVAILABLE[:]', 'tc-booking-flow' ) .
                                "</p>";

                            $choices[] = $choice;
                        }

                        // AVAILABLE
                        if ( $available_qty > 0 ) {

                            $choice = [];
                            $choice['value'] = $prod_id . '_' . $resource->ID;
                            $choice['imageChoices_image'] = $featured_image;
                            $choice['imageChoices_imageID'] = $_product->get_image_id();
                            $choice['imageChoices_largeImage'] = $featured_image;

                            $choice['text'] =
                                get_the_title() .
                                $size_line .
                                $left_line;

                            $choices[] = $choice;
                        }
                    }
                }

                foreach ( $form['fields'] as &$field ) {
                    if ( function_exists('gf_image_choices') && gf_image_choices() ) {
                        if ( $cat_slug === 'rental_road' && (int) $field->id === 130 && gf_image_choices()->field_has_image_choices_enabled($field) && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                            $field->choices = $choices;
                        }
                        if ( $cat_slug === 'rental_mtb' && (int) $field->id === 142 && gf_image_choices()->field_has_image_choices_enabled($field) && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                            $field->choices = $choices;
                        }
                        if ( $cat_slug === 'rental_emtb' && (int) $field->id === 143 && gf_image_choices()->field_has_image_choices_enabled($field) && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                            $field->choices = $choices;
                        }
                        if ( $cat_slug === 'rental_gravel' && (int) $field->id === 169 && gf_image_choices()->field_has_image_choices_enabled($field) && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                            $field->choices = $choices;
                        }
                    }
                }
            }

            wp_reset_postdata();
        }

        // available modalities - related to event categories slugs
        $modalities = [
            'btt',
            'btt_ladies_special',
            'btt_easy', 'carretera',
            'carretera_ladies_special',
            'carretera_easy',
            'carretera_medium',
            'ebike',
            'gravel',
            'gravel_easy',
            'participante',
            'voluntario',
            'none',
            'walking',
        ];

        // count available modalities
        $cat_number = 0;
        foreach ( $cat_array as $cat ) {
            if ( in_array($cat->slug, $modalities, true) ) {
                $cat_number++;
            }
        }

        foreach ( $form['fields'] as &$field ) {

            // populate "in the name of"
            if ( $field->type === 'select' && strpos((string)$field->cssClass, 'inscription_for') !== false ) {
                $field->choices = $hotel_users_array;
            }

            // construct modalities field (GF44 id=4)
            if ( (int) $field->id === 4 ) {
                $n = 0;
                $inputs = [];
                $choices = [];
                foreach ( $cat_array as $radio ) {
                    if ( in_array($radio->slug, $modalities, true) ) {
                        $n++;
                        $choices[] = [ 'text' => $radio->name, 'value' => $radio->slug ];
                        $inputs[]  = [ 'label' => $radio->name, 'id' => $n ];
                    }
                }
                $field->choices = $choices;
                $field->inputs  = $inputs;
            }

            // populate the available modalities number field (GF44 id=144)
            if ( (int) $field->id === 144 ) {
                $field->defaultValue = $cat_number;
            }
        }

        // X for hidden helper fields for event categories in gform
        $cat_array2 = get_the_terms($event_id, 'sc_event_category');
        if ( $cat_array2 && ! is_wp_error($cat_array2) ) {
            foreach ( $cat_array2 as $cat_id_obj ) {
                $slug = (string) $cat_id_obj->slug;
                echo "<script>console.log('" . esc_js($slug) . "-X');</script>";
                ${"fill_field_{$slug}"} = function() { return "X"; };
                add_filter('gform_field_value_' . $slug, ${"fill_field_{$slug}"} );
            }
        }

        // populate price fields from meta
        $event_price = get_post_meta($event_id, 'event_price', true);
        if ( isset($event_price) ) {
            add_filter('gform_field_value_event_price', function() use ($event_id) {
                return get_post_meta($event_id, 'event_price', true);
            });
        }

        $member_price = get_post_meta($event_id, 'member_price', true);
        if ( isset($member_price) ) {
            add_filter('gform_field_value_member_price', function() use ($event_id) {
                return get_post_meta($event_id, 'member_price', true);
            });
        }

        foreach ([
            'rental_price_road'   => 'rental_price_road',
            'rental_price_mtb'    => 'rental_price_mtb',
            'rental_price_ebike'  => 'rental_price_ebike',
            'rental_price_gravel' => 'rental_price_gravel',
        ] as $input => $meta_key ) {
            $val = get_post_meta($event_id, $meta_key, true);
            if ( isset($val) ) {
                add_filter('gform_field_value_' . $input, function() use ($event_id, $meta_key) {
                    return get_post_meta($event_id, $meta_key, true);
                });
            }
        }

        return $form;
    }

    /**
     * Frontend JS injection (ported from snippet, but consolidated).
     * NOTE: This handles:
     * - setting date helper fields (131/132/145/133/134)
     * - showing modalities tabs
     * - rental select option removal
     * - disabling not_avail radio options
     */
    public static function output_sc_event_inline_js() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) return;

        $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );
        if ( $form_id <= 0 ) return;

        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        $end_ts   = (int) get_post_meta($event_id, 'sc_event_end_date_time', true);

        $start_label = $start_ts ? date_i18n('d-m-Y H:i', $start_ts) : '';
        $end_label   = $end_ts ? date_i18n('d-m-Y H:i', $end_ts) : '';

        $event_price         = (string) get_post_meta($event_id, 'event_price', true);
        $rental_price_road   = (string) get_post_meta($event_id, 'rental_price_road', true);
        $rental_price_mtb    = (string) get_post_meta($event_id, 'rental_price_mtb', true);
        $rental_price_ebike  = (string) get_post_meta($event_id, 'rental_price_ebike', true);
        $rental_price_gravel = (string) get_post_meta($event_id, 'rental_price_gravel', true);

        $js_array = wp_json_encode( get_the_terms($event_id, 'sc_event_category') );

        // Default rental class (optional, from settings / meta)
        $default_rental_class = (string) get_post_meta($event_id, 'tc_default_rental_class', true);

        echo "\n<script id=\"tc-bf-sc-event-inline-js\">\n";
        ?>
        jQuery(function($){
            var fid = <?php echo (int) $form_id; ?>;

            // Debug helper (admin only)
            function tcBfDebug(msg, obj){
                try {
                    if (window.tcBfIsAdmin) console.log('[TCBF]', msg, obj || '');
                } catch(e) {}
            }

            // Optional admin flag injected by footer diagnostics
            window.tcBfIsAdmin = window.tcBfIsAdmin || false;

            // If form isn't present, exit.
            if (!$('#gform_'+fid).length) return;

            // Set hidden date/event fields (GF44 legacy IDs)
            $("#input_"+fid+"_131").val("<?php echo esc_js($start_label); ?>");
            $("#input_"+fid+"_132").val("<?php echo (int) $start_ts; ?>");
            $("#input_"+fid+"_145").val("<?php echo (int) $event_id; ?>_<?php echo (int) $start_ts; ?>");
            if (<?php echo (int) $end_ts; ?> > 0) {
                $("#input_"+fid+"_133").val("<?php echo esc_js($end_label); ?>");
                $("#input_"+fid+"_134").val("<?php echo (int) $end_ts; ?>");
            }

            /**
             * IMPORTANT (GF conditional logic):
             * In your current form, the whole Rental section (section field id 75)
             * is controlled by conditional logic that depends on hidden/price fields:
             * 50, 55, 56, 170 (and sometimes gravel).
             *
             * If these aren't set at render time, the section stays hidden even if
             * bike fields exist. So we set them here (authoritative from event meta)
             * and trigger GF logic refresh.
             */
            var tcBfMetaPrices = {
                event_price: "<?php echo esc_js((string) $event_price); ?>",
                road:        "<?php echo esc_js((string) $rental_price_road); ?>",
                mtb:         "<?php echo esc_js((string) $rental_price_mtb); ?>",
                ebike:       "<?php echo esc_js((string) $rental_price_ebike); ?>",
                gravel:      "<?php echo esc_js((string) $rental_price_gravel); ?>"
            };

            function tcBfToFloat(v){
                if (v === null || typeof v === 'undefined') return 0;
                v = String(v).replace(/\s+/g,'').replace(/€/g,'').replace(/,/g,'.');
                var n = parseFloat(v);
                return isNaN(n) ? 0 : n;
            }

            // Availability flags used by GF conditional logic (Section 57 shows if ANY of these == 'X')
            // Derived from GF form export (Form 44):
            // - 55 = Road rental flag
            // - 50 = MTB rental flag
            // - 56 = E-bike rental flag
            // - 170 = Gravel rental flag
            var tcBfAvailFlags = {
                55: (tcBfToFloat(tcBfMetaPrices.road)   > 0) ? 'X' : '',
                50: (tcBfToFloat(tcBfMetaPrices.mtb)    > 0) ? 'X' : '',
                56: (tcBfToFloat(tcBfMetaPrices.ebike)  > 0) ? 'X' : '',
                170:(tcBfToFloat(tcBfMetaPrices.gravel) > 0) ? 'X' : ''
            };

            // Apply GF conditional-logic driver flags (X / empty) and schedule ONE logic+total refresh.
            // IMPORTANT: These fields are FLAGS ONLY in Form 48 conditional logic. Do not write numeric prices here.
            var tcBfRecalcTimer = null;

            function tcBfSilentSet($inp, val){
                if (!$inp || !$inp.length) return;
                var next = (val === null || typeof val === 'undefined') ? '' : String(val);
                if ($inp.val() === next) return;
                $inp.val(next); // no per-field change events (avoid cascading GF recalcs)
            }

            function tcBfRecalcOnce(){
                if (tcBfRecalcTimer) window.clearTimeout(tcBfRecalcTimer);
                tcBfRecalcTimer = window.setTimeout(function(){
                    try {
                        if (typeof window.gf_apply_rules === 'function') {
                            window.gf_apply_rules(fid, [], true);
                        }
                        if (typeof window.gformCalculateTotalPrice === 'function') {
                            window.gformCalculateTotalPrice(fid);
                        }
                    } catch(e) {}
                }, 30);
            }

            function tcBfApplyDriverFlags(){
                // Set driver flags (X / empty) that control rental section visibility.
                tcBfSilentSet($("#input_"+fid+"_55"),  tcBfAvailFlags[55]  || '');
                tcBfSilentSet($("#input_"+fid+"_50"),  tcBfAvailFlags[50]  || '');
                tcBfSilentSet($("#input_"+fid+"_56"),  tcBfAvailFlags[56]  || '');
                tcBfSilentSet($("#input_"+fid+"_170"), tcBfAvailFlags[170] || '');

                tcBfRecalcOnce();
            }

            /**
             * Rental UI lifecycle repair (Roadmap #1)
             *
             * When the Rental section is hidden, Gravity Forms disables all inputs inside it.
             * With Image Choices, GF may re-render markup after conditional-logic changes,
             * and the disabled state can persist when the section becomes visible again.
             *
             * We *only* repair enabled/disabled state when the section is visible.
             * We still keep any "not_avail_*" options disabled.
             */
            function tcBfRepairRentalBikeChoices(){
                // Section field id that wraps all rental UI (legacy GF44)
                var $section = $('#field_'+fid+'_57');
                if (!$section.length) return;

                // Consider both display and GF visibility classes
                var isVisible = $section.is(':visible') && !$section.hasClass('gfield_visibility_hidden');
                if (!isVisible) return;

                // Bike image-choice fields (legacy GF44)
                var bikeFields = [130, 142, 143, 169];
                for (var i=0; i<bikeFields.length; i++) {
                    var fieldId = bikeFields[i];
                    var $f = $('#field_'+fid+'_'+fieldId);
                    if (!$f.length) continue;
                    $f.find('input[type=radio]').each(function(){
                        var $r = $(this);
                        var v = String($r.val() || '');
                        // Re-enable everything first (GF may have disabled it)
                        $r.prop('disabled', false);
                        // Then keep not-available options disabled
                        if (v.indexOf('not_avail') >= 0) $r.prop('disabled', true);
                    });
                }
            }

            // Debounced scheduler so we can call repair after GF updates the DOM.
            var tcBfRepairTimer = null;
            function tcBfScheduleRepair(){
                if (tcBfRepairTimer) window.clearTimeout(tcBfRepairTimer);
                tcBfRepairTimer = window.setTimeout(function(){
                    try { tcBfRepairRentalBikeChoices(); } catch(e) {}
                }, 50);
            }

            /**
             * Stage 3: Protect Single Product base prices from GF locale re-parse bugs after hide/show.
             *
             * Symptom: "30,00 €" becomes internally treated as "3000,00 €" after conditional logic toggles.
             * Strategy:
             *  - Cache the *intended* numeric value for each Single Product base price input at initial render.
             *  - After GF conditional logic runs, compare current numeric to intended.
             *  - If the current value is exactly 100x (or 1000x) the intended value, restore the intended display.
             *
             * This avoids touching pricing logic globally and only repairs obvious separator-misparse cases.
             */
            function tcBfParseMoneyToNumber(raw){
                if (raw === null || raw === undefined) return 0;
                var s = String(raw).trim();
                if (!s) return 0;

                // remove currency and NBSP
                s = s.replace(/ /g, ' ').replace(/€/g,'').trim();

                // keep digits, comma, dot, minus, spaces
                s = s.replace(/[^\d,\.\-\s]/g,'');
                s = s.replace(/\s+/g,'');

                // If both "." and "," exist, assume "." thousands and "," decimal (1.234,56)
                if (s.indexOf(',') !== -1 && s.indexOf('.') !== -1) {
                    s = s.replace(/\./g,'');
                    s = s.replace(',', '.');
                } else if (s.indexOf(',') !== -1) {
                    // Only comma: treat as decimal
                    s = s.replace(',', '.');
                }
                var n = parseFloat(s);
                return isNaN(n) ? 0 : n;
            }

            function tcBfFormatEuroComma(n){
                if (typeof n !== 'number' || isNaN(n)) n = 0;
                // two decimals, comma decimal separator, dot thousands
                var fixed = n.toFixed(2);
                var parts = fixed.split('.');
                var intPart = parts[0];
                var decPart = parts[1] || '00';
                // thousands grouping with dot
                intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return intPart + ',' + decPart + ' €';
            }

            function tcBfGetSingleProductBasePriceInputs(){
                // GF single product: id="ginput_base_price_{formId}_{fieldId}"
                return $("#gform_"+fid+" input[id^='ginput_base_price_"+fid+"_']");
            }

            function tcBfCacheIntendedSingleProductPrices(){
                tcBfGetSingleProductBasePriceInputs().each(function(){
                    var $inp = $(this);
                    if ($inp.data('tcIntended') !== undefined) return;
                    var n = tcBfParseMoneyToNumber($inp.val());
                    // store only meaningful positives
                    if (n > 0) {
                        $inp.data('tcIntended', n);
                    }
                });
            }

            function tcBfRepairSingleProductBasePrices(){
                var changed = false;

                tcBfGetSingleProductBasePriceInputs().each(function(){
                    var $inp = $(this);
                    var intended = $inp.data('tcIntended');
                    if (intended === undefined || intended === null) return;

                    var cur = tcBfParseMoneyToNumber($inp.val());
                    if (!cur || !intended) return;

                    // Detect common mis-parse multipliers (30,00 -> 3000,00 = x100)
                    var ratio = cur / intended;

                    // Allow small floating errors
                    function near(a,b){ return Math.abs(a-b) < 0.0001; }

                    if ( near(ratio, 100) || near(ratio, 1000) ) {
                        var restored = tcBfFormatEuroComma(intended);
                        if ($inp.val() !== restored) {
                            $inp.val(restored);
                            changed = true;
                        }
                    }
                });

                if (changed) {
                    try {
                        if (typeof window.gformCalculateTotalPrice === 'function') {
                            window.gformCalculateTotalPrice(fid);
                        }
                    } catch(e) {}

                    // If your booking-flow JS exposes a bike-field updater, call it too.
                    try {
                        if (typeof window.tcBfUpdateBikeFields === 'function') {
                            window.tcBfUpdateBikeFields(fid);
                        }
                    } catch(e) {}
                }
            }

            // Cache intended values early (initial correct render).
            tcBfCacheIntendedSingleProductPrices();

            // Run once now (non-AJAX) and also after GF finishes rendering (AJAX-safe)
            tcBfApplyDriverFlags();
            tcBfScheduleRepair();
            $(document).on('gform_post_render', function(e, formId){
                if (parseInt(formId,10) !== fid) return;
                tcBfApplyDriverFlags();
                tcBfScheduleRepair();
                tcBfCacheIntendedSingleProductPrices();
            });

            // After GF conditional logic runs (covers section hide/show toggles)
            $(document).on('gform_post_conditional_logic', function(e, formId){
                if (parseInt(formId,10) !== fid) return;
                tcBfScheduleRepair();
                // Let GF finish its internal re-parse first, then repair mis-parsed base prices.
                window.setTimeout(function(){
                    try { tcBfRepairSingleProductBasePrices(); } catch(e) {}
                }, 60);
            });

            // Also schedule repair after any input changes inside the form
            // (safe fallback when third-party GF add-ons trigger logic without firing events).
            $(document).on('change', '#gform_'+fid+' input, #gform_'+fid+' select', function(){
                tcBfScheduleRepair();
            });

            // Modalities tab show/hide
            try {
                $(".modalidades li.vc_tta-tab, .modalidades .vc_tta-panel").hide();
                var arr = <?php echo $js_array; ?>;
                for (var i=0;i<arr.length;i++) {
                    if (!arr[i] || !arr[i].slug) continue;
                    $("a[href='#"+arr[i].slug+"']").closest("li").show();
                    $("#"+arr[i].slug).show();
                }
            } catch(e) {}

            // Check first modality radio (GF44 id=4)
            $("input:radio[name='input_4']:first").prop('checked', true);

            // Rental select option classing & removal (GF44 id=106)
            var $sel = $("#input_"+fid+"_106");
            if ($sel.length) {
                $sel.find('option').eq(1).addClass('road');
                $sel.find('option').eq(2).addClass('mtb');
                $sel.find('option').eq(3).addClass('ebike');
                $sel.find('option').eq(4).addClass('gravel');

                if (tcBfToFloat(tcBfMetaPrices.road)   <= 0) $sel.find('.road').remove();
                if (tcBfToFloat(tcBfMetaPrices.mtb)    <= 0) $sel.find('.mtb').remove();
                if (tcBfToFloat(tcBfMetaPrices.ebike)  <= 0) $sel.find('.ebike').remove();
                if (tcBfToFloat(tcBfMetaPrices.gravel) <= 0) $sel.find('.gravel').remove();

                // Auto-select rental type and reveal the corresponding bike-choice field
                var defaultRentalClass = "<?php echo esc_js((string)$default_rental_class); ?>";
                if (defaultRentalClass) {
                    // Try to pick option that contains the class name
                    var found = false;
                    $sel.find('option').each(function(){
                        var $o = $(this);
                        if ($o.hasClass(defaultRentalClass)) {
                            $sel.val($o.val());
                            found = true;
                            return false;
                        }
                    });
                    if (found) {
                        $sel.trigger('change');
                    }
                }
            }

            // Disable all not_avail options (image choices radios)
            $("input:radio").each(function() {
                var v = String($(this).val() || '');
                if (v.indexOf("not_avail") >= 0) {
                    $(this).prop("disabled", true);
                }
            });

        });
        <?php
        echo "\n</script>\n";
    }

    /**
     * Admin-only diagnostics: show key meta + GF field ids on the frontend.
     */
    public static function output_admin_sc_event_diagnostics() : void {
        if ( ! is_singular('sc_event') ) return;
        if ( ! current_user_can('manage_options') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) return;

        $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );

        $diag = [
            'event_id' => $event_id,
            'form_id'  => $form_id,
            'meta'     => [
                'event_price'         => (string) get_post_meta($event_id, 'event_price', true),
                'rental_price_road'   => (string) get_post_meta($event_id, 'rental_price_road', true),
                'rental_price_mtb'    => (string) get_post_meta($event_id, 'rental_price_mtb', true),
                'rental_price_ebike'  => (string) get_post_meta($event_id, 'rental_price_ebike', true),
                'rental_price_gravel' => (string) get_post_meta($event_id, 'rental_price_gravel', true),
            ],
        ];

        ?>
        <script>
            window.tcBfIsAdmin = true;
            window.tcBfDiag = <?php echo wp_json_encode($diag); ?>;
        </script>
        <pre style="display:none" id="tc-bf-diag"><?php echo esc_html( wp_json_encode($diag, JSON_PRETTY_PRINT) ); ?></pre>
        <?php
    }

    /**
     * Ensure sc_event_tag taxonomy exists. Some installations rely on it for tag meta box.
     */
    public static function ensure_event_tag_taxonomy() : void {
        if ( taxonomy_exists('sc_event_tag') ) return;

        register_taxonomy(
            'sc_event_tag',
            ['sc_event'],
            [
                'label'        => __('Event Tags', 'tc-booking-flow'),
                'public'       => true,
                'show_ui'      => true,
                'show_admin_column' => true,
                'hierarchical' => false,
                'rewrite'      => ['slug' => 'sc_event_tag'],
            ]
        );
    }

    public static function add_metabox() : void {
        add_meta_box(
            'tc_bf_sc_event_meta',
            __('Tossa Cycling — Event Options', 'tc-booking-flow'),
            [__CLASS__, 'render_metabox'],
            'sc_event',
            'normal',
            'high'
        );
    }

    public static function render_metabox( \WP_Post $post ) : void {
        wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY);

        $get = function(string $key, $default = '') use ($post) {
            $v = get_post_meta($post->ID, $key, true);
            return $v === '' ? $default : $v;
        };

        $event_price = $get('event_price', '');
        $member_price = $get('member_price', '');
        $rental_price_road = $get('rental_price_road', '');
        $rental_price_mtb = $get('rental_price_mtb', '');
        $rental_price_ebike = $get('rental_price_ebike', '');
        $rental_price_gravel = $get('rental_price_gravel', '');

        $feat_img = $get('feat_img', '');
        $inscription = $get('inscription', 'No');
        $participants = $get('participants', 'No');

        $tc_header_title_mode = $get('tc_header_title_mode', 'default');
        $tc_header_title_custom = $get('tc_header_title_custom', '');
        $tc_header_subtitle = $get('tc_header_subtitle', '');
        $tc_header_logo_mode = $get('tc_header_logo_mode', 'none');
        $tc_header_logo_id = absint( $get('tc_header_logo_id', 0) );
        $tc_header_logo_url = $get('tc_header_logo_url', '');
        $tc_header_show_divider = $get('tc_header_show_divider', '1');
        $tc_header_show_back_link = $get('tc_header_show_back_link', '0');
        $tc_header_back_link_url = $get('tc_header_back_link_url', '');
        $tc_header_back_link_label = $get('tc_header_back_link_label', '');
        $tc_header_details_position = $get('tc_header_details_position', 'content');
        $tc_header_show_shopkeeper_meta = $get('tc_header_show_shopkeeper_meta', '');

        $tc_header_subtitle_size = absint( $get('tc_header_subtitle_size', 0) );
        $tc_header_padding_bottom = absint( $get('tc_header_padding_bottom', 0) );
        $tc_header_details_bottom = absint( $get('tc_header_details_bottom', 0) );
        $tc_header_logo_margin_bottom = absint( $get('tc_header_logo_margin_bottom', 0) );
        $tc_header_logo_max_width = absint( $get('tc_header_logo_max_width', 0) );
        $tc_header_title_max_size = absint( $get('tc_header_title_max_size', 0) );

        $logo_preview = '';
        if ( $tc_header_logo_id ) {
            $src = wp_get_attachment_image_url($tc_header_logo_id, 'medium');
            if ( $src ) {
                $logo_preview = '<img src="'.esc_url($src).'" style="max-width:200px;height:auto" alt="" />';
            }
        }

        ?>
        <style>
            .tc-bf-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:900px}
            .tc-bf-grid .wide{grid-column:1 / -1}
            .tc-bf-grid label{font-weight:600;display:block;margin-bottom:4px}
            .tc-bf-grid input[type=text], .tc-bf-grid input[type=number], .tc-bf-grid select{width:100%}
            .tc-bf-muted{opacity:.8;font-size:12px}
            .tc-bf-divider{margin:16px 0;border-top:1px solid #ddd}
            .tc-bf-flex{display:flex;gap:8px;align-items:center}
            .tc-bf-flex > *{flex:0 0 auto}
        </style>

        <div class="tc-bf-grid">
            <div>
                <label for="event_price"><?php esc_html_e('Event price (base)', 'tc-booking-flow'); ?></label>
                <input type="text" id="event_price" name="event_price" value="<?php echo esc_attr($event_price); ?>" placeholder="e.g. 20,00" />
                <div class="tc-bf-muted"><?php esc_html_e('Stored as a number (decimal comma allowed).', 'tc-booking-flow'); ?></div>
            </div>
            <div>
                <label for="member_price"><?php esc_html_e('Member price (optional)', 'tc-booking-flow'); ?></label>
                <input type="text" id="member_price" name="member_price" value="<?php echo esc_attr($member_price); ?>" placeholder="e.g. 15,00" />
            </div>

            <div>
                <label for="rental_price_road"><?php esc_html_e('Rental price — Road', 'tc-booking-flow'); ?></label>
                <input type="text" id="rental_price_road" name="rental_price_road" value="<?php echo esc_attr($rental_price_road); ?>" placeholder="e.g. 30,00" />
            </div>
            <div>
                <label for="rental_price_mtb"><?php esc_html_e('Rental price — MTB', 'tc-booking-flow'); ?></label>
                <input type="text" id="rental_price_mtb" name="rental_price_mtb" value="<?php echo esc_attr($rental_price_mtb); ?>" placeholder="e.g. 35,00" />
            </div>

            <div>
                <label for="rental_price_ebike"><?php esc_html_e('Rental price — eBike', 'tc-booking-flow'); ?></label>
                <input type="text" id="rental_price_ebike" name="rental_price_ebike" value="<?php echo esc_attr($rental_price_ebike); ?>" placeholder="e.g. 40,00" />
            </div>
            <div>
                <label for="rental_price_gravel"><?php esc_html_e('Rental price — Gravel', 'tc-booking-flow'); ?></label>
                <input type="text" id="rental_price_gravel" name="rental_price_gravel" value="<?php echo esc_attr($rental_price_gravel); ?>" placeholder="e.g. 30,00" />
            </div>

            <div class="wide tc-bf-divider"></div>

            <div>
                <label for="feat_img"><?php esc_html_e('Featured header image', 'tc-booking-flow'); ?></label>
                <select id="feat_img" name="feat_img">
                    <option value="" <?php selected($feat_img, ''); ?>><?php esc_html_e('Default (theme setting)', 'tc-booking-flow'); ?></option>
                    <option value="Yes" <?php selected($feat_img, 'Yes'); ?>><?php esc_html_e('Show', 'tc-booking-flow'); ?></option>
                    <option value="No" <?php selected($feat_img, 'No'); ?>><?php esc_html_e('Hide', 'tc-booking-flow'); ?></option>
                </select>
            </div>

            <div>
                <label><?php esc_html_e('Append to content', 'tc-booking-flow'); ?></label>
                <div class="tc-bf-flex">
                    <label style="font-weight:400"><input type="checkbox" name="inscription" value="Yes" <?php checked($inscription, 'Yes'); ?> /> <?php esc_html_e('Inscription form', 'tc-booking-flow'); ?></label>
                    <label style="font-weight:400"><input type="checkbox" name="participants" value="Yes" <?php checked($participants, 'Yes'); ?> /> <?php esc_html_e('Participants list', 'tc-booking-flow'); ?></label>
                </div>
            </div>

            <div class="wide tc-bf-divider"></div>

            <div class="wide">
                <h4 style="margin:0 0 8px"><?php esc_html_e('Header display', 'tc-booking-flow'); ?></h4>
                <div class="tc-bf-muted"><?php esc_html_e('Controls your custom single-sc_event header output.', 'tc-booking-flow'); ?></div>
            </div>

            <div>
                <label for="tc_header_title_mode"><?php esc_html_e('Title mode', 'tc-booking-flow'); ?></label>
                <select id="tc_header_title_mode" name="tc_header_title_mode">
                    <option value="default" <?php selected($tc_header_title_mode, 'default'); ?>><?php esc_html_e('Default (event title)', 'tc-booking-flow'); ?></option>
                    <option value="custom" <?php selected($tc_header_title_mode, 'custom'); ?>><?php esc_html_e('Custom', 'tc-booking-flow'); ?></option>
                    <option value="hide" <?php selected($tc_header_title_mode, 'hide'); ?>><?php esc_html_e('Hide', 'tc-booking-flow'); ?></option>
                </select>
            </div>

            <div>
                <label for="tc_header_title_custom"><?php esc_html_e('Custom title', 'tc-booking-flow'); ?></label>
                <input type="text" id="tc_header_title_custom" name="tc_header_title_custom" value="<?php echo esc_attr($tc_header_title_custom); ?>" placeholder="<?php esc_attr_e('e.g. Gravel Odyssey — Stage 2', 'tc-booking-flow'); ?>" />
            </div>

            <div>
                <label for="tc_header_subtitle"><?php esc_html_e('Subtitle', 'tc-booking-flow'); ?></label>
                <input type="text" id="tc_header_subtitle" name="tc_header_subtitle" value="<?php echo esc_attr($tc_header_subtitle); ?>" placeholder="<?php esc_attr_e('e.g. The REAL Costa Brava', 'tc-booking-flow'); ?>" />
            </div>

            <div>
                <label for="tc_header_logo_mode"><?php esc_html_e('Logo mode', 'tc-booking-flow'); ?></label>
                <select id="tc_header_logo_mode" name="tc_header_logo_mode">
                    <option value="none" <?php selected($tc_header_logo_mode, 'none'); ?>><?php esc_html_e('None', 'tc-booking-flow'); ?></option>
                    <option value="media" <?php selected($tc_header_logo_mode, 'media'); ?>><?php esc_html_e('Media library', 'tc-booking-flow'); ?></option>
                    <option value="url" <?php selected($tc_header_logo_mode, 'url'); ?>><?php esc_html_e('Direct URL', 'tc-booking-flow'); ?></option>
                </select>
            </div>

            <div class="wide">
                <label><?php esc_html_e('Logo (media)', 'tc-booking-flow'); ?></label>
                <div class="tc-bf-flex">
                    <input type="hidden" id="tc_header_logo_id" name="tc_header_logo_id" value="<?php echo (int) $tc_header_logo_id; ?>" />
                    <button type="button" class="button" id="tc-bf-logo-pick"><?php esc_html_e('Choose', 'tc-booking-flow'); ?></button>
                    <button type="button" class="button" id="tc-bf-logo-clear"><?php esc_html_e('Clear', 'tc-booking-flow'); ?></button>
                </div>
                <div id="tc-bf-logo-preview" style="margin-top:8px;"><?php echo $logo_preview; ?></div>
            </div>

            <div class="wide">
                <label for="tc_header_logo_url"><?php esc_html_e('Logo URL (if mode=url)', 'tc-booking-flow'); ?></label>
                <input type="text" id="tc_header_logo_url" name="tc_header_logo_url" value="<?php echo esc_attr($tc_header_logo_url); ?>" placeholder="https://..." />
            </div>

            <div>
                <label><?php esc_html_e('Header toggles', 'tc-booking-flow'); ?></label>
                <label style="font-weight:400;display:block"><input type="checkbox" name="tc_header_show_divider" value="1" <?php checked($tc_header_show_divider, '1'); ?> /> <?php esc_html_e('Show divider under title', 'tc-booking-flow'); ?></label>
                <label style="font-weight:400;display:block"><input type="checkbox" name="tc_header_show_shopkeeper_meta" value="1" <?php checked($tc_header_show_shopkeeper_meta, '1'); ?> /> <?php esc_html_e('Show Shopkeeper meta line', 'tc-booking-flow'); ?></label>
            </div>

            <div>
                <label><?php esc_html_e('Back link', 'tc-booking-flow'); ?></label>
                <label style="font-weight:400;display:block"><input type="checkbox" name="tc_header_show_back_link" value="1" <?php checked($tc_header_show_back_link, '1'); ?> /> <?php esc_html_e('Show back link', 'tc-booking-flow'); ?></label>
                <input type="text" name="tc_header_back_link_url" value="<?php echo esc_attr($tc_header_back_link_url); ?>" placeholder="<?php esc_attr_e('Back URL', 'tc-booking-flow'); ?>" style="margin-top:6px" />
                <input type="text" name="tc_header_back_link_label" value="<?php echo esc_attr($tc_header_back_link_label); ?>" placeholder="<?php esc_attr_e('Back label', 'tc-booking-flow'); ?>" style="margin-top:6px" />
            </div>

            <div>
                <label for="tc_header_details_position"><?php esc_html_e('Event details block position', 'tc-booking-flow'); ?></label>
                <select id="tc_header_details_position" name="tc_header_details_position">
                    <option value="content" <?php selected($tc_header_details_position, 'content'); ?>><?php esc_html_e('Keep in content (default)', 'tc-booking-flow'); ?></option>
                    <option value="header" <?php selected($tc_header_details_position, 'header'); ?>><?php esc_html_e('Move to header (with-thumb only)', 'tc-booking-flow'); ?></option>
                </select>
            </div>

            <div class="wide tc-bf-divider"></div>

            <div>
                <label for="tc_header_logo_max_width"><?php esc_html_e('Logo max width (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_logo_max_width" name="tc_header_logo_max_width" value="<?php echo (int) $tc_header_logo_max_width; ?>" />
            </div>

            <div>
                <label for="tc_header_title_max_size"><?php esc_html_e('Title max font size (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_title_max_size" name="tc_header_title_max_size" value="<?php echo (int) $tc_header_title_max_size; ?>" />
            </div>

            <div>
                <label for="tc_header_subtitle_size"><?php esc_html_e('Subtitle font size (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_subtitle_size" name="tc_header_subtitle_size" value="<?php echo (int) $tc_header_subtitle_size; ?>" />
            </div>

            <div>
                <label for="tc_header_padding_bottom"><?php esc_html_e('Header padding bottom (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_padding_bottom" name="tc_header_padding_bottom" value="<?php echo (int) $tc_header_padding_bottom; ?>" />
            </div>

            <div>
                <label for="tc_header_details_bottom"><?php esc_html_e('Details bottom offset (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_details_bottom" name="tc_header_details_bottom" value="<?php echo (int) $tc_header_details_bottom; ?>" />
            </div>

            <div>
                <label for="tc_header_logo_margin_bottom"><?php esc_html_e('Logo margin bottom (px)', 'tc-booking-flow'); ?></label>
                <input type="number" min="0" id="tc_header_logo_margin_bottom" name="tc_header_logo_margin_bottom" value="<?php echo (int) $tc_header_logo_margin_bottom; ?>" />
            </div>

        </div>
        <?php
    }

    public static function save_metabox( int $post_id, \WP_Post $post ) : void {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== 'sc_event' ) return;

        if ( ! isset($_POST[self::NONCE_KEY]) || ! wp_verify_nonce($_POST[self::NONCE_KEY], self::NONCE_KEY) ) {
            return;
        }

        $save_num = function(string $key) use ($post_id) : void {
            if ( ! isset($_POST[$key]) ) return;
            $raw = trim((string) $_POST[$key]);
            if ( $raw === '' ) { delete_post_meta($post_id, $key); return; }
            // Normalize to dot decimal for storage
            $normalized = str_replace([' ', '€'], '', $raw);
            // If both comma and dot exist, assume dot is thousands and comma is decimal (e.g. 1.234,56)
            if ( strpos($normalized, ',') !== false && strpos($normalized, '.') !== false ) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '.', $normalized);
            }
            update_post_meta($post_id, $key, (string) floatval($normalized));
        };

        // Numeric price fields
        foreach (['event_price','member_price','rental_price_road','rental_price_mtb','rental_price_ebike','rental_price_gravel'] as $k) {
            $save_num($k);
        }

        // Simple text fields (legacy)
        foreach (['feat_img','tc_header_title_custom','tc_header_subtitle','tc_header_logo_url','tc_header_back_link_url','tc_header_back_link_label'] as $k) {
            if ( ! isset($_POST[$k]) ) continue;
            $v = trim((string) $_POST[$k]);
            if ( $v === '' ) { delete_post_meta($post_id, $k); continue; }
            update_post_meta($post_id, $k, sanitize_text_field($v));
        }

        $save_yesno = function(string $key) use ($post_id) : void {
            if ( ! isset($_POST[$key]) ) return;
            $v = ($_POST[$key] === 'Yes') ? 'Yes' : 'No';
            update_post_meta($post_id, $key, $v);
        };
        $save_yesno('inscription');
        $save_yesno('participants');

        // Header/UI fields
        if ( isset($_POST['tc_header_title_mode']) ) {
            $v = (string) $_POST['tc_header_title_mode'];
            if ( ! in_array($v, ['default','custom','hide'], true) ) $v = 'default';
            update_post_meta($post_id, 'tc_header_title_mode', $v);
        }

        if ( isset($_POST['tc_header_logo_mode']) ) {
            $v = (string) $_POST['tc_header_logo_mode'];
            if ( ! in_array($v, ['none','media','url'], true) ) $v = 'none';
            update_post_meta($post_id, 'tc_header_logo_mode', $v);
        }

        $logo_id = isset($_POST['tc_header_logo_id']) ? absint($_POST['tc_header_logo_id']) : 0;
        update_post_meta($post_id, 'tc_header_logo_id', $logo_id);

        foreach (['tc_header_show_divider','tc_header_show_shopkeeper_meta'] as $k) {
            $val = isset($_POST[$k]) ? '1' : '';
            update_post_meta($post_id, $k, $val);
        }

        $tc_show_back_link = isset($_POST['tc_header_show_back_link']) ? '1' : '0';
        update_post_meta($post_id, 'tc_header_show_back_link', $tc_show_back_link);

        if ( isset($_POST['tc_header_details_position']) ) {
            $val = (string) $_POST['tc_header_details_position'];
            if ( ! in_array($val, ['content','header'], true) ) $val = 'content';
            update_post_meta($post_id, 'tc_header_details_position', $val);
        }

        // Per-event sizing (ints, allow 0 => default)
        $ints = [
            'tc_header_subtitle_size',
            'tc_header_padding_bottom',
            'tc_header_details_bottom',
            'tc_header_logo_margin_bottom',
            'tc_header_logo_max_width',
            'tc_header_title_max_size',
        ];
        foreach ( $ints as $k ) {
            if ( ! isset($_POST[$k]) ) continue;
            $v = absint($_POST[$k]);
            update_post_meta($post_id, $k, $v);
        }
    }

    public static function admin_assets( string $hook ) : void {
        if ( ! is_admin() ) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'sc_event' ) return;
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

        // Media picker for header logo
        wp_enqueue_media();

        $js = <<<'JS'
(function($){
  $(function(){
    var frame;
    var $id = $('#tc_header_logo_id');
    var $preview = $('#tc-bf-logo-preview');

    $('#tc-bf-logo-pick').on('click', function(e){
      e.preventDefault();
      if(frame){ frame.open(); return; }
      frame = wp.media({ title: 'Select header logo', button: { text: 'Use this logo' }, multiple: false });
      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        if(!att || !att.id){ return; }
        $id.val(att.id);
        var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
        $preview.html('<img src="'+url+'" alt="" />');
      });
      frame.open();
    });

    $('#tc-bf-logo-clear').on('click', function(e){
      e.preventDefault();
      $id.val('0');
      $preview.empty();
    });
  });
})(jQuery);
JS;
        wp_add_inline_script('jquery', $js);
    }

    /**
     * Append inscription (GF) and participants (GravityView) blocks to sc_event content.
     * Mirrors your snippet behavior, but uses the configured GF form ID.
     */
    public static function filter_sc_event_content( string $content ) : string {
        if ( ! is_singular('sc_event') ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) return $content;

        $custom_content = $content;

        // Inscription section
        if ( get_post_meta($post_id, 'inscription', true) === 'Yes' ) {
            $start_timestamp = get_post_meta($post_id, 'sc_event_date_time', true);
            if ( ! empty($start_timestamp) ) {

                $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );

                if ( $start_timestamp < time() ) {
                    $inscription_form = '<div style="padding:14px 16px;background:#f7f7f7;border:1px solid #e5e5e5;">'
                        . esc_html( self::tr('[:es]Formulario de inscripción no disponible. Este evento ya ha tenido lugar.[:en]Inscription form not available. This event has already taken place.[:]') )
                        . '</div>';
                } else {
                    $inscription_form = do_shortcode( '[gravityform id="' . $form_id . '" title="false" description="false" ajax="false"]' );
                }

                $inscription_content = do_shortcode( '
                    [vc_row]
                    [vc_column]
                    [vc_separator css=".vc_custom_1607950580058{margin-top: 30px !important;margin-bottom: 30px !important;}"]
                    [vc_column_text]<h3>' . self::tr("[:en]Inscription form[:es]Formulario de inscripción[:]") . '</h3>[/vc_column_text]
                    ' . $inscription_form . '
                    [/vc_column]
                    [/vc_row]
                ' );

                $custom_content .= $inscription_content;

            } else {
                $custom_content .= esc_html( self::tr('[:es]No se ha encontrado la fecha de inicio del evento.[:en]Event start date not found.[:]') );
            }
        }

        // Participants section (GravityView)
        if ( get_post_meta($post_id, 'participants', true) === 'Yes' ) {
            $participants_content = do_shortcode( "
                [vc_row]
                [vc_column]
                [vc_separator css='.vc_custom_1607950580058{margin-top: 30px !important;margin-bottom: 30px !important;}']
                [vc_column_text]<h3 id='participantes'>" . self::tr("[:en]List of participants[:es]Listado de participantes[:]") . "</h3>[/vc_column_text]
                [gravityview id='37950' search_field='145' search_value='" . $post_id . "_" . get_post_meta( $post_id, 'sc_event_date_time', true ) . "']
                [/vc_column]
                [/vc_row]
            " );

            $custom_content .= $participants_content;
        }

        // If details are moved to header, remove duplicate details from content.
        $custom_content = self::remove_details_from_content_when_in_header( $custom_content, $post_id );

        return $custom_content;
    }

    /**
     * Determine whether the SC Event header is rendered "with-thumb" (background image).
     * Mirrors the logic in single-sc_event.php enough to safely decide if we can move details to header.
     */
    private static function header_has_thumb( int $post_id ) : bool {
        if ( ! function_exists('has_post_thumbnail') ) return false;
        if ( ! has_post_thumbnail( $post_id ) ) return false;

        // Tossa Cycling meta: allow disabling featured header image per-event
        $feat_img = get_post_meta( $post_id, 'feat_img', true );
        if ( $feat_img === 'No' ) return false;

        // Shopkeeper toggle stored on post (same key the template checks)
        $opt = get_post_meta( $post_id, 'post_featured_image_meta_box_check', true );
        if ( $opt && $opt !== 'on' ) return false;

        return true;
    }

    /**
     * If details are set to "header", remove the Sugar Calendar details block from THE CONTENT.
     * This prevents a duplicate details line (one in header + one in content).
     *
     * We remove only the node with id="sc_event_details_{post_id}".
     */
    private static function remove_details_from_content_when_in_header( string $content, int $post_id ) : string {

        $details_position = get_post_meta( $post_id, 'tc_header_details_position', true );
        if ( $details_position !== 'header' ) return $content;

        // Only remove when header can actually display it (with-thumb).
        if ( ! self::header_has_thumb( $post_id ) ) return $content;

        $target_id = 'sc_event_details_' . $post_id;

        if ( strpos( $content, $target_id ) === false ) return $content;

        // DOM removal preferred
        if ( class_exists( '\DOMDocument' ) ) {
            libxml_use_internal_errors( true );
            $dom = new \DOMDocument();

            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>';
            if ( $dom->loadHTML( $html ) ) {
                $node = $dom->getElementById( $target_id );
                if ( $node && $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }

                $body = $dom->getElementsByTagName('body')->item(0);
                if ( $body ) {
                    $out = '';
                    foreach ( $body->childNodes as $child ) {
                        $out .= $dom->saveHTML( $child );
                    }
                    return $out;
                }
            }
        }

        // Fallback regex (first match)
        $content = preg_replace(
            '~<div[^>]*\bid="' . preg_quote( $target_id, '~' ) . '"[^>]*>.*?</div>~si',
            '',
            $content,
            1
        );

        return $content;
    }
}
