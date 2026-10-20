<?php
/**
 * Plugin Name: Voice ADD for HivePress PRO
 * Plugin URI:  https://uaserver.pp.ua/voice-add-hivepress/
 * Description: Голосове додавання оголошень у HivePress з фото та кастомними атрибутами.
 * Version:     2.3.1
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua/
 * License:     GPLv2 or later
 * Text Domain: voice-add-hp
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('VAHP_VERSION',      '2.3.1');
define('VAHP_FILE',         __FILE__);
define('VAHP_SERVICE_SLUG', 'voice-add-hivepress');
define('VAHP_GITHUB',       'portallcomua/voice-add-hivepress');
define('VAHP_FREE_LIMIT',   25);

// =====================================================================
// АВТООНОВЛЕННЯ З GITHUB
// =====================================================================
class VAHP_Updater {
    private $file, $slug, $repo;
    public function __construct($file, $repo) {
        $this->file = $file;
        $this->slug = dirname(plugin_basename($file));
        $this->repo = $repo;
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
        add_filter('plugins_api', [$this, 'info'], 10, 3);
    }
    public function check($t) {
        if (empty($t->checked)) return $t;
        $r = $this->remote();
        if ($r && version_compare(VAHP_VERSION, $r->version, '<')) {
            $t->response[plugin_basename($this->file)] = (object)[
                'slug' => $this->slug, 'new_version' => $r->version,
                'package' => $r->zip, 'url' => $r->url, 'tested' => '7.1',
            ];
        }
        return $t;
    }
    public function info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->slug) return $res;
        $r = $this->remote();
        if (!$r) return $res;
        return (object)['name' => 'Voice ADD for HivePress PRO', 'slug' => $this->slug,
            'version' => $r->version, 'download_link' => $r->zip,
            'tested' => '7.1', 'requires_php' => '7.4',
            'sections' => ['description' => 'Голосове додавання оголошень у HivePress.']];
    }
    private function remote() {
        $resp = wp_remote_get("https://api.github.com/repos/{$this->repo}/releases/latest",
            ['headers' => ['Accept' => 'application/json'], 'timeout' => 5]);
        if (is_wp_error($resp)) return null;
        $d = json_decode(wp_remote_retrieve_body($resp));
        if (!$d || !isset($d->tag_name)) return null;
        return (object)['version' => ltrim($d->tag_name, 'v'), 'zip' => $d->zipball_url, 'url' => $d->html_url];
    }
}
new VAHP_Updater(VAHP_FILE, VAHP_GITHUB);

// =====================================================================
// 1. ІНТЕГРАЦІЯ З ЯДРОМ
// =====================================================================
function vahp_core_active() { return class_exists('UM_Client') && class_exists('UM_Helpers'); }
function vahp_get_service_id() {
    if (!class_exists('UM_Client')) return 0;
    $service = UM_Client::get_service(VAHP_SERVICE_SLUG);
    if (!$service) return 0;
    if (is_object($service) && isset($service->id)) return (int) $service->id;
    if (is_array($service) && isset($service['id']))  return (int) $service['id'];
    return 0;
}
function vahp_pro() {
    if (!function_exists('wcc_check_user_premium')) return false;
    $sid = vahp_get_service_id();
    return $sid ? (bool) wcc_check_user_premium(get_current_user_id(), $sid) : false;
}

// =====================================================================
// 2. АКТИВАЦІЯ
// =====================================================================
register_activation_hook(__FILE__, function () {
    add_option('vahp_ops', 0);
    add_option('vahp_draft', '0');
    add_option('vahp_rbg_api', '');
    add_option('vahp_legacy_migrated', '0');

    if (class_exists('UM_Client')) {
        UM_Client::register([
            'slug' => VAHP_SERVICE_SLUG,
            'name' => 'Voice ADD for HivePress PRO',
        ]);
    }
});

// =====================================================================
// 3. LEGACY
// =====================================================================
function wcc_add_premium_access_hp_legacy($user_id, $pid_old, $type = 'trial', $email = '', $domain = '') {
    $sid = vahp_get_service_id();
    if (!$sid || !function_exists('wcc_add_premium_access')) return false;
    return wcc_add_premium_access($user_id, $sid, ['type' => $type, 'email' => $email, 'domain' => $domain]);
}
function wcc_block_premium_access_hp_legacy($user_id, $pid_old) {
    $sid = vahp_get_service_id();
    if (!$sid || !function_exists('wcc_block_premium_access')) return false;
    return wcc_block_premium_access($user_id, $sid);
}

// =====================================================================
// 4. МІГРАЦІЯ
// =====================================================================
add_action('admin_init', function () {
    if (get_option('vahp_legacy_migrated') === '1') return;
    if (!vahp_core_active() || !function_exists('wcc_add_premium_access')) return;

    global $wpdb;
    $old_table = $wpdb->prefix . 'wcc_premium_access';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$old_table}'") !== $old_table) {
        update_option('vahp_legacy_migrated', '1');
        return;
    }
    $sid = vahp_get_service_id();
    if (!$sid) return;

    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$old_table} WHERE product_id = %d", 4));
    if ($rows) {
        foreach ($rows as $row) {
            wcc_add_premium_access($row->user_id, $sid, [
                'type' => $row->type ?? 'legacy',
                'email' => $row->email ?? '',
                'domain' => $row->domain ?? '',
            ]);
        }
    }
    update_option('vahp_legacy_migrated', '1');
});

// =====================================================================
// 5. ХЕЛПЕРИ
// =====================================================================
function vahp_en() { return in_array(get_locale(), ['en_US','en_GB','en_CA','en_AU','en']); }
function vahp_t($ua, $en) { return vahp_en() ? $en : $ua; }
function vahp_count() { return (int) get_option('vahp_ops', 0); }
function vahp_inc() { update_option('vahp_ops', vahp_count() + 1); }
function vahp_user_can_use() { return is_user_logged_in() && current_user_can('manage_options'); }

function vahp_get_attributes() {
    $attributes = [];
    if ( function_exists('hivepress') ) {
        $hp = hivepress();
        if ( isset($hp->attributes) ) {
            $all = $hp->attributes->get_all();
            foreach ( $all as $attr ) {
                $attributes[] = [
                    'name' => $attr['name'],
                    'type' => $attr['type'] ?? 'text',
                ];
            }
        }
    }
    return $attributes;
}

// =====================================================================
// 6. МЕНЮ
// =====================================================================
add_action('admin_menu', function () {
    add_menu_page('Voice ADD HP', 'Voice ADD HP', 'manage_options', 'vahp_main', 'vahp_page_main', 'dashicons-microphone', 30);
    add_submenu_page('vahp_main', vahp_t('Налаштування','Settings'), '⚙️ ' . vahp_t('Налаштування','Settings'), 'manage_options', 'vahp_settings', 'vahp_page_settings');
});

// =====================================================================
// 7. НАЛАШТУВАННЯ
// =====================================================================
function vahp_page_settings() {
    if (isset($_POST['vahp_save']) && check_admin_referer('vahp_cfg')) {
        update_option('vahp_rbg_api', sanitize_text_field($_POST['rbg_api']));
        update_option('vahp_draft', isset($_POST['vahp_draft']) ? '1' : '0');
        echo '<div class="notice notice-success"><p>✅ Збережено!</p></div>';
    }
    $rbg = get_option('vahp_rbg_api', '');
    $draft = get_option('vahp_draft', '0');
    ?>
    <div class="wrap">
        <h1>⚙️ Налаштування Voice ADD HP</h1>
        <?php if (!vahp_core_active()): ?>
            <div class="notice notice-error"><p>⚠️ Ядро не активне. PRO-функції недоступні.</p></div>
        <?php endif ?>
        <form method="post"><?php wp_nonce_field('vahp_cfg') ?>
        <table class="form-table">
            <tr><th>Remove.bg API Key</th><td><input type="text" name="rbg_api" value="<?php echo esc_attr($rbg) ?>" style="width:340px"></td></tr>
            <tr><th>Статус</th><td><label><input type="checkbox" name="vahp_draft" value="1" <?php checked($draft, '1') ?>> Зберігати як чернетку</label></td></tr>
        </table>
        <input type="hidden" name="vahp_save" value="1">
        <?php submit_button('Зберегти') ?>
        </form>
    </div>
    <?php
}

// =====================================================================
// 8. ГОЛОВНА СТОРІНКА
// =====================================================================
function vahp_page_main() {
    if (!vahp_user_can_use()) wp_die('Доступ заборонено');

    $attrs = vahp_get_attributes();
    $pro = vahp_pro();
    $can = $pro || vahp_count() < VAHP_FREE_LIMIT;
    $has_rbg = !empty(get_option('vahp_rbg_api', ''));
    $draft = get_option('vahp_draft', '0') === '1';
    $sid = vahp_get_service_id();

    $template = vahp_en() ? "TITLE: \nCATEGORY: \nDESCRIPTION: \n" : "ЗАГОЛОВОК: \nКАТЕГОРІЯ: \nОПИС: \n";
    foreach ($attrs as $a) {
        if ($a['type'] === 'select') {
            $template .= (vahp_en() ? "Attribute " : "Атрибут ") . $a['name'] . (vahp_en() ? " Value \n" : " Значення \n");
        } else {
            $template .= $a['name'] . ": \n";
        }
    }

    $js_config = [
        'EN' => vahp_en(), 'PRO' => $pro, 'CAN' => $can, 'DRAFT' => $draft, 'HAS_RBG' => $has_rbg,
        'AJAX' => admin_url('admin-ajax.php'),
        'NS' => wp_create_nonce('vahp_save'),
        'TPL' => $template,
    ];
    ?>
    <style>@keyframes vahp_pulse {0%,100%{opacity:1} 50%{opacity:.5}}</style>
    <div class="wrap">
        <h1>🎤 Voice ADD for HivePress <sup style="font-size:11px;color:#aaa">v<?php echo esc_html(VAHP_VERSION) ?></sup></h1>

        <?php if (!$pro): ?>
            <div style="background:#fff3cd;padding:12px;border-radius:8px;margin-bottom:15px">
                📊 Залишилось: <strong><?php echo max(0, VAHP_FREE_LIMIT - vahp_count()) ?></strong>/<?php echo VAHP_FREE_LIMIT ?>
                <?php
                if (vahp_core_active() && $sid && method_exists('UM_Helpers', 'buy_button')) {
                    echo UM_Helpers::buy_button($sid, '💰 Купити PRO');
                } else {
                    echo '<span style="color:#c00;font-weight:bold">⚠️ Ядро не активне</span>';
                }
                ?>
            </div>
        <?php endif ?>

        <video id="vahp_cam" autoplay playsinline muted style="width:100%;max-height:280px;background:#111;border-radius:10px;margin-bottom:10px;object-fit:cover"></video>
        <div id="vahp_gal" style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px"></div>

        <?php if ($has_rbg): ?>
        <div id="vahp_bg_wrap" style="background:#f8f9fa;padding:10px;border-radius:8px;margin-bottom:10px;display:none">
            <strong>Фон:</strong>
            <label><input type="radio" name="vahp_bg" value="original" checked> Оригінал</label>
            <label><input type="radio" name="vahp_bg" value="transparent"> Прозорий</label>
            <label><input type="radio" name="vahp_bg" value="ffffff"> Білий</label>
        </div>
        <?php endif ?>

        <div style="display:flex;gap:8px;margin-bottom:10px">
            <button id="vahp_photo" style="flex:1;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:8px;cursor:pointer">📷 Фото</button>
            <button id="vahp_clr" style="flex:1;padding:12px;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer">❌ Очистити</button>
        </div>

        <div id="vahp_bar" style="display:none;padding:10px;text-align:center;color:#fff;border-radius:8px;margin-bottom:10px"></div>

        <textarea id="vahp_ta" rows="15" style="width:100%;font-family:monospace;font-size:14px;padding:10px;border:2px solid #2271b1;border-radius:8px;"><?php echo esc_textarea($template); ?></textarea>

        <button id="vahp_mic" style="width:100%;margin-top:10px;padding:14px;background:#1877F2;color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer">🎤 ДИКТУВАТИ</button>

        <button id="vahp_save" style="width:100%;margin-top:10px;padding:16px;background:#00a32a;color:#fff;border:none;border-radius:8px;font-size:18px;font-weight:bold;cursor:pointer">
            <?php echo $draft ? '➕ ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ' : '➕ ОПУБЛІКУВАТИ'; ?>
        </button>

        <div id="vahp_msg" style="margin-top:15px;"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var C = <?php echo json_encode($js_config, JSON_UNESCAPED_UNICODE) ?>;
        var photos = [], listening = false;

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (stream) { document.getElementById('vahp_cam').srcObject = stream; }).catch(function (e) { console.log(e); });

        document.getElementById('vahp_photo').addEventListener('click', function () {
            var v = document.getElementById('vahp_cam');
            if (!v.videoWidth) return;
            var c = document.createElement('canvas');
            c.width = v.videoWidth; c.height = v.videoHeight;
            c.getContext('2d').drawImage(v, 0, 0);
            var d = c.toDataURL('image/jpeg', 0.82);
            photos.push(d);
            var img = document.createElement('img');
            img.src = d;
            img.style.cssText = 'width:60px;height:60px;object-fit:cover;border-radius:6px;border:2px solid #2271b1;cursor:pointer';
            img.addEventListener('click', function () { photos.splice(photos.indexOf(d), 1); img.remove(); });
            document.getElementById('vahp_gal').prepend(img);
            var w = document.getElementById('vahp_bg_wrap'); if (w) w.style.display = 'block';
        });

        document.getElementById('vahp_clr').addEventListener('click', function () {
            photos = []; document.getElementById('vahp_gal').innerHTML = '';
            var w = document.getElementById('vahp_bg_wrap'); if (w) w.style.display = 'none';
        });

        function getBg() {
            var radios = document.querySelectorAll('input[name="vahp_bg"]');
            for (var i = 0; i < radios.length; i++) { if (radios[i].checked) return radios[i].value; }
            return 'original';
        }

        function processPhotos(list, cb) {
            if (!C.HAS_RBG || getBg() === 'original') { cb(list); return; }
            var bg = getBg();
            var res = [], i = 0;
            function next() {
                if (i >= list.length) { cb(res); return; }
                var fd = new FormData();
                fd.append('action', 'vahp_remove_bg'); fd.append('nonce', C.NS); fd.append('image', list[i]); fd.append('bg', bg);
                fetch(C.AJAX, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                    res.push(d.success && d.data && d.data.image ? d.data.image : list[i]);
                    i++; next();
                }).catch(function () { res.push(list[i]); i++; next(); });
            }
            next();
        }

        if (window.SpeechRecognition || window.webkitSpeechRecognition) {
            var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            var rec = new SR();
            rec.lang = C.EN ? 'en-US' : 'uk-UA';
            rec.continuous = true; rec.interimResults = false;
            var mb = document.getElementById('vahp_mic');

            mb.addEventListener('click', function () {
                if (!listening) {
                    rec.start(); listening = true; mb.textContent = '🛑 ЗУПИНИТИ'; mb.style.background = '#dc3545';
                } else {
                    listening = false; rec.stop(); mb.textContent = '🎤 ДИКТУВАТИ'; mb.style.background = '#1877F2';
                }
            });

            rec.onresult = function (e) {
                var spoken = e.results[e.results.length - 1][0].transcript.trim();
                document.getElementById('vahp_ta').value += "\n" + spoken;
            };
            rec.onend = function () { if (listening) setTimeout(function () { try { rec.start(); } catch (x) {} }, 100); };
        }

        document.getElementById('vahp_save').addEventListener('click', function () {
            var self = this;
            if (!C.CAN) { alert('Ліміт вичерпано!'); return; }
            self.disabled = true; self.textContent = '⏳ ЗБЕРІГАЮ...';

            function doSave(finalPhotos) {
                var fd = new FormData();
                fd.append('action', 'vahp_save_listing'); fd.append('nonce', C.NS);
                fd.append('text', document.getElementById('vahp_ta').value);
                fd.append('images', JSON.stringify(finalPhotos));

                fetch(C.AJAX, { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                    if (data.success) {
                        document.getElementById('vahp_msg').innerHTML = '<span style="color:green">✅ Створено! ID: ' + data.data.id + '</span>';
                        photos = []; document.getElementById('vahp_gal').innerHTML = '';
                        document.getElementById('vahp_ta').value = C.TPL;
                    } else {
                        document.getElementById('vahp_msg').innerHTML = '<span style="color:red">❌ ' + data.data + '</span>';
                    }
                }).finally(() => {
                    self.disabled = false; self.textContent = C.DRAFT ? '➕ ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ' : '➕ ОПУБЛІКУВАТИ';
                });
            }

            if (C.HAS_RBG && getBg() !== 'original' && photos.length > 0) processPhotos(photos.slice(), doSave);
            else doSave(photos.slice());
        });
    });
    </script>
    <?php
}

// =====================================================================
// 9. AJAX: ЗБЕРЕЖЕННЯ ОГОЛОШЕННЯ
// =====================================================================
add_action('wp_ajax_vahp_save_listing', function () {
    check_ajax_referer('vahp_save', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Немає прав');
    if (!vahp_pro() && vahp_count() >= VAHP_FREE_LIMIT) wp_send_json_error('Ліміт вичерпано');

    $raw = sanitize_textarea_field($_POST['text'] ?? '');
    $imgs = json_decode(stripslashes($_POST['images'] ?? '[]'), true) ?: [];
    $draft = get_option('vahp_draft', '0') === '1';

    $data = [];
    $custom_attrs = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (stripos($line, 'атрибут ') === 0 && strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            $attr_name = trim(str_ireplace('атрибут', '', $parts[0]));
            $custom_attrs[$attr_name] = trim($parts[1]);
        } elseif (strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            $data[strtoupper(trim($parts[0]))] = trim($parts[1]);
        }
    }

    $post_data = [
        'post_title'   => $data['ЗАГОЛОВОК'] ?? $data['TITLE'] ?? 'Оголошення ' . gmdate('H:i:s'),
        'post_content' => $data['ОПИС'] ?? $data['DESCRIPTION'] ?? '',
        'post_status'  => $draft ? 'draft' : 'publish',
        'post_type'    => 'hp_listing',
    ];
    $pid = wp_insert_post($post_data);
    if (!$pid) wp_send_json_error('Не вдалося створити');

    if (!empty($data['КАТЕГОРІЯ']) || !empty($data['CATEGORY'])) {
        $cat = $data['КАТЕГОРІЯ'] ?? $data['CATEGORY'];
        $term = term_exists($cat, 'hp_listing_category') ?: wp_insert_term($cat, 'hp_listing_category');
        if (!is_wp_error($term)) wp_set_object_terms($pid, (int)$term['term_id'], 'hp_listing_category');
    }

    $all_attrs = vahp_get_attributes();
    foreach ($custom_attrs as $name => $value) {
        $type = 'text';
        foreach ($all_attrs as $a) { if (strtolower($a['name']) === strtolower($name)) { $type = $a['type']; break; } }
        $meta_key = 'hp_' . sanitize_title($name);
        if (in_array($type, ['select', 'checkbox', 'radio'])) {
            $tax = 'hp_listing_' . sanitize_title($name);
            if (taxonomy_exists($tax)) {
                $term = term_exists($value, $tax) ?: wp_insert_term($value, $tax);
                if (!is_wp_error($term)) wp_set_object_terms($pid, (int)$term['term_id'], $tax);
            }
        } else {
            update_post_meta($pid, $meta_key, sanitize_text_field($value));
        }
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    foreach ($imgs as $i => $b64) {
        $is_png = strpos($b64, 'data:image/png') !== false;
        $img = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $b64));
        $ext = $is_png ? 'png' : 'jpg'; $mime = $is_png ? 'image/png' : 'image/jpeg';
        $file = wp_upload_bits('vahp_' . $pid . '_' . time() . '_' . $i . '.' . $ext, null, $img);
        if ($file['error']) continue;
        $aid = wp_insert_attachment(['post_mime_type' => $mime, 'post_status' => 'inherit'], $file['file'], $pid);
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $file['file']));
        if ($i === 0) set_post_thumbnail($pid, $aid);
    }

    vahp_inc();
    wp_send_json_success(['id' => $pid]);
});
