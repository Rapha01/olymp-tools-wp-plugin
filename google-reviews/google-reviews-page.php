<?php
/**
 * Google Reviews — admin page
 *
 * Included from Olymp_Tool_Google_Reviews::render_page(), which provides:
 *   $api_key, $place_id           (string)  current settings
 *   $stats                        (array|WP_Error) live { rating, count } or error
 *   $shortcode_average, $shortcode_count (string) shortcode tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap olymp-tools-wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Show your Google star rating and review count anywhere with a shortcode. Enter your Google Places API key and Place ID, then save.', 'olymp-tools' ); ?>
    </p>

    <form class="olymp-tools-form" data-tool="google-reviews" method="post">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="olymp-greviews-api-key"><?php esc_html_e( 'Google Places API Key', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="olymp-greviews-api-key" name="api_key" class="regular-text" autocomplete="off"
                               value="<?php echo esc_attr( $api_key ); ?>" placeholder="AIza..." />
                        <p class="description">
                            <?php
                            printf(
                                wp_kses(
                                    /* translators: %s: Google Cloud Console URL */
                                    __( 'Create a key in the <a href="%s" target="_blank" rel="noopener">Google Cloud Console</a> with the <strong>Places API (New)</strong> enabled and billing active. Restrict the key to the Places API.', 'olymp-tools' ),
                                    array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array() )
                                ),
                                'https://console.cloud.google.com/google/maps-apis/api-list'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-greviews-place-id"><?php esc_html_e( 'Place ID', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="olymp-greviews-place-id" name="place_id" class="regular-text"
                               value="<?php echo esc_attr( $place_id ); ?>" placeholder="ChIJ..." />
                        <p class="description">
                            <?php
                            printf(
                                wp_kses(
                                    /* translators: %s: Place ID Finder URL */
                                    __( 'Find your business with the <a href="%s" target="_blank" rel="noopener">Place ID Finder</a>.', 'olymp-tools' ),
                                    array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
                                ),
                                'https://developers.google.com/maps/documentation/places/web-service/place-id'
                            );
                            ?>
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

    <h2><?php esc_html_e( 'Current values', 'olymp-tools' ); ?></h2>
    <?php if ( is_wp_error( $stats ) ) : ?>
        <?php if ( '' === $api_key || '' === $place_id ) : ?>
            <p class="description">
                <?php esc_html_e( 'Enter your API key and Place ID and save to see your live rating and review count here.', 'olymp-tools' ); ?>
            </p>
        <?php else : ?>
            <div class="notice notice-error inline">
                <p>
                    <strong><?php esc_html_e( 'Could not fetch from Google:', 'olymp-tools' ); ?></strong>
                    <?php echo esc_html( $stats->get_error_message() ); ?>
                </p>
            </div>
        <?php endif; ?>
    <?php else : ?>
        <p class="olymp-tools-greviews-current">
            <strong><?php echo esc_html( number_format_i18n( $stats['rating'], 1 ) ); ?></strong>
            <?php esc_html_e( 'average rating', 'olymp-tools' ); ?>
            &nbsp;&middot;&nbsp;
            <strong><?php echo esc_html( number_format_i18n( $stats['count'] ) ); ?></strong>
            <?php esc_html_e( 'reviews', 'olymp-tools' ); ?>
        </p>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Shortcodes', 'olymp-tools' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Paste these into any post, page, or widget. Each outputs just the number. Values are cached for one week (and refresh immediately when you save new settings).', 'olymp-tools' ); ?>
    </p>
    <table class="widefat striped olymp-tools-shortcodes" style="max-width: 640px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Shortcode', 'olymp-tools' ); ?></th>
                <th><?php esc_html_e( 'Outputs', 'olymp-tools' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>[<?php echo esc_html( $shortcode_average ); ?>]</code></td>
                <td><?php esc_html_e( 'Average rating, e.g. 4.6', 'olymp-tools' ); ?></td>
                <td>
                    <button type="button" class="button button-small olymp-tools-copy"
                            data-clipboard="<?php echo esc_attr( '[' . $shortcode_average . ']' ); ?>">
                        <?php esc_html_e( 'Copy', 'olymp-tools' ); ?>
                    </button>
                </td>
            </tr>
            <tr>
                <td><code>[<?php echo esc_html( $shortcode_count ); ?>]</code></td>
                <td><?php esc_html_e( 'Total number of reviews, e.g. 128', 'olymp-tools' ); ?></td>
                <td>
                    <button type="button" class="button button-small olymp-tools-copy"
                            data-clipboard="<?php echo esc_attr( '[' . $shortcode_count . ']' ); ?>">
                        <?php esc_html_e( 'Copy', 'olymp-tools' ); ?>
                    </button>
                </td>
            </tr>
        </tbody>
    </table>
</div>
