<?php
/**
 * Plugin Name: Smart Property Checks - Lead & Call Attribution Tracker
 * Description: First-party real-time lead, phone call, and attribution tracker. Tracks calls, forms, and checkouts segmented by Google Ads, Local SEO, and AI (ChatGPT).
 * Version: 1.8.2
 * Author: Smart Property Checks
 *
 * 1.4.0 (2026-11-12): "Likely searches" for Google SEO leads (landing page + date matched against Search Console
 * query data pushed daily into {prefix}spc_gsc_queries by ~/seo-work/plugins/lead-tracker/push_gsc.py; Google does not
 * pass organic keywords to websites), and the Google Ads keyword (utm_term / {keyword} ValueTrack) stored per lead.
 *
 * 1.3.0 (2026-11-12): event_id de-duplication (one click = one row, even with rotating VPN IPs), image ping only as
 * fallback, form/JS share one event id, attribution time + Google Ads campaign kept in cookies, correct channel defaults
 * for forms/orders, VPN/hosting/outside-UK flags, outside-office-hours flag, UK-time date filters, dynamic month labels,
 * KPIs follow filters, whitelisted event types + per-IP rate limit, CSV formula-injection guard, table check via option.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('SPC_Lead_Tracker')) {

class SPC_Lead_Tracker {

    const VERSION = '1.4.0';
    const DB_VERSION = '1.4.0';
    const GSC_TABLE = 'spc_gsc_queries';
    const TABLE_NAME = 'smart_lead_tracker';
    const UK_TZ = 'Europe/London';
    /** Calendar days (Today/Yesterday/month filters, date ranges, date shown) follow Pakistan time. */
    const DAY_TZ = 'Asia/Karachi';
    // Office hours (UK time): Mon-Sat 08:00-18:00 (matches the site's OpeningHoursSpecification schema)
    const OFFICE_OPEN = 8;
    const OFFICE_CLOSE = 18;
    const ALLOWED_TYPES = ['call_click', 'form_submission', 'order_checkout', 'whatsapp_click'];

    public static function init() {
        if (get_option('spc_lead_tracker_db_version') !== self::DB_VERSION) {
            self::ensure_table();
        }

        // REST API endpoint
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        // AJAX fallback endpoints
        add_action('wp_ajax_nopriv_spc_track_lead', [__CLASS__, 'handle_ajax_event']);
        add_action('wp_ajax_spc_track_lead', [__CLASS__, 'handle_ajax_event']);

        // Frontend tracker script in footer
        add_action('wp_footer', [__CLASS__, 'render_frontend_tracker'], 9999);

        // Forminator hooks
        add_action('forminator_custom_form_after_save_entry', [__CLASS__, 'capture_forminator_entry'], 10, 2);
        add_action('forminator_form_after_save_entry', [__CLASS__, 'capture_forminator_entry'], 10, 2);

        // WooCommerce hook
        add_action('woocommerce_new_order', [__CLASS__, 'capture_woocommerce_order'], 10, 2);

        // Admin menu & actions
        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
            add_action('admin_post_spc_export_leads_csv', [__CLASS__, 'handle_csv_export']);
            add_action('admin_post_spc_delete_lead', [__CLASS__, 'handle_lead_delete']);
            add_action('admin_post_spc_bulk_delete_leads', [__CLASS__, 'handle_bulk_delete']);
        }
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function ensure_table() {
        global $wpdb;
        $table = self::get_table_name();

        // dbDelta creates the table or adds any missing columns/keys (safe on existing data)
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            lead_type varchar(50) NOT NULL,
            phone_or_target varchar(100) DEFAULT '',
            channel varchar(50) NOT NULL DEFAULT 'Direct',
            referrer_url text DEFAULT NULL,
            landing_page text DEFAULT NULL,
            current_page text DEFAULT NULL,
            utm_source varchar(100) DEFAULT '',
            utm_medium varchar(100) DEFAULT '',
            utm_campaign varchar(100) DEFAULT '',
            gclid varchar(255) DEFAULT '',
            gad_campaignid varchar(100) DEFAULT '',
            device_type varchar(50) NOT NULL DEFAULT 'Desktop',
            browser varchar(100) DEFAULT '',
            os varchar(100) DEFAULT '',
            user_ip varchar(100) NOT NULL DEFAULT '',
            country varchar(100) DEFAULT '',
            city varchar(100) DEFAULT '',
            lead_details text DEFAULT NULL,
            event_id varchar(64) DEFAULT '',
            attr_time datetime DEFAULT NULL,
            ip_flags varchar(100) DEFAULT '',
            search_term varchar(255) DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY lead_type (lead_type),
            KEY channel (channel),
            KEY device_type (device_type),
            KEY event_id (event_id)
        ) $charset_collate;";

        // Search Console query data (UK) per day and page, pushed in daily from the local SEO scripts
        $gsc = $wpdb->prefix . self::GSC_TABLE;
        $sql2 = "CREATE TABLE $gsc (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            d date NOT NULL,
            page varchar(255) NOT NULL DEFAULT '',
            query varchar(255) NOT NULL DEFAULT '',
            clicks int(11) NOT NULL DEFAULT 0,
            impressions int(11) NOT NULL DEFAULT 0,
            position float NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY page_d (page(150),d),
            KEY d (d)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
        update_option('spc_lead_tracker_db_version', self::DB_VERSION, false);
    }

    /**
     * Google does not pass organic search keywords to websites. Best available answer: the searches that brought
     * clicks (then impressions) to the lead's landing page around the lead date, from Search Console.
     * Returns ['terms' => [[query, clicks, impressions, position], ...], 'scope' => 'day'|'28d'|''].
     */
    public static function likely_queries($landing_url, $created_utc) {
        global $wpdb;
        $gsc = $wpdb->prefix . self::GSC_TABLE;
        $path = wp_parse_url((string) $landing_url, PHP_URL_PATH) ?: '/';
        $page = untrailingslashit(home_url($path)) . '/';
        if ($path === '/') $page = home_url('/');
        static $cache = [];
        $d = substr((string) $created_utc, 0, 10);
        $ck = $page . '|' . $d;
        if (isset($cache[$ck])) return $cache[$ck];
        $out = ['terms' => [], 'scope' => ''];
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $gsc)) !== $gsc) return $cache[$ck] = $out;
        foreach ([[3, 1, 'day'], [28, 0, '28d']] as $win) {
            $from = gmdate('Y-m-d', strtotime($d . ' -' . $win[0] . ' days'));
            $to   = gmdate('Y-m-d', strtotime($d . ' +' . $win[1] . ' days'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT query, SUM(clicks) c, SUM(impressions) i, ROUND(SUM(position*impressions)/GREATEST(SUM(impressions),1),1) p
                 FROM $gsc WHERE page = %s AND d BETWEEN %s AND %s GROUP BY query
                 HAVING i > 0 ORDER BY c DESC, i DESC LIMIT 3",
                $page, $from, $to
            ), ARRAY_N);
            if ($rows) {
                $out = ['terms' => $rows, 'scope' => $win[2]];
                break;
            }
        }
        return $cache[$ck] = $out;
    }

    /** UTC 'Y-m-d H:i:s' for now (created_at is always stored in UTC, whatever the WP timezone setting). */
    public static function now_utc() {
        return gmdate('Y-m-d H:i:s');
    }

    /** Convert a UK local date (Y-m-d) + time to a UTC MySQL datetime string. */
    public static function uk_to_utc($date, $time = '00:00:00') {
        try {
            $dt = new DateTime($date . ' ' . $time, new DateTimeZone(self::DAY_TZ));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return gmdate('Y-m-d H:i:s');
        }
    }

    /**
     * Landing pages where Google Ads sends visitors (update when campaigns change).
     * A Google-referred visit to one of these WITHOUT a click ID is shown as "Google Ads (no click ID)", not SEO.
     */
    public static function ads_landing_paths() {
        $paths = [
            '/epc-london',
            '/fire-risk-assessment-cost-london',
            '/fire-risk-assessor-london',
        ];
        return apply_filters('spc_ads_landing_paths', $paths);
    }

    /**
     * Google Ads campaign ID -> name. Add a line here when a new campaign starts.
     * Confirmed by the owner (15 Nov 2026): 24113204576 = EPC London (/epc-london/ only);
     * 24320809191 = Fire Risk Assessment (fire-risk pages only).
     */
    public static function ads_campaign_names() {
        $names = [
            '24113204576' => 'EPC London',
            '24320809191' => 'Fire Risk Assessment',  // campaign tag: ZQ_lead_Gen_MaxClicks_London_FRA
        ];
        return apply_filters('spc_ads_campaign_names', $names);
    }

    /** Campaign label for display: the name when known, otherwise the raw ID. */
    public static function campaign_label($id) {
        $id = trim((string) $id);
        $names = self::ads_campaign_names();
        return isset($names[$id]) ? $names[$id] . ' (' . $id . ')' : $id;
    }

    /** Campaign shown for a lead: the name for a known ID; else the campaign-name tag; else the raw ID. */
    public static function campaign_display($lead) {
        $gad = trim((string) ($lead['gad_campaignid'] ?? ''));
        $utm = trim((string) ($lead['utm_campaign'] ?? ''));
        $names = self::ads_campaign_names();
        if ($gad !== '' && isset($names[$gad])) return $names[$gad] . ' (' . $gad . ')';
        if ($utm !== '' && !ctype_digit($utm)) return $utm;
        return ($gad !== '' || $utm !== '') ? 'Ads' : '';
    }

    /** Named AI assistant from a referrer or utm_source string, or '' if none. */
    public static function ai_source_name($s) {
        $s = strtolower((string) $s);
        if (strpos($s, 'chatgpt') !== false || strpos($s, 'openai') !== false) return 'ChatGPT';
        if (strpos($s, 'perplexity') !== false) return 'Perplexity';
        if (strpos($s, 'claude.ai') !== false || strpos($s, 'anthropic') !== false) return 'Claude';
        if (strpos($s, 'gemini.google') !== false) return 'Gemini';
        if (strpos($s, 'copilot') !== false) return 'Copilot';
        return '';
    }

    /**
     * Channel for one visit. Rules, in order:
     *  1. Google Ads: click ID, ads campaign ID, utm_medium=cpc.
     *  2. A named AI assistant (utm_source tag first, then referrer): ChatGPT, Perplexity, Claude, Gemini, Copilot.
     *  3. Google referrer with no ads evidence: "SEO (Google)"; on an ads landing page: "Google Ads (unconfirmed)".
     *  4. Bing / Yahoo / DuckDuckGo referrer: "SEO (Bing)" etc.
     *  5. Other site: "Referral: domain".
     *  6. No referrer and no tag: "Direct" (we genuinely don't know the source).
     */
    public static function channel_from($ref, $url = '', $utm_source = '', $utm_medium = '') {
        $url = (string) $url;
        $q = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($q, $qs);
        $um = strtolower((string) ($utm_medium ?: ($qs['utm_medium'] ?? '')));
        $us = strtolower((string) ($utm_source ?: ($qs['utm_source'] ?? '')));
        if (preg_match('/(^|&)(gclid|gbraid|wbraid|gad_source|gad_campaignid)=/i', $q) || $um === 'cpc' || strpos($us, 'google-ads') !== false) {
            return 'Google Ads';
        }
        if ($ai = self::ai_source_name($us)) return $ai;
        $ref = strtolower((string) $ref);
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($ref !== '' && $ai = self::ai_source_name($ref)) return $ai;
        if ($ref === '' || ($host && strpos($ref, $host) !== false)) return 'Direct';
        if (strpos($ref, 'google.') !== false) {
            $path = rtrim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            foreach (self::ads_landing_paths() as $ap) {
                if ($path === rtrim($ap, '/')) return 'Google Ads (unconfirmed)';
            }
            return 'SEO (Google)';
        }
        if (strpos($ref, 'bing.com') !== false) return 'SEO (Bing)';
        if (strpos($ref, 'yahoo.') !== false) return 'SEO (Yahoo)';
        if (strpos($ref, 'duckduckgo.') !== false) return 'SEO (DuckDuckGo)';
        $h = wp_parse_url($ref, PHP_URL_HOST);
        return $h ? 'Referral: ' . $h : 'Direct';
    }

    /** Server-side channel guess when no attribution cookie exists (never invent a channel). */
    public static function guess_channel($ref, $url = '') {
        return self::channel_from($ref, $url, '', '');
    }

    /** True when the UTC datetime falls outside office hours (Mon-Sat 08:00-18:00 UK). */
    public static function is_out_of_hours($utc) {
        try {
            $dt = new DateTime($utc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(self::UK_TZ));
            $h = (int) $dt->format('G');
            $dow = (int) $dt->format('N'); // 7 = Sunday
            return ($dow === 7 || $h < self::OFFICE_OPEN || $h >= self::OFFICE_CLOSE);
        } catch (Exception $e) {
            return false;
        }
    }

    /** Simple per-IP rate limit for public endpoints: max 30 events per 10 minutes. */
    public static function rate_limited($ip) {
        $key = 'spc_lt_rl_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= 30) return true;
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
        return false;
    }

    public static function register_routes() {
        register_rest_route('smart-tracker/v1', '/event', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'handle_rest_event'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function handle_rest_event(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }
        return self::process_lead_event($params);
    }

    public static function handle_ajax_event() {
        $input = file_get_contents('php://input');
        $params = json_decode($input, true);
        if (empty($params)) {
            $params = !empty($_POST) ? $_POST : $_GET;
        }
        if (empty($params)) {
            $params = $_REQUEST;
        }
        $res = self::process_lead_event($params);

        if (isset($_REQUEST['img']) || isset($_GET['img'])) {
            header('Content-Type: image/gif');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        wp_send_json_success($res->get_data());
    }

    public static function process_lead_event($params) {
        $params = is_array($params) ? $params : [];
        $lead_type       = sanitize_text_field($params['lead_type'] ?? 'call_click');
        if (!in_array($lead_type, self::ALLOWED_TYPES, true)) {
            return new WP_REST_Response(['success' => false, 'error' => 'invalid type'], 400);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|python-requests|curl\//i', $ua)) {
            return new WP_REST_Response(['success' => false, 'error' => 'bot'], 200);
        }
        $ip = self::get_client_ip();
        $event_id = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($params['event_id'] ?? '')), 0, 64);
        // Basic anti-spam: the endpoint is public, so cap events per IP
        if (self::rate_limited($ip)) {
            return new WP_REST_Response(['success' => false, 'error' => 'rate limited'], 429);
        }
        $phone_or_target = substr(sanitize_text_field($params['phone_or_target'] ?? '02084323282'), 0, 100);
        $channel         = substr(sanitize_text_field($params['channel'] ?? 'Direct'), 0, 50);
        $referrer_url    = esc_url_raw($params['referrer_url'] ?? '');
        $landing_page    = esc_url_raw($params['landing_page'] ?? home_url('/'));
        $current_page    = esc_url_raw($params['current_page'] ?? home_url('/'));
        $utm_source      = sanitize_text_field($params['utm_source'] ?? '');
        $utm_medium      = sanitize_text_field($params['utm_medium'] ?? '');
        $utm_campaign    = sanitize_text_field($params['utm_campaign'] ?? '');
        $gclid           = sanitize_text_field($params['gclid'] ?? '');
        $gad_campaignid  = sanitize_text_field($params['gad_campaignid'] ?? '');
        $device_type     = sanitize_text_field($params['device_type'] ?? 'Desktop');
        $browser         = sanitize_text_field($params['browser'] ?? '');
        $os              = sanitize_text_field($params['os'] ?? '');
        $lead_details    = substr(sanitize_text_field($params['lead_details'] ?? ''), 0, 1000);
        $attr_time       = self::parse_attr_time($params['attr_time'] ?? '');
        $search_term     = substr(str_replace(['{', '}'], '', sanitize_text_field($params['search_term'] ?? '')), 0, 255);

        $geo = self::lookup_geo($ip);

        $record_id = self::insert_record([
            'search_term'     => $search_term,
            'event_id'        => $event_id,
            'attr_time'       => $attr_time,
            'ip_flags'        => $geo['flags'] ?? '',
            'lead_type'       => $lead_type,
            'phone_or_target' => $phone_or_target,
            'channel'         => $channel,
            'referrer_url'    => $referrer_url,
            'landing_page'    => $landing_page,
            'current_page'    => $current_page,
            'utm_source'      => $utm_source,
            'utm_medium'      => $utm_medium,
            'utm_campaign'    => $utm_campaign,
            'gclid'           => $gclid,
            'gad_campaignid'  => $gad_campaignid,
            'device_type'     => $device_type,
            'browser'         => $browser,
            'os'              => $os,
            'user_ip'         => $ip,
            'country'         => $geo['country'],
            'city'            => $geo['city'],
            'lead_details'    => $lead_details
        ]);

        return new WP_REST_Response([
            'success' => true,
            'id' => $record_id
        ], 200);
    }

    /** Attribution time arrives from the browser as epoch milliseconds; keep it only if plausible (last 90 days). */
    public static function parse_attr_time($ms) {
        $ms = preg_replace('/[^0-9]/', '', (string) $ms);
        if ($ms === '') return null;
        $s = (int) floor(((int) $ms) / 1000);
        if ($s < time() - 90 * DAY_IN_SECONDS || $s > time() + 300) return null;
        return gmdate('Y-m-d H:i:s', $s);
    }

    public static function insert_record($data) {
        global $wpdb;
        $table = self::get_table_name();

        // 1. Same event delivered by several transports (beacon / fetch / image) or by JS + server form hook:
        //    one event_id = one row, regardless of IP (VPNs and iCloud Private Relay rotate IPs per request).
        if (!empty($data['event_id'])) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, lead_details, channel, attr_time FROM {$table} WHERE event_id = %s ORDER BY id ASC LIMIT 1",
                $data['event_id']
            ), ARRAY_A);
            if ($existing) {
                $upd = [];
                if (!empty($data['lead_details']) && strlen($data['lead_details']) > strlen((string) $existing['lead_details'])) {
                    $upd['lead_details'] = $data['lead_details'];
                }
                if (empty($existing['attr_time']) && !empty($data['attr_time'])) {
                    $upd['attr_time'] = $data['attr_time'];
                }
                if (!empty($upd)) {
                    $wpdb->update($table, $upd, ['id' => $existing['id']]);
                }
                return (int) $existing['id'];
            }
        }

        $defaults = [
            'created_at'      => self::now_utc(),
            'lead_type'       => 'call_click',
            'phone_or_target' => '02084323282',
            'channel'         => 'Direct',
            'referrer_url'    => '',
            'landing_page'    => home_url('/'),
            'current_page'    => home_url('/'),
            'utm_source'      => '',
            'utm_medium'      => '',
            'utm_campaign'    => '',
            'gclid'           => '',
            'gad_campaignid'  => '',
            'device_type'     => 'Desktop',
            'browser'         => '',
            'os'              => '',
            'user_ip'         => self::get_client_ip(),
            'country'         => '',
            'city'            => '',
            'lead_details'    => '',
            'event_id'        => '',
            'attr_time'       => null,
            'ip_flags'        => '',
            'search_term'     => ''
        ];

        $insert_data = wp_parse_args($data, $defaults);
        if (empty($insert_data['attr_time'])) unset($insert_data['attr_time']);

        // 2. Fallback de-duplication for old cached pages without event_id:
        // calls/whatsapp 5 s, forms/checkouts 60 s, same IP + same target.
        if (in_array($insert_data['lead_type'], ['form_submission', 'order_checkout', 'call_click', 'whatsapp_click'])) {
            $dedup_seconds = in_array($insert_data['lead_type'], ['call_click', 'whatsapp_click']) ? 5 : 60;
            $window = gmdate('Y-m-d H:i:s', time() - $dedup_seconds);
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, lead_details, landing_page, current_page FROM {$table} WHERE lead_type = %s AND user_ip = %s AND phone_or_target = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
                $insert_data['lead_type'],
                $insert_data['user_ip'],
                $insert_data['phone_or_target'],
                $window
            ), ARRAY_A);

            if ($existing) {
                // If the new record has richer details or a more specific current_page, update existing record
                $update_fields = [];
                if (!empty($insert_data['lead_details']) && (empty($existing['lead_details']) || strlen($insert_data['lead_details']) > strlen($existing['lead_details']))) {
                    $update_fields['lead_details'] = $insert_data['lead_details'];
                }
                if (!empty($insert_data['current_page']) && $insert_data['current_page'] !== home_url('/') && ($existing['current_page'] === home_url('/') || empty($existing['current_page']))) {
                    $update_fields['current_page'] = $insert_data['current_page'];
                }
                if (!empty($insert_data['landing_page']) && $insert_data['landing_page'] !== home_url('/') && ($existing['landing_page'] === home_url('/') || empty($existing['landing_page']))) {
                    $update_fields['landing_page'] = $insert_data['landing_page'];
                }
                if (!empty($update_fields)) {
                    $wpdb->update($table, $update_fields, ['id' => $existing['id']]);
                }
                return (int)$existing['id'];
            }
        }

        $wpdb->insert($table, $insert_data);
        return $wpdb->insert_id;
    }

    public static function get_client_ip() {
        // The site is served directly by Apache (no Cloudflare). Forwarded headers can be faked by any visitor,
        // so they are only trusted when the connection itself comes from a private/internal proxy address.
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $remote;
        }
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];
        foreach ($ip_keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ips = explode(',', $_SERVER[$k]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public static function lookup_geo($ip) {
        if (empty($ip) || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            return ['country' => '', 'city' => '', 'flags' => 'private_ip'];
        }

        $cache_key = 'spc_geo2_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            return $cached;
        }

        // Unknown stays unknown: never pretend a failed lookup is "United Kingdom"
        $geo = ['country' => '', 'city' => '', 'flags' => ''];
        $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,regionName,proxy,hosting", [
            'timeout' => 1.5,
            'headers' => ['User-Agent' => 'SmartPropertyChecksTracker/1.3']
        ]);

        $ok = false;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && ($data['status'] ?? '') === 'success') {
                $ok = true;
                $geo['country'] = $data['country'] ?? '';
                $geo['city'] = $data['city'] ?? ($data['regionName'] ?? '');
                $flags = [];
                if (!empty($data['countryCode']) && $data['countryCode'] !== 'GB') $flags[] = 'outside_uk';
                if (!empty($data['proxy'])) $flags[] = 'vpn_proxy';
                if (!empty($data['hosting'])) $flags[] = 'datacenter';
                $geo['flags'] = implode(',', $flags);
            }
        }

        // Cache successes for 30 days, failures only briefly so they are retried
        set_transient($cache_key, $geo, $ok ? 30 * DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
        return $geo;
    }

    public static function render_frontend_tracker() {
        if (is_admin()) return;
        if (defined('ELEMENTOR_VERSION') && class_exists('\Elementor\Plugin')) {
            if (\Elementor\Plugin::$instance->preview->is_preview_mode()) return;
        }

        $rest_url = esc_url_raw(rest_url('smart-tracker/v1/event'));
        $ajax_url = esc_url_raw(admin_url('admin-ajax.php?action=spc_track_lead'));

        ?>
        <!-- Smart Property Checks Lead & Call Tracker -->
        <script id='spc-lead-tracker'>
        (function() {
            'use strict';

            var REST_URL = '<?php echo $rest_url; ?>';
            var AJAX_URL = '<?php echo $ajax_url; ?>';

            function getCookie(name) {
                var m = document.cookie.match(new RegExp('(^|; )' + name.replace(/([\.0*|{}\(\)\[\]\\/\+^])/g, '\$1') + '=([^;]*)'));
                return m ? decodeURIComponent(m[2]) : null;
            }

            function setCookie(name, val, days) {
                var d = new Date();
                d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
                document.cookie = name + '=' + encodeURIComponent(val) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
            }

            var SPC_ADS_PATHS = <?php echo wp_json_encode(array_values(array_map(function($p){ return rtrim($p, '/'); }, self::ads_landing_paths()))); ?>;
            var params = new URLSearchParams(window.location.search);
            var gclid = params.get('gclid') || params.get('gbraid') || params.get('wbraid') || '';
            var gadSource = params.get('gad_source') || '';
            var gadCampaign = params.get('gad_campaignid') || '';
            var utmSource = params.get('utm_source') || '';
            var utmMedium = params.get('utm_medium') || '';
            var utmCampaign = params.get('utm_campaign') || '';
            // Google Ads keyword via Final URL suffix: utm_term={keyword} (ignore unreplaced ValueTrack braces)
            var utmTerm = (params.get('utm_term') || params.get('keyword') || '').replace(/[{}]/g, '').substring(0, 200);

            var ref = document.referrer || '';
            var channel = 'Direct';

            var aiName = function(v) {
                v = (v || '').toLowerCase();
                if (v.indexOf('chatgpt') !== -1 || v.indexOf('openai') !== -1) return 'ChatGPT';
                if (v.indexOf('perplexity') !== -1) return 'Perplexity';
                if (v.indexOf('claude.ai') !== -1 || v.indexOf('anthropic') !== -1) return 'Claude';
                if (v.indexOf('gemini.google') !== -1) return 'Gemini';
                if (v.indexOf('copilot') !== -1) return 'Copilot';
                return '';
            };
            var srcTag = (utmSource || '').toLowerCase();
            var refL = ref.toLowerCase();
            var ownHost = window.location.hostname.toLowerCase();
            if (gclid || gadSource || utmMedium.toLowerCase() === 'cpc' || srcTag.indexOf('google-ads') !== -1) {
                channel = 'Google Ads';
            } else if (aiName(srcTag)) {
                channel = aiName(srcTag);
            } else if (refL && aiName(refL)) {
                channel = aiName(refL);
            } else if (!refL || (ownHost && refL.indexOf(ownHost) !== -1)) {
                channel = 'Direct';
            } else if (refL.indexOf('google.') !== -1) {
                var pathNow = window.location.pathname.replace(/\/+$/, '');
                channel = SPC_ADS_PATHS.indexOf(pathNow) !== -1 ? 'Google Ads (unconfirmed)' : 'SEO (Google)';
            } else if (refL.indexOf('bing.com') !== -1) {
                channel = 'SEO (Bing)';
            } else if (refL.indexOf('yahoo.') !== -1) {
                channel = 'SEO (Yahoo)';
            } else if (refL.indexOf('duckduckgo.') !== -1) {
                channel = 'SEO (DuckDuckGo)';
            } else {
                try { channel = 'Referral: ' + new URL(ref).hostname; } catch(e) { channel = 'Direct'; }
            }

            var existingChannel = getCookie('_spc_attr_channel');
            var isInternalNav = ref.includes(window.location.hostname);

            if (!existingChannel || (!isInternalNav && channel !== 'Direct')) {
                setCookie('_spc_attr_channel', channel, 30);
                setCookie('_spc_attr_landing', window.location.href, 30);
                setCookie('_spc_attr_ref', ref, 30);
                setCookie('_spc_attr_gclid', gclid, 30);
                setCookie('_spc_attr_campaign', utmCampaign || gadCampaign, 30);
                setCookie('_spc_attr_gad', gadCampaign, 30);
                setCookie('_spc_attr_term', utmTerm, 30);
                // When this visit's source was recorded (shows if an Ads lead clicked the ad earlier)
                setCookie('_spc_attr_time', String(Date.now()), 30);
            }

            function newEventId() {
                try {
                    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
                } catch(e) {}
                return 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
            }

            function getDeviceInfo() {
                var ua = navigator.userAgent || '';
                var width = window.innerWidth || (screen && screen.width) || 1024;
                var isMob = /Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua) || (width <= 768);
                var isTab = /iPad|tablet/i.test(ua) || (width > 768 && width <= 1024);
                var dev = isMob ? 'Mobile' : (isTab ? 'Tablet' : 'Desktop');

                var browser = 'Unknown';
                if (ua.includes('Edg/')) browser = 'Edge';
                else if (ua.includes('Chrome/')) browser = 'Chrome';
                else if (ua.includes('Safari/') && !ua.includes('Chrome/')) browser = 'Safari';
                else if (ua.includes('Firefox/')) browser = 'Firefox';

                var os = 'Unknown';
                if (ua.includes('Windows')) os = 'Windows';
                else if (ua.includes('Android')) os = 'Android';
                else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';
                else if (ua.includes('Mac OS')) os = 'macOS';
                else if (ua.includes('Linux')) os = 'Linux';

                return { device: dev, browser: browser, os: os };
            }

            var lastLogTime = 0;
            function logEvent(type, target, details, eventId) {
                var now = Date.now();
                if (now - lastLogTime < 1500) return;
                lastLogTime = now;

                var dev = getDeviceInfo();
                var curUrl = window.location.href;
                var landUrl = getCookie('_spc_attr_landing') || curUrl;
                var effChannel = getCookie('_spc_attr_channel') || channel;
                var effRef = getCookie('_spc_attr_ref') || ref;
                var effCampaign = getCookie('_spc_attr_campaign') || utmCampaign || gadCampaign;
                var effGclid = getCookie('_spc_attr_gclid') || gclid;
                var effGad = getCookie('_spc_attr_gad') || gadCampaign;

                var payload = {
                    action: 'spc_track_lead',
                    event_id: eventId || newEventId(),
                    attr_time: getCookie('_spc_attr_time') || '',
                    search_term: getCookie('_spc_attr_term') || utmTerm,
                    lead_type: type,
                    phone_or_target: target || '02084323282',
                    channel: effChannel,
                    referrer_url: effRef,
                    landing_page: landUrl,
                    current_page: curUrl,
                    utm_source: utmSource,
                    utm_medium: utmMedium,
                    utm_campaign: effCampaign,
                    gclid: effGclid,
                    gad_campaignid: effGad,
                    device_type: dev.device,
                    browser: dev.browser,
                    os: dev.os,
                    lead_details: details || ''
                };

                // 1. CORS-safelisted FormData via sendBeacon
                var formData = new FormData();
                for (var key in payload) {
                    if (payload.hasOwnProperty(key)) {
                        formData.append(key, payload[key]);
                    }
                }

                var beaconSent = false;
                if (navigator.sendBeacon) {
                    try {
                        beaconSent = navigator.sendBeacon(AJAX_URL, formData);
                    } catch(e) {
                        beaconSent = false;
                    }
                }

                // 3. Image ping (GET 1x1 GIF) - only used when beacon and fetch both fail.
                // The same event_id travels with every transport, so the server stores one row.
                function imagePing() {
                    try {
                        var qParts = [];
                        for (var pk in payload) {
                            if (payload.hasOwnProperty(pk)) {
                                qParts.push(encodeURIComponent(pk) + '=' + encodeURIComponent(payload[pk]));
                            }
                        }
                        var imgPing = new Image();
                        imgPing.src = AJAX_URL + '&img=1&' + qParts.join('&');
                    } catch(e) {}
                }

                // 2. Fetch with keepalive: true (POST FormData)
                if (!beaconSent) {
                    if (window.fetch) {
                        try {
                            fetch(AJAX_URL, {
                                method: 'POST',
                                body: formData,
                                keepalive: true
                            }).catch(function() { imagePing(); });
                        } catch(e) { imagePing(); }
                    } else {
                        imagePing();
                    }
                }
            }

            // Dynamically inject exact page URL into all forms & modals on the page
            function attachFormUrlFields() {
                var curUrl = window.location.href;
                var refUrl = document.referrer || '';
                var forms = document.querySelectorAll('form');
                for (var i = 0; i < forms.length; i++) {
                    var f = forms[i];
                    var spcInput = f.querySelector('input[name=spc_page_url]');
                    if (!spcInput) {
                        spcInput = document.createElement('input');
                        spcInput.type = 'hidden';
                        spcInput.name = 'spc_page_url';
                        f.appendChild(spcInput);
                    }
                    spcInput.value = curUrl;

                    // One event id per form submission, shared by the browser event and the server-side form hook
                    var evInput = f.querySelector('input[name=spc_event_id]');
                    if (!evInput) {
                        evInput = document.createElement('input');
                        evInput.type = 'hidden';
                        evInput.name = 'spc_event_id';
                        f.appendChild(evInput);
                    }
                    if (!evInput.value) evInput.value = newEventId();

                    var curInput = f.querySelector('input[name=current_url]');
                    if (curInput) {
                        curInput.value = curUrl;
                    }

                    var refInput = f.querySelector('input[name=referer_url]');
                    if (refInput && !refInput.value) {
                        refInput.value = refUrl;
                    }
                }
            }

            window.spcAttachFormUrls = attachFormUrlFields;
            attachFormUrlFields();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', attachFormUrlFields);
            }
            window.addEventListener('load', attachFormUrlFields);

            document.addEventListener('focusin', function(e) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT')) {
                    attachFormUrlFields();
                }
            }, true);

            document.addEventListener('submit', function(e) {
                attachFormUrlFields();
            }, true);

            if (window.jQuery) {
                jQuery(document).on('before:forminator:form:submit', function(e, formData) {
                    attachFormUrlFields();
                    if (formData && typeof formData.set === 'function') {
                        formData.set('spc_page_url', window.location.href);
                        formData.set('current_url', window.location.href);
                        var evEl = e.target && e.target.querySelector ? e.target.querySelector('input[name=spc_event_id]') : null;
                        if (evEl && evEl.value) formData.set('spc_event_id', evEl.value);
                    }
                });

                jQuery(document).on('forminator:form:submit:success', function(e, formData, response) {
                    var form = e.target;
                    var formId = (form && form.getAttribute('data-form-id')) || 'form';
                    var evEl = form && form.querySelector ? form.querySelector('input[name=spc_event_id]') : null;
                    var formEventId = (evEl && evEl.value) ? evEl.value : newEventId();
                    if (evEl) evEl.value = newEventId(); // fresh id for any further submission
                    var nameInput = form ? form.querySelector('input[name*="name"]') : null;
                    var phoneInput = form ? form.querySelector('input[name*="phone"]') : null;
                    var emailInput = form ? form.querySelector('input[name*="email"]') : null;
                    var details = 'Form submission on ' + window.location.pathname;
                    if (nameInput && nameInput.value) details += ' | Name: ' + nameInput.value;
                    if (phoneInput && phoneInput.value) details += ' | Tel: ' + phoneInput.value;
                    if (emailInput && emailInput.value) details += ' | Email: ' + emailInput.value;

                    lastLogTime = 0; // a form submit must never be skipped by the click throttle
                    logEvent('form_submission', 'Form #' + formId, details, formEventId);
                });
            }

            // Universal Call Target Detector
            function findCallTarget(node) {
                var el = node;
                if (el && el.nodeType === 3) el = el.parentElement;

                for (var i = 0; i < 10 && el && el !== document.body && el !== document.documentElement; i++) {
                    // Non-interactive text blocks must NEVER trigger a call unless an actual <a> link inside was clicked
                    if (el.classList && el.classList.contains('elementor-widget-text-editor')) {
                        if (node === el || (!node.closest || !node.closest('a[href*="tel:"]'))) {
                            return null;
                        }
                    }

                    // 1. Direct or parent href containing tel:
                    var href = (el.getAttribute && el.getAttribute('href')) || (typeof el.href === 'string' ? el.href : '') || '';
                    href = href.trim();
                    if (/tel:/i.test(href)) {
                        var m = href.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawP = m ? m[1] : href.replace(/^.*tel:/i, '');
                        try { rawP = decodeURIComponent(rawP); } catch(e) {}
                        var cleanP = rawP.replace(/[\s\(\)\-\.]/g, '');
                        var label = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || 'Call Button').trim().substring(0, 80);
                        return { phone: cleanP || '02084323282', label: label };
                    }

                    // 2. Elementor XPro widget link or data attributes
                    var xpro = (el.getAttribute && el.getAttribute('data-xpro-element-link')) || '';
                    if (/tel:/i.test(xpro)) {
                        var mX = xpro.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawPX = mX ? mX[1] : '';
                        try { rawPX = decodeURIComponent(rawPX); } catch(e) {}
                        var cleanPX = rawPX.replace(/[\s\(\)\-\.]/g, '');
                        var labelX = (el.innerText || 'Call Button (XPro)').trim().substring(0, 80);
                        return { phone: cleanPX || '02084323282', label: labelX };
                    }

                    // 3. Custom data-href, data-url or onclick with tel:
                    var dHref = (el.getAttribute && (el.getAttribute('data-href') || el.getAttribute('data-url') || el.getAttribute('onclick'))) || '';
                    if (/tel:/i.test(dHref)) {
                        var mD = dHref.match(/tel:([0-9\s\+\(\)\-%]+)/i);
                        var rawPD = mD ? mD[1] : '';
                        try { rawPD = decodeURIComponent(rawPD); } catch(e) {}
                        var cleanPD = rawPD.replace(/[\s\(\)\-\.]/g, '');
                        var labelD = (el.innerText || 'Call Button').trim().substring(0, 80);
                        return { phone: cleanPD || '02084323282', label: labelD };
                    }

                    // 4. Interactive button or link containing phone number in text
                    var isInteractive = el.tagName === 'A' || el.tagName === 'BUTTON' || 
                        (el.getAttribute && el.getAttribute('role') === 'button') ||
                        (el.classList && (
                            el.classList.contains('elementor-button') || 
                            el.classList.contains('ast-custom-button-link') || 
                            el.classList.contains('ast-custom-button') ||
                            el.classList.contains('menu-link') ||
                            el.classList.contains('btn') ||
                            el.classList.contains('call-btn')
                        ));

                    if (isInteractive) {
                        var txt = (el.innerText || el.getAttribute('aria-label') || '').trim();
                        if (txt && (txt.indexOf('02084323282') !== -1 || txt.indexOf('020 8432 3282') !== -1 || /\b020[\s\-\.]?8432[\s\-\.]?3282\b/.test(txt))) {
                            return { phone: '02084323282', label: txt.substring(0, 80) };
                        }
                    }

                    el = el.parentElement;
                }
                return null;
            }

            var lastCallTrigger = 0;
            function handleCallEvent(e) {
                if (!e || !e.target) return;
                if (e.isTrusted === false) return;

                var callInfo = findCallTarget(e.target);
                if (callInfo) {
                    var now = Date.now();
                    if (now - lastCallTrigger < 2500) return;
                    lastCallTrigger = now;

                    logEvent('call_click', callInfo.phone, 'Clicked: ' + callInfo.label);
                }
            }

            // Listen ONLY to genuine 'click' events with capture phase
            // Note: pointerdown is intentionally omitted to prevent false triggers during mobile scroll, swipe, or pull-to-refresh
            document.addEventListener('click', handleCallEvent, { capture: true });

            // Universal WhatsApp and quote modal click handler
            document.addEventListener('click', function(e) {
                var a = e.target && e.target.closest ? e.target.closest('a') : null;
                if (!a) return;
                var href = a.getAttribute('href') || '';
                if (href.includes('wa.me') || href.includes('whatsapp.com')) {
                    logEvent('whatsapp_click', 'WhatsApp', 'Clicked: ' + (a.innerText || 'WhatsApp Button').trim().substring(0, 50));
                } else if (href.includes('#quote-modal') || (a.classList && (a.classList.contains('spc-btn-free-quote') || a.classList.contains('my-catalog-quote-btn') || a.classList.contains('my-custom-quote-btn')))) {
                    attachFormUrlFields();
                }
            }, true);

        })();
        </script>
        <?php
    }

    public static function capture_forminator_entry($entry, $form_id) {
        if (!is_object($entry)) return;

        $ref     = esc_url_raw($_COOKIE['_spc_attr_ref'] ?? '');
        $campaign = sanitize_text_field($_COOKIE['_spc_attr_campaign'] ?? '');
        $gclid   = sanitize_text_field($_COOKIE['_spc_attr_gclid'] ?? '');
        $gad     = sanitize_text_field($_COOKIE['_spc_attr_gad'] ?? '');
        $event_id = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_POST['spc_event_id'] ?? '')), 0, 64);
        $attr_time = self::parse_attr_time($_COOKIE['_spc_attr_time'] ?? '');
        // Previously defaulted to "Google Organic (SEO)" when the cookie was missing, which inflated SEO leads
        $channel = !empty($_COOKIE['_spc_attr_channel'])
            ? sanitize_text_field($_COOKIE['_spc_attr_channel'])
            : self::guess_channel('', $_POST['spc_page_url'] ?? '');

        // 1. Detect exact page where the form was submitted (no guessing)
        $current_page = '';
        if (!empty($_POST['spc_page_url'])) {
            $current_page = esc_url_raw($_POST['spc_page_url']);
        } elseif (!empty($_POST['current_url'])) {
            $current_page = esc_url_raw($_POST['current_url']);
        } elseif (!empty($_POST['page_url'])) {
            $current_page = esc_url_raw($_POST['page_url']);
        } elseif (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'admin-ajax.php') === false) {
            $current_page = esc_url_raw($_SERVER['HTTP_REFERER']);
        } elseif (!empty($_POST['_wp_http_referer']) && strpos($_POST['_wp_http_referer'], 'admin-ajax.php') === false) {
            $current_page = esc_url_raw(home_url($_POST['_wp_http_referer']));
        } elseif (!empty($_COOKIE['_spc_attr_landing'])) {
            $current_page = esc_url_raw($_COOKIE['_spc_attr_landing']);
        } else {
            $current_page = home_url('/');
        }

        // 2. Landing page detection (first page visited)
        $landing_page = !empty($_COOKIE['_spc_attr_landing']) ? esc_url_raw($_COOKIE['_spc_attr_landing']) : $current_page;

        $meta = $entry->meta_data ?? [];
        $name = '';
        $email = '';
        $phone = '';
        $services = '';

        foreach ($meta as $k => $v) {
            $val = is_array($v['value'] ?? '') ? implode(', ', $v['value']) : ($v['value'] ?? '');
            if (strpos($k, 'name') !== false && empty($name)) $name = $val;
            if (strpos($k, 'email') !== false && empty($email)) $email = $val;
            if (strpos($k, 'phone') !== false && empty($phone)) $phone = $val;
            if (strpos($k, 'checkbox') !== false && empty($services)) $services = $val;
        }

        $details = trim("Name: $name | Email: $email | Phone: $phone | Services: $services");

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_mob = (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false);
        $dev = $is_mob ? 'Mobile' : 'Desktop';

        $ip = self::get_client_ip();
        $geo = self::lookup_geo($ip);

        self::insert_record([
            'search_term'     => substr(str_replace(['{', '}'], '', sanitize_text_field($_COOKIE['_spc_attr_term'] ?? '')), 0, 255),
            'event_id'        => $event_id,
            'attr_time'       => $attr_time,
            'ip_flags'        => $geo['flags'] ?? '',
            'lead_type'       => 'form_submission',
            'phone_or_target' => 'Form #' . $form_id,
            'channel'         => $channel,
            'referrer_url'    => $ref,
            'landing_page'    => $landing_page,
            'current_page'    => $current_page,
            'utm_campaign'    => $campaign,
            'gclid'           => $gclid,
            'gad_campaignid'  => $gad,
            'device_type'     => $dev,
            'user_ip'         => $ip,
            'country'         => $geo['country'],
            'city'            => $geo['city'],
            'lead_details'    => $details
        ]);
    }

    public static function capture_woocommerce_order($order_id, $order = null) {
        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        if (!$order) return;

        // Previously defaulted to "ChatGPT (AI)" when the cookie was missing
        $channel = !empty($_COOKIE['_spc_attr_channel'])
            ? sanitize_text_field($_COOKIE['_spc_attr_channel'])
            : self::guess_channel('', $_COOKIE['_spc_attr_landing'] ?? '');
        $landing = esc_url_raw($_COOKIE['_spc_attr_landing'] ?? home_url('/'));
        $ref = esc_url_raw($_COOKIE['_spc_attr_ref'] ?? '');
        $campaign = sanitize_text_field($_COOKIE['_spc_attr_campaign'] ?? '');
        $gclid = sanitize_text_field($_COOKIE['_spc_attr_gclid'] ?? '');

        $ua = $order->get_customer_user_agent();
        $is_mob = (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false);
        $dev = $is_mob ? 'Mobile' : 'Desktop';

        $total = $order->get_total();
        $city = $order->get_billing_city();
        $name = $order->get_formatted_billing_full_name();
        $details = "Order #$order_id | £$total | Customer: $name | City: $city";

        $ip = $order->get_customer_ip_address() ?: self::get_client_ip();
        $geo = self::lookup_geo($ip);

        self::insert_record([
            'search_term'     => substr(str_replace(['{', '}'], '', sanitize_text_field($_COOKIE['_spc_attr_term'] ?? '')), 0, 255),
            'event_id'        => 'order-' . intval($order_id),
            'attr_time'       => self::parse_attr_time($_COOKIE['_spc_attr_time'] ?? ''),
            'ip_flags'        => $geo['flags'] ?? '',
            'gad_campaignid'  => sanitize_text_field($_COOKIE['_spc_attr_gad'] ?? ''),
            'lead_type'       => 'order_checkout',
            'phone_or_target' => 'Order #' . $order_id,
            'channel'         => $channel,
            'referrer_url'    => $ref,
            'landing_page'    => $landing,
            'current_page'    => home_url('/checkout/'),
            'utm_campaign'    => $campaign,
            'gclid'           => $gclid,
            'device_type'     => $dev,
            'user_ip'         => $ip,
            'country'         => $geo['country'],
            'city'            => $city ?: $geo['city'],
            'lead_details'    => $details
        ]);
    }

    public static function register_admin_menu() {
        add_menu_page(
            'Smart Leads & Calls',
            'Smart Leads Tracker',
            'manage_options',
            'spc-lead-tracker',
            [__CLASS__, 'render_admin_dashboard'],
            'dashicons-chart-area',
            26
        );
    }

    public static function build_query_clauses($params) {
        global $wpdb;
        $where = ["1=1"];

        $lead_type  = sanitize_text_field($params['lead_type'] ?? '');
        $channel    = sanitize_text_field($params['channel'] ?? '');
        $device     = sanitize_text_field($params['device'] ?? '');
        $date_range = sanitize_text_field($params['date_range'] ?? '');
        $start_date = sanitize_text_field($params['start_date'] ?? '');
        $end_date   = sanitize_text_field($params['end_date'] ?? '');
        $search     = sanitize_text_field($params['s'] ?? '');
        $sort_order = strtolower(sanitize_text_field($params['sort_order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        if ($lead_type) {
            $where[] = $wpdb->prepare("lead_type = %s", $lead_type);
        }
        if ($channel) {
            $where[] = $wpdb->prepare("channel = %s", $channel);
        }
        if ($device) {
            $where[] = $wpdb->prepare("device_type = %s", $device);
        }

        // Date filter handling: days are Pakistan (PKT) calendar days (created_at is stored in UTC)
        $uk = new DateTime('now', new DateTimeZone(self::DAY_TZ));
        $today = $uk->format('Y-m-d');
        $range = null;
        if ($date_range === 'today') {
            $range = [$today, null];
        } elseif ($date_range === 'yesterday') {
            $y = (clone $uk)->modify('-1 day')->format('Y-m-d');
            $range = [$y, $today];
        } elseif ($date_range === '7days') {
            $where[] = $wpdb->prepare("created_at >= %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS));
        } elseif ($date_range === '30days') {
            $where[] = $wpdb->prepare("created_at >= %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS));
        } elseif ($date_range === 'this_month') {
            $range = [$uk->format('Y-m-01'), null];
        } elseif ($date_range === 'last_month') {
            $range = [(clone $uk)->modify('first day of last month')->format('Y-m-d'), $uk->format('Y-m-01')];
        } elseif ($start_date || $end_date) {
            if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                $where[] = $wpdb->prepare("created_at >= %s", self::uk_to_utc($start_date));
            }
            if ($end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                $next = (new DateTime($end_date))->modify('+1 day')->format('Y-m-d');
                $where[] = $wpdb->prepare("created_at < %s", self::uk_to_utc($next));
            }
        }
        if ($range) {
            $where[] = $wpdb->prepare("created_at >= %s", self::uk_to_utc($range[0]));
            if ($range[1]) {
                $where[] = $wpdb->prepare("created_at < %s", self::uk_to_utc($range[1]));
            }
        }

        // Free text search
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                "(lead_details LIKE %s OR phone_or_target LIKE %s OR city LIKE %s OR utm_campaign LIKE %s OR landing_page LIKE %s OR referrer_url LIKE %s OR user_ip LIKE %s OR search_term LIKE %s)",
                $search_like, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like
            );
        }

        return [
            'where_sql'  => implode(' AND ', $where),
            'sort_order' => $sort_order
        ];
    }

    public static function handle_csv_export() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('spc_export_csv_nonce');

        global $wpdb;
        $table = self::get_table_name();
        $clauses = self::build_query_clauses($_GET);

        $sql = "SELECT * FROM $table WHERE {$clauses['where_sql']} ORDER BY created_at {$clauses['sort_order']}, id {$clauses['sort_order']}";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=smart-property-checks-leads-' . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) {
                // Stop spreadsheet formula injection from visitor-supplied text (=, +, -, @)
                foreach ($r as $k => $v) {
                    if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                        $r[$k] = "'" . $v;
                    }
                }
                fputcsv($out, $r);
            }
        } else {
            fputcsv($out, ['No leads recorded matching criteria']);
        }
        fclose($out);
        exit;
    }

    public static function handle_lead_delete() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = intval($_GET['lead_id'] ?? 0);
        check_admin_referer('spc_delete_lead_' . $id);

        if ($id > 0) {
            global $wpdb;
            $wpdb->delete(self::get_table_name(), ['id' => $id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=spc-lead-tracker&deleted=1'));
        exit;
    }

    public static function handle_bulk_delete() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('spc_bulk_delete_leads_nonce');

        $ids = isset($_POST['lead_ids']) ? array_map('intval', (array)$_POST['lead_ids']) : [];
        $ids = array_filter($ids, function($id) { return $id > 0; });

        if (!empty($ids)) {
            global $wpdb;
            $table = self::get_table_name();
            $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($id_placeholders)", ...$ids));
            wp_safe_redirect(admin_url('admin.php?page=spc-lead-tracker&deleted_count=' . count($ids)));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=spc-lead-tracker'));
        exit;
    }

    public static function render_admin_dashboard() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = self::get_table_name();

        // Build query using helper
        $clauses = self::build_query_clauses($_GET);
        $sort = $clauses['sort_order'];

        // Pagination setup
        $per_page_options = [5, 10, 20, 50, 100];
        $per_page = intval($_GET['per_page'] ?? 10);
        if (!in_array($per_page, $per_page_options)) $per_page = 10;
        
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $total_records = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE {$clauses['where_sql']}");
        $total_pages = max(1, (int) ceil($total_records / $per_page));
        if ($paged > $total_pages) $paged = $total_pages;
        $offset = ($paged - 1) * $per_page;

        // Paginated query
        $leads = $wpdb->get_results(
            "SELECT * FROM $table WHERE {$clauses['where_sql']} ORDER BY created_at $sort, id $sort LIMIT $per_page OFFSET $offset",
            ARRAY_A
        );

        // Filter parameters for form state
        $f_type       = sanitize_text_field($_GET['lead_type'] ?? '');
        $f_chan       = sanitize_text_field($_GET['channel'] ?? '');
        $f_dev        = sanitize_text_field($_GET['device'] ?? '');
        $f_date_range = sanitize_text_field($_GET['date_range'] ?? '');
        $f_start      = sanitize_text_field($_GET['start_date'] ?? '');
        $f_end        = sanitize_text_field($_GET['end_date'] ?? '');
        $f_search     = sanitize_text_field($_GET['s'] ?? '');
        $f_sort       = strtolower(sanitize_text_field($_GET['sort_order'] ?? 'desc'));

        // KPI totals follow the current filters (previously always all-time, which did not match the table)
        $w = $clauses['where_sql'];
        $total_calls = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'call_click'");
        $ads_calls   = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'call_click' AND channel LIKE 'Google Ads%'");
        $seo_calls   = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'call_click' AND channel LIKE 'SEO%'");
        $ai_calls    = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'call_click' AND channel IN ('ChatGPT','Perplexity','Claude','Gemini','Copilot')");
        $total_forms = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'form_submission'");
        $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND lead_type = 'order_checkout'");
        $flagged     = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND ip_flags <> ''");

        $mob_leads   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND device_type = 'Mobile'");
        $desk_leads  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND device_type = 'Desktop'");
        $tab_leads   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $w AND device_type = 'Tablet'");
        $total_all   = max(1, $mob_leads + $desk_leads + $tab_leads);
        $mob_pct     = round(($mob_leads / $total_all) * 100);
        $desk_pct    = round(($desk_leads / $total_all) * 100);

        $uk_now = new DateTime('now', new DateTimeZone(self::DAY_TZ));
        $this_month_label = $uk_now->format('F');
        $last_month_label = (clone $uk_now)->modify('first day of last month')->format('F');

        $export_args = array_merge($_GET, ['action' => 'spc_export_leads_csv']);
        $export_url  = wp_nonce_url(add_query_arg($export_args, admin_url('admin-post.php')), 'spc_export_csv_nonce');

        ?>
        <div class="wrap" style="max-width:1380px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            
            <?php if (isset($_GET['deleted_count'])): ?>
                <div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:12px 18px;border-radius:8px;margin-top:16px;margin-bottom:20px;font-size:13.5px;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span>Successfully deleted <?php echo intval($_GET['deleted_count']); ?> selected record(s).</span>
                    <a href="<?php echo esc_url(remove_query_arg('deleted_count')); ?>" style="color:#065F46;text-decoration:none;font-weight:700;font-size:16px;">&times;</a>
                </div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:12px 18px;border-radius:8px;margin-top:16px;margin-bottom:20px;font-size:13.5px;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span>Record deleted successfully.</span>
                    <a href="<?php echo esc_url(remove_query_arg('deleted')); ?>" style="color:#065F46;text-decoration:none;font-weight:700;font-size:16px;">&times;</a>
                </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin:22px 0 25px;flex-wrap:wrap;gap:15px;">
                <div>
                    <h1 style="font-size:26px;font-weight:700;color:#0F172A;margin:0 0 6px;">
                        Smart Leads & Call Tracker
                    </h1>
                    <p style="margin:0;color:#64748B;font-size:14px;">
                        Real-time first-party leads sorted by <b>Latest First</b>. Complete channel attribution for Google Ads, Local SEO, and AI (ChatGPT).
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <!-- Live update status -->
                    <span id="spc-status-badge" style="display:inline-flex;align-items:center;gap:6px;background:#F1F5F9;color:#475569;border:1px solid #E2E8F0;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
                        <span id="spc-status-dot" style="width:8px;height:8px;border-radius:50%;background:#10B981;display:inline-block;transition:background 0.3s ease;"></span>
                        <span id="spc-last-updated">Live &bull; Updated just now</span>
                    </span>

                    <!-- Auto-Refresh Selector -->
                    <select id="spc-auto-refresh-select" onchange="spcChangeAutoRefresh(this.value)" style="height:36px;font-size:12.5px;font-weight:600;color:#334155;border:1px solid #CBD5E1;border-radius:6px;padding:0 10px;background:#fff;cursor:pointer;">
                        <option value="0">Auto-refresh: Off</option>
                        <option value="15">Auto-refresh: 15s</option>
                        <option value="30">Auto-refresh: 30s</option>
                        <option value="60">Auto-refresh: 60s</option>
                    </select>

                    <!-- Instant Refresh Button -->
                    <button type="button" id="spc-refresh-btn" onclick="spcRefreshDashboard(true)" class="button" style="background:#0F172A;color:#FFFFFF;border-color:#0F172A;padding:7px 16px;height:36px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:7px;cursor:pointer;border-radius:6px;box-shadow:0 1px 2px rgba(15,23,42,0.1);transition:all 0.2s ease;">
                        <svg id="spc-refresh-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.5s ease;">
                            <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                        </svg>
                        <span id="spc-refresh-text">Refresh Data</span>
                    </button>

                    <!-- Download Filtered CSV -->
                    <a href="<?php echo esc_url($export_url); ?>" class="button" style="background:#FFFFFF;border:1px solid #CBD5E1;color:#0F172A;padding:7px 16px;height:36px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;border-radius:6px;">
                        Download Filtered CSV
                    </a>
                </div>
            </div>

            <!-- KPI Cards -->
            <div id="spc-kpi-wrap">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:16px;margin-bottom:25px;">
                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px;font-weight:600;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">Phone Call Clicks <span style="text-transform:none;font-weight:500;">(<?php echo $f_date_range || $f_start || $f_end ? 'filtered' : 'all time'; ?>)</span></div>
                    <div style="font-size:30px;font-weight:800;color:#0F172A;margin:6px 0 4px;"><?php echo intval($total_calls); ?></div>
                    <div style="font-size:12px;color:#059669;font-weight:600;">
                        Ads: <b><?php echo intval($ads_calls); ?></b> | SEO: <b><?php echo intval($seo_calls); ?></b>
                    </div>
                </div>

                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px;font-weight:600;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">Form Quote Leads</div>
                    <div style="font-size:30px;font-weight:800;color:#0F172A;margin:6px 0 4px;"><?php echo intval($total_forms); ?></div>
                    <div style="font-size:12px;color:#64748B;">Quote Form Submissions</div>
                </div>

                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px;font-weight:600;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">Direct Paid Orders</div>
                    <div style="font-size:30px;font-weight:800;color:#0F172A;margin:6px 0 4px;"><?php echo intval($total_orders); ?></div>
                    <div style="font-size:12px;color:#059669;font-weight:600;">Direct 2-click Checkouts</div>
                </div>

                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px;font-weight:600;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">Device Split</div>
                    <div style="font-size:30px;font-weight:800;color:#0F172A;margin:6px 0 4px;"><?php echo $mob_pct; ?>% <span style="font-size:14px;color:#64748B;font-weight:500;">Mob</span></div>
                    <div style="font-size:12px;color:#64748B;font-weight:500;">Desktop: <b><?php echo $desk_pct; ?>%</b> (<?php echo intval($desk_leads); ?>)<?php if ($tab_leads): ?> · Tablet: <?php echo intval($tab_leads); ?><?php endif; ?></div>
                </div>

                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px;font-weight:600;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;">Filtered Results</div>
                    <div style="font-size:30px;font-weight:800;color:#2563EB;margin:6px 0 4px;"><?php echo intval($total_records); ?></div>
                    <div style="font-size:12px;color:#64748B;">Matching current filters<?php if ($flagged): ?> · <span style="color:#B91C1C;font-weight:600;"><?php echo intval($flagged); ?> flagged</span><?php endif; ?></div>
                </div>
            </div>
            </div>

            <!-- Advanced Filters Form -->
            <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:18px;margin-bottom:22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <form method="get" action="" style="margin:0;">
                    <input type="hidden" name="page" value="spc-lead-tracker" />

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:12px;align-items:end;">
                        <!-- Date Preset -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Date Range:</label>
                            <select name="date_range" id="spc_date_range" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;" onchange="toggleCustomDates(this.value)">
                                <option value="" <?php selected($f_date_range, ''); ?>>All Time</option>
                                <option value="today" <?php selected($f_date_range, 'today'); ?>>Today</option>
                                <option value="yesterday" <?php selected($f_date_range, 'yesterday'); ?>>Yesterday</option>
                                <option value="7days" <?php selected($f_date_range, '7days'); ?>>Last 7 Days</option>
                                <option value="30days" <?php selected($f_date_range, '30days'); ?>>Last 30 Days</option>
                                <option value="this_month" <?php selected($f_date_range, 'this_month'); ?>>This Month (<?php echo esc_html($this_month_label); ?>)</option>
                                <option value="last_month" <?php selected($f_date_range, 'last_month'); ?>>Last Month (<?php echo esc_html($last_month_label); ?>)</option>
                                <option value="custom" <?php selected($f_date_range, 'custom'); ?>>Custom Range...</option>
                            </select>
                        </div>

                        <!-- Custom Start Date -->
                        <div id="spc_start_box" style="<?php echo ($f_date_range === 'custom' || $f_start) ? '' : 'display:none;'; ?>">
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">From Date:</label>
                            <input type="date" name="start_date" value="<?php echo esc_attr($f_start); ?>" style="width:100%;font-size:13px;padding:4px 8px;border-radius:6px;" />
                        </div>

                        <!-- Custom End Date -->
                        <div id="spc_end_box" style="<?php echo ($f_date_range === 'custom' || $f_end) ? '' : 'display:none;'; ?>">
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">To Date:</label>
                            <input type="date" name="end_date" value="<?php echo esc_attr($f_end); ?>" style="width:100%;font-size:13px;padding:4px 8px;border-radius:6px;" />
                        </div>

                        <!-- Action Type -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Lead Action:</label>
                            <select name="lead_type" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;">
                                <option value="">All Actions</option>
                                <option value="call_click" <?php selected($f_type, 'call_click'); ?>>Phone Calls</option>
                                <option value="form_submission" <?php selected($f_type, 'form_submission'); ?>>Form Quotes</option>
                                <option value="order_checkout" <?php selected($f_type, 'order_checkout'); ?>>Direct Orders</option>
                                <option value="whatsapp_click" <?php selected($f_type, 'whatsapp_click'); ?>>WhatsApp</option>
                            </select>
                        </div>

                        <!-- Channel -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Channel / Source:</label>
                            <select name="channel" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;">
                                <option value="">All Channels</option>
                                <option value="Google Ads" <?php selected($f_chan, 'Google Ads'); ?>>Google Ads</option>
                                <option value="Google Ads (unconfirmed)" <?php selected($f_chan, 'Google Ads (unconfirmed)'); ?>>Google Ads (unconfirmed)</option>
                                <option value="SEO (Google)" <?php selected($f_chan, 'SEO (Google)'); ?>>SEO (Google)</option>
                                <option value="SEO (Bing)" <?php selected($f_chan, 'SEO (Bing)'); ?>>SEO (Bing)</option>
                                <option value="ChatGPT" <?php selected($f_chan, 'ChatGPT'); ?>>ChatGPT</option>
                                <option value="Perplexity" <?php selected($f_chan, 'Perplexity'); ?>>Perplexity</option>
                                <option value="Direct" <?php selected($f_chan, 'Direct'); ?>>Direct</option>
                            </select>
                        </div>

                        <!-- Device -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Device Type:</label>
                            <select name="device" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;">
                                <option value="">All Devices</option>
                                <option value="Mobile" <?php selected($f_dev, 'Mobile'); ?>>Mobile</option>
                                <option value="Desktop" <?php selected($f_dev, 'Desktop'); ?>>Desktop</option>
                                <option value="Tablet" <?php selected($f_dev, 'Tablet'); ?>>Tablet</option>
                            </select>
                        </div>

                        <!-- Sort Order -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Sort By:</label>
                            <select name="sort_order" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;">
                                <option value="desc" <?php selected($f_sort, 'desc'); ?>>Latest First (Newest)</option>
                                <option value="asc" <?php selected($f_sort, 'asc'); ?>>Oldest First</option>
                            </select>
                        </div>

                        <!-- Per Page -->
                        <div>
                            <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">Per Page:</label>
                            <select name="per_page" style="width:100%;font-size:13px;padding:5px 8px;border-radius:6px;" onchange="this.form.submit()">
                                <option value="5" <?php selected($per_page, 5); ?>>5 per page</option>
                                <option value="10" <?php selected($per_page, 10); ?>>10 per page</option>
                                <option value="20" <?php selected($per_page, 20); ?>>20 per page</option>
                                <option value="50" <?php selected($per_page, 50); ?>>50 per page</option>
                                <option value="100" <?php selected($per_page, 100); ?>>100 per page</option>
                            </select>
                        </div>
                    </div>

                    <!-- Search Row & Action Buttons -->
                    <div style="display:flex;gap:12px;margin-top:14px;align-items:center;flex-wrap:wrap;">
                        <div style="flex:1;min-width:240px;">
                            <input type="text" name="s" value="<?php echo esc_attr($f_search); ?>" placeholder="Search by phone, customer name, city, campaign, keyword, URL, or IP..." style="width:100%;font-size:13px;padding:6px 12px;border-radius:6px;border:1px solid #CBD5E1;" />
                        </div>
                        <button type="submit" class="button button-primary" style="background:#2563EB;border-color:#2563EB;padding:5px 16px;font-weight:600;">
                            Apply Filters
                        </button>
                        <?php if ($f_type || $f_chan || $f_dev || $f_date_range || $f_start || $f_end || $f_search || $f_sort !== 'desc'): ?>
                            <a href="<?php echo admin_url('admin.php?page=spc-lead-tracker'); ?>" class="button" style="color:#DC2626;border-color:#FCA5A5;">
                                Reset All Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Table of Leads -->
            <div id="spc-table-wrap">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="spc_bulk_form" style="margin:0;">
                <input type="hidden" name="action" value="spc_bulk_delete_leads" />
                <?php wp_nonce_field('spc_bulk_delete_leads_nonce'); ?>

                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="padding:14px 20px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <span style="font-weight:700;color:#0F172A;font-size:14px;">
                                Leads & Calls Activity Log
                            </span>
                            <span style="background:#E2E8F0;color:#334155;font-weight:600;font-size:11px;padding:2px 8px;border-radius:12px;">
                                <?php echo $total_records; ?> Total Records
                            </span>
                            <button type="submit" name="bulk_delete" class="button" onclick="return confirmBulkDelete();" style="color:#DC2626;border-color:#FCA5A5;background:#FEF2F2;font-weight:700;padding:2px 12px;font-size:11.5px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                                Delete Selected
                            </button>
                        </div>
                    <div style="display:flex;align-items:center;gap:12px;font-size:12px;color:#64748B;">
                        <span>Sorted by: <b><?php echo $sort === 'DESC' ? 'Latest First' : 'Oldest First'; ?></b></span>
                        <span>•</span>
                        <span style="color:#059669;font-weight:500;"><span style="display:inline-block;width:7px;height:7px;background:#10B981;border-radius:50%;margin-right:4px;"></span>Live Real-Time</span>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table style="table-layout:fixed;width:100%;min-width:1340px;border-collapse:separate;border-spacing:0;font-size:12.5px;text-align:left;">
                        <colgroup>
                            <col style="width:40px;">
                            <col style="width:120px;">
                            <col style="width:115px;">
                            <col style="width:120px;">
                            <col style="width:125px;">
                            <col style="width:130px;">
                            <col style="width:175px;">
                            <col style="width:110px;">
                            <col style="width:190px;">
                            <col style="width:auto;min-width:210px;">
                            <col style="width:75px;">
                        </colgroup>
                        <thead>
                            <tr style="background:#F8FAFC;border-bottom:2px solid #E2E8F0;color:#475569;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">
                                <th style="padding:11px 10px;border-bottom:1px solid #E2E8F0;text-align:center;">
                                    <input type="checkbox" id="spc_check_all" onclick="spcToggleAll(this)" title="Select all records" style="margin:0;cursor:pointer;" />
                                </th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Date & Time</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Event Type</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Channel</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Device & OS</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Location</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Page (Exact & Landing)</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Referrer</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;" title="Google Ads: the keyword passed by the ad. Google SEO: Google does not share the visitor's search, so these are the searches that brought clicks to the landing page around that day (Search Console).">Search / Keyword</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;">Details & Contact</th>
                                <th style="padding:11px 14px;border-bottom:1px solid #E2E8F0;text-align:center;">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leads)): ?>
                                <tr>
                                    <td colspan="11" style="padding:40px;text-align:center;color:#64748B;font-size:13px;">
                                        No leads found matching current filter selection.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $i = 0; foreach ($leads as $lead): $i++; ?>
                                    <?php
                                    // Row background alternating
                                    $bg = ($i % 2 === 0) ? '#FAFAFA' : '#FFFFFF';

                                    // Action badge
                                    $type_badge = '';
                                    if ($lead['lead_type'] === 'call_click') {
                                        $type_badge = '<span style="display:inline-flex;align-items:center;gap:4px;background:#E0F2FE;color:#0369A1;border:1px solid #BAE6FD;padding:3px 9px;border-radius:12px;font-weight:700;font-size:11px;white-space:nowrap;" title="Someone tapped a call button. It does not confirm the call connected.">Call Click</span>';
                                    } elseif ($lead['lead_type'] === 'form_submission') {
                                        $type_badge = '<span style="display:inline-flex;align-items:center;gap:4px;background:#FEF3C7;color:#B45309;border:1px solid #FDE68A;padding:3px 9px;border-radius:12px;font-weight:700;font-size:11px;white-space:nowrap;">Form Quote</span>';
                                    } elseif ($lead['lead_type'] === 'order_checkout') {
                                        $type_badge = '<span style="display:inline-flex;align-items:center;gap:4px;background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;padding:3px 9px;border-radius:12px;font-weight:700;font-size:11px;white-space:nowrap;">Direct Order</span>';
                                    } else {
                                        $type_badge = '<span style="display:inline-flex;align-items:center;gap:4px;background:#F1F5F9;color:#475569;border:1px solid #E2E8F0;padding:3px 9px;border-radius:12px;font-weight:600;font-size:11px;white-space:nowrap;">' . esc_html($lead['lead_type']) . '</span>';
                                    }

                                    // Channel badge
                                    $chan_badge = '';
                                    $lp_path = rtrim((string) wp_parse_url($lead['landing_page'] ?? '', PHP_URL_PATH), '/');
                                    $verified_ads = in_array($lp_path, array_map(function($x){ return rtrim($x, '/'); }, self::ads_landing_paths()), true);
                                    if ($lead['channel'] === 'Google Ads' && $verified_ads) {
                                        $chan_badge = '<span title="Google Ads click on a verified Ads page" style="display:inline-block;background:#FFFBEB;color:#92400E;border:1px solid #FCD34D;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;white-space:nowrap;">Google Ads</span>';
                                    } elseif (strpos($lead['channel'], 'Ads') !== false) {
                                        $chan_badge = '<span title="' . esc_attr($lead['channel']) . ' (page not verified as an Ads page: could be Ads or SEO)" style="display:inline-block;background:#EEF2FF;color:#3730A3;border:1px solid #C7D2FE;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;white-space:nowrap;">Ads/SEO</span>';
                                    } elseif (strpos($lead['channel'], 'ChatGPT') !== false) {
                                        $chan_badge = '<span style="display:inline-block;background:#FAF5FF;color:#6B21A8;border:1px solid #E9D5FF;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;white-space:nowrap;">ChatGPT (AI)</span>';
                                    } elseif (strpos($lead['channel'], 'SEO') === 0) {
                                        $chan_badge = '<span title="' . esc_attr($lead['channel']) . '" style="display:inline-block;background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;white-space:nowrap;">SEO</span>';
                                    } else {
                                        $chan_badge = '<span style="display:inline-block;background:#F1F5F9;color:#334155;border:1px solid #E2E8F0;padding:2px 8px;border-radius:6px;font-weight:600;font-size:11px;white-space:nowrap;">' . esc_html($lead['channel']) . '</span>';
                                    }

                                    $dev_icon = '';
                                    $landing_slug = wp_parse_url($lead['landing_page'], PHP_URL_PATH) ?: '/';
                                    $ref_host = $lead['referrer_url'] ? (wp_parse_url($lead['referrer_url'], PHP_URL_HOST) ?: 'Referrer') : '';

                                    // Format Details cleanly into lines
                                    $details_raw = $lead['lead_details'] ?? '';
                                    $detail_lines = [];
                                    if ($details_raw) {
                                        foreach (explode('|', $details_raw) as $part) {
                                            $part = trim($part);
                                            if (!empty($part)) $detail_lines[] = esc_html($part);
                                        }
                                    }
                                    ?>
                                    <tr style="background:<?php echo $bg; ?>;border-bottom:1px solid #F1F5F9;transition:background 0.15s;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='<?php echo $bg; ?>'">
                                        <!-- Checkbox -->
                                        <td style="padding:10px;vertical-align:middle;text-align:center;">
                                            <input type="checkbox" name="lead_ids[]" value="<?php echo intval($lead['id']); ?>" class="spc-lead-cb" style="margin:0;cursor:pointer;" />
                                        </td>

                                        <!-- Date & Time (12-Hour AM/PM UK & PKT) -->
                                        <?php
                                        // Stored created_at is UTC/GMT in MySQL
                                        $created_ts = strtotime($lead['created_at']);
                                        $dt_utc = new DateTime($lead['created_at'], new DateTimeZone('UTC'));
                                        
                                        // UK Time (London)
                                        $dt_uk = clone $dt_utc;
                                        $dt_uk->setTimezone(new DateTimeZone('Europe/London'));
                                        $uk_date = $dt_uk->format('d M Y');
                                        $uk_time = $dt_uk->format('h:i:s A');

                                        // Pakistan Time (Karachi / PKT)
                                        $dt_pkt = clone $dt_utc;
                                        $dt_pkt->setTimezone(new DateTimeZone('Asia/Karachi'));
                                        $pkt_time = $dt_pkt->format('h:i:s A');
                                        ?>
                                        <td style="padding:10px 14px;vertical-align:top;white-space:nowrap;">
                                            <div style="font-weight:700;color:#0F172A;font-size:12.5px;">
                                                <?php echo $dt_pkt->format('d M Y'); ?> <span style="font-size:9.5px;color:#94A3B8;font-weight:600;">(PKT day)</span>
                                            </div>
                                            <div style="font-size:11.5px;color:#0284C7;font-weight:700;margin-top:2px;" title="Client Official Business Time (London)">
                                                <?php echo $uk_time; ?> <span style="font-size:10px;color:#64748B;font-weight:600;">(UK)</span>
                                            </div>
                                            <div style="font-size:10.5px;color:#64748B;margin-top:2px;font-weight:500;" title="Your Local Pakistan Time (PKT)">
                                                <?php echo $pkt_time; ?> <span style="font-size:9.5px;color:#94A3B8;">(PKT)</span>
                                            </div>
                                            <?php if (self::is_out_of_hours($lead['created_at'])): ?>
                                                <div style="margin-top:4px;"><span title="Outside office hours (Mon-Sat 08:00-18:00 UK)" style="display:inline-block;background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:700;">After hours</span></div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Action -->
                                        <td style="padding:10px 14px;vertical-align:top;">
                                            <?php echo $type_badge; ?>
                                        </td>

                                        <!-- Source Channel -->
                                        <td style="padding:10px 14px;vertical-align:top;">
                                            <?php echo $chan_badge; ?>
                                            <?php $camp = self::campaign_display($lead); ?>
                                            <?php if (!empty($camp)): ?>
                                                <div style="font-size:10.5px;color:#64748B;margin-top:3px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="Google Ads campaign ID: <?php echo esc_attr($camp); ?>">
                                                    Camp: <b><?php echo esc_html($camp); ?></b><?php $cid = trim((string) (($lead['gad_campaignid'] ?? '') ?: ($lead['utm_campaign'] ?? ''))); if ($cid !== ''): ?><div style="font-size:10.5px;color:#94A3B8;margin-top:1px;">ID: <?php echo esc_html($cid); ?></div><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($lead['attr_time'])):
                                                $at = new DateTime($lead['attr_time'], new DateTimeZone('UTC'));
                                                $at->setTimezone(new DateTimeZone(self::UK_TZ));
                                                $mins = (int) round((strtotime($lead['created_at'] . ' UTC') - strtotime($lead['attr_time'] . ' UTC')) / 60);
                                                $ago = $mins < 60 ? $mins . ' min before' : ($mins < 2880 ? round($mins / 60) . ' h before' : round($mins / 1440) . ' days before');
                                            ?>
                                                <div style="font-size:10.5px;color:#64748B;margin-top:3px;white-space:nowrap;" title="When this visitor arrived from <?php echo esc_attr($lead['channel']); ?> (UK time)">
                                                    Arrived: <b><?php echo esc_html($at->format('d M H:i')); ?></b><br><?php echo esc_html($ago); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Device & OS -->
                                        <td style="padding:10px 14px;vertical-align:top;color:#1E293B;">
                                            <div style="font-weight:700;font-size:12.5px;">
                                                <?php echo esc_html($lead['device_type']); ?>
                                            </div>
                                            <div style="font-size:11px;color:#64748B;margin-top:2px;">
                                                <?php echo esc_html($lead['os'] ?: 'OS'); ?> · <?php echo esc_html($lead['browser'] ?: 'Browser'); ?>
                                            </div>
                                        </td>

                                        <!-- Location -->
                                        <td style="padding:10px 14px;vertical-align:top;color:#1E293B;">
                                            <div style="font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo esc_attr(trim($lead['city'] . ', ' . $lead['country'], ', ')); ?>">
                                                <?php echo esc_html($lead['city'] ?: ($lead['country'] ?: 'Unknown')); ?>
                                            </div>
                                            <?php if (!empty($lead['country']) && $lead['country'] !== 'United Kingdom'): ?>
                                                <div style="font-size:10.5px;color:#64748B;"><?php echo esc_html($lead['country']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size:10.5px;color:#94A3B8;margin-top:2px;">
                                                <?php echo esc_html($lead['user_ip']); ?>
                                            </div>
                                            <?php
                                            $flag_labels = ['outside_uk' => 'Outside UK', 'vpn_proxy' => 'VPN / proxy', 'datacenter' => 'Data centre', 'private_ip' => 'Private IP'];
                                            foreach (array_filter(explode(',', (string) ($lead['ip_flags'] ?? ''))) as $fl):
                                                if (!isset($flag_labels[$fl])) continue; ?>
                                                <span title="IP check: treat with caution - may be a VPN, bot or out-of-area visitor" style="display:inline-block;margin:3px 3px 0 0;background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:700;"><?php echo esc_html($flag_labels[$fl]); ?></span>
                                            <?php endforeach; ?>
                                        </td>

                                        <!-- Page (Exact & Landing) -->
                                        <td style="padding:10px 14px;vertical-align:top;">
                                            <?php
                                            $cur_url = !empty($lead['current_page']) ? $lead['current_page'] : $lead['landing_page'];
                                            $cur_slug = wp_parse_url($cur_url, PHP_URL_PATH) ?: '/';
                                            $land_slug = wp_parse_url($lead['landing_page'], PHP_URL_PATH) ?: '/';
                                            ?>
                                            <a href="<?php echo esc_url($cur_url); ?>" target="_blank" title="Exact Page of Action: <?php echo esc_attr($cur_url); ?>" style="display:inline-block;max-width:185px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0284C7;text-decoration:none;font-weight:700;font-size:12px;background:#F0F9FF;padding:2px 7px;border-radius:4px;border:1px solid #BAE6FD;">
                                                <?php echo esc_html($cur_slug); ?>
                                            </a>
                                            <?php if ($land_slug !== $cur_slug && $land_slug !== '/'): ?>
                                                <div style="font-size:10.5px;color:#64748B;margin-top:3px;max-width:185px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="Initial landing: <?php echo esc_attr($lead['landing_page']); ?>">
                                                    Landed: <a href="<?php echo esc_url($lead['landing_page']); ?>" target="_blank" style="color:#64748B;text-decoration:none;"><?php echo esc_html($land_slug); ?></a>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Referrer -->
                                        <td style="padding:10px 14px;vertical-align:top;">
                                            <?php if ($ref_host): ?>
                                                <a href="<?php echo esc_url($lead['referrer_url']); ?>" target="_blank" title="<?php echo esc_attr($lead['referrer_url']); ?>" style="display:inline-block;max-width:125px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#475569;text-decoration:none;font-size:11px;font-weight:500;background:#F1F5F9;padding:2px 6px;border-radius:4px;border:1px solid #E2E8F0;">
                                                    <?php echo esc_html($ref_host); ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#94A3B8;">Direct / None</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Search / Keyword -->
                                        <td style="padding:10px 14px;vertical-align:top;font-size:11.5px;line-height:1.45;">
                                            <?php if (!empty($lead['search_term'])): ?>
                                                <div title="Keyword passed by Google Ads (utm_term)" style="font-weight:700;color:#92400E;">&ldquo;<?php echo esc_html($lead['search_term']); ?>&rdquo;</div>
                                                <div style="font-size:10px;color:#94A3B8;">Ad keyword</div>
                                            <?php elseif (strpos((string) $lead['channel'], 'SEO') === 0):
                                                $lq = self::likely_queries($lead['landing_page'], $lead['created_at']);
                                                if (!empty($lq['terms'])): ?>
                                                    <?php foreach ($lq['terms'] as $q): ?>
                                                        <div title="<?php echo esc_attr(intval($q[1]) . ' clicks, ' . intval($q[2]) . ' impressions, avg position ' . $q[3]); ?>" style="color:#166534;font-weight:600;">&ldquo;<?php echo esc_html($q[0]); ?>&rdquo; <span style="color:#94A3B8;font-weight:500;">#<?php echo esc_html(round((float) $q[3])); ?></span></div>
                                                    <?php endforeach; ?>
                                                    <div style="font-size:10px;color:#94A3B8;"><?php echo $lq['scope'] === 'day' ? 'Likely searches (that day)' : 'Page\'s top searches (28 days)'; ?></div>
                                                <?php else: ?>
                                                    <span style="font-size:11px;color:#94A3B8;" title="Search Console data arrives 2-3 days late">Waiting for Search Console data</span>
                                                <?php endif; ?>
                                            <?php elseif (strpos((string) $lead['channel'], 'Ads') !== false): ?>
                                                <span style="font-size:11px;color:#94A3B8;" title="Add utm_term={keyword} to the Google Ads Final URL suffix">Keyword not passed</span>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#CBD5E1;">&mdash;</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Details & Contact -->
                                        <td style="padding:10px 14px;vertical-align:top;font-size:12px;line-height:1.45;">
                                            <?php if (!empty($lead['phone_or_target'])): ?>
                                                <div style="font-weight:700;color:#0F172A;font-size:12.5px;margin-bottom:2px;">
                                                    <?php echo esc_html($lead['phone_or_target']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($detail_lines)): ?>
                                                <div style="color:#475569;font-size:11.5px;">
                                                    <?php foreach ($detail_lines as $dl): ?>
                                                        <div>• <?php echo $dl; ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Action / Delete -->
                                        <td style="padding:10px 14px;vertical-align:middle;text-align:center;">
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=spc_delete_lead&lead_id=' . $lead['id']), 'spc_delete_lead_' . $lead['id']); ?>" 
                                               onclick="return confirm('Are you sure you want to delete lead #<?php echo $lead['id']; ?>? This cannot be undone.');" 
                                               style="display:inline-block;background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;padding:4px 9px;border-radius:4px;font-size:11px;font-weight:700;text-decoration:none;transition:background 0.15s;"
                                               onmouseover="this.style.background='#FCA5A5';this.style.color='#991B1B';"
                                               onmouseout="this.style.background='#FEE2E2';this.style.color='#DC2626';">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <?php if ($total_records > 0): ?>
                    <div style="padding:12px 18px;background:#F8FAFC;border-top:1px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;font-size:12.5px;color:#475569;">
                        <div>
                            Showing <b><?php echo min($total_records, $offset + 1); ?></b> to <b><?php echo min($total_records, $offset + count($leads)); ?></b> of <b><?php echo $total_records; ?></b> records
                        </div>
                        <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
                            <?php if ($paged > 1): ?>
                                <a href="<?php echo esc_url(add_query_arg(['paged' => 1, 'per_page' => $per_page])); ?>" class="button button-small" style="font-size:11.5px;font-weight:600;">&laquo; First</a>
                                <a href="<?php echo esc_url(add_query_arg(['paged' => $paged - 1, 'per_page' => $per_page])); ?>" class="button button-small" style="font-size:11.5px;font-weight:600;">&lsaquo; Prev</a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $paged - 2);
                            $end_page = min($total_pages, $paged + 2);
                            for ($pg = $start_page; $pg <= $end_page; $pg++):
                                $is_active = ($pg === $paged);
                            ?>
                                <a href="<?php echo esc_url(add_query_arg(['paged' => $pg, 'per_page' => $per_page])); ?>" class="button button-small <?php echo $is_active ? 'button-primary' : ''; ?>" style="font-size:11.5px;font-weight:<?php echo $is_active ? '700' : '500'; ?>;min-width:28px;text-align:center;<?php echo $is_active ? 'background:#0F172A;border-color:#0F172A;' : ''; ?>">
                                    <?php echo $pg; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($paged < $total_pages): ?>
                                <a href="<?php echo esc_url(add_query_arg(['paged' => $paged + 1, 'per_page' => $per_page])); ?>" class="button button-small" style="font-size:11.5px;font-weight:600;">Next &rsaquo;</a>
                                <a href="<?php echo esc_url(add_query_arg(['paged' => $total_pages, 'per_page' => $per_page])); ?>" class="button button-small" style="font-size:11.5px;font-weight:600;">Last &raquo;</a>
                            <?php endif; ?>

                            <span style="margin-left:6px;color:#64748B;font-size:11.5px;">
                                (Page <b><?php echo $paged; ?></b> of <b><?php echo $total_pages; ?></b>)
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
            </form>
            </div>
        </div>

        <style>
        @keyframes spc-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .spc-rotating {
            animation: spc-spin 0.75s linear infinite !important;
        }
        </style>

        <script>
        function toggleCustomDates(val) {
            var s = document.getElementById('spc_start_box');
            var e = document.getElementById('spc_end_box');
            if (val === 'custom') {
                s.style.display = 'block';
                e.style.display = 'block';
            } else {
                s.style.display = 'none';
                e.style.display = 'none';
            }
        }

        function spcToggleAll(master) {
            var cbs = document.querySelectorAll('.spc-lead-cb');
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = master.checked;
            }
        }

        function confirmBulkDelete() {
            var checked = document.querySelectorAll('.spc-lead-cb:checked');
            if (checked.length === 0) {
                alert('Please select at least one record using the checkboxes to delete.');
                return false;
            }
            return confirm('Are you sure you want to delete ' + checked.length + ' selected record(s)? This action cannot be undone.');
        }

        // Live in-place dashboard refresh without full page reload
        var spcAutoRefreshTimer = null;

        function spcRefreshDashboard(isManual) {
            var btn = document.getElementById('spc-refresh-btn');
            var icon = document.getElementById('spc-refresh-icon');
            var text = document.getElementById('spc-refresh-text');
            var dot = document.getElementById('spc-status-dot');
            var updatedText = document.getElementById('spc-last-updated');

            if (btn) btn.disabled = true;
            if (icon) icon.classList.add('spc-rotating');
            if (text) text.textContent = 'Updating...';
            if (dot) dot.style.background = '#F59E0B';

            // Preserve current query parameters and filters
            var fetchUrl = new URL(window.location.href);
            var filterForm = document.querySelector('form[action=""]') || document.querySelector('form[method="get"]');
            if (filterForm) {
                var fd = new FormData(filterForm);
                for (var pair of fd.entries()) {
                    if (pair[1] !== '') {
                        fetchUrl.searchParams.set(pair[0], pair[1]);
                    } else {
                        fetchUrl.searchParams.delete(pair[0]);
                    }
                }
            }
            fetchUrl.searchParams.set('_spc_refresh', Date.now());

            fetch(fetchUrl.toString(), {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');

                var newKpi = doc.getElementById('spc-kpi-wrap');
                var curKpi = document.getElementById('spc-kpi-wrap');
                if (newKpi && curKpi) {
                    curKpi.innerHTML = newKpi.innerHTML;
                }

                var newTable = doc.getElementById('spc-table-wrap');
                var curTable = document.getElementById('spc-table-wrap');
                if (newTable && curTable) {
                    curTable.innerHTML = newTable.innerHTML;
                }

                var now = new Date();
                var timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                if (updatedText) updatedText.textContent = 'Live • Updated ' + timeStr;
                if (dot) dot.style.background = '#10B981';

                if (text) text.textContent = 'Updated!';
                setTimeout(function() {
                    if (text) text.textContent = 'Refresh Data';
                }, 1200);
            })
            .catch(function(err) {
                console.error('SPC Tracker Refresh Error:', err);
                if (updatedText) updatedText.textContent = 'Update error';
                if (dot) dot.style.background = '#EF4444';
                if (text) text.textContent = 'Error';
                setTimeout(function() {
                    if (text) text.textContent = 'Refresh Data';
                    if (dot) dot.style.background = '#10B981';
                }, 2000);
            })
            .finally(function() {
                if (btn) btn.disabled = false;
                if (icon) icon.classList.remove('spc-rotating');
            });
        }

        function spcChangeAutoRefresh(seconds) {
            if (spcAutoRefreshTimer) {
                clearInterval(spcAutoRefreshTimer);
                spcAutoRefreshTimer = null;
            }
            var sec = parseInt(seconds, 10);
            if (sec > 0) {
                try { localStorage.setItem('spc_auto_refresh_sec', sec); } catch(e) {}
                spcAutoRefreshTimer = setInterval(function() {
                    spcRefreshDashboard(false);
                }, sec * 1000);
            } else {
                try { localStorage.removeItem('spc_auto_refresh_sec'); } catch(e) {}
            }
        }

        // Restore saved preference on load
        (function() {
            try {
                var savedSec = localStorage.getItem('spc_auto_refresh_sec');
                var sel = document.getElementById('spc-auto-refresh-select');
                if (savedSec && sel) {
                    sel.value = savedSec;
                    spcChangeAutoRefresh(savedSec);
                }
            } catch(e) {}
        })();
        </script>
        <?php
    }
}

// Start
SPC_Lead_Tracker::init();

}