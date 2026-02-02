<?php
/**
 * Plugin Name: LegalPress Push Notifications
 * Plugin URI: https://lawandbeyond.in/
 * Description: Web Push Notifications for WordPress - Send browser notifications to subscribers when new posts are published.
 * Version: 1.2.1
 * Author: Law & Beyond
 * Author URI: https://lawandbeyond.in/
 * License: GPL v2 or later
 * Text Domain: legalpress-push
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package LegalPressPush
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Define plugin constants
define('LEGALPRESS_PUSH_VERSION', '1.2.1');
define('LEGALPRESS_PUSH_DIR', plugin_dir_path(__FILE__));
define('LEGALPRESS_PUSH_URL', plugin_dir_url(__FILE__));
define('LEGALPRESS_PUSH_BASENAME', plugin_basename(__FILE__));

/**
 * Include GitHub Updater for automatic updates
 */
if (is_admin()) {
    require_once LEGALPRESS_PUSH_DIR . 'inc/github-updater.php';
    new LegalPress_Plugin_GitHub_Updater(__FILE__);
}

/**
 * Check if web-push library is available
 */
function legalpress_push_library_available()
{
    // Check if composer autoload exists
    $autoload = LEGALPRESS_PUSH_DIR . 'vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    
    return class_exists('Minishlink\WebPush\WebPush');
}

/**
 * Show admin notice if library is not installed
 */
function legalpress_push_library_notice()
{
    if (legalpress_push_library_available()) {
        return;
    }
    
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_legalpress-push') {
        ?>
        <div class="notice notice-error">
            <p><strong><?php _e('LegalPress Push Notifications:', 'legalpress-push'); ?></strong></p>
            <p><?php _e('The web-push library is required for sending notifications. Please install it:', 'legalpress-push'); ?></p>
            <ol>
                <li><?php _e('Open terminal/command prompt', 'legalpress-push'); ?></li>
                <li><?php printf(__('Navigate to: %s', 'legalpress-push'), '<code>' . esc_html(LEGALPRESS_PUSH_DIR) . '</code>'); ?></li>
                <li><?php _e('Run:', 'legalpress-push'); ?> <code>composer require minishlink/web-push</code></li>
            </ol>
            <p><em><?php _e('Until installed, notifications cannot be sent (but subscriptions will still be collected).', 'legalpress-push'); ?></em></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'legalpress_push_library_notice');

/**
 * Plugin activation hook
 */
function legalpress_push_activate()
{
    // Create database table
    legalpress_push_create_table();
    
    // Generate VAPID keys if library is available
    if (legalpress_push_library_available() && !get_option('legalpress_vapid_public_key')) {
        legalpress_push_generate_vapid_keys();
    }
    
    // Flush rewrite rules for service worker
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'legalpress_push_activate');

/**
 * Plugin deactivation hook
 */
function legalpress_push_deactivate()
{
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'legalpress_push_deactivate');

/**
 * Create push subscriptions table
 */
function legalpress_push_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'push_subscriptions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        endpoint text NOT NULL,
        p256dh text NOT NULL,
        auth text NOT NULL,
        user_agent text,
        ip_address varchar(45),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        last_used datetime DEFAULT CURRENT_TIMESTAMP,
        is_active tinyint(1) DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY endpoint_hash (endpoint(191))
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Generate proper VAPID keys using the web-push library
 * VAPID requires ECDSA P-256 keypair - NOT random bytes!
 */
function legalpress_push_generate_vapid_keys()
{
    if (!legalpress_push_library_available()) {
        return false;
    }

    try {
        // Use the library to generate proper ECDSA P-256 keys
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        
        update_option('legalpress_vapid_public_key', $keys['publicKey']);
        update_option('legalpress_vapid_private_key', $keys['privateKey']);
        
        return true;
    } catch (Exception $e) {
        error_log('[LegalPress Push] VAPID key generation failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enqueue frontend push notification scripts
 */
function legalpress_push_enqueue_scripts()
{
    $vapid_public = get_option('legalpress_vapid_public_key', '');
    
    // Don't load if VAPID keys not set
    if (empty($vapid_public)) {
        return;
    }
    
    wp_enqueue_script(
        'legalpress-push',
        LEGALPRESS_PUSH_URL . 'assets/js/push-notifications.js',
        array(),
        LEGALPRESS_PUSH_VERSION,
        true
    );

    wp_localize_script('legalpress-push', 'legalpressPush', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('legalpress_push_nonce'),
        'vapidPublicKey' => $vapid_public,
        'serviceWorkerUrl' => home_url('/legalpress-sw.js'),
        'siteName' => get_bloginfo('name'),
        'siteIcon' => legalpress_push_get_icon(192),
    ));
}
add_action('wp_enqueue_scripts', 'legalpress_push_enqueue_scripts');

/**
 * Serve Service Worker - hook EARLY to prevent any redirects
 * This must run before WordPress processes any redirects
 */
function legalpress_push_serve_sw()
{
    // Check the raw request URI directly - before WordPress processes it
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($request_uri, PHP_URL_PATH);
    
    // Match /legalpress-sw.js exactly
    if ($path === '/legalpress-sw.js' || basename($path) === 'legalpress-sw.js') {
        // Send headers immediately - no redirects allowed
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        
        $sw_file = LEGALPRESS_PUSH_DIR . 'assets/js/sw.js';
        if (file_exists($sw_file)) {
            readfile($sw_file);
        } else {
            echo '// Service worker file not found';
        }
        exit;
    }
}
// Hook at 'init' with priority 1 - runs BEFORE permalink redirects
add_action('init', 'legalpress_push_serve_sw', 1);

/**
 * AJAX handler: Save push subscription
 */
function legalpress_push_save_subscription()
{
    check_ajax_referer('legalpress_push_nonce', 'nonce');

    $subscription = isset($_POST['subscription']) ? $_POST['subscription'] : null;
    
    if (!$subscription) {
        wp_send_json_error('Invalid subscription data');
        return;
    }

    // Decode if JSON string
    if (is_string($subscription)) {
        $subscription = json_decode(stripslashes($subscription), true);
    }

    if (!isset($subscription['endpoint']) || !isset($subscription['keys'])) {
        wp_send_json_error('Missing subscription fields');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'push_subscriptions';

    // Check if subscription already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE endpoint = %s",
        $subscription['endpoint']
    ));

    if ($existing) {
        // Update existing subscription
        $wpdb->update(
            $table_name,
            array(
                'p256dh' => sanitize_text_field($subscription['keys']['p256dh']),
                'auth' => sanitize_text_field($subscription['keys']['auth']),
                'last_used' => current_time('mysql'),
                'is_active' => 1,
            ),
            array('id' => $existing),
            array('%s', '%s', '%s', '%d'),
            array('%d')
        );
    } else {
        // Insert new subscription
        $wpdb->insert(
            $table_name,
            array(
                'endpoint' => esc_url_raw($subscription['endpoint']),
                'p256dh' => sanitize_text_field($subscription['keys']['p256dh']),
                'auth' => sanitize_text_field($subscription['keys']['auth']),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR']),
                'created_at' => current_time('mysql'),
                'last_used' => current_time('mysql'),
                'is_active' => 1,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );
    }

    wp_send_json_success('Subscription saved successfully');
}
add_action('wp_ajax_legalpress_save_subscription', 'legalpress_push_save_subscription');
add_action('wp_ajax_nopriv_legalpress_save_subscription', 'legalpress_push_save_subscription');

/**
 * AJAX handler: Remove push subscription
 */
function legalpress_push_remove_subscription()
{
    check_ajax_referer('legalpress_push_nonce', 'nonce');

    // Use esc_url_raw to match how the endpoint was stored during save
    $endpoint = isset($_POST['endpoint']) ? esc_url_raw($_POST['endpoint']) : '';
    
    if (!$endpoint || strpos($endpoint, 'https://') !== 0) {
        wp_send_json_error('Invalid endpoint');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'push_subscriptions';

    // Delete the subscription entirely (not just deactivate)
    $deleted = $wpdb->delete(
        $table_name,
        array('endpoint' => $endpoint),
        array('%s')
    );

    if ($deleted !== false) {
        wp_send_json_success(array('message' => 'Subscription removed', 'deleted' => $deleted));
    } else {
        wp_send_json_error('Failed to remove subscription');
    }
}
add_action('wp_ajax_legalpress_remove_subscription', 'legalpress_push_remove_subscription');
add_action('wp_ajax_nopriv_legalpress_remove_subscription', 'legalpress_push_remove_subscription');

/**
 * Get all active push subscriptions
 */
function legalpress_push_get_subscriptions()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'push_subscriptions';

    return $wpdb->get_results(
        "SELECT * FROM $table_name WHERE is_active = 1",
        ARRAY_A
    );
}

/**
 * Get the best available icon for push notifications
 * Priority: Site Icon > Custom Logo > Default fallback
 * 
 * @param int $size Preferred icon size
 * @return string Icon URL
 */
function legalpress_push_get_icon($size = 192)
{
    // 1. Try Site Icon (best for notifications - square format)
    $site_icon = get_site_icon_url($size);
    if ($site_icon) {
        return $site_icon;
    }
    
    // 2. Try Custom Logo
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
        if ($logo_url) {
            return $logo_url;
        }
    }
    
    // 3. Fallback to default icon in plugin (if exists)
    $default_icon = LEGALPRESS_PUSH_DIR . 'assets/images/icon-192.png';
    if (file_exists($default_icon)) {
        return LEGALPRESS_PUSH_URL . 'assets/images/icon-192.png';
    }
    
    // 4. Last resort - favicon
    return home_url('/favicon.ico');
}

/**
 * Send push notification to all subscribers
 * REQUIRES minishlink/web-push library - no fallback!
 * 
 * @param string $title Notification title
 * @param string $body Notification body
 * @param string $url URL to open on click
 * @param string $icon Icon URL
 * @return array Results array with success/failure counts
 */
function legalpress_push_send_notification($title, $body, $url = '', $icon = '')
{
    // CRITICAL: Require the library - no fake fallback!
    if (!legalpress_push_library_available()) {
        return array(
            'success' => 0, 
            'failed' => 0, 
            'message' => __('Web Push library not installed. Run: composer require minishlink/web-push', 'legalpress-push'),
            'error' => true
        );
    }

    $subscriptions = legalpress_push_get_subscriptions();
    
    if (empty($subscriptions)) {
        return array('success' => 0, 'failed' => 0, 'message' => __('No subscribers', 'legalpress-push'));
    }

    $vapid_public = get_option('legalpress_vapid_public_key');
    $vapid_private = get_option('legalpress_vapid_private_key');

    if (empty($vapid_public) || empty($vapid_private)) {
        return array(
            'success' => 0, 
            'failed' => 0, 
            'message' => __('VAPID keys not configured', 'legalpress-push'),
            'error' => true
        );
    }

    $payload = json_encode(array(
        'title' => $title,
        'body' => $body,
        'url' => $url ?: home_url('/'),
        'icon' => $icon ?: legalpress_push_get_icon(192),
        'badge' => legalpress_push_get_icon(72),
        'timestamp' => time() * 1000,
    ));

    $success = 0;
    $failed = 0;

    try {
        $webPush = new \Minishlink\WebPush\WebPush(array(
            'VAPID' => array(
                'subject' => 'mailto:admin@' . parse_url(home_url(), PHP_URL_HOST),
                'publicKey' => $vapid_public,
                'privateKey' => $vapid_private,
            ),
        ));

        // Queue all notifications
        foreach ($subscriptions as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create(array(
                'endpoint' => $sub['endpoint'],
                'keys' => array(
                    'p256dh' => $sub['p256dh'],
                    'auth' => $sub['auth'],
                ),
            ));

            $webPush->queueNotification($subscription, $payload);
        }

        // Send all queued notifications
        global $wpdb;
        $table_name = $wpdb->prefix . 'push_subscriptions';

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $success++;
            } else {
                $failed++;
                
                // Deactivate subscription if endpoint is gone (404, 410)
                $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                if (in_array($statusCode, array(404, 410))) {
                    $wpdb->update(
                        $table_name,
                        array('is_active' => 0),
                        array('endpoint' => $endpoint),
                        array('%d'),
                        array('%s')
                    );
                }
                
                // Log failure reason
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[LegalPress Push] Failed: %s - %s',
                        $endpoint,
                        $report->getReason()
                    ));
                }
            }
        }
    } catch (Exception $e) {
        return array(
            'success' => 0, 
            'failed' => count($subscriptions), 
            'message' => sprintf(__('Error: %s', 'legalpress-push'), $e->getMessage()),
            'error' => true
        );
    }

    return array(
        'success' => $success,
        'failed' => $failed,
        'total' => count($subscriptions),
        'message' => sprintf(__('%d notifications sent, %d failed', 'legalpress-push'), $success, $failed)
    );
}

/**
 * Auto-send push notification when new post is published
 */
function legalpress_push_on_publish($new_status, $old_status, $post)
{
    // Only for posts
    if ($post->post_type !== 'post') {
        return;
    }

    // Only when transitioning to publish
    if ($new_status !== 'publish' || $old_status === 'publish') {
        return;
    }

    // Check if notifications are enabled
    if (!get_option('legalpress_push_on_publish', true)) {
        return;
    }

    // Check if library is available
    if (!legalpress_push_library_available()) {
        return;
    }

    // Don't send if already sent
    $already_sent = get_post_meta($post->ID, '_legalpress_push_sent', true);
    if ($already_sent) {
        return;
    }

    // Get post details
    $title = get_the_title($post);
    $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(strip_tags($post->post_content), 20);
    $url = get_permalink($post);
    $icon = get_the_post_thumbnail_url($post, 'thumbnail') ?: get_site_icon_url(192);

    // Send notification
    $result = legalpress_push_send_notification($title, $excerpt, $url, $icon);

    // Mark as sent
    update_post_meta($post->ID, '_legalpress_push_sent', current_time('mysql'));

    // Log result
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[LegalPress Push] Auto-notification result: ' . print_r($result, true));
    }
}
add_action('transition_post_status', 'legalpress_push_on_publish', 10, 3);

/**
 * Add admin menu for push notifications
 */
function legalpress_push_admin_menu()
{
    add_menu_page(
        __('Push Notifications', 'legalpress-push'),
        __('Push Notify', 'legalpress-push'),
        'manage_options',
        'legalpress-push',
        'legalpress_push_admin_page',
        'dashicons-megaphone',
        30
    );
}
add_action('admin_menu', 'legalpress_push_admin_menu');

/**
 * Admin page for push notifications
 */
function legalpress_push_admin_page()
{
    $library_available = legalpress_push_library_available();
    $vapid_public = get_option('legalpress_vapid_public_key', '');
    $vapid_private = get_option('legalpress_vapid_private_key', '');
    
    // Handle plugin update check
    $update_message = '';
    $update_info = null;
    
    if (isset($_POST['legalpress_check_update']) && wp_verify_nonce($_POST['_wpnonce'], 'legalpress_check_update')) {
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $update_message = __('Update check completed!', 'legalpress-push');
    }
    
    if (isset($_POST['legalpress_force_update']) && wp_verify_nonce($_POST['_wpnonce'], 'legalpress_force_update')) {
        // Force update by triggering the WordPress updater
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->upgrade(LEGALPRESS_PUSH_BASENAME);
        
        if (!is_wp_error($result) && $result !== false) {
            $update_message = __('Plugin updated successfully! Page will reload...', 'legalpress-push');
            echo '<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>';
        } else {
            $update_message = __('Update failed. Please try updating from the Plugins page.', 'legalpress-push');
        }
    }
    
    // Get update info
    $update_plugins = get_site_transient('update_plugins');
    if (isset($update_plugins->response[LEGALPRESS_PUSH_BASENAME])) {
        $update_info = $update_plugins->response[LEGALPRESS_PUSH_BASENAME];
    }
    
    // Handle VAPID key generation
    if (isset($_POST['legalpress_generate_vapid']) && wp_verify_nonce($_POST['_wpnonce'], 'legalpress_generate_vapid')) {
        if ($library_available) {
            if (legalpress_push_generate_vapid_keys()) {
                $vapid_generated = true;
                $vapid_public = get_option('legalpress_vapid_public_key', '');
            } else {
                $vapid_error = true;
            }
        }
    }

    // Handle form submission
    if (isset($_POST['legalpress_send_push']) && wp_verify_nonce($_POST['_wpnonce'], 'legalpress_send_push')) {
        $title = sanitize_text_field($_POST['push_title']);
        $body = sanitize_textarea_field($_POST['push_body']);
        $url = esc_url_raw($_POST['push_url']);

        if ($title && $body) {
            $result = legalpress_push_send_notification($title, $body, $url);
            $message = $result['message'];
            $success = !isset($result['error']) && $result['success'] > 0;
        } else {
            $message = __('Please fill in title and message', 'legalpress-push');
            $success = false;
        }
    }

    // Handle settings update
    if (isset($_POST['legalpress_push_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'legalpress_push_settings')) {
        update_option('legalpress_push_on_publish', isset($_POST['push_on_publish']) ? 1 : 0);
        $settings_saved = true;
    }

    // Get stats
    global $wpdb;
    $table_name = $wpdb->prefix . 'push_subscriptions';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if (!$table_exists) {
        legalpress_push_create_table();
    }
    
    $total_subs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_active = 1");
    $push_on_publish = get_option('legalpress_push_on_publish', true);
    ?>
    <div class="wrap">
        <h1><?php _e('Push Notifications', 'legalpress-push'); ?></h1>

        <?php if (isset($message)): ?>
            <div class="notice <?php echo $success ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($settings_saved)): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved!', 'legalpress-push'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($vapid_generated)): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('VAPID keys generated successfully!', 'legalpress-push'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($vapid_error)): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('Failed to generate VAPID keys.', 'legalpress-push'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($update_message)): ?>
            <div class="notice notice-info is-dismissible">
                <p><?php echo esc_html($update_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Plugin Update Card -->
        <div class="card" style="max-width: 600px; margin-bottom: 20px;">
            <h2><?php _e('Plugin Update', 'legalpress-push'); ?></h2>
            <table class="widefat" style="border: none;">
                <tr>
                    <td><strong><?php _e('Current Version', 'legalpress-push'); ?></strong></td>
                    <td><code><?php echo esc_html(LEGALPRESS_PUSH_VERSION); ?></code></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Update Status', 'legalpress-push'); ?></strong></td>
                    <td>
                        <?php if ($update_info): ?>
                            <span style="color: #dba617; font-weight: bold;">
                                ⬆ <?php printf(__('Version %s available!', 'legalpress-push'), esc_html($update_info->new_version)); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: green;">✔ <?php _e('Up to date', 'legalpress-push'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p style="margin-top: 15px; display: flex; gap: 10px;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('legalpress_check_update'); ?>
                    <button type="submit" name="legalpress_check_update" class="button button-secondary">
                        🔄 <?php _e('Check for Updates', 'legalpress-push'); ?>
                    </button>
                </form>
                <?php if ($update_info): ?>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('legalpress_force_update'); ?>
                        <button type="submit" name="legalpress_force_update" class="button button-primary">
                            ⬆ <?php printf(__('Update to %s', 'legalpress-push'), esc_html($update_info->new_version)); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </p>
            <p class="description">
                <?php _e('Updates are fetched from GitHub releases.', 'legalpress-push'); ?>
                <a href="https://github.com/CypherNinjaa/legalpress-push-notifications/releases" target="_blank">
                    <?php _e('View releases →', 'legalpress-push'); ?>
                </a>
            </p>
        </div>

        <!-- Status Card -->
        <div class="card" style="max-width: 600px; margin-bottom: 20px;">
            <h2><?php _e('System Status', 'legalpress-push'); ?></h2>
            <table class="widefat" style="border: none;">
                <tr>
                    <td><strong><?php _e('Web Push Library', 'legalpress-push'); ?></strong></td>
                    <td>
                        <?php if ($library_available): ?>
                            <span style="color: green;">✔ <?php _e('Installed', 'legalpress-push'); ?></span>
                        <?php else: ?>
                            <span style="color: red;">✘ <?php _e('Not Installed', 'legalpress-push'); ?></span>
                            <br><code>composer require minishlink/web-push</code>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('VAPID Keys', 'legalpress-push'); ?></strong></td>
                    <td>
                        <?php if (!empty($vapid_public)): ?>
                            <span style="color: green;">✔ <?php _e('Configured', 'legalpress-push'); ?></span>
                        <?php else: ?>
                            <span style="color: orange;">⚠ <?php _e('Not Generated', 'legalpress-push'); ?></span>
                            <?php if ($library_available): ?>
                                <form method="post" style="display: inline; margin-left: 10px;">
                                    <?php wp_nonce_field('legalpress_generate_vapid'); ?>
                                    <button type="submit" name="legalpress_generate_vapid" class="button button-small">
                                        <?php _e('Generate Now', 'legalpress-push'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Active Subscribers', 'legalpress-push'); ?></strong></td>
                    <td><span style="font-size: 18px; font-weight: bold; color: #2271b1;"><?php echo esc_html($total_subs); ?></span></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Ready to Send', 'legalpress-push'); ?></strong></td>
                    <td>
                        <?php if ($library_available && !empty($vapid_public) && $total_subs > 0): ?>
                            <span style="color: green;">✔ <?php _e('Yes', 'legalpress-push'); ?></span>
                        <?php else: ?>
                            <span style="color: red;">✘ <?php _e('No', 'legalpress-push'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Settings Card -->
        <div class="card" style="max-width: 600px; margin-bottom: 20px;">
            <h2><?php _e('Settings', 'legalpress-push'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('legalpress_push_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Auto-send on Publish', 'legalpress-push'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="push_on_publish" value="1" <?php checked($push_on_publish); ?>>
                                <?php _e('Automatically send push notification when a new post is published', 'legalpress-push'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="legalpress_push_settings" class="button button-secondary">
                        <?php _e('Save Settings', 'legalpress-push'); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- Send Notification Card -->
        <div class="card" style="max-width: 600px;">
            <h2><?php _e('Send Custom Notification', 'legalpress-push'); ?></h2>
            
            <?php if (!$library_available): ?>
                <p style="color: #d63638;">
                    <strong><?php _e('Cannot send notifications until the web-push library is installed.', 'legalpress-push'); ?></strong>
                </p>
            <?php elseif (empty($vapid_public)): ?>
                <p style="color: #dba617;">
                    <strong><?php _e('Please generate VAPID keys first.', 'legalpress-push'); ?></strong>
                </p>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('legalpress_send_push'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="push_title"><?php _e('Title', 'legalpress-push'); ?></label></th>
                        <td>
                            <input type="text" name="push_title" id="push_title" class="regular-text" required 
                                   placeholder="<?php esc_attr_e('Breaking News!', 'legalpress-push'); ?>"
                                   <?php echo (!$library_available || empty($vapid_public)) ? 'disabled' : ''; ?>>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="push_body"><?php _e('Message', 'legalpress-push'); ?></label></th>
                        <td>
                            <textarea name="push_body" id="push_body" rows="3" class="large-text" required
                                      placeholder="<?php esc_attr_e('Check out our latest article...', 'legalpress-push'); ?>"
                                      <?php echo (!$library_available || empty($vapid_public)) ? 'disabled' : ''; ?>></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="push_url"><?php _e('URL (optional)', 'legalpress-push'); ?></label></th>
                        <td>
                            <input type="url" name="push_url" id="push_url" class="regular-text" 
                                   placeholder="<?php echo esc_attr(home_url('/')); ?>"
                                   <?php echo (!$library_available || empty($vapid_public)) ? 'disabled' : ''; ?>>
                            <p class="description"><?php _e('Leave empty to link to homepage', 'legalpress-push'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="legalpress_send_push" class="button button-primary"
                            <?php echo (!$library_available || empty($vapid_public)) ? 'disabled' : ''; ?>>
                        <?php _e('Send Notification', 'legalpress-push'); ?> (<?php echo esc_html($total_subs); ?> <?php _e('subscribers', 'legalpress-push'); ?>)
                    </button>
                </p>
            </form>
        </div>

        <!-- VAPID Keys Info -->
        <?php if (!empty($vapid_public)): ?>
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2><?php _e('VAPID Public Key', 'legalpress-push'); ?></h2>
            <p class="description"><?php _e('This key is used by browsers to authenticate your push notifications.', 'legalpress-push'); ?></p>
            <code style="word-break: break-all; display: block; padding: 10px; background: #f0f0f1; margin-top: 10px;">
                <?php echo esc_html($vapid_public); ?>
            </code>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Initialize on admin_init to ensure table exists
 */
function legalpress_push_admin_init()
{
    if (current_user_can('manage_options')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'push_subscriptions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            legalpress_push_create_table();
        }
    }
}
add_action('admin_init', 'legalpress_push_admin_init');
