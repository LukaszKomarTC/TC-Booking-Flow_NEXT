<?php
/**
 * Global helper functions used by your custom single-sc_event template.
 *
 * These were previously provided via Code Snippets (notably snippet #163).
 * They are kept as GLOBAL functions for backwards compatibility.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('tc_sc_event_tr') ) {
    function tc_sc_event_tr( string $text ) : string {
        if ( function_exists('qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage') ) {
            return (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage($text);
        }
        return $text;
    }
}

if ( ! function_exists('tc_sc_event_dates') ) {
    function tc_sc_event_dates( int $event_id ) : array {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return [];

        // Raw timestamps are still stored on the WP post as meta (used for calendar links etc.)
        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        $end_ts   = (int) get_post_meta($event_id, 'sc_event_end_date_time', true);

        if ( $start_ts <= 0 ) return [];

        // ==========================================================
        // Canonical "all-day" flag: read from Sugar Calendar event object
        // (NO heuristics based on 00:00 / 23:59).
        // ==========================================================
        $is_all_day = false;

        if ( function_exists( 'sugar_calendar_get_event_by_object' ) ) {
            // SC v2+ (events in sc_events/sc_eventmeta) – get event object for this post.
            // (Call with ONE arg only to avoid signature mismatch fatals across SC builds.)
            $sc_event = sugar_calendar_get_event_by_object( $event_id );

            if ( is_object( $sc_event ) ) {
                if ( method_exists( $sc_event, 'is_all_day' ) ) {
                    $is_all_day = (bool) $sc_event->is_all_day();
                } elseif ( isset( $sc_event->all_day ) ) {
                    // Some builds expose a property instead.
                    $is_all_day = (bool) $sc_event->all_day;
                }
            }
        }

        // Fallback only if SC object API is unavailable (older builds): rely on the meta flag if present.
        if ( ! $is_all_day ) {
            $all_day_meta = get_post_meta($event_id, 'sc_event_all_day', true);
            $is_all_day = in_array( (string) $all_day_meta, array('1','yes','true'), true );
        }

        // ==========================================================
        // Display formatting – prefer Sugar Calendar helper output
        // so this matches SC formatting/settings.
        // ==========================================================
        $date_text = '';
        if ( function_exists( 'sc_get_event_date' ) ) {
            // Returns HTML (<time> tags). Strip tags for header.
            $date_text = wp_strip_all_tags( (string) sc_get_event_date( $event_id, true ) );
            $date_text = trim( preg_replace( '/\s+/', ' ', $date_text ) );
        }

        $time_text = '';
        if ( ! $is_all_day ) {
            // Prefer SC time helpers (they respect SC settings).
            $start_time = function_exists( 'sc_get_event_start_time' ) ? (string) sc_get_event_start_time( $event_id ) : '';
            $end_time   = function_exists( 'sc_get_event_end_time' )   ? (string) sc_get_event_end_time( $event_id )   : '';
            $start_time = trim( wp_strip_all_tags( $start_time ) );
            $end_time   = trim( wp_strip_all_tags( $end_time ) );

            if ( $start_time !== '' ) {
                $time_text = $start_time;
                if ( $end_time !== '' && $end_time !== $start_time ) {
                    $time_text .= ' - ' . $end_time;
                }
            }
        }

        // If SC helpers are not available, fall back to the plugin defaults.
        if ( $date_text === '' ) {
            $date_fmt = (string) apply_filters( 'tc_sc_event_date_format', 'd/m/Y', $event_id );
            $time_fmt = (string) apply_filters( 'tc_sc_event_time_format', 'H:i',   $event_id );

            $start_date = date_i18n( $date_fmt, $start_ts );
            $start_time = date_i18n( $time_fmt, $start_ts );

            $end_date = $end_ts ? date_i18n( $date_fmt, $end_ts ) : '';
            $end_time = $end_ts ? date_i18n( $time_fmt, $end_ts ) : '';

            $same_day = ( $end_ts && date_i18n( 'Y-m-d', $start_ts ) === date_i18n( 'Y-m-d', $end_ts ) );

            // For the header we want:
            // - all-day => date only (range if multi-day)
            // - timed single-day => date time – time
            // - timed multi-day => date time – date time
            if ( $is_all_day ) {
                if ( ! $end_ts || $same_day ) {
                    $date_text = $start_date;
                } else {
                    $date_text = $start_date . ' - ' . $end_date;
                }
                $time_text = '';
            } else {
                if ( ! $end_ts ) {
                    $date_text = $start_date . ' ' . $start_time;
                    $time_text = '';
                } elseif ( $same_day ) {
                    $date_text = $start_date . ' ' . $start_time . ' - ' . $end_time;
                    $time_text = '';
                } else {
                    $date_text = $start_date . ' ' . $start_time . ' - ' . $end_date . ' ' . $end_time;
                    $time_text = '';
                }
            }
        }

        // Header-friendly formatted string:
        // - all-day => date only (already a range if multi-day)
        // - timed   => date + times (single line)
        $display_header_date = $date_text;
        if ( ! $is_all_day && $time_text !== '' ) {
            $display_header_date .= ' ' . $time_text;
        }

        // Back-compat strings (used elsewhere)
        $display_start = $date_text;
        $display_end   = '';

        // Availability/range in whole days: start day 00:00 (UTC) +1 day exclusive
        $start_day_utc = (int) strtotime(gmdate('Y-m-d 00:00:00', $start_ts) . ' UTC');
        $end_excl_utc  = $start_day_utc + DAY_IN_SECONDS;

        return [
            'start_ts' => $start_ts,
            'end_ts'   => $end_ts,
            'is_all_day' => $is_all_day,
            'display_start' => $display_start,
            'display_end'   => $display_end,
            'display_header_date' => $display_header_date,
            'range_start_ts' => $start_day_utc,
            'range_end_exclusive_ts' => $end_excl_utc,
        ];
    }
}

if ( ! function_exists('tc_sc_event_get_logo_html') ) {
    function tc_sc_event_get_logo_html( int $event_id ) : string {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return '';

        $mode = (string) get_post_meta($event_id, 'tc_header_logo_mode', true);
        if ( $mode === '' ) $mode = 'none';

        if ( $mode === 'media' ) {
            $logo_id = (int) get_post_meta($event_id, 'tc_header_logo_id', true);
            if ( $logo_id > 0 ) {
                $src = wp_get_attachment_image_url($logo_id, 'full');
                if ( $src ) {
                    return '<div class="tc-event-header-logo"><img class="tc-event-header-logo-img tc-header-logo-img" src="' . esc_url($src) . '" alt="" loading="lazy" decoding="async" /></div>';
                }
            }
            return '';
        }

        if ( $mode === 'url' ) {
            $url = trim((string) get_post_meta($event_id, 'tc_header_logo_url', true));
            if ( $url !== '' ) {
                return '<div class="tc-event-header-logo"><img class="tc-event-header-logo-img tc-header-logo-img" src="' . esc_url($url) . '" alt="" loading="lazy" decoding="async" /></div>';
            }
            return '';
        }

        return '';
    }
}

if ( ! function_exists('tc_sc_event_render_details_bar') ) {
    function tc_sc_event_render_details_bar( int $event_id ) : string {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return '';

        $d = tc_sc_event_dates($event_id);
        if ( empty($d) ) return '';

        // This string is used in the HEADER, and should mirror Sugar Calendar formatting
        // (single day timed vs all-day vs multi-day).
        $range = $d['display_header_date'] ?? '';

        // Terms (Calendar categories)
        $terms      = get_the_terms($event_id, 'sc_event_category');
        $term_links = [];
        if ( ! empty($terms) && ! is_wp_error($terms) ) {
            foreach ( $terms as $t ) {
                $term_links[] = '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
            }
        }

        // Google Calendar dates:
        // - all-day: YYYYMMDD/YYYYMMDD (END exclusive => +1 day)
        // - timed:   YYYYMMDDTHHMMSSZ/YYYYMMDDTHHMMSSZ
        $google_dates = '';
        if ( ! empty( $d['start_ts'] ) ) {
            if ( ! empty( $d['is_all_day'] ) ) {
                $start_ymd = gmdate('Y-m-d', (int) $d['start_ts']);
                $end_ymd   = ! empty($d['end_ts']) ? gmdate('Y-m-d', (int) $d['end_ts']) : $start_ymd;
                $google_start = str_replace('-', '', $start_ymd);
                $end_excl     = gmdate('Y-m-d', strtotime($end_ymd . ' +1 day'));
                $google_end   = str_replace('-', '', $end_excl);
                $google_dates = $google_start . '/' . $google_end;
            } else {
                $google_dates = gmdate('Ymd\\THis\\Z', (int) $d['start_ts']);
                $google_dates .= '/' . gmdate('Ymd\\THis\\Z', (int) ( ! empty($d['end_ts']) ? $d['end_ts'] : ((int)$d['start_ts'] + HOUR_IN_SECONDS) ) );
            }
        }

        $title = tc_sc_event_tr( get_the_title( $event_id ) );

        $google_url = '';
        if ( $google_dates !== '' ) {
            $google_url = add_query_arg(
                array(
                    'action' => 'TEMPLATE',
                    'text'   => $title,
                    'dates'  => $google_dates,
                ),
                'https://calendar.google.com/calendar/render'
            );
        }

        // ICS download (Outlook/Apple/Download)
        $ics_url = trailingslashit( get_permalink( $event_id ) ) . 'ics/?download=1';

        $label_calendar = tc_sc_event_tr('[:en]Calendar[:es]Calendario[:]');
        $label_download = tc_sc_event_tr('[:en]Download[:es]Descargar[:]');

        ob_start();
        ?>
        <div class="tc-sc-header-details tc-sc_event_details--header" id="sc_event_details_<?php echo (int) $event_id; ?>">

            <div class="tc-sc-header-details__date">
                <?php echo esc_html( $range ); ?>
            </div>

            <div class="tc-sc-header-details__links">
                <?php if ( ! empty( $google_url ) ) : ?>
                    <a href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener">Google Calendar</a>
                <?php endif; ?>

                <?php if ( ! empty( $ics_url ) ) : ?>
                    <?php if ( ! empty( $google_url ) ) : ?><span class="tc-sc-sep">·</span><?php endif; ?>
                    <a href="<?php echo esc_url( $ics_url ); ?>" target="_blank" rel="noopener">Microsoft Outlook</a>
                    <span class="tc-sc-sep">·</span>
                    <a href="<?php echo esc_url( $ics_url ); ?>" target="_blank" rel="noopener">Apple Calendar</a>
                    <span class="tc-sc-sep">·</span>
                    <a href="<?php echo esc_url( $ics_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label_download ); ?></a>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $term_links ) ) : ?>
                <div class="tc-sc-header-details__terms">
                    <span class="tc-sc-header-details__terms-label"><?php echo esc_html( $label_calendar ); ?>:</span>
                    <?php echo wp_kses_post( implode( ', ', $term_links ) ); ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
        return (string) ob_get_clean();
    }
}
