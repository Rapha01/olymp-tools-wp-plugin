<?php
/**
 * Olymp Tool: Google Reviews
 *
 * Provides two shortcodes that output bare numbers pulled from Google:
 *   [olymp_google_reviews_average] → average rating, e.g. "4.6"
 *   [olymp_google_reviews_count]   → total review count, e.g. "128"
 *
 * Data is fetched server-side from the Google Places API (New) — only the
 * `rating` and `userRatingCount` fields — and stored in a persistent option.
 * The stored values are served for a week, after which a refresh is attempted;
 * a failed refresh (network error, hit daily quota, etc.) keeps serving the
 * last known-good values. Old values are never discarded — only overwritten by
 * a successful fetch — so the shortcodes never go blank once they have data.
 * The API key never reaches the browser.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olymp_Tool_Google_Reviews implements Olymp_Tool {

    const OPT_API_KEY  = 'olymp_tools_greviews_api_key';
    const OPT_PLACE_ID = 'olymp_tools_greviews_place_id';
    const OPT_STATS    = 'olymp_tools_greviews_stats';

    /** How long stored stats are served before a refresh is attempted. */
    const REFRESH_INTERVAL = WEEK_IN_SECONDS;

    const SHORTCODE_AVERAGE = 'olymp_google_reviews_average';
    const SHORTCODE_COUNT   = 'olymp_google_reviews_count';

    // ── Olymp_Tool contract ───────────────────────────────────────────────────

    public function get_id() {
        return 'google-reviews';
    }

    public function get_menu_label() {
        return __( 'Google Reviews', 'olymp-tools' );
    }

    public function get_page_title() {
        return __( 'Google Reviews', 'olymp-tools' );
    }

    public function init() {
        add_shortcode( self::SHORTCODE_AVERAGE, array( $this, 'shortcode_average' ) );
        add_shortcode( self::SHORTCODE_COUNT, array( $this, 'shortcode_count' ) );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Values passed into the included template (method scope, no globals).
        $api_key           = (string) get_option( self::OPT_API_KEY, '' );
        $place_id          = (string) get_option( self::OPT_PLACE_ID, '' );
        $stats             = $this->get_stats( false ); // live check (no stale fallback): array{rating,count} | WP_Error
        $shortcode_average = self::SHORTCODE_AVERAGE;
        $shortcode_count   = self::SHORTCODE_COUNT;

        include __DIR__ . '/google-reviews-page.php';
    }

    public function save() {
        check_ajax_referer( Olymp_Tools::NONCE, 'nonce' );

        $api_key  = isset( $_POST['api_key'] )  ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )  : '';
        $place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';

        update_option( self::OPT_API_KEY, $api_key );
        update_option( self::OPT_PLACE_ID, $place_id );

        // Force the next get_stats() to re-fetch so changed credentials take
        // effect immediately — but keep the last known-good values as a fallback
        // (mark them stale rather than discarding them).
        $stored = get_option( self::OPT_STATS, array() );
        if ( is_array( $stored ) && isset( $stored['fetched_at'] ) ) {
            $stored['fetched_at'] = 0;
            update_option( self::OPT_STATS, $stored, false );
        }

        return array( 'message' => __( 'Google Reviews settings saved.', 'olymp-tools' ) );
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    /**
     * Output the average rating (e.g. "4.6"). Empty string if unavailable.
     */
    public function shortcode_average() {
        $stats = $this->get_stats();
        if ( is_wp_error( $stats ) ) {
            return '';
        }
        return esc_html( number_format_i18n( $stats['rating'], 1 ) );
    }

    /**
     * Output the total review count (e.g. "128"). Empty string if unavailable.
     */
    public function shortcode_count() {
        $stats = $this->get_stats();
        if ( is_wp_error( $stats ) ) {
            return '';
        }
        return esc_html( number_format_i18n( $stats['count'] ) );
    }

    // ── Data ──────────────────────────────────────────────────────────────────

    /**
     * Return the { rating, count } for the configured place.
     *
     * Values are persisted and served for REFRESH_INTERVAL before a refresh is
     * attempted. Old values are NEVER discarded: they are only ever replaced by
     * a successful fetch.
     *
     * On the front-end ($allow_stale = true) a failed refresh falls back to the
     * last known-good values so shortcodes never go blank. The admin connection
     * check passes $allow_stale = false, so a failed refresh surfaces the real
     * API error instead of silently showing stale numbers — that's what lets a
     * manual Save report whether the entered credentials actually work.
     *
     * A WP_Error is returned when the refresh fails and either stale fallback is
     * disabled or nothing has been cached yet (first run / newly-changed Place ID).
     *
     * @param bool $allow_stale Serve the last known-good values when a refresh fails.
     * @return array{rating: float, count: int}|WP_Error
     */
    public function get_stats( $allow_stale = true ) {
        $api_key  = (string) get_option( self::OPT_API_KEY, '' );
        $place_id = (string) get_option( self::OPT_PLACE_ID, '' );

        if ( '' === $api_key || '' === $place_id ) {
            return new WP_Error( 'not_configured', __( 'API key or Place ID is not set.', 'olymp-tools' ) );
        }

        // Last known-good values (persisted, never auto-expires). Only trusted
        // when they belong to the currently-configured place.
        $stored      = get_option( self::OPT_STATS, array() );
        $have_stored = is_array( $stored )
            && isset( $stored['rating'], $stored['count'], $stored['place_id'], $stored['fetched_at'] )
            && $stored['place_id'] === $place_id;

        // Still fresh → serve without an API call.
        if ( $have_stored && ( time() - (int) $stored['fetched_at'] ) < self::REFRESH_INTERVAL ) {
            return array(
                'rating' => (float) $stored['rating'],
                'count'  => (int) $stored['count'],
            );
        }

        // Stale or missing → attempt a refresh.
        $fresh = $this->fetch_stats( $api_key, $place_id );

        if ( is_wp_error( $fresh ) ) {
            // Never throw away old values: serve the last known-good on the
            // front-end. The admin check ($allow_stale = false) lets the real
            // error through instead, so a manual Save reports a broken connection.
            if ( $allow_stale && $have_stored ) {
                return array(
                    'rating' => (float) $stored['rating'],
                    'count'  => (int) $stored['count'],
                );
            }
            return $fresh; // No fallback (or nothing cached yet) — surface the error.
        }

        // Success → overwrite the stored values and stamp the fetch time.
        update_option(
            self::OPT_STATS,
            array(
                'rating'     => $fresh['rating'],
                'count'      => $fresh['count'],
                'place_id'   => $place_id,
                'fetched_at' => time(),
            ),
            false
        );

        return $fresh;
    }

    /**
     * Perform the live Google Places API (New) request for { rating, count }.
     *
     * @param string $api_key
     * @param string $place_id
     * @return array{rating: float, count: int}|WP_Error
     */
    private function fetch_stats( $api_key, $place_id ) {
        $response = wp_remote_get(
            'https://places.googleapis.com/v1/places/' . rawurlencode( $place_id ),
            array(
                'timeout' => 15,
                'headers' => array(
                    'X-Goog-Api-Key'   => $api_key,
                    'X-Goog-FieldMask' => 'rating,userRatingCount',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || ! is_array( $body ) ) {
            $msg = isset( $body['error']['message'] )
                ? $body['error']['message']
                /* translators: %d: HTTP status code */
                : sprintf( __( 'Google Places API returned HTTP %d.', 'olymp-tools' ), $code );
            return new WP_Error( 'api_error', $msg );
        }

        return array(
            'rating' => isset( $body['rating'] ) ? (float) $body['rating'] : 0.0,
            'count'  => isset( $body['userRatingCount'] ) ? (int) $body['userRatingCount'] : 0,
        );
    }
}
