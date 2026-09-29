<?php
/**
 * Olymp Tool: AI-Image Marker
 *
 * Lets the site owner mark Media Library images as AI-generated, so the site
 * can disclose this to visitors (transparency obligations, e.g. EU AI Act).
 * The mark is a single boolean stored as attachment meta — it is deliberately
 * NOT baked into the image file, so the front-end labelling can be changed in
 * one place and apply everywhere a marked image is used.
 *
 * Flag management:
 *   - Checkbox on every image's attachment details (media modal + edit screen).
 *   - "AI" column, mark/unmark bulk actions, and a filter dropdown in the
 *     Media Library list view.
 *   - Optional auto-flagging of every newly uploaded image.
 *
 * Front-end labelling:
 *   - A configurable badge (text or custom image) is rendered on every marked
 *     image, client-side: assets/ai-image-marker.js matches <img> tags against
 *     the marked attachments' file stems and injects the badge into the final
 *     DOM. That makes it work with any page builder / theme / ACF output and
 *     behind full-page caching, without touching the image files themselves.
 *   - Optionally appends a disclosure suffix to marked images' alt text, so
 *     the disclosure also reaches screen-reader users.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olymp_Tool_AI_Image_Marker implements Olymp_Tool {

    /*
     * Naming convention: every identifier this tool owns — DB keys, query
     * args, column/bulk ids, asset handles, CSS classes — carries the tool-
     * specific prefix "olymp_tools_aiimgmark" (or "olymp-aiimgmark" in
     * HTML/CSS), so nothing can collide with other (future) Olymp Tools.
     */

    /** Attachment meta flag. Set to '1' on marked images, deleted when unmarked. */
    const META_KEY = 'olymp_tools_aiimgmark_marked';

    /** Option: automatically flag every newly uploaded image. */
    const OPT_AUTO_FLAG = 'olymp_tools_aiimgmark_auto_flag';

    /** Option: front-end labelling settings (array — see default_settings()). */
    const OPT_SETTINGS = 'olymp_tools_aiimgmark_settings';

    /** Handle + cache-busting version for this tool's own JS/CSS assets. */
    const HANDLE  = 'olymp-tools-aiimgmark';
    const VERSION = '1.0.1';

    /** Media list table column id / filter + notice query args. */
    const COLUMN_ID      = 'olymp_tools_aiimgmark';
    const FILTER_ARG     = 'olymp_tools_aiimgmark_filter';
    const NOTICE_ACTION  = 'olymp_tools_aiimgmark_bulk';
    const NOTICE_COUNT   = 'olymp_tools_aiimgmark_count';
    const NOTICE_NONCE   = 'olymp_tools_aiimgmark_nonce';

    /** Bulk action ids in the Media Library list view. */
    const BULK_MARK   = 'olymp_tools_aiimgmark_mark';
    const BULK_UNMARK = 'olymp_tools_aiimgmark_unmark';

    // ── Olymp_Tool contract ───────────────────────────────────────────────────

    public function get_id() {
        return 'ai-image-marker';
    }

    public function get_menu_label() {
        return __( 'AI-Image Marker', 'olymp-tools' );
    }

    public function get_page_title() {
        return __( 'AI-Image Marker', 'olymp-tools' );
    }

    public function init() {
        // Auto-flag runs on every upload path (admin, front-end forms, REST).
        add_action( 'add_attachment', array( $this, 'maybe_flag_new_attachment' ) );

        // Front-end labelling of marked images.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );

        if ( is_admin() ) {
            // Settings page extras: media picker + live badge preview.
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

            // Checkbox in the attachment details (media modal + attachment edit screen).
            add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
            add_filter( 'attachment_fields_to_save', array( $this, 'save_attachment_field' ), 10, 2 );

            // Media Library list view: column, bulk actions, filter dropdown.
            add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
            add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
            add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
            add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
            add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ), 10, 2 );
            add_action( 'pre_get_posts', array( $this, 'apply_filter_query' ) );

            // Feedback notice after a bulk action (args are cleaned from the URL afterwards).
            add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
            add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $auto_flag = (bool) get_option( self::OPT_AUTO_FLAG, false );
        $settings  = $this->get_settings();

        // Current badge image URL (for the media picker preview), if one is set.
        $badge_image_url = '';
        if ( $settings['image_id'] ) {
            $badge_image_url = (string) wp_get_attachment_image_url( $settings['image_id'], 'medium' );
        }

        // Count of currently marked images (admin page only — not a hot path).
        $count_query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => '1',            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ) );
        $flagged_count = (int) $count_query->found_posts;

        // Media Library list view, pre-filtered to marked images.
        $filtered_list_url = add_query_arg(
            array(
                'mode'           => 'list',
                self::FILTER_ARG => '1',
            ),
            admin_url( 'upload.php' )
        );

        include __DIR__ . '/ai-image-marker-page.php';
    }

    /**
     * Persist the tool's settings (shared olymp_tools_save AJAX endpoint).
     * Checkboxes are absent from the payload when unchecked.
     */
    public function save() {
        check_ajax_referer( Olymp_Tools::NONCE, 'nonce' );

        // Checkboxes: present with value "1" when checked, absent otherwise.
        $auto_flag  = isset( $_POST['auto_flag'] ) && '1' === sanitize_key( wp_unslash( $_POST['auto_flag'] ) );
        $enabled    = isset( $_POST['enabled'] ) && '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) );
        $alt_enable = isset( $_POST['alt_suffix_enable'] ) && '1' === sanitize_key( wp_unslash( $_POST['alt_suffix_enable'] ) );

        // Flag management.
        update_option( self::OPT_AUTO_FLAG, $auto_flag );

        // Front-end labelling settings.
        $defaults = self::default_settings();

        $badge_type  = isset( $_POST['badge_type'] ) ? sanitize_key( wp_unslash( $_POST['badge_type'] ) ) : '';
        $position    = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : '';
        $size        = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : '';
        $text        = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : $defaults['text'];
        $tooltip     = isset( $_POST['tooltip'] ) ? sanitize_text_field( wp_unslash( $_POST['tooltip'] ) ) : '';
        $text_color  = isset( $_POST['text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['text_color'] ) ) : '';
        $bg_color    = isset( $_POST['bg_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['bg_color'] ) ) : '';
        $bg_opacity  = isset( $_POST['bg_opacity'] ) ? absint( wp_unslash( $_POST['bg_opacity'] ) ) : $defaults['bg_opacity'];
        $min_size    = isset( $_POST['min_size'] ) ? absint( wp_unslash( $_POST['min_size'] ) ) : $defaults['min_size'];
        $alt_suffix  = isset( $_POST['alt_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_suffix'] ) ) : $defaults['alt_suffix'];
        $badge_link  = isset( $_POST['badge_link'] ) ? esc_url_raw( wp_unslash( $_POST['badge_link'] ) ) : '';
        $image_id    = isset( $_POST['image_id'] ) ? absint( wp_unslash( $_POST['image_id'] ) ) : 0;
        $img_width   = isset( $_POST['image_width'] ) ? absint( wp_unslash( $_POST['image_width'] ) ) : 0;
        $excludes    = isset( $_POST['exclude_selectors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_selectors'] ) ) : '';

        $settings = array(
            'enabled'           => $enabled,
            'badge_type'        => ( 'image' === $badge_type ) ? 'image' : 'text',
            'text'              => $text,
            'tooltip'           => $tooltip,
            'position'          => in_array( $position, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? $position : $defaults['position'],
            'size'              => in_array( $size, array( 'small', 'medium', 'large' ), true ) ? $size : $defaults['size'],
            'text_color'        => $text_color ? $text_color : $defaults['text_color'],
            'bg_color'          => $bg_color ? $bg_color : $defaults['bg_color'],
            'bg_opacity'        => min( 100, $bg_opacity ),
            'min_size'          => min( 2000, $min_size ),
            'alt_suffix_enable' => $alt_enable,
            'alt_suffix'        => $alt_suffix,
            'badge_link'        => $badge_link,
            // Only an existing image attachment can serve as the badge image.
            'image_id'          => ( $image_id && wp_attachment_is_image( $image_id ) ) ? $image_id : 0,
            'image_width'       => max( 20, min( 1000, $img_width ? $img_width : $defaults['image_width'] ) ),
            'exclude_selectors' => $excludes,
        );

        update_option( self::OPT_SETTINGS, $settings );

        return array( 'message' => __( 'AI-Image Marker settings saved.', 'olymp-tools' ) );
    }

    // ── Flag helpers ──────────────────────────────────────────────────────────

    /**
     * Whether an attachment is marked as AI-generated.
     *
     * Public so the (later) front-end rendering step and other code can share
     * the single source of truth for the flag.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function is_marked( $attachment_id ) {
        return (bool) get_post_meta( $attachment_id, self::META_KEY, true );
    }

    /**
     * Mark or unmark an attachment. Unmarking deletes the meta row entirely so
     * the "not marked" filter can use a plain NOT EXISTS query.
     *
     * @param int  $attachment_id
     * @param bool $marked
     */
    private function set_marked( $attachment_id, $marked ) {
        if ( $marked ) {
            update_post_meta( $attachment_id, self::META_KEY, '1' );
        } else {
            delete_post_meta( $attachment_id, self::META_KEY );
        }
    }

    // ── Auto-flag on upload ───────────────────────────────────────────────────

    /**
     * Flag every newly added image when the auto-flag option is enabled.
     * Runs on 'add_attachment', i.e. for uploads from any source. Non-image
     * attachments (PDFs etc.) are never flagged.
     *
     * @param int $attachment_id
     */
    public function maybe_flag_new_attachment( $attachment_id ) {
        if ( ! get_option( self::OPT_AUTO_FLAG, false ) ) {
            return;
        }
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return;
        }
        $this->set_marked( $attachment_id, true );
    }

    // ── Attachment details checkbox ───────────────────────────────────────────

    /**
     * Add the "AI-generated" checkbox to the attachment details of images
     * (shown in the media modal sidebar and on the attachment edit screen).
     *
     * @param array   $fields
     * @param WP_Post $post
     * @return array
     */
    public function attachment_field( $fields, $post ) {
        if ( ! wp_attachment_is_image( $post->ID ) ) {
            return $fields;
        }

        $field_name  = 'attachments[' . $post->ID . '][' . self::META_KEY . ']';
        $marker_name = 'attachments[' . $post->ID . '][' . self::META_KEY . '_present]';

        // The hidden input marks "this form included our checkbox". Without it,
        // an unchecked checkbox would be indistinguishable from a save that
        // never rendered the field (e.g. the attachment edit screen), which
        // would silently unmark the image on unrelated saves.
        $fields[ self::META_KEY ] = array(
            'label' => __( 'AI-generated', 'olymp-tools' ),
            'input' => 'html',
            'html'  => '<input type="hidden" name="' . esc_attr( $marker_name ) . '" value="1" />'
                . '<label><input type="checkbox" name="' . esc_attr( $field_name ) . '" value="1" '
                . checked( self::is_marked( $post->ID ), true, false ) . ' /> '
                . esc_html__( 'This image was generated by AI', 'olymp-tools' )
                . '</label>',
            'helps' => __( 'Used by Olymp Tools to label AI-generated images on the website.', 'olymp-tools' ),
        );

        return $fields;
    }

    /**
     * Persist the checkbox, but only when the submission actually contained our
     * field (see the hidden "_present" marker above). An unchecked checkbox is
     * absent from the submitted fields, so absence alone must not unmark.
     *
     * @param array $post       Attachment post data (array form).
     * @param array $attachment The submitted attachment fields.
     * @return array
     */
    public function save_attachment_field( $post, $attachment ) {
        if ( isset( $attachment[ self::META_KEY . '_present' ] ) ) {
            $this->set_marked( $post['ID'], ! empty( $attachment[ self::META_KEY ] ) );
        }
        return $post;
    }

    // ── Media Library list view: column ───────────────────────────────────────

    /**
     * @param array $columns
     * @return array
     */
    public function media_column( $columns ) {
        $columns[ self::COLUMN_ID ] = __( 'AI', 'olymp-tools' );
        return $columns;
    }

    /**
     * @param string $column_name
     * @param int    $post_id
     */
    public function media_column_content( $column_name, $post_id ) {
        if ( self::COLUMN_ID !== $column_name ) {
            return;
        }
        if ( self::is_marked( $post_id ) ) {
            echo '<span class="dashicons dashicons-yes-alt" style="color:#2271b1;" aria-hidden="true" title="'
                . esc_attr__( 'Marked as AI-generated', 'olymp-tools' ) . '"></span>'
                . '<span class="screen-reader-text">' . esc_html__( 'Marked as AI-generated', 'olymp-tools' ) . '</span>';
        } else {
            echo '<span aria-hidden="true">&mdash;</span>';
        }
    }

    // ── Media Library list view: bulk actions ─────────────────────────────────

    /**
     * @param array $actions
     * @return array
     */
    public function bulk_actions( $actions ) {
        $actions[ self::BULK_MARK ]   = __( 'Mark as AI-generated', 'olymp-tools' );
        $actions[ self::BULK_UNMARK ] = __( 'Remove AI-generated mark', 'olymp-tools' );
        return $actions;
    }

    /**
     * Apply a mark/unmark bulk action. Only images the user can edit are
     * touched; other attachments in the selection are skipped silently.
     *
     * @param string $redirect Redirect URL after the action.
     * @param string $action   Selected bulk action id.
     * @param array  $ids      Selected attachment ids.
     * @return string
     */
    public function handle_bulk_actions( $redirect, $action, $ids ) {
        if ( self::BULK_MARK !== $action && self::BULK_UNMARK !== $action ) {
            return $redirect;
        }

        $count = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( ! current_user_can( 'edit_post', $id ) || ! wp_attachment_is_image( $id ) ) {
                continue;
            }
            $this->set_marked( $id, self::BULK_MARK === $action );
            $count++;
        }

        return add_query_arg(
            array(
                self::NOTICE_ACTION => ( self::BULK_MARK === $action ) ? 'mark' : 'unmark',
                self::NOTICE_COUNT  => $count,
                self::NOTICE_NONCE  => wp_create_nonce( self::NOTICE_ACTION ),
            ),
            $redirect
        );
    }

    /**
     * Success notice after a bulk mark/unmark.
     */
    public function bulk_action_notice() {
        if ( ! isset( $_GET[ self::NOTICE_NONCE ], $_GET[ self::NOTICE_ACTION ], $_GET[ self::NOTICE_COUNT ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET[ self::NOTICE_NONCE ] ) ), self::NOTICE_ACTION ) ) {
            return;
        }
        if ( ! current_user_can( 'upload_files' ) ) {
            return;
        }
        $action = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ACTION ] ) );
        $count  = absint( wp_unslash( $_GET[ self::NOTICE_COUNT ] ) );

        if ( 'mark' === $action ) {
            /* translators: %s: number of images */
            $message = sprintf( _n( '%s image marked as AI-generated.', '%s images marked as AI-generated.', $count, 'olymp-tools' ), number_format_i18n( $count ) );
        } elseif ( 'unmark' === $action ) {
            /* translators: %s: number of images */
            $message = sprintf( _n( 'AI-generated mark removed from %s image.', 'AI-generated mark removed from %s images.', $count, 'olymp-tools' ), number_format_i18n( $count ) );
        } else {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    /**
     * Let WordPress strip our one-time feedback args from the URL after display.
     *
     * @param array $args
     * @return array
     */
    public function removable_query_args( $args ) {
        $args[] = self::NOTICE_ACTION;
        $args[] = self::NOTICE_COUNT;
        $args[] = self::NOTICE_NONCE;
        return $args;
    }

    // ── Media Library list view: filter ───────────────────────────────────────

    /**
     * Dropdown to filter the list view by AI mark. On the Media Library list
     * screen this action fires once, from the filter toolbar ($which = 'bar').
     *
     * @param string $post_type
     * @param string $which     Tablenav position ('bar' on upload.php).
     */
    public function filter_dropdown( $post_type, $which ) {
        if ( 'attachment' !== $post_type ) {
            return;
        }

        $current = $this->current_filter();
        ?>
        <select name="<?php echo esc_attr( self::FILTER_ARG ); ?>">
            <option value=""><?php esc_html_e( 'All images', 'olymp-tools' ); ?></option>
            <option value="1" <?php selected( $current, '1' ); ?>><?php esc_html_e( 'AI-generated', 'olymp-tools' ); ?></option>
            <option value="0" <?php selected( $current, '0' ); ?>><?php esc_html_e( 'Not AI-generated', 'olymp-tools' ); ?></option>
        </select>
        <?php
    }

    /**
     * Apply the filter dropdown to the Media Library list query.
     *
     * @param WP_Query $query
     */
    public function apply_filter_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( ! isset( $GLOBALS['pagenow'] ) || 'upload.php' !== $GLOBALS['pagenow'] ) {
            return;
        }

        $current = $this->current_filter();

        if ( '1' === $current ) {
            $query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'   => self::META_KEY,
                    'value' => '1',
                ),
            ) );
        } elseif ( '0' === $current ) {
            $query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => self::META_KEY,
                    'compare' => 'NOT EXISTS',
                ),
            ) );
        }
    }

    /**
     * The active filter dropdown value: '1' (marked), '0' (not marked) or ''
     * (no filter). Anything else is discarded.
     *
     * No nonce: this is a read-only view filter of the Media Library list — it
     * changes no data, like core's own list filters. It also cannot carry one:
     * upload.php redirects the filter submission to a URL without _wpnonce.
     *
     * @return string
     */
    private function current_filter() {
        if ( ! current_user_can( 'upload_files' ) ) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, see docblock.
        $value = isset( $_GET[ self::FILTER_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_ARG ] ) ) : '';
        return in_array( $value, array( '0', '1' ), true ) ? $value : '';
    }

    // ── Labelling settings ────────────────────────────────────────────────────

    /**
     * Default labelling settings. German-locale sites get German label texts;
     * everything else falls back to the translatable English defaults.
     *
     * @return array
     */
    public static function default_settings() {
        $is_de = ( 0 === stripos( (string) get_locale(), 'de' ) );

        return array(
            'enabled'           => false,
            'badge_type'        => 'text',           // 'text' | 'image'
            'text'              => $is_de ? 'KI-generiert' : __( 'AI-generated', 'olymp-tools' ),
            'tooltip'           => '',
            'position'          => 'bottom-right',   // corner of the image
            'size'              => 'medium',         // 'small' | 'medium' | 'large'
            'text_color'        => '#ffffff',
            'bg_color'          => '#000000',
            'bg_opacity'        => 65,               // 0–100 %
            'min_size'          => 150,              // skip images whose smaller side is below this (px)
            'alt_suffix_enable' => true,
            'alt_suffix'        => $is_de ? '(KI-generiert)' : __( '(AI-generated)', 'olymp-tools' ),
            'badge_link'        => '',               // badge becomes a link when set
            'image_id'          => 0,                // custom badge image (attachment id)
            'image_width'       => 120,              // rendered badge image width (px)
            'exclude_selectors' => '',               // one CSS selector per line
        );
    }

    /**
     * Saved labelling settings merged over the defaults.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = self::default_settings();
        $saved    = get_option( self::OPT_SETTINGS, array() );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

        // An empty label text would render a pointless empty badge.
        if ( '' === trim( (string) $settings['text'] ) ) {
            $settings['text'] = $defaults['text'];
        }

        return $settings;
    }

    // ── Front-end labelling ───────────────────────────────────────────────────

    /**
     * Enqueue the badge engine on the front end — only when labelling is
     * enabled and at least one image is marked.
     */
    public function enqueue_frontend() {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $stems = $this->get_marked_stems();
        if ( empty( $stems ) ) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            plugins_url( 'assets/ai-image-marker.css', __FILE__ ),
            array(),
            self::VERSION
        );

        wp_register_script(
            self::HANDLE,
            plugins_url( 'assets/ai-image-marker.js', __FILE__ ),
            array(),
            self::VERSION,
            true
        );
        wp_add_inline_script(
            self::HANDLE,
            'var olympAiImgMark = ' . wp_json_encode( $this->frontend_config( $settings, $stems ) ) . ';',
            'before'
        );
        wp_enqueue_script( self::HANDLE );
    }

    /**
     * Build the config object handed to assets/ai-image-marker.js.
     *
     * @param array $settings Labelling settings (see get_settings()).
     * @param array $stems    Marked file stems (see get_marked_stems()).
     * @return array
     */
    private function frontend_config( $settings, $stems ) {
        $image_url = '';
        if ( 'image' === $settings['badge_type'] && $settings['image_id'] ) {
            $image_url = (string) wp_get_attachment_image_url( $settings['image_id'], 'full' );
        }

        $excludes = array_values( array_filter( array_map(
            'trim',
            preg_split( '/[\r\n]+/', (string) $settings['exclude_selectors'] )
        ) ) );

        return array(
            'stems'      => array_values( $stems ),
            // Fall back to a text badge when no badge image is set.
            'type'       => ( '' !== $image_url ) ? 'image' : 'text',
            'text'       => $settings['text'],
            'tooltip'    => $settings['tooltip'],
            'link'       => $settings['badge_link'],
            'position'   => $settings['position'],
            'size'       => $settings['size'],
            'textColor'  => $settings['text_color'],
            'bg'         => $this->rgba( $settings['bg_color'], $settings['bg_opacity'] ),
            'minSize'    => (int) $settings['min_size'],
            'imageUrl'   => $image_url,
            'imageWidth' => (int) $settings['image_width'],
            'altSuffix'  => $settings['alt_suffix_enable'] ? $settings['alt_suffix'] : '',
            'excludes'   => $excludes,
        );
    }

    /**
     * Uploads-relative file "stems" of all marked images, e.g. "2026/08/foo"
     * for 2026/08/foo.webp. The client matches <img> URLs against these after
     * stripping the extension and WordPress' -{w}x{h} / -scaled suffixes, so
     * every size variant of a marked image is recognised.
     *
     * One indexed meta join — cheap even with many attachments.
     *
     * @return string[]
     */
    private function get_marked_stems() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed query per page view; result depends on live meta.
        $files = $wpdb->get_col( $wpdb->prepare(
            "SELECT f.meta_value
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = '_wp_attached_file'
             WHERE m.meta_key = %s AND m.meta_value = '1'",
            self::META_KEY
        ) );

        $stems = array();
        foreach ( (array) $files as $file ) {
            $file = (string) $file;
            if ( '' === $file ) {
                continue;
            }
            $stem = preg_replace( '/\.[^.\/]+$/', '', $file ); // drop the extension
            $stem = preg_replace( '/-scaled$/', '', $stem );   // big-image threshold suffix
            $stems[ $stem ] = true;
        }

        return array_keys( $stems );
    }

    /**
     * Convert a hex color + opacity percentage to a CSS rgba() string.
     *
     * @param string $hex     e.g. '#000000' or '#abc'.
     * @param int    $opacity 0–100.
     * @return string
     */
    private function rgba( $hex, $opacity ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
            $hex = '000000';
        }

        $alpha = rtrim( rtrim( number_format( min( 100, max( 0, (int) $opacity ) ) / 100, 2, '.', '' ), '0' ), '.' );
        if ( '' === $alpha ) {
            $alpha = '0';
        }

        return sprintf(
            'rgba(%d,%d,%d,%s)',
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
            $alpha
        );
    }

    // ── Settings page assets ──────────────────────────────────────────────────

    /**
     * On this tool's admin page, load the media picker and the badge engine +
     * admin script for the live preview. The engine does not auto-run there
     * (no front-end config variable is printed), it only exposes its badge
     * builder for the preview.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'olymp-tools-ai-image-marker' ) ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            self::HANDLE,
            plugins_url( 'assets/ai-image-marker.css', __FILE__ ),
            array(),
            self::VERSION
        );
        wp_enqueue_script(
            self::HANDLE,
            plugins_url( 'assets/ai-image-marker.js', __FILE__ ),
            array(),
            self::VERSION,
            true
        );
        wp_enqueue_script(
            self::HANDLE . '-admin',
            plugins_url( 'assets/ai-image-marker-admin.js', __FILE__ ),
            array( 'jquery', self::HANDLE ),
            self::VERSION,
            true
        );
    }
}
