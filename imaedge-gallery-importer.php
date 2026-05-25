<?php
/**
 * Plugin Name: Imaedge Gallery Importer
 * Description: Importiert Bilder aus HTML-Exports in die WordPress-Mediathek und erstellt daraus eine Galerie.
 * Version: 1.0.0
 * Author: OpenAI / ChatGPT
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class Imaedge_Gallery_Importer {
    const MENU_SLUG = 'imaedge-gallery-importer';
    const NONCE_ACTION = 'imaedge_gallery_import';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_imaedge_gallery_import', [$this, 'handle_import']);
    }

    public function add_admin_page() {
        add_management_page(
            'Imaedge Gallery Importer',
            'Imaedge Gallery Importer',
            'upload_files',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du hast keine Berechtigung, Dateien hochzuladen.', 'imaedge-gallery-importer'));
        }

        $result = get_transient('imaedge_gallery_import_result_' . get_current_user_id());
        delete_transient('imaedge_gallery_import_result_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>Imaedge Gallery Importer</h1>
            <p>Füge den HTML-Source ein. Das Plugin sucht bevorzugt nach Links auf <code>/original.jpg</code>, <code>/original.jpeg</code>, <code>/original.png</code> oder <code>/original.webp</code>, lädt diese in die Mediathek und erstellt daraus eine Galerie.</p>

            <?php if (is_array($result)): ?>
                <div class="notice notice-<?php echo empty($result['errors']) ? 'success' : 'warning'; ?> is-dismissible">
                    <p><strong><?php echo intval($result['imported']); ?></strong> Bilder importiert.</p>
                    <?php if (!empty($result['shortcode'])): ?>
                        <p><strong>Gallery Shortcode:</strong></p>
                        <textarea readonly rows="2" style="width:100%;font-family:monospace;"><?php echo esc_textarea($result['shortcode']); ?></textarea>
                        <p><strong>Gutenberg Gallery Block:</strong></p>
                        <textarea readonly rows="5" style="width:100%;font-family:monospace;"><?php echo esc_textarea($result['block']); ?></textarea>
                    <?php endif; ?>
                    <?php if (!empty($result['post_edit_url'])): ?>
                        <p><a class="button button-primary" href="<?php echo esc_url($result['post_edit_url']); ?>">Erstellte Galerie-Seite bearbeiten</a></p>
                    <?php endif; ?>
                    <?php if (!empty($result['errors'])): ?>
                        <p><strong>Fehler / übersprungene URLs:</strong></p>
                        <ul style="list-style:disc;margin-left:20px;">
                            <?php foreach ($result['errors'] as $error): ?>
                                <li><code><?php echo esc_html($error); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="imaedge_gallery_import">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="igi_title">Titel</label></th>
                        <td><input name="title" id="igi_title" type="text" class="regular-text" value="Imported Gallery"></td>
                    </tr>
                    <tr>
                        <th scope="row">Seite erstellen</th>
                        <td>
                            <label><input type="checkbox" name="create_page" value="1" checked> Neue Entwurfs-Seite mit Galerie erstellen</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Quelle</th>
                        <td>
                            <label><input type="radio" name="source_mode" value="original" checked> Originale aus <code>&lt;a href=".../original..."&gt;</code> importieren</label><br>
                            <label><input type="radio" name="source_mode" value="images"> Bilder aus <code>&lt;img src="..."&gt;</code> importieren</label><br>
                            <label><input type="radio" name="source_mode" value="both"> Beides importieren</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="igi_html">HTML</label></th>
                        <td>
                            <textarea name="html" id="igi_html" rows="18" style="width:100%;font-family:monospace;" required></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Bilder importieren und Galerie erstellen'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_import() {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du hast keine Berechtigung, Dateien hochzuladen.', 'imaedge-gallery-importer'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Imported Gallery';
        $source_mode = isset($_POST['source_mode']) ? sanitize_key($_POST['source_mode']) : 'original';
        $create_page = !empty($_POST['create_page']);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $items = $this->extract_items($html, $source_mode);
        $ids = [];
        $errors = [];

        foreach ($items as $item) {
            $attachment_id = $this->import_remote_file($item['url'], $item['filename'], $title);
            if (is_wp_error($attachment_id)) {
                $errors[] = $item['url'] . ' - ' . $attachment_id->get_error_message();
                continue;
            }
            $ids[] = $attachment_id;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $shortcode = '';
        $block = '';
        $post_edit_url = '';

        if (!empty($ids)) {
            $shortcode = '[gallery ids="' . implode(',', $ids) . '"]';
            $block = '<!-- wp:gallery {"ids":[' . implode(',', $ids) . '],"linkTo":"media"} -->' . "\n";
            $block .= '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">';
            foreach ($ids as $id) {
                $src = wp_get_attachment_image_url($id, 'large');
                $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                $block .= '<!-- wp:image {"id":' . intval($id) . ',"sizeSlug":"large","linkDestination":"media"} -->';
                $block .= '<figure class="wp-block-image size-large"><a href="' . esc_url(wp_get_attachment_url($id)) . '"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . intval($id) . '"/></a></figure>';
                $block .= '<!-- /wp:image -->';
            }
            $block .= '</figure>' . "\n" . '<!-- /wp:gallery -->';

            if ($create_page) {
                $post_id = wp_insert_post([
                    'post_title' => $title,
                    'post_status' => 'draft',
                    'post_type' => 'page',
                    'post_content' => $block,
                ], true);

                if (is_wp_error($post_id)) {
                    $errors[] = 'Seite konnte nicht erstellt werden: ' . $post_id->get_error_message();
                } else {
                    $post_edit_url = get_edit_post_link($post_id, 'raw');
                }
            }
        }

        set_transient('imaedge_gallery_import_result_' . get_current_user_id(), [
            'imported' => count($ids),
            'shortcode' => $shortcode,
            'block' => $block,
            'post_edit_url' => $post_edit_url,
            'errors' => $errors,
        ], 60);

        wp_safe_redirect(admin_url('tools.php?page=' . self::MENU_SLUG));
        exit;
    }

    private function extract_items($html, $source_mode) {
        $items = [];

        if ($source_mode === 'original' || $source_mode === 'both') {
            if (preg_match_all('/<a\b[^>]*href=["\']([^"\']*\/original\.(?:jpe?g|png|webp|gif))(?:\?[^"\']*)?["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tag = $match[0];
                    $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                    $filename = $this->attribute_from_tag($tag, 'download');
                    if (!$filename) {
                        $filename = basename(parse_url($url, PHP_URL_PATH));
                    }
                    $items[] = ['url' => $url, 'filename' => sanitize_file_name($filename)];
                }
            }
        }

        if ($source_mode === 'images' || $source_mode === 'both') {
            if (preg_match_all('/<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                    if (!$this->looks_like_image_url($url)) {
                        continue;
                    }
                    $items[] = ['url' => $url, 'filename' => basename(parse_url($url, PHP_URL_PATH))];
                }
            }
        }

        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            if (empty($item['url']) || isset($seen[$item['url']])) {
                continue;
            }
            $seen[$item['url']] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    private function attribute_from_tag($tag, $attr) {
        if (preg_match('/\s' . preg_quote($attr, '/') . '=["\']([^"\']+)["\']/i', $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        return '';
    }

    private function looks_like_image_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $path);
    }

    private function import_remote_file($url, $filename, $title) {
        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', 'Ungültige oder nicht erlaubte URL');
        }

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        if (!$filename) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
        }
        $filename = sanitize_file_name($filename);

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, 0, $title);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        return $attachment_id;
    }
}

new Imaedge_Gallery_Importer();
