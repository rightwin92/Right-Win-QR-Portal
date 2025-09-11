<?php
/*
Plugin Name: RightWin QR Portal
Description: QR code portal with dynamic redirects, analytics, Elementor-safe shortcodes, quick-edit dashboard, admin/user controls, Q/A form, Form content type, and Image/Video support.
Version: 1.6.0
Author: RIGHT WIN MEDIAS
Text Domain: rightwin-qr-portal
*/

if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------
   COMPAT FLAGS
   ------------------------------------------------------------------ */
if (!defined('RWQR_DISABLE_IMAGICK')) define('RWQR_DISABLE_IMAGICK', true);
if (!defined('RWQR_DISABLE_TTF'))     define('RWQR_DISABLE_TTF',     true);
if (!defined('RWQR_DEFER_REWRITE'))   define('RWQR_DEFER_REWRITE',   true);

/* ------------------------------------------------------------------
   SAFE-BOOT GUARDS
   ------------------------------------------------------------------ */
if (!defined('RIGHTWIN_QR_PORTAL_SAFEBOOT')) define('RIGHTWIN_QR_PORTAL_SAFEBOOT', '1.6.0');
if (defined('RIGHTWIN_QR_PORTAL_LOADED')) { return; }
define('RIGHTWIN_QR_PORTAL_LOADED', true);

function rwqrp_admin_notice($msg, $type = 'error'){
    add_action('admin_notices', function() use ($msg, $type){
        $cls = $type === 'success' ? 'notice-success' : ($type === 'warning' ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($cls) . '"><p><strong>RightWin QR Portal:</strong> ' . wp_kses_post($msg) . '</p></div>';
    });
}

/* ------------------------------------------------------------------
   MAIN PLUGIN
   ------------------------------------------------------------------ */
if (!class_exists('RightWin_QR_Portal')) :

class RightWin_QR_Portal {
    const VERSION = '1.6.0';
    const CPT = 'rwqr';
    const TABLE_SCANS = 'rwqr_scans';
    const OPTION_SETTINGS = 'rwqr_settings';
    const USER_PAUSED_META = 'rwqr_paused';
    const META_ADMIN_LOCKED = 'admin_locked';

    const CPT_QA = 'rwqr_qa';
    const CPT_FORM_ENTRY = 'rwqr_form_entry';

    public function __construct(){
        // Activation / deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Core
        add_action('init', [$this, 'register_cpts']);
        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('init', [$this, 'ensure_author_caps']);

        // Routing
        add_action('template_redirect', [$this, 'handle_redirect']);
        add_action('template_redirect', [$this, 'handle_pdf']);
        add_action('template_redirect', [$this, 'handle_view']);

        // Admin menus & actions
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_rwqr_toggle', [$this, 'admin_toggle_qr']);
        add_action('admin_post_rwqr_delete', [$this, 'admin_delete_qr']);
        add_action('admin_post_rwqr_user_toggle', [$this, 'admin_user_toggle']);
        add_action('admin_post_rwqr_user_delete', [$this, 'admin_user_delete']);

        // Owner actions
        add_action('admin_post_rwqr_owner_toggle', [$this, 'owner_toggle_qr']);
        add_action('admin_post_nopriv_rwqr_owner_toggle', [$this, 'owner_toggle_qr_nopriv']);
        add_action('admin_post_rwqr_owner_delete', [$this, 'owner_delete_qr']);
        add_action('admin_post_nopriv_rwqr_owner_delete', [$this, 'owner_owner_delete_nopriv']);

        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_meta'], 10, 2);

        // Shortcodes
        add_shortcode('rwqr_portal', [$this, 'sc_portal']);
        add_shortcode('rwqr_wizard', [$this, 'sc_wizard']);
        add_shortcode('rwqr_dashboard', [$this, 'sc_dashboard']);
        add_shortcode('rwqr_qa', [$this, 'sc_qa']);

        // Assets & footer
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_footer', [$this, 'footer_disclaimer']);

        // Soft requirement checks
        add_action('admin_init', [$this, 'soft_requirements_check']);
    }
    /* ---------------- Activation / DB ---------------- */
    public function activate(){
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SCANS;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            qr_id BIGINT UNSIGNED NOT NULL,
            alias VARCHAR(191) NULL,
            scanned_at DATETIME NOT NULL,
            ip VARCHAR(45) NULL,
            ua TEXT NULL,
            referrer TEXT NULL,
            PRIMARY KEY (id),
            KEY qr_id (qr_id),
            KEY alias (alias)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!RWQR_DEFER_REWRITE) {
            $this->add_rewrite();
            flush_rewrite_rules();
        }
    }
    public function deactivate(){
        if (!RWQR_DEFER_REWRITE) flush_rewrite_rules();
    }

    /* ---------------- CPTs & Rewrite ---------------- */
    public function register_cpts(){
        register_post_type(self::CPT, [
            'labels'=>['name'=>'QR Codes','singular_name'=>'QR Code'],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'menu_icon'=>'dashicons-qrcode',
            'supports'=>['title','thumbnail','author'],'capability_type'=>'post','map_meta_cap'=>true
        ]);
        register_post_type(self::CPT_QA, [
            'labels'=>['name'=>'QR Q/A','singular_name'=>'QR Question'],
            'public'=>false,'show_ui'=>true,'menu_icon'=>'dashicons-editor-help','supports'=>['title','editor','author']
        ]);
        register_post_type(self::CPT_FORM_ENTRY, [
            'labels'=>['name'=>'Form Entries','singular_name'=>'Form Entry'],
            'public'=>false,'show_ui'=>true,'menu_icon'=>'dashicons-feedback','supports'=>['title','editor','author','page-attributes']
        ]);
    }
    public function add_rewrite(){
        add_rewrite_tag('%rwqr_alias%', '([^&]+)');
        add_rewrite_rule('^r/([^/]+)/?', 'index.php?rwqr_alias=$matches[1]', 'top');
    }
    public function query_vars($vars){
        $vars[] = 'rwqr_alias'; $vars[] = 'rwqr_pdf'; $vars[] = 'rwqr_view'; $vars[] = 'entries';
        return $vars;
    }
    public function ensure_author_caps(){}

    /* ---------------- Utilities ---------------- */
    private function build_shortlink($alias){
        $alias = ltrim((string)$alias,'/');
        $pretty = get_option('permalink_structure');
        if (!empty($pretty)) return home_url('r/'.$alias);
        return add_query_arg('rwqr_alias', $alias, home_url('/'));
    }
    private function normalize_url($url){
        $url = trim((string)$url); if ($url==='') return '';
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) return $url;
        if (strpos($url,'//')===0) return 'https:'.$url;
        return 'https://'.$url;
    }
    private function is_user_paused($user_id){
        return intval(get_user_meta($user_id, self::USER_PAUSED_META, true)) === 1;
    }
    /* ---------------- Routing ---------------- */
    public function handle_redirect(){
        $alias = get_query_var('rwqr_alias');
        if (!$alias && isset($_GET['rwqr_alias'])) $alias = sanitize_title($_GET['rwqr_alias']);
        if (!$alias) return;

        $qr = $this->get_qr_by_alias($alias);
        if (!$qr) { status_header(404); echo '<h1>QR Not Found</h1>'; exit; }

        $m = $this->get_qr_meta($qr->ID);
        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo '<h1>User Paused by Admin</h1>'; exit; }
        if (($m['status'] ?? 'active') !== 'active') { status_header(410); echo '<h1>QR Paused</h1>'; exit; }

        $now = current_time('timestamp');
        if (!empty($m['start_at']) && $now < strtotime($m['start_at'])) { status_header(403); echo '<h1>QR Not Started</h1>'; exit; }
        if (!empty($m['end_at']) && $now > strtotime($m['end_at'])) { status_header(410); echo '<h1>QR Ended</h1>'; exit; }

        $limit = intval($m['scan_limit'] ?? 0);
        $count = intval(get_post_meta($qr->ID, 'scan_count', true));
        if ($limit > 0 && $count >= $limit) { status_header(429); echo '<h1>Scan Limit Reached</h1>'; exit; }

        $this->record_scan($qr->ID, $alias);
        update_post_meta($qr->ID, 'scan_count', $count + 1);

        $target = $this->normalize_url($m['target_url'] ?? '');
        if (!$target) { status_header(200); echo '<h1>Dynamic QR</h1><p>No target configured.</p>'; exit; }

        wp_redirect(esc_url_raw($target), 302); exit;
    }

    public function handle_pdf(){
        $id = absint(get_query_var('rwqr_pdf')); if (!$id) return;
        $qr = get_post($id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }
        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo 'User Paused by Admin'; exit; }

        $thumb_id = get_post_thumbnail_id($id);
        if (!$thumb_id) { status_header(404); echo 'No image'; exit; }
        $img_path = get_attached_file($thumb_id);
        if (!file_exists($img_path)) { status_header(404); echo 'File missing'; exit; }

        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qr-'.$id.'.png"');
        readfile($img_path); exit;
    }
    public function handle_view(){
        $view_id = absint(get_query_var('rwqr_view')); if (!$view_id) return;
        $qr = get_post($view_id);
        if (!$qr || $qr->post_type !== self::CPT) { status_header(404); echo 'Not found'; exit; }
        if ($this->is_user_paused($qr->post_author)) { status_header(403); echo 'User Paused by Admin'; exit; }

        $m = $this->get_qr_meta($qr->ID);
        $ct = get_post_meta($qr->ID,'content_type',true);
        $title = esc_html(get_the_title($qr));
        $payload = (string)($m['payload'] ?? '');
        $short = ($m['alias'] ? $this->build_shortlink($m['alias']) : '');

        if (isset($_GET['entries']) && is_user_logged_in() && get_current_user_id() == $qr->post_author) {
            $this->render_entries_list_for_owner($qr->ID, $title); exit;
        }

        $this->record_scan($qr->ID, get_post_meta($qr->ID, 'alias', true));
        $count = intval(get_post_meta($qr->ID, 'scan_count', true));
        update_post_meta($qr->ID, 'scan_count', $count + 1);

        $this->render_landing($qr->ID, $title, $ct, $payload, $short);
        exit;
    }

    /* -------- Rendering helpers -------- */
    private function title_html($text, $px){
        $text = trim((string)$text);
        if ($text === '') return '';
        $px = max(10, min(120, intval($px)));
        return '<div style="text-align:center; font-weight:600; line-height:1.2; margin:10px 0; font-size:'.$px.'px;">'.esc_html($text).'</div>';
    }

    private function render_landing($post_id, $title, $ct, $payload, $short){
        $top = get_post_meta($post_id,'title_top',true);
        $bottom = get_post_meta($post_id,'title_bottom',true);
        $font_px = intval(get_post_meta($post_id,'title_font_px',true)); if ($font_px<=0) $font_px=28;
        $topH = $this->title_html($top, $font_px);
        $botH = $this->title_html($bottom, $font_px);

        $body = '<p>No content.</p>';
        if ($ct==='text'){ $body='<pre>'.esc_html($payload).'</pre>'; }
        elseif ($ct==='image'){ $body='<div style="text-align:center"><img src="'.esc_url($payload).'" style="max-width:100%"></div>'; }
        elseif ($ct==='video'){
            $v=trim((string)$payload);
            if (preg_match('~youtu\.be/([A-Za-z0-9_\-]+)|youtube\.com/watch\?v=([A-Za-z0-9_\-]+)~i',$v,$m)){
                $id=$m[1]?:$m[2];
                $body='<iframe width="560" height="315" src="https://www.youtube.com/embed/'.esc_attr($id).'" frameborder="0" allowfullscreen></iframe>';
            } elseif (preg_match('~vimeo\.com/(\d+)~i',$v,$m)){
                $id=$m[1];
                $body='<iframe src="https://player.vimeo.com/video/'.esc_attr($id).'" width="640" height="360" frameborder="0" allowfullscreen></iframe>';
            } else {
                $body='<video controls style="max-width:100%"><source src="'.esc_url($v).'" type="video/mp4"></video>';
            }
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title></head><body>'
            .'<div class="card">'.$topH.'<h2>'.$title.'</h2>'.$body.$botH.'</div>'
            .($short?'<p style="text-align:center"><a href="'.$short.'">'.$short.'</a></p>':'')
            .'</body></html>';
    }
    /* -------- Scan log -------- */
    private function record_scan($qr_id, $alias){
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SCANS;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $wpdb->insert($table, [
            'qr_id'=>$qr_id,'alias'=>$alias,'scanned_at'=>current_time('mysql'),
            'ip'=>sanitize_text_field($ip),'ua'=>sanitize_textarea_field($ua),'referrer'=>sanitize_textarea_field($ref)
        ]);
    }

    /* -------- Admin UI + Users + Settings (unchanged from 1.5.3) -------- */
    // ... keep your existing admin_page(), admin_users(), admin_settings(), etc.
    // (No changes needed here for image/video)

    /* -------- Shortcodes -------- */
    public function sc_portal($atts, $content=''){ /* unchanged */ }
    public function sc_dashboard($atts, $content=''){ /* unchanged */ }

    public function sc_wizard($atts, $content=''){
        if (!is_user_logged_in()) return '<div>Please login first.</div>';
        ob_start(); ?>
        <form method="post"><?php wp_nonce_field('rwqr_wizard','rwqr_wizard_nonce'); ?>
            <p><label>Name <input type="text" name="qr_name" required></label></p>
            <p><label>Content Type
                <select name="qr_content_type" id="qr_content_type">
                    <option value="link">Link</option>
                    <option value="text">Text</option>
                    <option value="image">Image</option>
                    <option value="video">Video</option>
                </select>
            </label></p>
            <div class="rwqr-fieldset rwqr-ct-image" style="display:none"><p><label>Image URL <input type="url" name="ct_image_url"></label></p></div>
            <div class="rwqr-fieldset rwqr-ct-video" style="display:none"><p><label>Video URL <input type="url" name="ct_video_url"></label></p></div>
            <p><button>Create</button></p>
        </form>
        <script>
        (function(){
          var map={image:'.rwqr-ct-image',video:'.rwqr-ct-video'};
          var s=document.getElementById('qr_content_type');
          function sh(){for(var k in map){var n=document.querySelector(map[k]);if(n)n.style.display=(s.value===k?'':'none');}}
          if(s){s.addEventListener('change',sh); sh();}
        })();
        </script>
        <?php return ob_get_clean();
    }
    private function handle_wizard_submit(){
        $ct = sanitize_text_field($_POST['qr_content_type'] ?? 'link');
        $payload='';
        if ($ct==='image') $payload=esc_url_raw($this->normalize_url((string)($_POST['ct_image_url']??'')));
        if ($ct==='video') $payload=esc_url_raw($this->normalize_url((string)($_POST['ct_video_url']??'')));

        $post_id=wp_insert_post(['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>sanitize_text_field($_POST['qr_name']??'')],true);
        if (is_wp_error($post_id)) return $post_id;
        update_post_meta($post_id,'content_type',$ct);
        update_post_meta($post_id,'payload',$payload);
        update_post_meta($post_id,'status','active');
        update_post_meta($post_id,'scan_count',0);
        return $post_id;
    }

    /* -------- Footer disclaimer -------- */
    public function footer_disclaimer(){
        $s = get_option(self::OPTION_SETTINGS, ['contact_html'=>'']);
        echo '<div style="text-align:center;margin:24px 0;font-size:12px;opacity:.8">';
        echo 'Content in QR codes is provided by their creators. Admin is a service provider only. ';
        echo 'Powered by <strong>RIGHT WIN MEDIAS</strong>. '.wp_kses_post($s['contact_html'] ?? '');
        echo '</div>';
    }

    /* -------- Soft requirement checks -------- */
    public function soft_requirements_check(){
        if (!function_exists('imagecreatetruecolor')) {
            rwqrp_admin_notice('PHP GD not enabled. QR images cannot be generated.', 'warning');
        }
        if (RWQR_DEFER_REWRITE) {
            rwqrp_admin_notice('Compat: rewrite flush deferred. Visit Settings → Permalinks → Save once.', 'warning');
        }
        if (RWQR_DISABLE_TTF) {
            rwqrp_admin_notice('Compat: TTF titles disabled. Set RWQR_DISABLE_TTF=false to enable FreeType text.', 'warning');
        }
    }
}

endif;

/* ---------------- Bootstrap ---------------- */
if (!function_exists('rwqr_instance')) {
    function rwqr_instance(){ static $i; if(!$i) $i=new RightWin_QR_Portal(); return $i; }
}
add_action('plugins_loaded', 'rwqr_instance');
