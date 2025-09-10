<?php
/**
 * Plugin Name: RightWin QR Portal
 * Description: All-in-one QR portal system — dynamic/static QR codes with analytics, user quotas, subscriptions, WhatsApp/email reminders, Image/Video support, and full admin control.
 * Version: 1.6.0
 * Author: RIGHT WIN MEDIAS
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class RightWin_QR_Portal {
    const CPT = 'rwqr_code';
    const META_ALIAS = '_alias';
    const META_CONTENT_TYPE = '_content_type';
    const META_IMAGE_URL = '_image_url';
    const META_VIDEO_URL = '_video_url';
    const META_TITLE_TOP = '_title_top';
    const META_TITLE_BOTTOM = '_title_bottom';
    const META_TITLE_TOP_SIZE = '_title_top_size';
    const META_TITLE_BOTTOM_SIZE = '_title_bottom_size';
    const META_SCAN_COUNT = '_scan_count';
    const META_START = '_start';
    const META_END = '_end';
    const META_LIMIT = '_limit';
    const META_STATUS = '_status'; // active/paused
    const USER_QR_COUNT = '_rwqr_user_qr_count';
    const USER_SCAN_TOTAL = '_rwqr_user_scan_total';
    const OPT_SETTINGS = 'rwqr_portal_settings';

    public function __construct(){
        // Register post type
        add_action('init', [$this,'register_cpt']);
        // Metaboxes
        add_action('add_meta_boxes', [$this,'add_metaboxes']);
        add_action('save_post', [$this,'save_meta'], 10, 2);
        // Shortcodes
        add_shortcode('rwqr_portal', [$this,'shortcode_portal']);
        add_shortcode('rwqr_dashboard', [$this,'shortcode_dashboard']);
        // Handle redirects
        add_action('template_redirect', [$this,'handle_scan']);
        // Assets
        add_action('wp_enqueue_scripts', [$this,'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this,'enqueue_admin_assets']);
        // Admin menu
        add_action('admin_menu', [$this,'admin_menu']);
        // User registration hooks (trial/subscriptions)
        add_action('user_register', [$this,'on_user_register'], 20);
        // Cron for reminders
        add_action('rwqr_daily_event', [$this,'cron_daily']);
        if (!wp_next_scheduled('rwqr_daily_event')){
            wp_schedule_event(time()+3600, 'daily', 'rwqr_daily_event');
        }
    }

    /** Register CPT */
    public function register_cpt(){
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'QR Codes',
                'singular_name' => 'QR Code'
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-qrcode',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }

    /** Enqueue assets */
    public function enqueue_assets(){
        wp_enqueue_style('rwqr-portal', plugins_url('assets/portal.css', __FILE__), [], '1.6.0');
        wp_enqueue_script('rwqr-portal', plugins_url('assets/portal.js', __FILE__), ['jquery'], '1.6.0', true);
    }
    public function enqueue_admin_assets(){
        wp_enqueue_style('rwqr-admin', plugins_url('assets/admin.css', __FILE__), [], '1.6.0');
    }

    /** Settings helper */
    private function settings(){
        $defaults = [
            'max_qrs_per_user' => 10,
            'quota_days' => 30,
            'max_scans_per_qr' => 0,
            'max_scans_per_user' => 0,
            'wa_provider' => 'none',
            'wa_use_template' => 0,
            'wa_tpl_lang' => 'en_US',
            'wa_tpl_welcome' => '',
            'wa_tpl_reminder' => '',
            'wa_tpl_thanks' => ''
        ];
        return wp_parse_args(get_option(self::OPT_SETTINGS, []), $defaults);
    }

    /** Add Admin Menu */
    public function admin_menu(){
        add_menu_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr-portal', [$this,'page_dashboard'], 'dashicons-qrcode');
        add_submenu_page('rwqr-portal', 'Subscriptions', 'Subscriptions', 'manage_options', 'rwqr-subscriptions', [$this,'page_subscriptions']);
        add_submenu_page('rwqr-portal', 'Settings', 'Settings', 'manage_options', 'rwqr-settings', [$this,'page_settings']);
    }
    /* ===================== Utilities ===================== */

    private function get_user_qr_count($user_id){
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => ['publish','draft','pending','private'],
            'author' => (int)$user_id,
            'posts_per_page' => 1,
            'fields'=>'ids',
        ]);
        $n = (int)$q->found_posts;
        wp_reset_postdata();
        return $n;
    }

    private function get_user_max_qrs($user_id){
        $override = get_user_meta($user_id, self::UMAX_QRS, true);
        if ($override !== '' && $override !== null) return max(0, (int)$override);
        return max(0, (int)get_option(self::OPT_MAX_QRS_PER_USER, 100));
    }

    private function sanitize_datetime($s){
        $s = trim((string)$s);
        if (!$s) return 0;
        // Accept "Y-m-d H:i" or "Y-m-d"
        $ts = strtotime($s);
        return $ts ? $ts : 0;
    }

    private function clamp($v,$min,$max){ $v=(int)$v; return max($min, min($max, $v)); }

    private function title_html($txt,$px,$class=''){
        if(!$txt) return '';
        $px = $this->clamp($px ?: 18, 8, 120);
        return '<div class="'.esc_attr($class).'" style="font-size:'.$px.'px;line-height:1.2;margin:8px 0;text-align:center;">'.esc_html($txt).'</div>';
    }

    private function incr_scan($qr_id){
        $n = (int)get_post_meta($qr_id, self::M_SCAN_COUNT, true);
        $n++;
        update_post_meta($qr_id, self::M_SCAN_COUNT, $n);
        return $n;
    }

    private function is_active_window($qr_id){
        $start = (int)get_post_meta($qr_id, self::M_START, true);
        $end   = (int)get_post_meta($qr_id, self::M_END, true);
        $now   = time();
        if ($start && $now < $start) return false;
        if ($end   && $now > $end)   return false;
        return true;
    }

    private function within_limit($qr_id){
        $limit = (int)get_post_meta($qr_id, self::M_SCAN_LIMIT, true);
        $global_cap = (int)get_option(self::OPT_MAX_SCANS_PER_QR, 0);
        $cap = ($global_cap > 0) ? ($limit > 0 ? min($limit,$global_cap) : $global_cap) : $limit;

        if ($cap <= 0) return true;
        $count = (int)get_post_meta($qr_id, self::M_SCAN_COUNT, true);
        return $count < $cap;
    }

    private function user_within_qr_quota($user_id){
        $max = $this->get_user_max_qrs($user_id);
        if ($max === 0) return true; // 0=unlimited
        $have = $this->get_user_qr_count($user_id);
        return $have < $max;
    }

    private function pause_reason_html($txt){
        return '<div class="rwqr-paused-note" style="background:#fff3cd;border:1px solid #ffe69c;color:#664d03;padding:8px 12px;border-radius:8px;margin:10px 0">'.$txt.'</div>';
    }

    /* admin columns */
    public function cols($cols){
        $cols['scan'] = 'Scans';
        $cols['alias']= 'Alias';
        $cols['state']= 'State';
        return $cols;
    }
    public function col_content($col,$post_id){
        if ($col==='scan'){
            echo (int)get_post_meta($post_id,self::M_SCAN_COUNT,true);
        } elseif ($col==='alias'){
            echo esc_html(get_post_meta($post_id,self::M_ALIAS,true));
        } elseif ($col==='state'){
            $st = get_post_meta($post_id,self::M_STATUS,true) ?: 'active';
            echo esc_html($st);
        }
    }

    public function stamp_owner($post_id, $post){
        if ($post->post_type !== self::CPT) return;
        if (!get_post_meta($post_id, self::M_OWNER, true)){
            update_post_meta($post_id, self::M_OWNER, (int)$post->post_author);
        }
    }
    /* ===================== Shortcode: Portal (Create QR) ===================== */
    public function sc_portal($atts){
        if (!is_user_logged_in()){
            return '<div class="rwqr-card"><p>Please <a href="'.esc_url(wp_login_url(get_permalink())).'">login</a> to create QR codes.</p></div>';
        }

        $u = wp_get_current_user();
        $errors = [];
        $ok_msg = '';

        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rwqr_wizard_nonce']) && wp_verify_nonce($_POST['rwqr_wizard_nonce'], 'rwqr_wizard')){
            // Enforce user quota
            if (!$this->user_within_qr_quota($u->ID)){
                $errors[] = 'You have reached the maximum number of QR codes allowed for your account.';
            } else {
                $res = $this->handle_create($u->ID, $errors);
                if ($res && empty($errors)){
                    $ok_msg = 'QR created successfully.';
                }
            }
        }

        ob_start();
        ?>
        <div class="rwqr-card">
            <h3>Create QR Code</h3>
            <?php
            if ($errors){
                echo '<div class="rwqr-err"><ul>';
                foreach($errors as $e) echo '<li>'.esc_html($e).'</li>';
                echo '</ul></div>';
            }
            if ($ok_msg){
                echo '<div class="rwqr-ok">'.esc_html($ok_msg).'</div>';
            }
            ?>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rwqr_wizard','rwqr_wizard_nonce'); ?>
                <div class="rwqr-grid">
                    <p><label>QR Name<br><input type="text" name="qr_name" required></label></p>
                    <p><label>QR Mode<br>
                        <select name="qr_type" id="qr_mode">
                            <option value="dynamic">Dynamic (trackable)</option>
                            <option value="static">Static (non-trackable)</option>
                        </select></label></p>
                    <p><label>Content Type<br>
                        <select name="qr_content_type" id="qr_content_type">
                            <option value="link">Link</option>
                            <option value="text">Text</option>
                            <option value="vcard">vCard</option>
                            <option value="file">File</option>
                            <option value="catalogue">Catalogue</option>
                            <option value="price">Price</option>
                            <option value="social">Social Links</option>
                            <option value="greview">Google Review (Place ID)</option>
                            <option value="form">Form (collect replies)</option>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                        </select></label></p>
                    <p><label>Pattern<br>
                        <select name="qr_pattern">
                            <option value="square">Square</option>
                            <option value="dots">Dots</option>
                            <option value="rounded">Rounded</option>
                        </select></label></p>
                    <p><label>Dark Color<br><input type="color" name="qr_dark" value="#000000"></label></p>
                    <p><label>Light Color<br><input type="color" name="qr_light" value="#ffffff"></label></p>
                    <p><label>Logo (PNG/JPG)<br><input type="file" name="qr_logo" accept=".png,.jpg,.jpeg"></label></p>
                    <p><label>Logo Size % of QR width<br><input type="number" name="qr_logo_pct" min="0" max="60" value="20"></label></p>
                    <p><label>Top Title<br><input type="text" name="qr_title_top"></label></p>
                    <p><label>Bottom Title<br><input type="text" name="qr_title_bottom"></label></p>
                    <p><label>Title Font Size (px)<br><input type="number" name="qr_title_font_px" min="10" max="120" value="28"></label></p>
                    <p class="rwqr-dynamic-only"><label>Alias (for dynamic)<br><input type="text" name="qr_alias" placeholder="optional-custom-alias"></label></p>

                    <!-- Content fields -->
                    <div class="rwqr-fieldset rwqr-ct-link"><p><label>Destination URL<br><input type="text" name="ct_link_url" placeholder="https://example.com or example.com"></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-text" style="display:none"><p><label>Message Text<br><textarea name="ct_text" rows="4" placeholder="Your text or instructions"></textarea></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-vcard" style="display:none">
                        <p><label>Full Name<br><input type="text" name="ct_v_name" placeholder="Jane Doe"></label></p>
                        <p><label>Title<br><input type="text" name="ct_v_title" placeholder="Marketing Manager"></label></p>
                        <p><label>Organization<br><input type="text" name="ct_v_org" placeholder="Company Pvt Ltd"></label></p>
                        <p><label>Phone<br><input type="text" name="ct_v_tel" placeholder="+91 9xxxxxxxxx"></label></p>
                        <p><label>Email<br><input type="email" name="ct_v_email" placeholder="name@example.com"></label></p>
                        <p><label>Website<br><input type="text" name="ct_v_url" placeholder="https://... or domain.com"></label></p>
                    </div>
                    <div class="rwqr-fieldset rwqr-ct-file" style="display:none"><p><label>File Upload (PDF/Doc/Image)<br><input type="file" name="ct_file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-catalogue" style="display:none"><p><label>Catalogue URL<br><input type="text" name="ct_catalogue_url" placeholder="https://your-catalogue or catalogue.domain.com"></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-price" style="display:none">
                        <p><label>Amount<br><input type="number" step="0.01" name="ct_price_amount" placeholder="999.00"></label></p>
                        <p><label>Currency<br><input type="text" name="ct_price_currency" value="INR"></label></p>
                        <p><label>Product/Page URL (optional)<br><input type="text" name="ct_price_url" placeholder="https://buy-link or domain.com/buy"></label></p>
                    </div>
                    <div class="rwqr-fieldset rwqr-ct-social" style="display:none">
                        <p><label>Facebook<br><input type="text" name="ct_social_fb" placeholder="https://facebook.com/..."></label></p>
                        <p><label>Instagram<br><input type="text" name="ct_social_ig" placeholder="https://instagram.com/..."></label></p>
                        <p><label>YouTube<br><input type="text" name="ct_social_yt" placeholder="https://youtube.com/@..."></label></p>
                        <p><label>WhatsApp (share text or wa.me link)<br><input type="text" name="ct_social_wa" placeholder="Hi! or https://wa.me/91XXXXXXXXXX"></label></p>
                        <p><label>Telegram<br><input type="text" name="ct_social_tg" placeholder="https://t.me/..."></label></p>
                    </div>
                    <div class="rwqr-fieldset rwqr-ct-greview" style="display:none"><p><label>Google Place ID<br><input type="text" name="ct_g_placeid" placeholder="ChIJ..."></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-form" style="display:none"><p><label>Question / Instructions (shown on landing)<br><textarea name="ct_form_question" rows="3" placeholder="e.g., Please provide your feedback"></textarea></label></p></div>

                    <!-- NEW: Image / Video -->
                    <div class="rwqr-fieldset rwqr-ct-image" style="display:none"><p><label>Image URL<br><input type="url" name="ct_image_url" placeholder="https://example.com/path/file.jpg"></label></p></div>
                    <div class="rwqr-fieldset rwqr-ct-video" style="display:none"><p><label>Video URL (YouTube/Vimeo or MP4)<br><input type="url" name="ct_video_url" placeholder="https://youtube.com/watch?v=..."></label></p></div>

                    <p><label>Start At (Y-m-d H:i)<br><input type="text" name="qr_start"></label></p>
                    <p><label>End At (Y-m-d H:i)<br><input type="text" name="qr_end"></label></p>
                    <p><label>Scan Limit (0 = unlimited)<br><input type="number" name="qr_limit" value="0" min="0"></label></p>
                </div>
                <p><button class="rwqr-btn">Create</button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function handle_create($user_id, &$errors){
        $name  = sanitize_text_field($_POST['qr_name'] ?? '');
        $type  = ($_POST['qr_type'] ?? 'dynamic') === 'static' ? 'static' : 'dynamic';
        $ctype = sanitize_text_field($_POST['qr_content_type'] ?? 'link');

        if (!$name){ $errors[]='QR Name is required.'; return false; }

        // Create post
        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status'=>'publish',
            'post_title' => $name,
            'post_author'=> $user_id,
        ], true);
        if (is_wp_error($post_id)){ $errors[]=$post_id->get_error_message(); return false; }

        update_post_meta($post_id, self::M_TYPE, $type);
        update_post_meta($post_id, self::M_CONTENT_TYPE, $ctype);

        // Titles + size (one size for both, from your form)
        $top = sanitize_text_field($_POST['qr_title_top'] ?? '');
        $bot = sanitize_text_field($_POST['qr_title_bottom'] ?? '');
        $fsz = $this->clamp((int)($_POST['qr_title_font_px'] ?? 28), 10, 120);
        update_post_meta($post_id, self::M_TITLE_TOP, $top);
        update_post_meta($post_id, self::M_TITLE_BOTTOM, $bot);
        update_post_meta($post_id, self::M_TITLE_TOP_SIZE, $fsz);
        update_post_meta($post_id, self::M_TITLE_BOTTOM_SIZE, $fsz);

        // Date window
        $start = $this->sanitize_datetime($_POST['qr_start'] ?? '');
        $end   = $this->sanitize_datetime($_POST['qr_end'] ?? '');
        update_post_meta($post_id, self::M_START, $start);
        update_post_meta($post_id, self::M_END, $end);

        // Limit
        $limit = max(0, (int)($_POST['qr_limit'] ?? 0));
        update_post_meta($post_id, self::M_SCAN_LIMIT, $limit);

        // Alias for dynamic
        if ($type==='dynamic'){
            $alias = sanitize_title($_POST['qr_alias'] ?? '');
            if (!$alias){
                $alias = uniqid('rw', false);
            } else {
                // ensure unique
                if ($this->alias_exists($alias)){
                    $alias .= '-' . wp_generate_password(4,false,false);
                }
            }
            update_post_meta($post_id, self::M_ALIAS, $alias);
        }

        // Content save (only essential)
        $this->save_content_fields($post_id, $ctype);

        // Default status
        update_post_meta($post_id, self::M_STATUS, 'active');

        return $post_id;
    }

    private function alias_exists($alias){
        $q = new WP_Query([
            'post_type'=> self::CPT,
            'meta_key' => self::M_ALIAS,
            'meta_value'=> $alias,
            'fields'=>'ids',
            'posts_per_page'=>1
        ]);
        $e = $q->have_posts();
        wp_reset_postdata();
        return $e;
    }

    private function save_content_fields($post_id, $ctype){
        switch ($ctype){
            case 'link':
                update_post_meta($post_id, self::M_LINK_URL, esc_url_raw($_POST['ct_link_url'] ?? ''));
                break;
            case 'text':
                update_post_meta($post_id, self::M_TEXT, wp_kses_post($_POST['ct_text'] ?? ''));
                break;
            case 'vcard':
                // Basic VCF packing
                $name = sanitize_text_field($_POST['ct_v_name'] ?? '');
                $title= sanitize_text_field($_POST['ct_v_title'] ?? '');
                $org  = sanitize_text_field($_POST['ct_v_org'] ?? '');
                $tel  = sanitize_text_field($_POST['ct_v_tel'] ?? '');
                $mail = sanitize_email($_POST['ct_v_email'] ?? '');
                $url  = esc_url_raw($_POST['ct_v_url'] ?? '');
                $vcf  = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:$name\r\nTITLE:$title\r\nORG:$org\r\nTEL;TYPE=CELL:$tel\r\nEMAIL:$mail\r\nURL:$url\r\nEND:VCARD";
                update_post_meta($post_id, self::M_VCARD, $vcf);
                break;
            case 'file':
                if (!empty($_FILES['ct_file']['name'])){
                    $aid = media_handle_upload('ct_file', $post_id);
                    if (!is_wp_error($aid)){
                        update_post_meta($post_id, self::M_FILE_ID, (int)$aid);
                    }
                }
                break;
            case 'catalogue':
                update_post_meta($post_id, self::M_CATALOGUE_URL, esc_url_raw($_POST['ct_catalogue_url'] ?? ''));
                break;
            case 'price':
                update_post_meta($post_id, self::M_PRICE_AMT, (float)($_POST['ct_price_amount'] ?? 0));
                update_post_meta($post_id, self::M_PRICE_CUR, sanitize_text_field($_POST['ct_price_currency'] ?? 'INR'));
                update_post_meta($post_id, self::M_PRICE_URL, esc_url_raw($_POST['ct_price_url'] ?? ''));
                break;
            case 'social':
                update_post_meta($post_id, self::M_SOCIAL_FB, esc_url_raw($_POST['ct_social_fb'] ?? ''));
                update_post_meta($post_id, self::M_SOCIAL_IG, esc_url_raw($_POST['ct_social_ig'] ?? ''));
                update_post_meta($post_id, self::M_SOCIAL_YT, esc_url_raw($_POST['ct_social_yt'] ?? ''));
                update_post_meta($post_id, self::M_SOCIAL_WA, sanitize_text_field($_POST['ct_social_wa'] ?? ''));
                update_post_meta($post_id, self::M_SOCIAL_TG, esc_url_raw($_POST['ct_social_tg'] ?? ''));
                break;
            case 'greview':
                update_post_meta($post_id, self::M_G_PLACEID, sanitize_text_field($_POST['ct_g_placeid'] ?? ''));
                break;
            case 'form':
                update_post_meta($post_id, self::M_FORM_Q, sanitize_text_field($_POST['ct_form_question'] ?? ''));
                break;
            case 'image':
                update_post_meta($post_id, self::M_IMAGE_URL, esc_url_raw($_POST['ct_image_url'] ?? ''));
                break;
            case 'video':
                update_post_meta($post_id, self::M_VIDEO_URL, esc_url_raw($_POST['ct_video_url'] ?? ''));
                break;
        }
    }
    /* ===================== Shortcode: Dashboard ===================== */
    public function sc_dashboard($atts){
        if (!is_user_logged_in()){
            return '<div class="rwqr-card"><p>Please <a href="'.esc_url(wp_login_url(get_permalink())).'">login</a> to view your dashboard.</p></div>';
        }
        $u = wp_get_current_user();

        // actions: pause/resume/delete
        if (!empty($_GET['rwqr_action']) && !empty($_GET['qr']) && !empty($_GET['_wpnonce'])){
            $pid = (int)$_GET['qr'];
            if (wp_verify_nonce($_GET['_wpnonce'], 'rwqr_act_'.$pid)){
                $post = get_post($pid);
                if ($post && (int)$post->post_author === (int)$u->ID){
                    switch ($_GET['rwqr_action']){
                        case 'pause':
                            update_post_meta($pid, self::M_STATUS, 'paused');
                            break;
                        case 'resume':
                            update_post_meta($pid, self::M_STATUS, 'active');
                            break;
                        case 'delete':
                            wp_trash_post($pid);
                            break;
                    }
                }
            }
        }

        $q = new WP_Query([
            'post_type'=> self::CPT,
            'post_status'=> ['publish','private'],
            'author' => $u->ID,
            'posts_per_page'=> 50,
            'orderby'=>'date',
            'order'=>'DESC'
        ]);

        ob_start();
        echo '<div class="rwqr-card"><h3>My QR Codes</h3>';
        if (!$q->have_posts()){
            echo '<p>No QR codes yet. Create your first one!</p></div>';
            return ob_get_clean();
        }

        echo '<table class="rwqr-table"><thead><tr><th>Title</th><th>Alias</th><th>Type</th><th>Scans</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        while ($q->have_posts()){ $q->the_post();
            $pid = get_the_ID();
            $alias = get_post_meta($pid, self::M_ALIAS, true);
            $type  = get_post_meta($pid, self::M_TYPE, true);
            $st    = get_post_meta($pid, self::M_STATUS, true) ?: 'active';
            $scans = (int)get_post_meta($pid, self::M_SCAN_COUNT, true);
            $short = $alias ? home_url('/qr/'.rawurlencode($alias)) : '—';
            $nonce = wp_create_nonce('rwqr_act_'.$pid);

            echo '<tr>';
            echo '<td>'.esc_html(get_the_title()).'</td>';
            echo '<td>'.($alias ? '<a target="_blank" href="'.esc_url($short).'">'.esc_html($alias).'</a>' : '—').'</td>';
            echo '<td>'.esc_html($type).'</td>';
            echo '<td>'.(int)$scans.'</td>';
            echo '<td>'.($st==='paused' ? '<span class="rwqr-badge-paused">Paused</span>' : 'Active').'</td>';
            echo '<td class="rwqr-actions">';
            if ($st==='paused'){
                echo '<a class="button" href="'.esc_url(add_query_arg(['rwqr_action'=>'resume','qr'=>$pid,'_wpnonce'=>$nonce])).'">Resume</a> ';
            } else {
                echo '<a class="button" href="'.esc_url(add_query_arg(['rwqr_action'=>'pause','qr'=>$pid,'_wpnonce'=>$nonce])).'">Pause</a> ';
            }
            echo '<a class="button button-danger" href="'.esc_url(add_query_arg(['rwqr_action'=>'delete','qr'=>$pid,'_wpnonce'=>$nonce])).'" onclick="return confirm(\'Delete this QR?\')">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
        wp_reset_postdata();
        echo '</tbody></table></div>';
        return ob_get_clean();
    }
    /* ===================== Alias Resolve & Landing ===================== */
    public function maybe_handle_alias(){
        $alias = get_query_var(self::QV_ALIAS);
        if (!$alias){
            // also accept ?rwqr_alias=
            $alias = isset($_GET[self::QV_ALIAS]) ? sanitize_title($_GET[self::QV_ALIAS]) : '';
        }
        if (!$alias) return;

        // find QR by alias
        $qr = $this->get_by_alias($alias);
        if (!$qr){ $this->die_msg('QR not found.'); }

        $pid = $qr->ID;

        // check user-paused
        $state = get_post_meta($pid, self::M_STATUS, true) ?: 'active';
        if ($state === 'paused'){
            $this->die_msg('This QR is currently paused by the owner.');
        }

        // check window
        if (!$this->is_active_window($pid)){
            $this->die_msg('This QR is not active at this time.');
        }

        // check scan limit
        if (!$this->within_limit($pid)){
            $this->die_msg('Scan limit reached for this QR.');
        }

        // increment scans
        $this->incr_scan($pid);

        // dispatch by content type
        $ctype = get_post_meta($pid, self::M_CONTENT_TYPE, true) ?: 'link';
        if (in_array($ctype, ['image','video'], true)){
            $this->render_media_landing($pid, $ctype); // exits
        }

        // else: redirect behaviors
        switch ($ctype){
            case 'link':
                $url = get_post_meta($pid, self::M_LINK_URL, true);
                if (!$url) $this->die_msg('No URL configured.');
                $this->safe_redirect($url);
                break;
            case 'text':
                $txt = get_post_meta($pid, self::M_TEXT, true);
                $this->render_simple_landing($pid, nl2br(esc_html($txt)));
                break;
            case 'vcard':
                $vcf = get_post_meta($pid, self::M_VCARD, true);
                if (!$vcf) $this->die_msg('No vCard data.');
                header('Content-Type: text/vcard; charset=utf-8');
                header('Content-Disposition: attachment; filename="contact.vcf"');
                echo $vcf; exit;
            case 'file':
                $fid = (int)get_post_meta($pid, self::M_FILE_ID, true);
                if ($fid){
                    $url = wp_get_attachment_url($fid);
                    if ($url) $this->safe_redirect($url);
                }
                $this->die_msg('File not found.');
                break;
            case 'catalogue':
                $cu = get_post_meta($pid, self::M_CATALOGUE_URL, true);
                $this->safe_redirect($cu ?: home_url('/'));
                break;
            case 'price':
                $url = get_post_meta($pid, self::M_PRICE_URL, true);
                $this->safe_redirect($url ?: home_url('/'));
                break;
            case 'social':
                // simple landing with links
                $links = [];
                foreach ([self::M_SOCIAL_FB=>'Facebook', self::M_SOCIAL_IG=>'Instagram', self::M_SOCIAL_YT=>'YouTube', self::M_SOCIAL_WA=>'WhatsApp', self::M_SOCIAL_TG=>'Telegram'] as $k=>$label){
                    $v = get_post_meta($pid, $k, true);
                    if ($v) $links[] = '<p><a href="'.esc_url($v).'" target="_blank" rel="noopener">'.$label.'</a></p>';
                }
                $this->render_simple_landing($pid, $links ? implode('',$links) : '<p>No social links configured.</p>');
                break;
            case 'greview':
                $place = get_post_meta($pid, self::M_G_PLACEID, true);
                if ($place){
                    $url = 'https://search.google.com/local/writereview?placeid='.rawurlencode($place);
                    $this->safe_redirect($url);
                }
                $this->die_msg('Place ID missing.');
                break;
            case 'form':
                $q = get_post_meta($pid, self::M_FORM_Q, true);
                $this->render_simple_landing($pid, '<p>'.esc_html($q ?: 'Please submit your response.').'</p>');
                break;
            default:
                $this->safe_redirect(home_url('/'));
        }
        exit;
    }

    private function get_by_alias($alias){
        $q = new WP_Query([
            'post_type'=> self::CPT,
            'meta_key' => self::M_ALIAS,
            'meta_value'=> sanitize_title($alias),
            'posts_per_page'=>1,
            'no_found_rows'=>true
        ]);
        $p = $q->have_posts() ? $q->posts[0] : null;
        wp_reset_postdata();
        return $p;
    }

    private function safe_redirect($url){
        if (!$url) $url = home_url('/');
        wp_redirect(esc_url_raw($url), 302);
        exit;
    }

    private function die_msg($msg){
        wp_die('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:720px;margin:10vh auto;background:#fff;border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08)">'
            .'<h2 style="margin-top:0">RightWin QR</h2><p>'.esc_html($msg).'</p></div>');
    }

    private function render_simple_landing($pid, $body_html){
        $top = get_post_meta($pid, self::M_TITLE_TOP, true);
        $bot = get_post_meta($pid, self::M_TITLE_BOTTOM, true);
        $ts  = (int)(get_post_meta($pid, self::M_TITLE_TOP_SIZE, true) ?: 18);
        $bs  = (int)(get_post_meta($pid, self::M_TITLE_BOTTOM_SIZE, true) ?: 14);

        $topH = $this->title_html($top,$ts,'rwqr-title-top');
        $botH = $this->title_html($bot,$bs,'rwqr-title-bottom');

        wp_die('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            .esc_html(get_the_title($pid))
            .'</title><style>body{background:#0b0c10;margin:0;padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}'
            .'.rwqr-card{max-width:720px;margin:0 auto;background:#fff;border-radius:16px;padding:16px;box-shadow:0 8px 24px rgba(0,0,0,.08)}'
            .'.rwqr-title-top,.rwqr-title-bottom{text-align:center;line-height:1.2}</style></head><body>'
            .'<div class="rwqr-card">'.$topH.$body_html.$botH.'</div></body></html>');
    }

    private function render_media_landing($pid, $ctype){
        $top = get_post_meta($pid, self::M_TITLE_TOP, true);
        $bot = get_post_meta($pid, self::M_TITLE_BOTTOM, true);
        $ts  = (int)(get_post_meta($pid, self::M_TITLE_TOP_SIZE, true) ?: 18);
        $bs  = (int)(get_post_meta($pid, self::M_TITLE_BOTTOM_SIZE, true) ?: 14);

        $topH = $this->title_html($top,$ts,'rwqr-title-top');
        $botH = $this->title_html($bot,$bs,'rwqr-title-bottom');

        $body = '';
        if ($ctype==='image'){
            $img = esc_url(get_post_meta($pid, self::M_IMAGE_URL, true));
            if ($img) $body = '<div style="text-align:center"><img src="'.$img.'" alt="" style="max-width:100%;height:auto;border-radius:12px"></div>';
        } else {
            $v = trim((string)get_post_meta($pid, self::M_VIDEO_URL, true));
            if ($v){
                if (preg_match('~(youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_\-]+)~i',$v,$m)){
                    $id = end($m);
                    $src= 'https://www.youtube.com/embed/'.esc_attr($id);
                    $body = '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:12px"><iframe src="'.$src.'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe></div>';
                } elseif (preg_match('~vimeo\.com/(\d+)~i',$v,$m)){
                    $id = $m[1];
                    $src= 'https://player.vimeo.com/video/'.esc_attr($id);
                    $body = '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:12px"><iframe src="'.$src.'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe></div>';
                } else {
                    $body = '<video controls playsinline style="width:100%;max-height:70vh;border-radius:12px"><source src="'.esc_url($v).'" type="video/mp4">Your browser does not support the video tag.</video>';
                }
            }
        }

        $this->render_simple_landing($pid, $body);
        exit;
    }
    /* ===================== Admin Settings (Limits) ===================== */
    public function admin_menu(){
        add_menu_page('RightWin QR', 'RightWin QR', 'manage_options', 'rwqr_root', [$this,'admin_root'], 'dashicons-qr', 56);
        add_submenu_page('rwqr_root', 'Limits', 'Limits', 'manage_options', 'rwqr_limits', [$this,'admin_limits']);
        // user meta override added to user profile:
        add_action('show_user_profile', [$this,'user_fields']);
        add_action('edit_user_profile', [$this,'user_fields']);
        add_action('personal_options_update', [$this,'save_user_fields']);
        add_action('edit_user_profile_update', [$this,'save_user_fields']);
    }

    public function admin_root(){
        echo '<div class="wrap"><h1>RightWin QR</h1><p>Core management. Use the Limits submenu for quotas.</p></div>';
    }

    public function register_settings(){
        register_setting('rwqr_limits', self::OPT_MAX_QRS_PER_USER);
        register_setting('rwqr_limits', self::OPT_MAX_SCANS_PER_QR);
    }

    public function admin_limits(){
        ?>
        <div class="wrap">
            <h1>RightWin QR — Limits</h1>
            <form method="post" action="options.php">
                <?php settings_fields('rwqr_limits'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="maxq">Global Max QRs per User</label></th>
                        <td><input id="maxq" type="number" min="0" name="<?php echo esc_attr(self::OPT_MAX_QRS_PER_USER); ?>" value="<?php echo esc_attr(get_option(self::OPT_MAX_QRS_PER_USER,100)); ?>"> <span class="description">0 = unlimited</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maxs">Global Max Scans per QR</label></th>
                        <td><input id="maxs" type="number" min="0" name="<?php echo esc_attr(self::OPT_MAX_SCANS_PER_QR); ?>" value="<?php echo esc_attr(get_option(self::OPT_MAX_SCANS_PER_QR,0)); ?>"> <span class="description">0 = unlimited (owner’s per-QR limit still applies)</span></td>
                    </tr>
                </table>
                <?php submit_button('Save Limits'); ?>
            </form>
            <p class="description">Per-user overrides are available on each user’s profile.</p>
        </div>
        <?php
    }

    public function user_fields($user){
        if (!current_user_can('manage_options')) return;
        $v = get_user_meta($user->ID, self::UMAX_QRS, true);
        ?>
        <h2>RightWin QR Limits</h2>
        <table class="form-table">
            <tr>
                <th><label for="rwqr_user_max_qrs">Max QRs for this user</label></th>
                <td>
                    <input type="number" min="0" id="rwqr_user_max_qrs" name="rwqr_user_max_qrs" value="<?php echo esc_attr($v); ?>">
                    <p class="description">0 = unlimited. Leave blank to use global default.</p>
                </td>
            </tr>
        </table>
        <?php
    }
    public function save_user_fields($user_id){
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['rwqr_user_max_qrs'])){
            $v = trim((string)$_POST['rwqr_user_max_qrs']);
            if ($v==='') delete_user_meta($user_id, self::UMAX_QRS);
            else update_user_meta($user_id, self::UMAX_QRS, max(0,(int)$v));
        }
    }

    /* ===================== Assets ===================== */
    public function assets(){
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('rwqr-portal', $base.'assets/portal.css', [], self::VER);
        wp_enqueue_script('rwqr-portal', $base.'assets/portal.js', [], self::VER, true);
    }
}

RightWin_QR_Portal_Core::instance();
