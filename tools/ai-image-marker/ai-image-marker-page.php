<?php
/**
 * AI-Image Marker — admin page
 *
 * Included from Olymp_Tool_AI_Image_Marker::render_page(), which provides:
 *   $auto_flag         (bool)   automatically flag newly uploaded images
 *   $settings          (array)  labelling settings (see default_settings())
 *   $badge_image_url   (string) current custom badge image URL ('' if none)
 *   $flagged_count     (int)    number of currently marked images
 *   $filtered_list_url (string) Media Library list view filtered to marked images
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Neutral sample image for the live preview (inline SVG, no asset needed).
$olymp_aiimgmark_sample_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="300">'
    . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
    . '<stop offset="0" stop-color="#7db9e8"/><stop offset="1" stop-color="#1e5799"/>'
    . '</linearGradient></defs>'
    . '<rect width="480" height="300" fill="url(#g)"/>'
    . '<circle cx="380" cy="70" r="34" fill="#ffe28a"/>'
    . '<path d="M0 300 L140 150 L240 250 L330 170 L480 300 Z" fill="#2f6b4f"/>'
    . '</svg>';
$olymp_aiimgmark_sample_src = 'data:image/svg+xml;base64,' . base64_encode( $olymp_aiimgmark_sample_svg );
?>
<div class="wrap olymp-tools-wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Mark images in the Media Library as AI-generated and show a disclosure badge on them across the website. The mark is stored with the image — not baked into the image file — so the badge can be changed centrally and applies everywhere a marked image is used.', 'olymp-tools' ); ?>
    </p>

    <form class="olymp-tools-form" data-tool="ai-image-marker" method="post">

        <h2><?php esc_html_e( 'Automatic marking', 'olymp-tools' ); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'New uploads', 'olymp-tools' ); ?></th>
                    <td>
                        <label for="olymp-aiimgmark-auto-flag">
                            <input type="checkbox" id="olymp-aiimgmark-auto-flag" name="auto_flag" value="1" <?php checked( $auto_flag ); ?> />
                            <?php esc_html_e( 'Automatically mark every newly uploaded image as AI-generated', 'olymp-tools' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Applies to all images added to the Media Library from now on, regardless of how they are uploaded. Existing images are not affected. If you also upload non-AI images, leave this off — or remove the mark from individual images afterwards.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Front-end labelling', 'olymp-tools' ); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show labels', 'olymp-tools' ); ?></th>
                    <td>
                        <label for="olymp-aiimgmark-enabled">
                            <input type="checkbox" id="olymp-aiimgmark-enabled" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
                            <?php esc_html_e( 'Show the badge on marked images on the website', 'olymp-tools' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Master switch. You can configure everything below first and enable the badge when you are happy with the preview.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-badge-type"><?php esc_html_e( 'Badge type', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <select id="olymp-aiimgmark-badge-type" name="badge_type">
                            <option value="text" <?php selected( $settings['badge_type'], 'text' ); ?>><?php esc_html_e( 'Text badge', 'olymp-tools' ); ?></option>
                            <option value="image" <?php selected( $settings['badge_type'], 'image' ); ?>><?php esc_html_e( 'Custom image', 'olymp-tools' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="olymp-aiimgmark-type-text">
                    <th scope="row">
                        <label for="olymp-aiimgmark-text"><?php esc_html_e( 'Badge text', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="olymp-aiimgmark-text" name="text" class="regular-text"
                               value="<?php echo esc_attr( $settings['text'] ); ?>"
                               placeholder="<?php esc_attr_e( 'KI-generiert', 'olymp-tools' ); ?>" />
                    </td>
                </tr>
                <tr class="olymp-aiimgmark-type-image">
                    <th scope="row"><?php esc_html_e( 'Badge image', 'olymp-tools' ); ?></th>
                    <td>
                        <input type="hidden" name="image_id" value="<?php echo esc_attr( $settings['image_id'] ); ?>" />
                        <img id="olymp-aiimgmark-image-thumb" src="<?php echo esc_url( $badge_image_url ); ?>" alt=""
                             style="max-width: 120px; height: auto; display: <?php echo $badge_image_url ? 'block' : 'none'; ?>; margin-bottom: 6px;" />
                        <button type="button" class="button" id="olymp-aiimgmark-image-choose"
                                data-title="<?php esc_attr_e( 'Select badge image', 'olymp-tools' ); ?>">
                            <?php esc_html_e( 'Choose image', 'olymp-tools' ); ?>
                        </button>
                        <button type="button" class="button" id="olymp-aiimgmark-image-remove"
                                style="<?php echo $badge_image_url ? '' : 'display:none;'; ?>">
                            <?php esc_html_e( 'Remove', 'olymp-tools' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Overlaid as-is (a PNG/SVG with transparency works best). Falls back to the text badge while no image is selected.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr class="olymp-aiimgmark-type-image">
                    <th scope="row">
                        <label for="olymp-aiimgmark-image-width"><?php esc_html_e( 'Badge image width', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="olymp-aiimgmark-image-width" name="image_width" class="small-text"
                               min="20" max="1000" step="1" value="<?php echo esc_attr( $settings['image_width'] ); ?>" /> px
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-tooltip"><?php esc_html_e( 'Tooltip', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="olymp-aiimgmark-tooltip" name="tooltip" class="large-text"
                               value="<?php echo esc_attr( $settings['tooltip'] ); ?>"
                               placeholder="<?php esc_attr_e( 'e.g. Dieses Bild wurde mit Hilfe künstlicher Intelligenz erstellt.', 'olymp-tools' ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Optional longer explanation, shown when hovering the badge.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-position"><?php esc_html_e( 'Position & size', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <select id="olymp-aiimgmark-position" name="position">
                            <option value="top-left" <?php selected( $settings['position'], 'top-left' ); ?>><?php esc_html_e( 'Top left', 'olymp-tools' ); ?></option>
                            <option value="top-right" <?php selected( $settings['position'], 'top-right' ); ?>><?php esc_html_e( 'Top right', 'olymp-tools' ); ?></option>
                            <option value="bottom-left" <?php selected( $settings['position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'olymp-tools' ); ?></option>
                            <option value="bottom-right" <?php selected( $settings['position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'olymp-tools' ); ?></option>
                        </select>
                        <select id="olymp-aiimgmark-size" name="size">
                            <option value="small" <?php selected( $settings['size'], 'small' ); ?>><?php esc_html_e( 'Small', 'olymp-tools' ); ?></option>
                            <option value="medium" <?php selected( $settings['size'], 'medium' ); ?>><?php esc_html_e( 'Medium', 'olymp-tools' ); ?></option>
                            <option value="large" <?php selected( $settings['size'], 'large' ); ?>><?php esc_html_e( 'Large', 'olymp-tools' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="olymp-aiimgmark-type-text">
                    <th scope="row"><?php esc_html_e( 'Colors', 'olymp-tools' ); ?></th>
                    <td>
                        <label>
                            <?php esc_html_e( 'Text', 'olymp-tools' ); ?>
                            <input type="color" name="text_color" value="<?php echo esc_attr( $settings['text_color'] ); ?>" />
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <?php esc_html_e( 'Background', 'olymp-tools' ); ?>
                            <input type="color" name="bg_color" value="<?php echo esc_attr( $settings['bg_color'] ); ?>" />
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <?php esc_html_e( 'Background opacity', 'olymp-tools' ); ?>
                            <input type="number" name="bg_opacity" class="small-text" min="0" max="100" step="5"
                                   value="<?php echo esc_attr( $settings['bg_opacity'] ); ?>" /> %
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-min-size"><?php esc_html_e( 'Minimum image size', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="olymp-aiimgmark-min-size" name="min_size" class="small-text"
                               min="0" max="2000" step="10" value="<?php echo esc_attr( $settings['min_size'] ); ?>" /> px
                        <p class="description">
                            <?php esc_html_e( 'Images whose smaller side is below this get no badge — keeps thumbnails and small teasers clean.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alt text disclosure', 'olymp-tools' ); ?></th>
                    <td>
                        <label for="olymp-aiimgmark-alt-enable">
                            <input type="checkbox" id="olymp-aiimgmark-alt-enable" name="alt_suffix_enable" value="1" <?php checked( $settings['alt_suffix_enable'] ); ?> />
                            <?php esc_html_e( 'Append a suffix to the alt text of marked images', 'olymp-tools' ); ?>
                        </label>
                        <input type="text" name="alt_suffix" class="regular-text" style="margin-left: 8px;"
                               value="<?php echo esc_attr( $settings['alt_suffix'] ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'The visual badge is invisible to screen readers — this makes the disclosure accessible too.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-link"><?php esc_html_e( 'Badge link', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="olymp-aiimgmark-link" name="badge_link" class="large-text"
                               value="<?php echo esc_attr( $settings['badge_link'] ); ?>"
                               placeholder="https://" />
                        <p class="description">
                            <?php esc_html_e( 'Optional: the badge links to this URL, e.g. a page explaining your use of AI. Images that are themselves inside a link keep a plain (non-linked) badge.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="olymp-aiimgmark-excludes"><?php esc_html_e( 'Exclude selectors', 'olymp-tools' ); ?></label>
                    </th>
                    <td>
                        <textarea id="olymp-aiimgmark-excludes" name="exclude_selectors" class="large-text code" rows="3"
                                  placeholder=".client-logo-slider img&#10;.no-ai-badge"><?php echo esc_textarea( $settings['exclude_selectors'] ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'One CSS selector per line. Images matching a selector — or sitting inside an element that matches — get no badge.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Further styling', 'olymp-tools' ); ?></th>
                    <td>
                        <p class="description">
                            <?php esc_html_e( 'Need more than the options above? Add CSS for .olymp-aiimgmark-badge under "Additional CSS" in the Customizer or Site Editor. Text and background color are set inline from the options above — use !important to override them there.', 'olymp-tools' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Preview', 'olymp-tools' ); ?></h2>
        <div id="olymp-aiimgmark-preview"
             data-default-text="<?php echo esc_attr( Olymp_Tool_AI_Image_Marker::default_settings()['text'] ); ?>"
             style="position: relative; display: inline-block; max-width: 100%; line-height: 0;">
            <img src="<?php echo esc_url( $olymp_aiimgmark_sample_src ); ?>" alt=""
                 style="display: block; width: 480px; max-width: 100%; height: auto; border-radius: 4px;" />
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary olymp-tools-save-button"><?php esc_html_e( 'Save Changes', 'olymp-tools' ); ?></button>
            <span class="olymp-tools-save-status"></span>
        </p>
    </form>

    <h2><?php esc_html_e( 'Marking images', 'olymp-tools' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'You can mark images one by one or in bulk:', 'olymp-tools' ); ?>
    </p>
    <ul style="list-style: disc; margin-left: 20px;">
        <li>
            <?php
            echo wp_kses(
                __( '<strong>Single image:</strong> open an image in the Media Library and tick the &ldquo;This image was generated by AI&rdquo; checkbox in the attachment details.', 'olymp-tools' ),
                array( 'strong' => array() )
            );
            ?>
        </li>
        <li>
            <?php
            echo wp_kses(
                __( '<strong>Multiple images:</strong> switch the Media Library to <em>list view</em>, select the images, and use the &ldquo;Mark as AI-generated&rdquo; / &ldquo;Remove AI-generated mark&rdquo; bulk actions.', 'olymp-tools' ),
                array( 'strong' => array(), 'em' => array() )
            );
            ?>
        </li>
        <li>
            <?php
            echo wp_kses(
                __( '<strong>Overview:</strong> the list view has an &ldquo;AI&rdquo; column and a filter dropdown to show only marked (or only unmarked) images.', 'olymp-tools' ),
                array( 'strong' => array() )
            );
            ?>
        </li>
    </ul>

    <h2><?php esc_html_e( 'Currently marked', 'olymp-tools' ); ?></h2>
    <p class="olymp-tools-aiimgmark-count">
        <strong><?php echo esc_html( number_format_i18n( $flagged_count ) ); ?></strong>
        <?php echo esc_html( _n( 'image is marked as AI-generated.', 'images are marked as AI-generated.', $flagged_count, 'olymp-tools' ) ); ?>
    </p>
    <p>
        <a href="<?php echo esc_url( $filtered_list_url ); ?>" class="button">
            <?php esc_html_e( 'View marked images in the Media Library', 'olymp-tools' ); ?>
        </a>
    </p>
</div>
