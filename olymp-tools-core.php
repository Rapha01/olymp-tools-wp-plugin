<?php
/**
 * Olymp Tools — framework
 *
 * A lightweight, pluggable container for standalone side-features. Each
 * feature ("tool") is a class implementing the Olymp_Tool contract and
 * registered with the manager, which exposes them under a dedicated top-level
 * "Olymp Tools" admin menu — one submenu per tool.
 *
 * To add a new tool:
 *   1. Create a class implementing Olymp_Tool.
 *   2. require_once it and call $olymp_tools->register( new Your_Tool() ) in
 *      olymp_tools_init() at the bottom of this file.
 * It gets its own submenu automatically — nothing else needs to change.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract every Olymp Tools side-feature must implement.
 */
interface Olymp_Tool {

    /** Unique slug fragment, e.g. 'google-reviews' (becomes 'olymp-tools-google-reviews'). */
    public function get_id();

    /** Submenu label shown in the admin menu. */
    public function get_menu_label();

    /** <title> / page heading for the tool's admin screen. */
    public function get_page_title();

    /**
     * Register hooks that must run on EVERY request (front-end + admin), such
     * as add_shortcode(). Called once during plugin bootstrap.
     */
    public function init();

    /** Render the tool's admin page. */
    public function render_page();

    /**
     * Persist settings posted from this tool's form.
     *
     * @param array $post The raw $_POST payload (the tool sanitizes its own fields).
     * @return array Response data passed to wp_send_json_success().
     */
    public function save( $post );
}

/**
 * Manager / registry for the Olymp Tools framework.
 */
class Olymp_Tools {

    const MENU_SLUG  = 'olymp-tools';
    const MENU_TITLE = 'Olymp Tools'; // Brand label — change here to rename the menu.
    const CAPABILITY = 'manage_options';
    const NONCE      = 'olymp_tools_nonce';
    const VERSION    = '1.0.2';        // Asset cache-busting version for the framework.

    /** @var Olymp_Tool[] Keyed by tool id. */
    private $tools = array();

    /**
     * Register a side-feature module.
     *
     * @param Olymp_Tool $tool
     */
    public function register( Olymp_Tool $tool ) {
        $this->tools[ $tool->get_id() ] = $tool;
    }

    /**
     * Wire up hooks. Tool init() runs everywhere (front-end shortcodes); the
     * admin menu is only registered in the dashboard.
     */
    public function init() {
        foreach ( $this->tools as $tool ) {
            $tool->init();
        }

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_menus' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'wp_ajax_olymp_tools_save', array( $this, 'ajax_save' ) );
        }
    }

    /**
     * Register the top-level "Olymp Tools" menu plus one submenu per tool.
     */
    public function register_menus() {
        if ( empty( $this->tools ) ) {
            return;
        }

        add_menu_page(
            self::MENU_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_overview' ),
            'dashicons-admin-tools',
            61
        );

        // Relabel the auto-created first submenu (a duplicate of the parent)
        // from "Olymp Tools" to "Overview".
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Overview', 'olymp-tools' ),
            __( 'Overview', 'olymp-tools' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_overview' )
        );

        // One submenu per registered tool.
        foreach ( $this->tools as $tool ) {
            add_submenu_page(
                self::MENU_SLUG,
                $tool->get_page_title(),
                $tool->get_menu_label(),
                self::CAPABILITY,
                $this->tool_slug( $tool->get_id() ),
                array( $tool, 'render_page' )
            );
        }
    }

    /**
     * Build the admin page slug for a tool id.
     *
     * @param string $id
     * @return string e.g. 'olymp-tools-google-reviews'
     */
    private function tool_slug( $id ) {
        return self::MENU_SLUG . '-' . $id;
    }

    /**
     * Render the landing/overview page listing the available tools.
     */
    public function render_overview() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( self::MENU_TITLE ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'A collection of standalone marketing tools.', 'olymp-tools' ); ?>
            </p>
            <ul class="olymp-tools-overview-list" style="list-style: disc; margin-left: 20px;">
                <?php foreach ( $this->tools as $tool ) : ?>
                    <li>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->tool_slug( $tool->get_id() ) ) ); ?>">
                            <?php echo esc_html( $tool->get_menu_label() ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Enqueue shared admin JS/CSS on the Olymp Tools pages (top-level + tool submenus).
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'olymp-tools',
            plugins_url( 'assets/olymp-tools.css', __FILE__ ),
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'olymp-tools',
            plugins_url( 'assets/olymp-tools.js', __FILE__ ),
            array( 'jquery' ),
            self::VERSION,
            true
        );

        wp_localize_script( 'olymp-tools', 'olympTools', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE ),
            'saving'  => __( 'Saving...', 'olymp-tools' ),
            'saved'   => __( 'Saved!', 'olymp-tools' ),
            'error'   => __( 'Error saving settings.', 'olymp-tools' ),
            'copied'  => __( 'Copied!', 'olymp-tools' ),
        ) );
    }

    /**
     * Shared AJAX save endpoint. Routes the POST to the addressed tool's save().
     */
    public function ajax_save() {
        check_ajax_referer( self::NONCE, 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'olymp-tools' ) ) );
        }

        $id = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';

        if ( ! isset( $this->tools[ $id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown tool.', 'olymp-tools' ) ) );
        }

        // The addressed tool sanitizes its own fields.
        $result = $this->tools[ $id ]->save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; sanitized inside save().
        wp_send_json_success( $result );
    }
}

/**
 * Bootstrap the Olymp Tools framework: load tools, register them, and wire hooks.
 * Called on 'plugins_loaded' from the plugin's main file (olymp-tools.php).
 *
 * To add a tool: require its file and register it here — nothing else changes.
 *
 * @return Olymp_Tools
 */
function olymp_tools_init() {
    global $olymp_tools;

    require_once __DIR__ . '/google-reviews/google-reviews.php';
    require_once __DIR__ . '/visitor-location/visitor-location.php';
    require_once __DIR__ . '/ai-image-marker/ai-image-marker.php';

    $olymp_tools = new Olymp_Tools();
    $olymp_tools->register( new Olymp_Tool_Google_Reviews() );
    $olymp_tools->register( new Olymp_Tool_Visitor_Location() );
    $olymp_tools->register( new Olymp_Tool_AI_Image_Marker() );
    $olymp_tools->init();

    return $olymp_tools;
}
