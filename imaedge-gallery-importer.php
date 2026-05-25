<?php
/**
 * Plugin Name: Imaedge Gallery Importer
 * Description: Importiert Bilder aus Imaedge-Links oder HTML-Exports in die WordPress-Mediathek und erstellt daraus eine Galerie.
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
        add_action('wp_ajax_imaedge_gallery_import_log', [$this, 'handle_log_request']);
    }

    public function add_admin_page() {
        add_menu_page(
            'Imaedge Gallery Importer',
            'Imaedge Gallery Importer',
            'upload_files',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-format-gallery',
            56
        );
    }

    public function render_admin_page() {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du hast keine Berechtigung, Dateien hochzuladen.', 'imaedge-gallery-importer'));
        }

        $result = get_transient('imaedge_gallery_import_result_' . get_current_user_id());
        delete_transient('imaedge_gallery_import_result_' . get_current_user_id());
        $run_id = wp_generate_uuid4();
        ?>
        <div class="wrap">
            <h1>Imaedge Gallery Importer</h1>
            <p>Füge einen Imaedge-Export-Link oder HTML-Source ein. Das Plugin sucht bevorzugt nach Links auf <code>/original.jpg</code>, <code>/original.jpeg</code>, <code>/original.png</code> oder <code>/original.webp</code>, lädt diese in die Mediathek und erstellt daraus eine Galerie.</p>

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
                        <p><a class="button button-primary" href="<?php echo esc_url($result['post_edit_url']); ?>">Erstellten Galerie-Beitrag bearbeiten</a></p>
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

            <div id="igi_progress" class="igi-progress" hidden>
                <p class="igi-progress__status">
                    <span class="spinner is-active"></span>
                    <strong id="igi_progress_title">Import läuft...</strong>
                </p>
                <div id="igi_live_log" class="igi-live-log" aria-live="polite"></div>
            </div>

            <div id="igi_ajax_result"></div>

            <form id="igi_import_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="imaedge_gallery_import">
                <input type="hidden" name="run_id" value="<?php echo esc_attr($run_id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="igi_title">Titel</label></th>
                        <td><input name="title" id="igi_title" type="text" class="regular-text" value="Imported Gallery"></td>
                    </tr>
                    <tr>
                        <th scope="row">Beitrag erstellen</th>
                        <td>
                            <label><input type="checkbox" name="create_page" value="1" checked> Neuen Entwurfs-Beitrag mit Galerie erstellen</label>
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
                        <th scope="row"><label for="igi_source">Quelle</label></th>
                        <td>
                            <textarea name="source" id="igi_source" rows="18" style="width:100%;font-family:monospace;" placeholder="https://www.imaedge.org/i/.../export" required></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Bilder importieren und Galerie erstellen'); ?>
            </form>
        </div>
        <style>
            .igi-progress {
                background: #fff;
                border: 1px solid #c3c4c7;
                margin: 20px 0;
                padding: 16px;
            }

            .igi-progress__status {
                align-items: center;
                display: flex;
                gap: 8px;
                margin: 0 0 12px;
            }

            .igi-progress__status .spinner {
                float: none;
                margin: 0;
                visibility: visible;
            }

            .igi-live-log {
                background: #1d2327;
                color: #f0f0f1;
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                line-height: 1.5;
                max-height: 280px;
                overflow: auto;
                padding: 12px;
                white-space: pre-wrap;
            }

            .igi-live-log__line {
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
                padding: 2px 0;
            }
        </style>
        <script>
            (function () {
                var form = document.getElementById('igi_import_form');
                var progress = document.getElementById('igi_progress');
                var log = document.getElementById('igi_live_log');
                var title = document.getElementById('igi_progress_title');
                var result = document.getElementById('igi_ajax_result');
                var logUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

                if (!form || !window.fetch || !window.FormData) {
                    return;
                }

                function setControlsDisabled(disabled) {
                    Array.prototype.forEach.call(form.elements, function (field) {
                        field.disabled = disabled;
                    });
                }

                function renderLog(lines) {
                    log.textContent = '';
                    lines.forEach(function (line) {
                        var row = document.createElement('div');
                        row.className = 'igi-live-log__line';
                        row.textContent = line;
                        log.appendChild(row);
                    });
                    log.scrollTop = log.scrollHeight;
                }

                function renderResult(data) {
                    var errors = data.errors || [];
                    var noticeClass = errors.length ? 'notice-warning' : 'notice-success';
                    var html = '<div class="notice ' + noticeClass + ' is-dismissible">';
                    html += '<p><strong>' + Number(data.imported || 0) + '</strong> Bilder importiert.</p>';

                    if (data.shortcode) {
                        html += '<p><strong>Gallery Shortcode:</strong></p>';
                        html += '<textarea readonly rows="2" style="width:100%;font-family:monospace;">' + escapeHtml(data.shortcode) + '</textarea>';
                        html += '<p><strong>Gutenberg Gallery Block:</strong></p>';
                        html += '<textarea readonly rows="5" style="width:100%;font-family:monospace;">' + escapeHtml(data.block || '') + '</textarea>';
                    }

                    if (data.post_edit_url) {
                        html += '<p><a class="button button-primary" href="' + encodeURI(data.post_edit_url) + '">Erstellten Galerie-Beitrag bearbeiten</a></p>';
                    }

                    if (errors.length) {
                        html += '<p><strong>Fehler / übersprungene URLs:</strong></p><ul style="list-style:disc;margin-left:20px;">';
                        errors.forEach(function (error) {
                            html += '<li><code>' + escapeHtml(error) + '</code></li>';
                        });
                        html += '</ul>';
                    }

                    html += '</div>';
                    result.innerHTML = html;
                }

                function escapeHtml(value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                form.addEventListener('submit', function (event) {
                    var data = new FormData(form);
                    var runId = data.get('run_id');
                    var pollTimer;

                    event.preventDefault();
                    data.set('ajax_import', '1');
                    progress.hidden = false;
                    result.innerHTML = '';
                    title.textContent = 'Import läuft...';
                    renderLog(['Import gestartet.']);
                    setControlsDisabled(true);

                    function pollLog() {
                        var pollData = new FormData();
                        pollData.set('action', 'imaedge_gallery_import_log');
                        pollData.set('_wpnonce', data.get('_wpnonce'));
                        pollData.set('run_id', runId);

                        fetch(logUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: pollData
                        })
                            .then(function (response) {
                                return response.json();
                            })
                            .then(function (payload) {
                                if (payload && payload.success && payload.data && payload.data.lines && payload.data.lines.length) {
                                    renderLog(payload.data.lines);
                                }
                            })
                            .catch(function () {});
                    }

                    pollTimer = window.setInterval(pollLog, 800);
                    pollLog();

                    fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (payload) {
                            if (!payload || !payload.success) {
                                throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Import fehlgeschlagen.');
                            }

                            window.clearInterval(pollTimer);
                            renderLog(payload.data.log || []);
                            renderResult(payload.data.result || {});
                            title.textContent = 'Import abgeschlossen.';
                        })
                        .catch(function (error) {
                            window.clearInterval(pollTimer);
                            title.textContent = 'Import fehlgeschlagen.';
                            renderLog([error.message || 'Import fehlgeschlagen.']);
                        })
                        .finally(function () {
                            setControlsDisabled(false);
                        });
                });
            })();
        </script>
        <?php
    }

    public function handle_import() {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du hast keine Berechtigung, Dateien hochzuladen.', 'imaedge-gallery-importer'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $is_ajax = !empty($_POST['ajax_import']);
        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : wp_generate_uuid4();
        $this->reset_log($run_id);
        $this->append_log($run_id, 'Import gestartet.');

        $source = isset($_POST['source']) ? trim(wp_unslash($_POST['source'])) : '';
        if ($source === '' && isset($_POST['html'])) {
            $source = trim(wp_unslash($_POST['html']));
        }
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Imported Gallery';
        $source_mode = isset($_POST['source_mode']) ? sanitize_key($_POST['source_mode']) : 'original';
        $create_page = !empty($_POST['create_page']);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $errors = [];
        $this->append_log($run_id, 'Quelle wird geprüft.');
        $source_data = $this->load_source($source, $run_id);
        if (is_wp_error($source_data)) {
            $errors[] = $source_data->get_error_message();
            $this->append_log($run_id, 'Quelle konnte nicht geladen werden: ' . $source_data->get_error_message());
            $items = [];
        } else {
            $this->append_log($run_id, 'Quelle geladen. Bild-URLs werden gesucht.');
            $items = $this->extract_items($source_data['html'], $source_mode, $source_data['base_url']);
            if (empty($items)) {
                $errors[] = 'Keine passenden Bild-URLs in der Quelle gefunden.';
                $this->append_log($run_id, 'Keine passenden Bild-URLs gefunden.');
            } else {
                $this->append_log($run_id, count($items) . ' Bild-URLs gefunden.');
            }
        }

        $ids = [];
        $total = count($items);

        foreach ($items as $index => $item) {
            $this->append_log($run_id, 'Importiere Bild ' . ($index + 1) . ' von ' . $total . ': ' . $item['url']);
            $attachment_id = $this->import_remote_file($item['url'], $item['filename'], $title);
            if (is_wp_error($attachment_id)) {
                $errors[] = $item['url'] . ' - ' . $attachment_id->get_error_message();
                $this->append_log($run_id, 'Übersprungen: ' . $attachment_id->get_error_message());
                continue;
            }
            $ids[] = $attachment_id;
            $this->append_log($run_id, 'Importiert als Attachment #' . intval($attachment_id) . '.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $shortcode = '';
        $block = '';
        $post_edit_url = '';

        if (!empty($ids)) {
            $this->append_log($run_id, 'Galerie wird vorbereitet.');
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
                $this->append_log($run_id, 'Entwurfs-Beitrag wird erstellt.');
                $post_id = wp_insert_post([
                    'post_title' => $title,
                    'post_status' => 'draft',
                    'post_type' => 'post',
                    'post_content' => $block,
                ], true);

                if (is_wp_error($post_id)) {
                    $errors[] = 'Beitrag konnte nicht erstellt werden: ' . $post_id->get_error_message();
                    $this->append_log($run_id, 'Beitrag konnte nicht erstellt werden: ' . $post_id->get_error_message());
                } else {
                    $post_edit_url = get_edit_post_link($post_id, 'raw');
                    $this->append_log($run_id, 'Entwurfs-Beitrag erstellt.');
                }
            }
        }

        $result = [
            'imported' => count($ids),
            'shortcode' => $shortcode,
            'block' => $block,
            'post_edit_url' => $post_edit_url,
            'errors' => $errors,
        ];

        $this->append_log($run_id, 'Fertig. ' . count($ids) . ' Bilder importiert.');

        set_transient('imaedge_gallery_import_result_' . get_current_user_id(), $result, 60);

        if ($is_ajax) {
            wp_send_json_success([
                'result' => $result,
                'log' => $this->get_log($run_id),
            ]);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function handle_log_request() {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION);

        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : '';
        wp_send_json_success([
            'lines' => $this->get_log($run_id),
        ]);
    }

    private function load_source($source, $run_id = '') {
        if ($source === '') {
            return new WP_Error('empty_source', 'Bitte einen Imaedge-Export-Link oder HTML-Source einfügen.');
        }

        if ($this->is_probably_url($source)) {
            if ($this->looks_like_image_url($source)) {
                $this->append_log($run_id, 'Direkte Bild-URL erkannt.');
                return [
                    'html' => $source,
                    'base_url' => $source,
                ];
            }

            if (!wp_http_validate_url($source)) {
                return new WP_Error('invalid_source_url', 'Ungültige oder nicht erlaubte Quell-URL.');
            }

            $this->append_log($run_id, 'Quell-URL wird geladen: ' . $source);
            $response = wp_remote_get($source, [
                'timeout' => 30,
                'redirection' => 5,
                'user-agent' => 'Imaedge Gallery Importer/' . get_bloginfo('version') . '; ' . home_url('/'),
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('source_fetch_failed', 'Quell-URL konnte nicht geladen werden: ' . $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $this->append_log($run_id, 'Quell-URL antwortet mit HTTP-Status ' . intval($status_code) . '.');
            if ($status_code < 200 || $status_code >= 300) {
                return new WP_Error('source_bad_status', 'Quell-URL konnte nicht geladen werden. HTTP-Status: ' . intval($status_code));
            }

            return [
                'html' => wp_remote_retrieve_body($response),
                'base_url' => $source,
            ];
        }

        $this->append_log($run_id, 'HTML-Source erkannt.');

        return [
            'html' => $source,
            'base_url' => '',
        ];
    }

    private function extract_items($html, $source_mode, $base_url = '') {
        $items = [];

        if ($source_mode === 'original' || $source_mode === 'both') {
            if (preg_match_all('/<a\b[^>]*href=["\']([^"\']*\/original\.(?:jpe?g|png|webp|gif)(?:\?[^"\']*)?)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tag = $match[0];
                    $url = $this->absolute_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), $base_url);
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
                    $url = $this->absolute_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), $base_url);
                    if (!$this->looks_like_image_url($url)) {
                        continue;
                    }
                    $items[] = ['url' => $url, 'filename' => basename(parse_url($url, PHP_URL_PATH))];
                }
            }
        }

        if (empty($items) && $this->looks_like_image_url($html)) {
            $items[] = [
                'url' => $this->absolute_url(trim($html), $base_url),
                'filename' => basename(parse_url(trim($html), PHP_URL_PATH)),
            ];
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

    private function is_probably_url($value) {
        return is_string($value) && preg_match('/^https?:\/\/[^\s<>"\']+$/i', trim($value));
    }

    private function absolute_url($url, $base_url = '') {
        $url = trim($url);
        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            $scheme = parse_url($base_url, PHP_URL_SCHEME);
            return ($scheme ?: 'https') . ':' . $url;
        }

        if (!$base_url || !preg_match('/^https?:\/\//i', $base_url)) {
            return $url;
        }

        $base = wp_parse_url($base_url);
        if (empty($base['scheme']) || empty($base['host'])) {
            return $url;
        }

        $host = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($url, '/') === 0) {
            return $host . $this->normalize_path($url);
        }

        $base_path = isset($base['path']) ? $base['path'] : '/';
        $dir = preg_replace('/\/[^\/]*$/', '/', $base_path);
        return $host . $this->normalize_path($dir . $url);
    }

    private function normalize_path($path) {
        $segments = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return '/' . implode('/', $normalized);
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

    private function log_key($run_id) {
        return 'imaedge_gallery_import_log_' . get_current_user_id() . '_' . sanitize_key($run_id);
    }

    private function reset_log($run_id) {
        set_transient($this->log_key($run_id), [], 10 * MINUTE_IN_SECONDS);
    }

    private function append_log($run_id, $message) {
        $lines = $this->get_log($run_id);
        $lines[] = '[' . current_time('H:i:s') . '] ' . $message;
        set_transient($this->log_key($run_id), array_slice($lines, -200), 10 * MINUTE_IN_SECONDS);
    }

    private function get_log($run_id) {
        if (!$run_id) {
            return [];
        }

        $lines = get_transient($this->log_key($run_id));
        return is_array($lines) ? $lines : [];
    }
}

new Imaedge_Gallery_Importer();
