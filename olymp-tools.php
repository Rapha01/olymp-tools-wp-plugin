<?php
/**
 * Plugin Name: Olymp Tools
 * Description: Standalone marketing tools — Google Reviews shortcodes, local visitor-location shortcodes, an AI-image disclosure marker and a Complianz-to-Google-Consent-Mode bridge.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: olympagency
 * Author URI: https://olympagency.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: olymp-tools
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'OLYMP_TOOLS_VERSION', '1.1.0' );
define( 'OLYMP_TOOLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLYMP_TOOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OLYMP_TOOLS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * The framework is loaded on 'plugins_loaded' rather than here, so we can detect
 * SparkPlus <= 1.1.8, which still bundles its own copy of Olymp Tools. That copy
 * is required when SparkPlus's main file loads — i.e. before 'plugins_loaded' —
 * so if the Olymp_Tool interface already exists at that point, loading ours
 * would be a fatal "cannot redeclare" error. In that case we stand down, let the
 * bundled copy run (same option names, so no settings are lost) and ask the
 * admin to update SparkPlus.
 */
add_action( 'plugins_loaded', 'olymp_tools_bootstrap', 5 );

function olymp_tools_bootstrap() {
    if ( interface_exists( 'Olymp_Tool', false ) || function_exists( 'olymp_tools_init' ) ) {
        add_action( 'admin_notices', 'olymp_tools_bundled_copy_notice' );
        return;
    }

    require_once OLYMP_TOOLS_PLUGIN_DIR . 'olymp-tools-core.php';
    olymp_tools_init();
}

/**
 * Admin notice shown while an outdated SparkPlus still ships its bundled Olymp Tools.
 */
function olymp_tools_bundled_copy_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e( 'Olymp Tools: an older SparkPlus version with a bundled copy of Olymp Tools is active, so that copy is used instead of this plugin. Update SparkPlus to use the standalone Olymp Tools plugin — your settings are kept.', 'olymp-tools' ); ?>
        </p>
    </div>
    <?php
}
