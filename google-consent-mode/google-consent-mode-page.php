<?php
/**
 * Google Consent Mode — admin page
 *
 * Included from Olymp_Tool_Google_Consent_Mode::render_page(), which provides:
 *   $settings (array) tool settings (see default_settings())
 *   $status   (array) environment checks (see get_status())
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$olymp_gcm_enabled = ! empty( $settings['enabled'] );
?>
<div class="wrap olymp-tools-wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Passes the consent visitors give in Complianz on to Google Consent Mode v2, so Google Analytics and Google Ads tags in Google Tag Manager respect it. A returning visitor\'s stored choice is applied before any Google tag runs, and changes on the current page take effect immediately. No consent logic is needed in the GTM container or in Complianz\' Script Center.', 'olymp-tools' ); ?>
    </p>

    <form class="olymp-tools-form" data-tool="google-consent-mode" method="post">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable', 'olymp-tools' ); ?></th>
                    <td>
                        <label for="olymp-gcm-enabled">
                            <input type="checkbox" id="olymp-gcm-enabled" name="enabled" value="1" <?php checked( $olymp_gcm_enabled ); ?> />
                            <?php esc_html_e( 'Send the Consent Mode default and pass on the Complianz consent', 'olymp-tools' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'While enabled, GTM4WP\'s own consent default is switched off automatically (GTM4WP 2.0.0 or newer), so the default is never sent twice.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary olymp-tools-save-button"><?php esc_html_e( 'Save Changes', 'olymp-tools' ); ?></button>
            <span class="olymp-tools-save-status"></span>
        </p>
    </form>

    <h2><?php esc_html_e( 'Status', 'olymp-tools' ); ?></h2>

    <?php if ( ! $status['script_readable'] ) : ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e( 'The front-end script of this tool is missing. The tool stays inactive and GTM4WP\'s own consent default is left untouched. Reinstall Olymp Tools.', 'olymp-tools' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! $status['complianz_active'] ) : ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e( 'Complianz is not active. The consent default (denied) is still sent while this tool is enabled, but without Complianz visitors cannot give consent, so Google tags only send cookieless pings.', 'olymp-tools' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $status['complianz_consent_mode'] ) : ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e( 'Complianz\' own Google Consent Mode is enabled. It sends a second consent default and its own updates. Switch it off in Complianz (Wizard → Consent → Statistics: answer "Yes, but not with one of the above services").', 'olymp-tools' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $status['gtm4wp_active'] && ! $status['gtm4wp_filter_supported'] && $status['gtm4wp_own_mode'] ) : ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e( 'This GTM4WP version cannot be told to skip its own consent default. Switch off "Google Consent Mode" under Settings → Google Tag Manager → Consent mode & consent tools, or update GTM4WP to 2.0.0 or newer.', 'olymp-tools' ); ?></p>
        </div>
    <?php endif; ?>

    <table class="widefat striped" style="max-width: 760px;">
        <tbody>
            <tr>
                <td style="width: 240px;"><strong><?php esc_html_e( 'Tool', 'olymp-tools' ); ?></strong></td>
                <td><?php echo $olymp_gcm_enabled ? esc_html__( 'Enabled', 'olymp-tools' ) : esc_html__( 'Disabled', 'olymp-tools' ); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e( 'Complianz', 'olymp-tools' ); ?></strong></td>
                <td>
                    <?php
                    if ( $status['complianz_active'] ) {
                        echo esc_html(
                            sprintf(
                                /* translators: 1: cookie prefix, 2: cookie policy id */
                                __( 'Active — cookie prefix "%1$s", cookie policy id %2$s', 'olymp-tools' ),
                                $status['cookie_prefix'],
                                '' !== $status['policy_id'] ? $status['policy_id'] : __( '(unknown, not checked)', 'olymp-tools' )
                            )
                        );
                    } else {
                        esc_html_e( 'Not active', 'olymp-tools' );
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e( 'Complianz Consent Mode', 'olymp-tools' ); ?></strong></td>
                <td><?php echo $status['complianz_consent_mode'] ? esc_html__( 'On — must be switched off', 'olymp-tools' ) : esc_html__( 'Off (correct)', 'olymp-tools' ); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e( 'GTM4WP', 'olymp-tools' ); ?></strong></td>
                <td>
                    <?php
                    if ( ! $status['gtm4wp_active'] ) {
                        esc_html_e( 'Not active — make sure the GTM container is placed after this tool\'s script in <head>.', 'olymp-tools' );
                    } elseif ( $status['gtm4wp_filter_supported'] ) {
                        echo esc_html(
                            sprintf(
                                /* translators: %s: GTM4WP version */
                                __( 'Active (%s) — its own consent default is switched off by this tool while it is enabled.', 'olymp-tools' ),
                                $status['gtm4wp_version']
                            )
                        );
                    } else {
                        echo esc_html(
                            sprintf(
                                /* translators: %s: GTM4WP version */
                                __( 'Active (%s) — too old to be switched off automatically; its own consent default must be off.', 'olymp-tools' ),
                                $status['gtm4wp_version']
                            )
                        );
                    }
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'How it works', 'olymp-tools' ); ?></h2>
    <ul style="list-style: disc; margin-left: 20px;">
        <li><?php esc_html_e( 'At the very top of <head>, before the GTM container, the consent default is sent: everything denied except functionality and security storage.', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'Right after that, the choice stored in the Complianz cookies is applied as an update — before any Google tag runs.', 'olymp-tools' ); ?></li>
        <li>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %d: polling interval in milliseconds */
                    __( 'The cookies are compared every %d ms; when a visitor accepts, rejects or withdraws consent, the complete new state is sent immediately.', 'olymp-tools' ),
                    (int) $status['poll_interval']
                )
            );
            ?>
        </li>
        <li><?php esc_html_e( 'Mapping: Statistics → analytics_storage · Marketing → ad_storage, ad_user_data, ad_personalization · Preferences → personalization_storage. Only an explicit "allow" counts.', 'olymp-tools' ); ?></li>
    </ul>

    <h2><?php esc_html_e( 'Setup checklist', 'olymp-tools' ); ?></h2>
    <ol style="margin-left: 20px;">
        <li><?php esc_html_e( 'GTM4WP places the container. Its own "Google Consent Mode" setting can stay as it is — this tool switches it off (GTM4WP 2.0.0 or newer).', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'Complianz: Wizard → Consent → Statistics → "Yes, but not with one of the above services". Complianz then only shows the banner and neither adds GTM nor its own Consent Mode.', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'Complianz → Integrations → Script Center: remove entries that send gtag(\'consent\', \'update\', …) — this tool replaces them.', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'In GTM, no consent tags are needed. Google tags use their built-in consent checks.', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'After changing the cookie policy in Complianz, clear the page cache: the active policy id is part of the page.', 'olymp-tools' ); ?></li>
    </ol>

    <h2><?php esc_html_e( 'Testing', 'olymp-tools' ); ?></h2>
    <ol style="margin-left: 20px;">
        <li><?php esc_html_e( 'Page source: this tool\'s script (id "olymp-tools-gcm") is the first consent code in <head> and comes before the GTM container. There is no second gtag("consent", "default", …).', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'GTM Preview, first visit: before the banner is answered, the consent state is "denied". After accepting, it switches to "granted" without a reload.', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'GTM Preview, returning visitor (open another page after accepting): the event "Consent Initialization" already shows "granted" — before the Google tag fires on "Initialization".', 'olymp-tools' ); ?></li>
        <li><?php esc_html_e( 'Withdraw consent in the Complianz settings: the state switches back to "denied" on the same page.', 'olymp-tools' ); ?></li>
    </ol>
</div>
