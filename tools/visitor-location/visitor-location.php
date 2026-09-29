<?php
/**
 * Olymp Tool: Visitor Location
 *
 * Provides shortcodes that output the geographic location of the current site
 * visitor (city, region, country, country code), e.g. for
 * location-personalised marketing copy like "Best offers in [olymp_visitor_city]".
 *
 *   [olymp_visitor_city         default="deiner Nähe"]   → e.g. "München"
 *   [olymp_visitor_region       default="deiner Region"] → e.g. "Bayern"
 *   [olymp_visitor_country      default="Deutschland"]   → e.g. "Deutschland"
 *   [olymp_visitor_country_code default="DE"]            → e.g. "DE"
 *   [olymp_visitor_location field="city,country" separator=", " default="deiner Nähe"]
 *
 * How it works
 * ------------
 * Lookups are 100 % local: the visitor's IP is resolved against a DB-IP Lite
 * City database (MaxMind DB / .mmdb format) that lives in wp-content/uploads —
 * the IP never leaves the server (no third-party API, GDPR-friendly).
 *
 * Rendering is client-side (cache-safe): each shortcode outputs a placeholder
 * <span> whose text is the `default`. A single AJAX call (admin-ajax, nopriv)
 * resolves the visitor's location and the browser fills the placeholders in.
 * This works behind full-page caching, where a server-rendered per-visitor
 * value would be wrong for everyone but the first visitor.
 *
 * The database is fetched lazily: the first lookup that finds it missing or
 * stale schedules a one-off WP-Cron event that downloads it in the background.
 * The triggering visitor simply sees the `default` until the DB is ready; once
 * present, a stale DB keeps serving real data while the refresh runs (atomic
 * swap), so only the very first visitor ever falls back to the default.
 *
 * Data: "IP Geolocation by DB-IP" (https://db-ip.com), licensed CC-BY 4.0.
 * Reader: MaxMind DB Reader (Apache-2.0), vendored under lib/.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olymp_Tool_Visitor_Location implements Olymp_Tool {

    /** Persisted state. */
    const OPT_LAST_UPDATED = 'olymp_tools_vloc_updated'; // int  timestamp of last successful download
    const OPT_LAST_ERROR   = 'olymp_tools_vloc_error';   // string last download error (admin display)

    /** Single-flight lock; doubles as the failure back-off window. */
    const FLAG_DOWNLOADING = 'olymp_tools_vloc_downloading';

    /** Serve a DB for this long before a background refresh is attempted. */
    const REFRESH_INTERVAL = 30 * DAY_IN_SECONDS;

    /** How long the download lock/back-off is held once a download starts. */
    const DOWNLOAD_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

    /** Public AJAX action + front-end script handle + asset cache-buster. */
    const AJAX_ACTION   = 'olymp_visitor_location';
    const SCRIPT_HANDLE = 'olymp-visitor-location';
    const VERSION       = '1.0.1';

    /**
     * Fields a shortcode may request (also the JSON keys returned to the client).
     * Note: DB-IP Lite carries no postal codes, so no `postal` field is offered.
     */
    private static $FIELDS = array( 'city', 'region', 'country', 'country_code' );

    // ── Olymp_Tool contract ───────────────────────────────────────────────────

    public function get_id() {
        return 'visitor-location';
    }

    public function get_menu_label() {
        return __( 'Visitor Location', 'olymp-tools' );
    }

    public function get_page_title() {
        return __( 'Visitor Location', 'olymp-tools' );
    }

    public function init() {
        // Single-field shortcodes: [olymp_visitor_city], [olymp_visitor_region], …
        foreach ( self::$FIELDS as $field ) {
            add_shortcode( 'olymp_visitor_' . $field, function ( $atts ) use ( $field ) {
                $atts = shortcode_atts( array( 'default' => '' ), $atts, 'olymp_visitor_' . $field );
                return $this->render_field( $field, $atts['default'] );
            } );
        }

        // Combined shortcode with conditional separator handling.
        add_shortcode( 'olymp_visitor_location', array( $this, 'shortcode_location' ) );

        // Public AJAX lookup (visitors are not logged in → nopriv too). This same
        // request also drives the lazy DB download in the background (see
        // handle_lookup()), so no WP-Cron or server-to-self loopback is involved.
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_lookup' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_lookup' ) );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $db_path     = $this->db_path();
        $db_exists   = file_exists( $db_path );
        $db_size     = $db_exists ? (int) filesize( $db_path ) : 0;
        $db_updated  = (int) get_option( self::OPT_LAST_UPDATED, 0 );
        $db_error    = (string) get_option( self::OPT_LAST_ERROR, '' );
        $downloading = (bool) get_transient( self::FLAG_DOWNLOADING );
        $stale       = $this->is_db_stale();
        $refresh_days = (int) round( self::REFRESH_INTERVAL / DAY_IN_SECONDS );

        // Live preview: the admin's own detected IP, or an explicit test IP.
        $preview_ip = $this->get_client_ip();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin preview, capability-checked above.
        if ( isset( $_GET['test_ip'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_GET['test_ip'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                $preview_ip = $candidate;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $preview = ( $db_exists && '' !== $preview_ip && $this->is_public_ip( $preview_ip ) )
            ? $this->lookup( $preview_ip )
            : null;

        include __DIR__ . '/visitor-location-page.php';
    }

    /**
     * The tool's admin form has a single control: "refresh the database now".
     * There are no persisted settings.
     *
     * Forces a fresh download through the very same path the first-visitor lookup
     * uses (run_locked_download() → download_database()), so the download logic
     * lives in exactly one place. It runs synchronously here — the admin waits a
     * few seconds but gets a definitive result — and works on hosts where WP-Cron
     * or loopback requests are blocked.
     */
    public function save( $post ) {
        // Clear any back-off lock so a manual refresh always proceeds immediately.
        delete_transient( self::FLAG_DOWNLOADING );

        $result = $this->run_locked_download();

        if ( is_wp_error( $result ) ) {
            return array( 'message' => sprintf(
                /* translators: %s: error message */
                __( 'Download failed: %s', 'olymp-tools' ),
                $result->get_error_message()
            ) );
        }

        return array( 'message' => __( 'Database downloaded and installed successfully.', 'olymp-tools' ) );
    }

    // ── Shortcodes ──────────────────────────────────────────────────────────────

    /**
     * Render a single-field placeholder.
     */
    private function render_field( $field, $default ) {
        $this->enqueue_frontend();
        return $this->placeholder( array( $field ), '', $default );
    }

    /**
     * [olymp_visitor_location] — join several fields, dropping empty parts so the
     * separator is only inserted between values that actually resolved.
     */
    public function shortcode_location( $atts ) {
        $atts = shortcode_atts(
            array(
                'field'     => 'city,country',
                'separator' => ', ',
                'default'   => '',
            ),
            $atts,
            'olymp_visitor_location'
        );

        $fields = array_values( array_intersect(
            array_map( 'trim', explode( ',', $atts['field'] ) ),
            self::$FIELDS
        ) );
        if ( empty( $fields ) ) {
            $fields = array( 'city' );
        }

        $this->enqueue_frontend();
        return $this->placeholder( $fields, (string) $atts['separator'], $atts['default'] );
    }

    /**
     * Build the placeholder markup. The `default` is the initial text content and
     * doubles as the SSR/JS-failure/lookup-miss fallback: the client only replaces
     * it when it has a non-empty value (see visitor-location.js).
     */
    private function placeholder( array $fields, $separator, $default ) {
        $attrs = 'data-field="' . esc_attr( implode( ',', $fields ) ) . '"';
        if ( '' !== $separator ) {
            $attrs .= ' data-separator="' . esc_attr( $separator ) . '"';
        }
        return '<span class="olymp-visitor" ' . $attrs . '>' . esc_html( $default ) . '</span>';
    }

    /**
     * Register (once) and enqueue the front-end script. Called from the shortcode
     * so the asset only loads on pages that actually use a shortcode.
     */
    private function enqueue_frontend() {
        if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
            wp_register_script(
                self::SCRIPT_HANDLE,
                plugins_url( 'assets/visitor-location.js', __FILE__ ),
                array(),
                self::VERSION,
                true
            );
            wp_localize_script( self::SCRIPT_HANDLE, 'olympVisitorLocation', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action'  => self::AJAX_ACTION,
            ) );
        }
        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    // ── AJAX lookup ─────────────────────────────────────────────────────────────

    /**
     * Public endpoint. Resolves the location of the CALLER's IP only, so it cannot
     * be used as an open geolocation proxy. No nonce: it is a read-only, self-only
     * lookup, and requiring one would break on long-cached pages where the printed
     * nonce has expired.
     *
     * Exception: a logged-in admin may pass ?test_ip= to force a specific address —
     * to exercise the lookup (and the lazy DB download it triggers) from a local
     * environment, where the real client IP is private and would short-circuit
     * below. The override is capability-gated, so anonymous callers still cannot
     * geolocate an arbitrary IP.
     */
    public function handle_lookup() {
        $ip = $this->get_client_ip();

        // Dev/test override — admins only (see method docblock).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability-checked, read-only; the only side effect is an idempotent background DB download.
        if ( isset( $_GET['test_ip'] ) && current_user_can( 'manage_options' ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_GET['test_ip'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                $ip = $candidate;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Local/dev/invalid IP → empty fields; the client just keeps its defaults.
        $fields = ( '' === $ip || ! $this->is_public_ip( $ip ) )
            ? $this->empty_fields()
            : $this->lookup( $ip );

        // Decide before we detach whether the DB needs (re)downloading.
        $needs_download = ! file_exists( $this->db_path() ) || $this->is_db_stale();

        // Send the location to the visitor and close the connection — they never
        // wait for the download. Then, if needed, download the DB in the background
        // of this same request: the visitor's own request drives it, so no WP-Cron
        // or server-to-self loopback is required. Only the first visitor to find
        // the DB missing/stale (and win the single-flight lock) actually downloads.
        $this->send_json_and_close( array( 'success' => true, 'data' => $fields ) );

        if ( $needs_download ) {
            $this->run_locked_download();
        }

        exit;
    }

    /**
     * Resolve { city, region, country, country_code } for an IP — a pure read with
     * no side effects. A missing (or unreadable) database yields empty fields;
     * deciding whether to (re)download is the caller's job (see handle_lookup()).
     *
     * @return array<string,string>
     */
    private function lookup( $ip ) {
        $fields = $this->empty_fields();
        $path   = $this->db_path();

        if ( ! file_exists( $path ) ) {
            return $fields; // no DB yet — the caller triggers the download
        }

        try {
            $this->load_reader_library();
            $reader = new \MaxMind\Db\Reader( $path );
            $record = $reader->get( $ip );
            $reader->close();
        } catch ( \Throwable $e ) {
            return $fields;
        }

        if ( ! is_array( $record ) ) {
            return $fields; // IP not present in the database
        }

        $country_code = isset( $record['country']['iso_code'] ) ? (string) $record['country']['iso_code'] : '';

        // City & region: resolve the DB name, then apply a manual translation
        // (DB-IP localizes only country names — see translate_name()).
        $city                   = isset( $record['city']['names'] ) ? $this->localized_name( $record['city']['names'] ) : '';
        $city                   = $this->clean_city( $city );
        $fields['city']         = $this->translate_name( $city, $country_code );

        $region                 = isset( $record['subdivisions'][0]['names'] ) ? $this->localized_name( $record['subdivisions'][0]['names'] ) : '';
        $fields['region']       = $this->translate_name( $region, $country_code );

        $fields['country']      = isset( $record['country']['names'] ) ? $this->localized_name( $record['country']['names'] ) : '';
        $fields['country_code'] = $country_code;

        // Last resort: show the ISO code if the DB has no localized country name.
        if ( '' === $fields['country'] && '' !== $fields['country_code'] ) {
            $fields['country'] = $fields['country_code'];
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function empty_fields() {
        return array_fill_keys( self::$FIELDS, '' );
    }

    /**
     * Pick the best available localized name, preferring the site language then
     * English, then whatever the DB has.
     *
     * @param array<string,string> $names
     */
    private function localized_name( $names ) {
        if ( ! is_array( $names ) || empty( $names ) ) {
            return '';
        }
        foreach ( $this->name_locales() as $loc ) {
            if ( ! empty( $names[ $loc ] ) ) {
                return (string) $names[ $loc ];
            }
        }
        $first = reset( $names );
        return is_string( $first ) ? $first : '';
    }

    /**
     * MaxMind/DB-IP name locale keys to try, most-preferred first.
     *
     * @return string[]
     */
    private function name_locales() {
        $wp = get_locale();
        if ( 0 === stripos( $wp, 'pt' ) ) {
            $primary = 'pt-BR';
        } elseif ( 0 === stripos( $wp, 'zh' ) ) {
            $primary = 'zh-CN';
        } else {
            $primary = strtolower( substr( $wp, 0, 2 ) );
        }
        return array_unique( array( $primary, 'en' ) );
    }

    /**
     * DB-IP appends the district in parentheses for larger cities, e.g.
     * "Berlin (Charlottenburg-Wilmersdorf)". Strip it so the city reads cleanly
     * in copy ("Berlin").
     */
    private function clean_city( $city ) {
        return trim( preg_replace( '/\s*\([^)]*\)\s*$/u', '', (string) $city ) );
    }

    /**
     * Apply a manual name translation from data/translations.php, if one exists for
     * this (name, country, target language). DB-IP City Lite only localizes country
     * names, so city/region names arrive in English; this fills the gap. Scoped by
     * country code, so a same-named place elsewhere (e.g. Vienna, USA) is left as-is.
     *
     * @param string $name         Name as resolved from the DB (city already cleaned).
     * @param string $country_code ISO 3166-1 alpha-2 country code of the visitor.
     * @return string Translated name, or the input unchanged.
     */
    private function translate_name( $name, $country_code ) {
        if ( '' === $name || '' === $country_code ) {
            return $name;
        }
        $lang = $this->target_lang();
        $map  = $this->translations();
        if ( isset( $map[ $country_code ][ $lang ][ $name ] ) ) {
            return (string) $map[ $country_code ][ $lang ][ $name ];
        }
        return $name;
    }

    /**
     * Output language: the site locale's 2-letter code — the same primary locale
     * localized_name() prefers (e.g. 'de' for a German site).
     */
    private function target_lang() {
        $locales = $this->name_locales();
        return ! empty( $locales[0] ) ? $locales[0] : 'en';
    }

    /**
     * Load and cache the manual translation table (data/translations.php).
     *
     * @return array [ country_code => [ lang => [ english_name => translation ] ] ]
     */
    private function translations() {
        static $cache = null;
        if ( null === $cache ) {
            $file  = __DIR__ . '/data/translations.php';
            $cache = is_readable( $file ) ? (array) include $file : array();
        }
        return $cache;
    }

    // ── IP detection ────────────────────────────────────────────────────────────

    /**
     * Best-effort client IP. Honours proxy/CDN headers so the real visitor is
     * geolocated behind Cloudflare etc. This is NOT a security decision: a spoofed
     * header only changes the spoofer's own displayed city, so trusting these
     * headers is acceptable here.
     */
    private function get_client_ip() {
        $keys       = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        $candidates = array();

        foreach ( $keys as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) {
                continue;
            }
            $raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            foreach ( explode( ',', $raw ) as $ip ) {
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    $candidates[] = $ip;
                }
            }
        }

        // Prefer the first public address; fall back to the first valid one.
        foreach ( $candidates as $ip ) {
            if ( $this->is_public_ip( $ip ) ) {
                return $ip;
            }
        }
        return isset( $candidates[0] ) ? $candidates[0] : '';
    }

    private function is_public_ip( $ip ) {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    // ── Database download ───────────────────────────────────────────────────────

    /**
     * Acquire the single-flight lock and download the database. Both the admin
     * "Refresh" button and the first-visitor lookup call this, so the download is
     * driven from exactly one place — no duplicated logic, no WP-Cron.
     *
     * The lock (a transient) stops concurrent visitors from stampeding the download
     * and doubles as a failure back-off window; download_database() releases it on
     * success and holds it (until it expires) on failure.
     *
     * @return true|WP_Error|null true / WP_Error from the attempt, or null when
     *                            another download already holds the lock.
     */
    private function run_locked_download() {
        if ( get_transient( self::FLAG_DOWNLOADING ) ) {
            return null; // in-flight or backing off after a failure
        }
        set_transient( self::FLAG_DOWNLOADING, time(), self::DOWNLOAD_LOCK_TTL );

        return $this->download_database();
    }

    /**
     * Emit a JSON response and detach from the client, so the request can keep
     * running (to download the database) without the visitor waiting.
     *
     * Prefers fastcgi_finish_request() / litespeed_finish_request(); otherwise it
     * flushes with an explicit Content-Length so the browser treats the (small)
     * body as complete immediately while any follow-up work runs inline — the
     * visitor's page is never blocked either way.
     *
     * @param array $payload Response data, echoed as JSON.
     */
    private function send_json_and_close( array $payload ) {
        $json = wp_json_encode( $payload );

        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            header( 'Content-Length: ' . strlen( $json ) );
            header( 'Connection: close' );
        }

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        } else {
            // Can't detach: flush what we have. The Content-Length lets the browser
            // treat the body as complete immediately; any follow-up work runs inline.
            while ( ob_get_level() > 0 ) {
                ob_end_flush();
            }
            flush();
        }
    }

    private function is_db_stale() {
        $updated = (int) get_option( self::OPT_LAST_UPDATED, 0 );
        return ( time() - $updated ) > self::REFRESH_INTERVAL;
    }

    /**
     * Download the current DB-IP Lite City database. Reached via run_locked_download()
     * from both the admin "Refresh" button (synchronous) and the first-visitor lookup
     * (after its response is flushed). Downloads to a temp file, decompresses and
     * verifies it, then atomically swaps it into place — a failure mid-way never
     * corrupts the live DB (the old one keeps serving).
     *
     * @return true|WP_Error True on success; a WP_Error describing the last failed
     *                       attempt otherwise. The error is also stored in
     *                       OPT_LAST_ERROR for display on the admin page.
     */
    public function download_database() {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- the ~62 MB download must not hit max_execution_time; some hosts disable set_time_limit, so ignore failure.
        @set_time_limit( 0 );

        $dir   = $this->ensure_storage_dir();
        $final = $this->db_path();

        // DB-IP publishes a dated file each month; fall back to last month if the
        // current month isn't live yet (early in the month).
        $months = array(
            gmdate( 'Y-m' ),
            gmdate( 'Y-m', strtotime( 'first day of previous month' ) ),
        );

        $last_error = '';
        foreach ( $months as $month ) {
            $url    = 'https://download.db-ip.com/free/dbip-city-lite-' . $month . '.mmdb.gz';
            $tmp_gz = download_url( $url, 300 ); // ~62 MB file; give it headroom on slow links
            if ( is_wp_error( $tmp_gz ) ) {
                $last_error = $url . ' → ' . $tmp_gz->get_error_message();
                continue;
            }

            $tmp_mmdb = $this->decompress_gz( $tmp_gz, $dir );
            wp_delete_file( $tmp_gz );
            if ( is_wp_error( $tmp_mmdb ) ) {
                $last_error = $tmp_mmdb->get_error_message();
                continue;
            }

            if ( ! $this->verify_db( $tmp_mmdb ) ) {
                wp_delete_file( $tmp_mmdb );
                $last_error = __( 'Downloaded file is not a valid MaxMind DB.', 'olymp-tools' );
                continue;
            }

            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same-directory rename() is the atomic swap (WP_Filesystem::move() may copy+delete or need FTP credentials in a background request); a warning is handled via the return value.
            if ( ! @rename( $tmp_mmdb, $final ) ) {
                wp_delete_file( $tmp_mmdb );
                $last_error = __( 'Could not move the database into place.', 'olymp-tools' );
                continue;
            }

            update_option( self::OPT_LAST_UPDATED, time() );
            delete_option( self::OPT_LAST_ERROR );
            delete_transient( self::FLAG_DOWNLOADING );
            return true;
        }

        // All attempts failed: record the error and let the lock expire (back-off).
        update_option( self::OPT_LAST_ERROR, $last_error );
        return new WP_Error( 'vloc_download_failed', $last_error );
    }

    /**
     * Stream-decompress a .gz into a sibling temp file (same dir as the final DB
     * so the later rename() is atomic). Never loads the whole DB into memory.
     *
     * @return string|WP_Error path to the decompressed temp file
     */
    private function decompress_gz( $src, $dir ) {
        $dest = trailingslashit( $dir ) . 'dbip-city-lite-' . wp_generate_password( 8, false ) . '.tmp';

        // copy() through the zlib stream wrapper decompresses chunk by chunk.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy() warns on failure; handled by return value.
        if ( ! @copy( 'compress.zlib://' . $src, $dest ) ) {
            wp_delete_file( $dest );
            return new WP_Error( 'gunzip', __( 'Error while decompressing the archive.', 'olymp-tools' ) );
        }

        return $dest;
    }

    /**
     * Confirm a file is a usable MaxMind DB before swapping it in.
     */
    private function verify_db( $path ) {
        try {
            $this->load_reader_library();
            $reader = new \MaxMind\Db\Reader( $path );
            $reader->get( '8.8.8.8' ); // throws on a corrupt database
            $reader->close();
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // ── Storage & library ───────────────────────────────────────────────────────

    private function storage_dir() {
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'olymp-tools';
    }

    private function db_path() {
        return trailingslashit( $this->storage_dir() ) . 'dbip-city-lite.mmdb';
    }

    /**
     * Ensure the storage dir exists and is not web-browsable.
     */
    private function ensure_storage_dir() {
        $dir = $this->storage_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules  = "# Olymp Tools — deny direct access to data files.\n";
            $rules .= "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
            $rules .= "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
            file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        return $dir;
    }

    /**
     * Load the vendored MaxMind DB reader — but only if the class isn't already
     * provided (the `maxminddb` C extension defines the same class; requiring our
     * copy on top of it would fatal with "cannot redeclare").
     */
    private function load_reader_library() {
        if ( class_exists( '\\MaxMind\\Db\\Reader' ) ) {
            return;
        }
        $lib = __DIR__ . '/lib/MaxMind/Db';
        require_once $lib . '/Reader/InvalidDatabaseException.php';
        require_once $lib . '/Reader/Util.php';
        require_once $lib . '/Reader/Metadata.php';
        require_once $lib . '/Reader/Decoder.php';
        require_once $lib . '/Reader.php';
    }
}
