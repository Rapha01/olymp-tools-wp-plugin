<?php
/**
 * Olymp Tool: Google Consent Mode
 *
 * Bridges the consent a visitor gives in Complianz to Google Consent Mode v2,
 * so Google tags (GA4, Google Ads) delivered through Google Tag Manager respect
 * it — without any consent logic in the GTM container and without Complianz'
 * Script Center.
 *
 * How it works
 * ------------
 * A small inline script is printed at the very top of <head> (wp_head,
 * priority 0), i.e. before the GTM container, which GTM4WP outputs at
 * priority 10 (2 with "Load GTM container as early as possible"):
 *
 *   1. It sends the Consent Mode "default" (everything denied except the
 *      strictly necessary functionality/security storage).
 *   2. It reads the choice Complianz has stored in its category cookies and
 *      applies it right away as an "update" — synchronously, so a returning
 *      visitor's known choice is in place before any Google tag runs. There is
 *      no race against the late load of complianz.js at the end of <body>.
 *   3. It keeps comparing the cookies and sends the complete state whenever it
 *      changes, so accepting, rejecting or withdrawing consent on the current
 *      page takes effect immediately.
 *
 * The Complianz category cookies (cmplz_statistics, cmplz_marketing,
 * cmplz_preferences = "allow"/"deny", plus cmplz_policy_id) are the only
 * interface this relies on. They are the most stable part of Complianz, since
 * renaming them would invalidate every stored consent. Reading them client-side
 * keeps the tool correct behind full-page caching.
 *
 * Category mapping (fixed, as Google defines the consent types):
 *   statistics  → analytics_storage
 *   marketing   → ad_storage, ad_user_data, ad_personalization
 *   preferences → personalization_storage
 *   (functional → functionality_storage, security_storage: always granted)
 *
 * While enabled, the tool switches off GTM4WP's own consent default through
 * GTM4WP's filter (available since GTM4WP 2.0.0), so the default is never sent
 * twice. The tool fails safe: if its script file cannot be read, it neither
 * prints anything nor touches GTM4WP, and GTM4WP's own default stays in place.
 *
 * Verified against GTM4WP 2.0.4 (src/Frontend/ContainerCode.php,
 * src/Frontend/ConsentDefaults.php) and Complianz 7.5.5
 * (cookiebanner/js/complianz.js, cookiebanner/class-banner-loader.php).
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olymp_Tool_Google_Consent_Mode implements Olymp_Tool {

    /*
     * Naming convention: every identifier this tool owns — option keys, asset
     * handles, JS globals, CSS classes — carries the tool-specific prefix
     * "olymp_tools_gcm" (or "olymp-gcm" / "olympGcm"), so nothing can collide
     * with other (future) Olymp Tools.
     */

    /** Option: tool settings (array — see default_settings()). */
    const OPT_SETTINGS = 'olymp_tools_gcm_settings';

    /** Id of the printed inline script tag + cache-busting version of this tool. */
    const HANDLE  = 'olymp-tools-gcm';
    const VERSION = '1.0.0';

    /** How often the front end compares the consent cookies (ms). */
    const POLL_INTERVAL = 250;

    /**
     * wp_head priority. 0 prints the script before anything else in <head>,
     * including GTM4WP's container (10, or 2 with "load early") and other
     * plugins' Google tags.
     */
    const HEAD_PRIORITY = 0;

    /** GTM4WP filter (since 2.0.0): whether GTM4WP prints its own consent default. */
    const GTM4WP_DEFAULT_FILTER = 'gtm4wp_consent_mode_default_enabled';

    /** Fallback Complianz cookie prefix (single sites). */
    const DEFAULT_COOKIE_PREFIX = 'cmplz_';

    // ── Olymp_Tool contract ───────────────────────────────────────────────────

    public function get_id() {
        return 'google-consent-mode';
    }

    public function get_menu_label() {
        return __( 'Google Consent Mode', 'olymp-tools' );
    }

    public function get_page_title() {
        return __( 'Google Consent Mode', 'olymp-tools' );
    }

    public function init() {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Fail safe: without our script, leave GTM4WP's own default untouched.
        if ( ! is_readable( $this->frontend_script_path() ) ) {
            return;
        }

        add_action( 'wp_head', array( $this, 'print_consent_script' ), self::HEAD_PRIORITY );

        // We send the consent default ourselves — GTM4WP must not send a second one.
        add_filter( self::GTM4WP_DEFAULT_FILTER, '__return_false', 99 );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $status   = $this->get_status();

        include __DIR__ . '/google-consent-mode-page.php';
    }

    /**
     * Persist the tool's settings (shared olymp_tools_save AJAX endpoint).
     * Checkboxes are absent from the payload when unchecked.
     */
    public function save( $post ) {
        $settings = array(
            'enabled' => isset( $post['enabled'] ) && '1' === $post['enabled'],
        );

        update_option( self::OPT_SETTINGS, $settings );

        return array( 'message' => __( 'Google Consent Mode settings saved.', 'olymp-tools' ) );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    /**
     * @return array
     */
    public static function default_settings() {
        return array(
            'enabled' => false,
        );
    }

    /**
     * Saved settings merged over the defaults.
     *
     * @return array
     */
    public function get_settings() {
        $saved = get_option( self::OPT_SETTINGS, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
    }

    // ── Front end ─────────────────────────────────────────────────────────────

    /**
     * Print the consent bridge inline at the top of <head>.
     *
     * Inline on purpose: an enqueued file would be subject to script
     * optimisation (defer, combine, delay) and could end up after the GTM
     * container. The data attributes are the same ones GTM4WP uses for its
     * own head scripts to keep optimisers from moving them.
     */
    public function print_consent_script() {
        $source = (string) file_get_contents( $this->frontend_script_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, printed inline.
        if ( '' === trim( $source ) ) {
            return;
        }

        $config = array(
            'cookiePrefix' => $this->cookie_prefix(),
            'policyId'     => $this->policy_id(),
            'interval'     => self::POLL_INTERVAL,
        );

        wp_print_inline_script_tag(
            'var olympGcm = ' . wp_json_encode( $config ) . ";\n" . $source,
            array(
                'id'                      => self::HANDLE,
                'data-cfasync'            => 'false',
                'data-pagespeed-no-defer' => true,
            )
        );
    }

    /**
     * @return string Absolute path of the front-end script.
     */
    private function frontend_script_path() {
        return __DIR__ . '/assets/google-consent-mode.js';
    }

    // ── Complianz ─────────────────────────────────────────────────────────────

    /**
     * Whether Complianz (free or premium) is active.
     *
     * @return bool
     */
    private function complianz_active() {
        return class_exists( 'COMPLIANZ' );
    }

    /**
     * Complianz' banner loader, which knows the cookie prefix and the active
     * cookie policy id — or null when unavailable.
     *
     * @return object|null
     */
    private function complianz_banner_loader() {
        if ( ! $this->complianz_active() || ! isset( COMPLIANZ::$banner_loader ) || ! is_object( COMPLIANZ::$banner_loader ) ) {
            return null;
        }
        return COMPLIANZ::$banner_loader;
    }

    /**
     * Cookie prefix Complianz uses ("cmplz_", or "cmplz_rt_" on a multisite
     * main site that does not set cookies on the root). Restricted to
     * characters that are safe inside a cookie name and a JS string.
     *
     * @return string
     */
    private function cookie_prefix() {
        $prefix = self::DEFAULT_COOKIE_PREFIX;

        $loader = $this->complianz_banner_loader();
        if ( $loader && method_exists( $loader, 'get_cookie_prefix' ) ) {
            $prefix = (string) $loader->get_cookie_prefix();
        }

        $prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $prefix );
        return '' !== $prefix ? $prefix : self::DEFAULT_COOKIE_PREFIX;
    }

    /**
     * Id of the active Complianz cookie policy, '' when unknown (then the
     * front end skips the policy check). Complianz itself discards consent
     * that belongs to an older policy.
     *
     * Note: this id is printed into the page, so after changing the cookie
     * policy in Complianz the page cache must be cleared.
     *
     * @return string
     */
    private function policy_id() {
        $loader = $this->complianz_banner_loader();
        if ( ! $loader || ! method_exists( $loader, 'get_active_policy_id' ) ) {
            return '';
        }
        return preg_replace( '/[^0-9]/', '', (string) $loader->get_active_policy_id() );
    }

    // ── Admin status ──────────────────────────────────────────────────────────

    /**
     * Environment checks shown on the tool's admin page.
     *
     * @return array
     */
    private function get_status() {
        $gtm4wp_version = defined( 'GTM4WP_VERSION' ) ? (string) GTM4WP_VERSION : '';

        // GTM4WP stores its settings in one array option ('gtm4wp-options').
        $gtm4wp_options  = get_option( 'gtm4wp-options', array() );
        $gtm4wp_own_mode = is_array( $gtm4wp_options ) && ! empty( $gtm4wp_options['integrate-consent-mode'] );

        return array(
            'script_readable'         => is_readable( $this->frontend_script_path() ),
            'complianz_active'        => $this->complianz_active(),
            'complianz_consent_mode'  => function_exists( 'cmplz_consent_mode' ) && cmplz_consent_mode(),
            'cookie_prefix'           => $this->cookie_prefix(),
            'policy_id'               => $this->policy_id(),
            'gtm4wp_active'           => '' !== $gtm4wp_version,
            'gtm4wp_version'          => $gtm4wp_version,
            'gtm4wp_filter_supported' => '' !== $gtm4wp_version && version_compare( $gtm4wp_version, '2.0.0', '>=' ),
            'gtm4wp_own_mode'         => $gtm4wp_own_mode,
            'poll_interval'           => self::POLL_INTERVAL,
        );
    }
}
