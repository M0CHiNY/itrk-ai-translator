<?php
/**
 * Plugin Name: ITRK AI Translator
 * Description: v5.1 with Date Monitoring. Shows last update time for Original docs and Translations.
 * Version: 5.1
 * Author: Disotech
 * Requires Plugins: legal-texts-connector-it-recht-kanzlei, polylang
 */

if (!defined('ABSPATH')) {
    exit;
}

// --- DEPENDENCY CHECKER ---
add_action('plugins_loaded', 'itrk_ai_init_plugin', 20);

function itrk_ai_init_plugin()
{
    $missing = [];

    if (!class_exists('ITRechtKanzlei\LegalText\Plugin\Wordpress\Plugin')) {
        $missing[] = 'Legal Text Connector of the IT-Recht Kanzlei';
    }

    if (!defined('POLYLANG_VERSION') && !function_exists('pll_default_language')) {
        $missing[] = 'Polylang';
    }

    if (!empty($missing)) {
        add_action('admin_notices', function () use ($missing) {
            echo '<div class="notice notice-error"><p>❌ <strong>ITRK AI Translator halted:</strong> Please install and activate: <strong>' . implode(', ', $missing) . '</strong>.</p></div>';
        });
        return;
    }

    new ITRK_AI_Translator();
}

class ITRK_AI_Translator
{

    const OPTION_SETTINGS = 'itrk_ai_settings';
    const CACHE_PREFIX = 'itrk_ai_trans_';
    const CRON_HOOK = 'itrk_ai_background_translation_v5';
    const TRANSIENT_PREFIX = 'itrk_ai_proc_';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('itrk_ai_translate', [$this, 'render_shortcode']);
        add_action(self::CRON_HOOK, [$this, 'handle_background_job'], 10, 4);

        add_action('wp_ajax_itrk_process_job', [$this, 'ajax_process_job']);
        add_action('wp_ajax_itrk_test_connection', [$this, 'ajax_test_connection']);
    }

    // --- 1. SETTINGS & HELPERS ---
    public function get_setting($key, $default = '')
    {
        $options = get_option(self::OPTION_SETTINGS);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    // --- 2. SHORTCODE ---
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts([
            'type' => 'impressum',
            'target_lang' => 'en',
            'source_lang' => 'de',
            'country' => 'CH'
        ], $atts);

        $source_data = $this->get_source_content_smart($atts['type'], $atts['source_lang'], $atts['country']);
        if (!$source_data) {
            return $this->render_alert("Source '{$atts['type']}' not found.", 'red');
        }

        $source_hash = md5($source_data['content']);
        $cache_key = self::CACHE_PREFIX . md5($atts['type'] . $atts['target_lang']);
        $cached_data = get_option($cache_key);

        if ($cached_data && isset($cached_data['content']) && $cached_data['hash'] === $source_hash) {
            return sprintf(
                '<div class="itrk-ai-text type-%s lang-%s">%s</div>',
                esc_attr($atts['type']),
                esc_attr($atts['target_lang']),
                $cached_data['content']
            );
        }

        $lock_key = self::TRANSIENT_PREFIX . md5($atts['type'] . $atts['target_lang']);

        if (get_transient($lock_key)) {
            return $this->render_alert(
                sprintf('⏳ Translation to %s in progress... Please refresh in a minute.', strtoupper($atts['target_lang'])),
                'orange'
            );
        }

        $cron_args = [
            $atts['type'],
            $atts['source_lang'],
            $atts['country'],
            $atts['target_lang']
        ];

        set_transient($lock_key, 'processing', 30 * MINUTE_IN_SECONDS);

        if (!wp_next_scheduled(self::CRON_HOOK, $cron_args)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, $cron_args);
        }

        return $this->render_alert(
            sprintf('🚀 Source changed. Auto-translating to %s...', strtoupper($atts['target_lang'])),
            'blue'
        );
    }


    private function render_alert($msg, $color)
    {
        $colors = ['red' => '#ffebee', 'orange' => '#fff3e0', 'blue' => '#e3f2fd'];
        $txt = ['red' => '#c62828', 'orange' => '#ef6c00', 'blue' => '#1565c0'];
        return sprintf('<div style="background:%s; color:%s; padding:15px; border-radius:4px; border:1px solid %s;"><strong>ITRK AI:</strong> %s</div>', $colors[$color], $txt[$color], $txt[$color], esc_html($msg));
    }

    // --- 3. AJAX HANDLERS ---
    public function ajax_test_connection()
    {
        check_ajax_referer('itrk_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Auth failed');
        $key = $this->get_setting('api_key');
        if (empty($key))
            wp_send_json_error("API Key is empty!");
        $response = wp_remote_get('https://api.openai.com/v1/models', ['headers' => ['Authorization' => 'Bearer ' . $key], 'timeout' => 10]);
        if (is_wp_error($response))
            wp_send_json_error($response->get_error_message());
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200)
            wp_send_json_success("Connection Established! Key is valid.");
        else
            wp_send_json_error("API Error: " . $code);
    }

    public function ajax_process_job()
    {
        check_ajax_referer('itrk_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Auth failed');

        $type = $_POST['type'];
        $src = $_POST['src'];
        $cnt = $_POST['country'];
        $tgt = $_POST['target'];
        if (isset($_POST['force']) && $_POST['force'] == 'true') {
            delete_option(self::CACHE_PREFIX . md5($type . $tgt));
        }

        $res = $this->handle_background_job_verbose($type, $src, $cnt, $tgt);
        wp_clear_scheduled_hook(self::CRON_HOOK, [$type, $src, $cnt, $tgt]);

        if (is_wp_error($res))
            wp_send_json_error($res->get_error_message());
        wp_send_json_success($res);
    }

    public function handle_background_job_verbose($type, $source_lang, $country, $target_lang)
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(600);

        $logs = [];

        $lock_key = self::TRANSIENT_PREFIX . md5($type . $target_lang);

        $source_data = $this->get_source_content_smart($type, $source_lang, $country);
        if (!$source_data) {
            delete_transient($lock_key);
            return new WP_Error('no_source', 'Source doc not found');
        }

        $logs[] = "Found Source: {$source_data['key']} (Length: " . strlen($source_data['content']) . " chars).";

        $source_hash = md5($source_data['content']);
        $cache_key = self::CACHE_PREFIX . md5($type . $target_lang);
        $cached = get_option($cache_key);

        if ($cached && isset($cached['hash']) && $cached['hash'] === $source_hash) {
            $logs[] = "Translation already up-to-date. Skipped.";
            delete_transient($lock_key);
            return $logs;
        }

        $logs[] = "Sending to OpenAI (" . $this->get_setting('model', 'gpt-4o-mini') . ")...";
        $translated = $this->call_openai($source_data['content'], $source_lang, $target_lang);

        if (is_wp_error($translated)) {
            delete_transient($lock_key);
            return $translated;
        }

        $logs[] = "Received response from AI.";
        $this->save_translation($type, $target_lang, $source_hash, $translated);

        $purged = $this->purge_litespeed_cache();
        if ($purged) {
            $logs[] = "🧹 Cache purged: " . $purged;
        }

        delete_transient($lock_key);

        $logs[] = "Saved to Database successfully.";
        return $logs;
    }


    public function handle_background_job($type, $source_lang, $country, $target_lang)
    {
        $this->handle_background_job_verbose($type, $source_lang, $country, $target_lang);
    }

    private function call_openai($content, $src, $tgt)
    {
        $key = $this->get_setting('api_key');
        $model = $this->get_setting('model', 'gpt-4o-mini');
        if (empty($key))
            return new WP_Error('no_key', 'Missing API Key');

        $langs = ['uk' => 'Ukrainian', 'de' => 'German', 'en' => 'English', 'fr' => 'French', 'it' => 'Italian', 'es' => 'Spanish', 'pl' => 'Polish', 'nl' => 'Dutch'];
        $s_name = $langs[$src] ?? $src;
        $t_name = $langs[$tgt] ?? $tgt;

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a legal translator. Preserve HTML tags strictly.'],
                ['role' => 'user', 'content' => "Translate from $s_name to $t_name. Keep HTML structure (div, p, ul, b, etc). Do NOT translate classes/IDs. Output ONLY HTML.\n\n" . $content]
            ]
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 600
        ]);

        if (is_wp_error($response))
            return $response;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error']))
            return new WP_Error('openai', $data['error']['message']);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    // --- 5. DATA HANDLING ---
    private function get_source_content_smart($type, $lang, $country)
    {
        global $wpdb;
        $this->load_doc_class();
        $key_map = ['agb_imprint' => 'impressum', 'agb_terms' => 'agb', 'agb_privacy' => 'datenschutz', 'agb_revocation' => 'widerruf'];
        $real_type = $key_map[$type] ?? $type;

        $candidates = [];
        if ($country) {
            $c = strtoupper($country);
            $candidates[] = "itrk_lti_doc_{$lang}_{$c}_{$real_type}";
            $candidates[] = "itrk_lti_doc_{$real_type}_{$lang}_{$c}";
        }
        $candidates[] = "itrk_lti_doc_{$lang}_{$real_type}";
        $candidates[] = "itrk_lti_doc_{$real_type}_{$lang}";

        foreach ($candidates as $opt) {
            $doc = get_option($opt);
            if ($this->is_doc($doc))
                return ['key' => $opt, 'content' => $doc->getContent()];
        }

        $res = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE %s LIMIT 1", "%$real_type"));
        if (!empty($res)) {
            $doc = maybe_unserialize($res[0]->option_value);
            if ($this->is_doc($doc))
                return ['key' => $res[0]->option_name, 'content' => $doc->getContent()];
        }
        return false;
    }

    private function save_translation($type, $lang, $hash, $content)
    {
        update_option(self::CACHE_PREFIX . md5($type . $lang), ['hash' => $hash, 'content' => $content, 'updated' => current_time('mysql')], false);
    }
    private function is_doc($doc)
    {
        return (is_object($doc) && method_exists($doc, 'getContent'));
    }
    private function load_doc_class()
    {
        if (!class_exists('ITRechtKanzlei\LegalText\Plugin\Wordpress\Document')) {
            $p = WP_PLUGIN_DIR . '/legal-texts-connector-it-recht-kanzlei/src/Document.php';
            if (file_exists($p))
                require_once $p;
        }
    }

    // --- 6. ADMIN UI ---
    public function add_admin_menu()
    {
        add_options_page('ITRK AI', 'ITRK AI Translator', 'manage_options', 'itrk-ai-translator', [$this, 'page_router']);
    }
    public function register_settings()
    {
        register_setting('itrk_ai_group', self::OPTION_SETTINGS);
    }

    public function page_router()
    {
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        ?>
        <div class="wrap">
            <h1>ITRK AI Control Center <span
                    style="font-size:12px;background:#2271b1;color:#fff;padding:2px 8px;border-radius:10px;">v5.1</span></h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=itrk-ai-translator&tab=dashboard"
                    class="nav-tab <?php echo $tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Dashboard</a>
                <a href="?page=itrk-ai-translator&tab=settings"
                    class="nav-tab <?php echo $tab == 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
            </nav>
            <div style="background:#fff; border:1px solid #c3c4c7; border-top:none; padding:20px;">
                <?php if ($tab == 'settings')
                    $this->render_settings();
                else
                    $this->render_dashboard(); ?>
            </div>
        </div>
        <?php
    }

    private function render_settings()
    {
        $opts = get_option(self::OPTION_SETTINGS);
        ?>
        <div class="card" style="max-width:600px; padding:20px;">
            <form method="post" action="options.php">
                <?php settings_fields('itrk_ai_group'); ?>
                <h3>API Configuration</h3>
                <p><label>OpenAI API Key:</label><br><input type="password" name="<?php echo self::OPTION_SETTINGS; ?>[api_key]"
                        value="<?php echo esc_attr($opts['api_key'] ?? ''); ?>" style="width:100%; margin-top:5px;"></p>
                <p><label>Model:</label><br>
                    <select name="<?php echo self::OPTION_SETTINGS; ?>[model]" style="width:100%;">
                        <option value="gpt-4o-mini" <?php selected(($opts['model'] ?? ''), 'gpt-4o-mini'); ?>>GPT-4o Mini (Fast
                            &
                            Cheap)</option>
                        <option value="gpt-4o" <?php selected(($opts['model'] ?? ''), 'gpt-4o'); ?>>GPT-4o (High Quality)
                        </option>
                    </select>
                </p>
                <?php submit_button(); ?>
            </form>
            <hr>
            <button class="button button-secondary" id="btn-test-api">⚡ Test Connection</button>
            <span id="api-test-res" style="margin-left:10px; font-weight:bold;"></span>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                $('#btn-test-api').click(function (e) {
                    e.preventDefault();
                    var btn = $(this); btn.prop('disabled', true).text('Testing...');
                    $.post(ajaxurl, { action: 'itrk_test_connection', nonce: '<?php echo wp_create_nonce("itrk_nonce"); ?>' }, function (res) {
                        btn.prop('disabled', false).text('⚡ Test Connection');
                        if (res.success) $('#api-test-res').css('color', 'green').text('✅ ' + res.data);
                        else $('#api-test-res').css('color', 'red').text('❌ ' + res.data);
                    });
                });
            });
        </script>
        <?php
    }

    private function render_dashboard()
    {
        global $wpdb;
        $this->load_doc_class();

        $targets = function_exists('pll_languages_list') ? array_diff(pll_languages_list(['fields' => 'slug']), ['de']) : ['uk', 'en', 'fr', 'it'];

        $docs = [];
        $rows = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'itrk_lti_doc_%'");
        foreach ($rows as $r) {
            $name = $r->option_name;
            $parts = explode('_', $name);
            $type = 'unknown';
            $country = 'DE';
            $lang = 'de';
            foreach ($parts as $p) {
                if (in_array($p, ['impressum', 'agb', 'datenschutz', 'widerruf']))
                    $type = $p;
                if (strlen($p) == 2 && ctype_upper($p))
                    $country = $p;
            }
            $doc_obj = get_option($name);
            $content = '';
            $date = '-';
            if (is_object($doc_obj) && method_exists($doc_obj, 'getContent')) {
                $content = $doc_obj->getContent();
                // Extract Original Date from Document Object
                if (method_exists($doc_obj, 'getCreationDate')) {
                    $dt = $doc_obj->getCreationDate();
                    if ($dt instanceof DateTime)
                        $date = $dt->format('d.m.y H:i');
                    elseif (is_string($dt))
                        $date = date('d.m.y H:i', strtotime($dt));
                }
            }
            $docs[$type] = ['key' => $name, 'type' => $type, 'country' => $country, 'lang' => $lang, 'hash' => md5($content), 'date' => $date];
        }
        ?>

        <style>
            .itrk-grid {
                display: grid;
                grid-template-columns: 200px repeat(<?php echo count($targets); ?>, 1fr);
                gap: 1px;
                background: #e0e0e0;
                border: 1px solid #ccc;
                margin-top: 20px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .itrk-cell {
                background: #fff;
                padding: 15px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                min-height: 80px;
            }

            .itrk-head {
                font-weight: 800;
                background: #f0f0f1;
                text-transform: uppercase;
                color: #3c434a;
                letter-spacing: 0.5px;
            }

            .itrk-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 700;
                margin-bottom: 8px;
                display: inline-block;
            }

            .b-ok {
                background: #d1e7dd;
                color: #0f5132;
                border: 1px solid #badbcc;
            }

            .b-warn {
                background: #fff3cd;
                color: #664d03;
                border: 1px solid #ffecb5;
            }

            .b-none {
                background: #f8d7da;
                color: #842029;
                border: 1px solid #f5c6cb;
            }

            .btn-mini {
                cursor: pointer;
                font-size: 12px;
                border: 1px solid #2271b1;
                padding: 4px 10px;
                border-radius: 4px;
                background: #fff;
                color: #2271b1;
                font-weight: 500;
                transition: all 0.2s;
            }

            .btn-mini:hover {
                background: #2271b1;
                color: #fff;
            }

            .btn-mini:disabled {
                border-color: #ccc;
                color: #ccc;
                cursor: default;
            }

            #itrk-console {
                background: #0c0d0e;
                color: #00f900;
                padding: 15px;
                font-family: 'Consolas', monospace;
                font-size: 12px;
                height: 200px;
                overflow-y: auto;
                display: none;
                border-radius: 5px;
                border-left: 5px solid #2271b1;
                line-height: 1.5;
                box-shadow: inset 0 0 10px #000;
            }

            .log-time {
                color: #888;
                margin-right: 8px;
            }

            .log-info {
                color: #4dc4ff;
            }

            .log-success {
                color: #00f900;
            }

            .log-error {
                color: #ff4d4d;
            }

            .sc-wrapper {
                width: 100%;
                margin-top: 8px;
                opacity: 0;
                transform: translateY(-5px);
                transition: all 0.3s ease;
                height: 0;
                overflow: hidden;
            }

            .sc-wrapper.visible {
                opacity: 1;
                transform: translateY(0);
                height: auto;
            }

            .sc-input {
                width: 100%;
                font-size: 10px;
                background: #f0f0f1;
                border: 1px solid #ccc;
                padding: 4px;
                color: #555;
                text-align: center;
                border-radius: 3px;
                cursor: pointer;
                font-family: monospace;
            }

            .sc-input:focus {
                border-color: #2271b1;
                background: #fff;
                color: #000;
                select-all: true;
            }

            .sc-label {
                font-size: 9px;
                color: #888;
                display: block;
                margin-bottom: 2px;
                text-transform: uppercase;
            }
        </style>

        <div id="itrk-console"></div>

        <div
            style="background:#fff; padding:15px; border:1px solid #ccc; border-left: 4px solid #2271b1; margin-bottom: 20px; display:flex; align-items:center; gap:15px; border-radius: 4px;">
            <div><strong>⚡ Bulk Translator:</strong> Translate ALL docs to:</div>
            <select id="bulk-lang" style="min-width: 100px;">
                <?php foreach ($targets as $t)
                    echo "<option value='$t'>" . strtoupper($t) . "</option>"; ?> </select>
            <button class="button button-primary" id="btn-bulk-run">Run Bulk</button>
        </div>

        <div class="itrk-grid">
            <div class="itrk-cell itrk-head">Document</div>
            <?php foreach ($targets as $t)
                echo "<div class='itrk-cell itrk-head'>$t</div>"; ?>

            <?php foreach ($docs as $type => $d): ?>
                <div class="itrk-cell"
                    style="align-items:flex-start; text-align:left; background:#f9f9f9; border-right:1px solid #eee;">
                    <strong style="font-size:14px;"><?php echo ucfirst($type); ?></strong>
                    <div style="font-size:10px; color:#666; margin-top:5px;">Source: <?php echo strtoupper($d['lang']); ?>
                        (<?php echo $d['country']; ?>)</div>
                    <div style="font-size:10px; color:#555; margin-top:2px;">Original: <strong><?php echo $d['date']; ?></strong>
                    </div>
                </div>
                <?php foreach ($targets as $t):
                    $trans_opt = get_option(self::CACHE_PREFIX . md5($type . $t));
                    $status = 'NONE';
                    $trans_date = '';
                    if ($trans_opt) {
                        $status = ($trans_opt['hash'] === $d['hash']) ? 'OK' : 'CHANGED';
                        if (isset($trans_opt['updated']))
                            $trans_date = date('d.m.y H:i', strtotime($trans_opt['updated']));
                    }
                    $shortcode = sprintf('[itrk_ai_translate type="%s" target_lang="%s" country="%s"]', $type, $t, $d['country']);
                    ?>
                    <div class="itrk-cell" id="cell-<?php echo $type . '-' . $t; ?>">
                        <div class="status-area">
                            <?php if ($status == 'OK'): ?>
                                <span class="itrk-badge b-ok">Active</span>
                                <div style="font-size:9px; color:#888; margin-bottom:5px;">Upd: <?php echo $trans_date; ?></div>
                                <button class="btn-mini action-run" data-type="<?php echo $type; ?>" data-src="<?php echo $d['lang']; ?>"
                                    data-country="<?php echo $d['country']; ?>" data-target="<?php echo $t; ?>" data-force="true">🔄
                                    Re-check</button>
                            <?php elseif ($status == 'CHANGED'): ?>
                                <span class="itrk-badge b-warn">⚠️ Changed</span>
                                <div style="font-size:9px; color:#a67c00; margin-bottom:5px;">Old: <?php echo $trans_date; ?></div>
                                <button class="btn-mini action-run" data-type="<?php echo $type; ?>" data-src="<?php echo $d['lang']; ?>"
                                    data-country="<?php echo $d['country']; ?>" data-target="<?php echo $t; ?>" data-force="true">⚡
                                    Update</button>
                            <?php else: ?>
                                <span class="itrk-badge b-none">Missing</span>
                                <br><button class="btn-mini action-run" data-type="<?php echo $type; ?>"
                                    data-src="<?php echo $d['lang']; ?>" data-country="<?php echo $d['country']; ?>"
                                    data-target="<?php echo $t; ?>" data-force="false">➕ Create</button>
                            <?php endif; ?>
                        </div>
                        <div class="sc-wrapper <?php echo ($status == 'OK') ? 'visible' : ''; ?>">
                            <span class="sc-label">Shortcode:</span>
                            <input type="text" class="sc-input" value='<?php echo esc_attr($shortcode); ?>' readonly
                                onclick="this.select(); document.execCommand('copy');">
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                function log(msg, type = 'info') {
                    var now = new Date();
                    var time = now.getHours() + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
                    var cls = 'log-' + type;
                    $('#itrk-console').show().append('<div><span class="log-time">[' + time + ']</span> <span class="' + cls + '">' + msg + '</span></div>');
                    var c = $('#itrk-console')[0]; c.scrollTop = c.scrollHeight;
                }

                $('.action-run').click(function (e) {
                    e.preventDefault();
                    var btn = $(this); var parentCell = btn.closest('.itrk-cell');
                    var data = btn.data(); data.action = 'itrk_process_job'; data.nonce = '<?php echo wp_create_nonce("itrk_nonce"); ?>';

                    btn.prop('disabled', true).html('⏳ Processing...');
                    log('------------------------------------------------', 'info');
                    log('🚀 Starting job: ' + data.type.toUpperCase() + ' -> ' + data.target.toUpperCase(), 'info');

                    var waitMsgs = ['Connecting to OpenAI...', 'Sending HTML content...', 'Waiting for translation...', 'Validating response...'];
                    var waitIdx = 0;
                    var liveTimer = setInterval(function () { if (waitIdx < waitMsgs.length) { log('... ' + waitMsgs[waitIdx], 'info'); waitIdx++; } }, 2500);

                    $.post(ajaxurl, data, function (res) {
                        clearInterval(liveTimer);
                        if (res.success) {
                            if (Array.isArray(res.data)) res.data.forEach(function (l) { log('   > ' + l, 'success'); }); else log('   > ' + res.data, 'success');
                            log('✅ Job Completed.', 'success');
                            var scStr = '[itrk_ai_translate type="' + data.type + '" target_lang="' + data.target + '" country="' + data.country + '"]';
                            var now = new Date();
                            var dateStr = String(now.getDate()).padStart(2, '0') + '.' + String(now.getMonth() + 1).padStart(2, '0') + '.' + String(now.getFullYear()).slice(2) + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

                            var statusArea = parentCell.find('.status-area');
                            statusArea.html('<span class="itrk-badge b-ok">Just Now</span><div style="font-size:9px; color:#888; margin-bottom:5px;">Upd: ' + dateStr + '</div><button class="btn-mini action-run" disabled>Done</button>');
                            var scArea = parentCell.find('.sc-wrapper');
                            scArea.find('input').val(scStr); scArea.addClass('visible');
                        } else {
                            log('❌ Error: ' + res.data, 'error'); btn.prop('disabled', false).text('Retry');
                        }
                    }).fail(function () { clearInterval(liveTimer); log('❌ Server Error', 'error'); btn.prop('disabled', false).text('Retry'); });
                });

                $('#btn-bulk-run').click(function (e) {
                    e.preventDefault();
                    var lang = $('#bulk-lang').val();
                    log('🚀 STARTING BULK TRANSLATION TO: ' + lang.toUpperCase(), 'info');
                    var queue = [];
                    $('.action-run[data-target="' + lang + '"]').each(function () {
                        if (!$(this).prop('disabled') && $(this).text().indexOf('Done') === -1) queue.push($(this));
                    });
                    if (queue.length === 0) { log('⚠️ No eligible jobs found for ' + lang, 'error'); return; }
                    function processQueue() {
                        if (queue.length === 0) { log('🏁 ALL BULK JOBS FINISHED.', 'success'); return; }
                        var btn = queue.shift();
                        btn.click();
                        var check = setInterval(function () { if (btn.text().indexOf('Done') !== -1 || btn.text().indexOf('Retry') !== -1) { clearInterval(check); setTimeout(processQueue, 1000); } }, 1000);
                    }
                    processQueue();
                });
            });
        </script>
        <?php
    }

    // Clean Cache
    private function purge_litespeed_cache()
    {
        if (!class_exists('LiteSpeed\Purge') && !class_exists('LiteSpeed_Cache_API')) {
            return false;
        }

        // LiteSpeed Cache v3+
        if (class_exists('LiteSpeed\Purge')) {
            do_action('litespeed_purge_all');
            return 'LiteSpeed Cache';
        }

        // LiteSpeed Cache v2.x
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            return 'LiteSpeed Cache (v2)';
        }

        return false;
    }
}
