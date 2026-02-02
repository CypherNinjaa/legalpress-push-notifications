<?php
/**
 * GitHub Plugin Updater
 *
 * Allows clients to update the plugin directly from GitHub releases.
 * Checks for new versions and provides one-click updates through WordPress admin.
 *
 * @package LegalPress_Push_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LegalPress Plugin GitHub Updater Class
 */
class LegalPress_Plugin_GitHub_Updater {
    
    /**
     * GitHub repository owner
     * @var string
     */
    private $github_username;
    
    /**
     * GitHub repository name
     * @var string
     */
    private $github_repo;
    
    /**
     * GitHub access token (optional, for private repos or higher rate limits)
     * @var string
     */
    private $access_token;
    
    /**
     * Plugin slug
     * @var string
     */
    private $plugin_slug;
    
    /**
     * Plugin basename
     * @var string
     */
    private $plugin_basename;
    
    /**
     * Current plugin version
     * @var string
     */
    private $current_version;
    
    /**
     * GitHub API response cache
     * @var object
     */
    private $github_response;
    
    /**
     * Plugin data
     * @var array
     */
    private $plugin_data;
    
    /**
     * Constructor
     *
     * @param string $plugin_file Main plugin file path
     */
    public function __construct($plugin_file) {
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug = dirname($this->plugin_basename);
        
        // Get plugin data
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data($plugin_file);
        $this->current_version = $this->plugin_data['Version'];
        
        // Get settings from options or use defaults
        $settings = get_option('legalpress_plugin_github_settings', array());
        $this->github_username = !empty($settings['github_username']) ? $settings['github_username'] : 'CypherNinjaa';
        $this->github_repo = !empty($settings['github_repo']) ? $settings['github_repo'] : 'legalpress-push-notifications';
        $this->access_token = !empty($settings['github_token']) ? $settings['github_token'] : '';
        
        // Only run in admin
        if (is_admin()) {
            add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
            add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
            add_filter('upgrader_source_selection', array($this, 'fix_directory_name'), 10, 4);
            add_filter('plugin_action_links_' . $this->plugin_basename, array($this, 'plugin_action_links'));
            
            // Add settings page
            add_action('admin_menu', array($this, 'add_settings_page'));
            add_action('admin_init', array($this, 'register_settings'));
            
            // AJAX handlers
            add_action('wp_ajax_legalpress_plugin_check_update', array($this, 'ajax_check_update'));
            add_action('wp_ajax_legalpress_plugin_clear_cache', array($this, 'ajax_clear_cache'));
        }
    }
    
    /**
     * Get GitHub repository data
     *
     * @return object|false Repository data or false on failure
     */
    private function get_github_data() {
        if (!empty($this->github_response)) {
            return $this->github_response;
        }
        
        // Check cache first
        $cached = get_transient('legalpress_plugin_github_response');
        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }
        
        // Build API URL for latest release
        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );
        
        // Set up request args
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ),
        );
        
        // Add authorization if token exists
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        // Make request
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (empty($data) || !isset($data->tag_name)) {
            return false;
        }
        
        // Cache for 6 hours
        set_transient('legalpress_plugin_github_response', $data, 6 * HOUR_IN_SECONDS);
        
        $this->github_response = $data;
        return $data;
    }
    
    /**
     * Check for plugin updates
     *
     * @param object $transient Update transient
     * @return object Modified transient
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $github_data = $this->get_github_data();
        
        if (!$github_data) {
            return $transient;
        }
        
        // Get version from tag (remove 'v' prefix if present)
        $github_version = ltrim($github_data->tag_name, 'v');
        
        // Compare versions
        if (version_compare($github_version, $this->current_version, '>')) {
            // Get download URL
            $download_url = $this->get_download_url($github_data);
            
            if ($download_url) {
                $transient->response[$this->plugin_basename] = (object) array(
                    'slug' => $this->plugin_slug,
                    'plugin' => $this->plugin_basename,
                    'new_version' => $github_version,
                    'url' => $github_data->html_url,
                    'package' => $download_url,
                    'icons' => array(),
                    'banners' => array(),
                    'requires' => '5.0',
                    'requires_php' => '7.4',
                    'tested' => get_bloginfo('version'),
                );
            }
        } else {
            // No update available
            $transient->no_update[$this->plugin_basename] = (object) array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $this->current_version,
                'url' => '',
                'package' => '',
            );
        }
        
        return $transient;
    }
    
    /**
     * Get download URL for the release
     *
     * @param object $release_data GitHub release data
     * @return string|false Download URL or false
     */
    private function get_download_url($release_data) {
        // First, check for a zip asset in the release
        if (!empty($release_data->assets) && is_array($release_data->assets)) {
            foreach ($release_data->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    $url = $asset->browser_download_url;
                    
                    // Add token for private repos
                    if (!empty($this->access_token)) {
                        $url = add_query_arg('access_token', $this->access_token, $url);
                    }
                    
                    return $url;
                }
            }
        }
        
        // Fall back to zipball URL
        if (!empty($release_data->zipball_url)) {
            $url = $release_data->zipball_url;
            
            if (!empty($this->access_token)) {
                $url = add_query_arg('access_token', $this->access_token, $url);
            }
            
            return $url;
        }
        
        return false;
    }
    
    /**
     * Provide plugin information for the update details popup
     *
     * @param false|object|array $result The result object or array
     * @param string $action The type of information being requested
     * @param object $args Query arguments
     * @return object|false Plugin info or false
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        $github_data = $this->get_github_data();
        
        if (!$github_data) {
            return $result;
        }
        
        $github_version = ltrim($github_data->tag_name, 'v');
        
        $result = (object) array(
            'name' => $this->plugin_data['Name'],
            'slug' => $this->plugin_slug,
            'version' => $github_version,
            'author' => $this->plugin_data['Author'],
            'homepage' => $this->plugin_data['PluginURI'],
            'requires' => '5.0',
            'requires_php' => '7.4',
            'tested' => get_bloginfo('version'),
            'downloaded' => 0,
            'last_updated' => $github_data->published_at,
            'sections' => array(
                'description' => $this->plugin_data['Description'],
                'changelog' => $this->parse_changelog($github_data->body),
            ),
            'download_link' => $this->get_download_url($github_data),
        );
        
        return $result;
    }
    
    /**
     * Parse changelog from release body
     *
     * @param string $body Release body/description
     * @return string HTML formatted changelog
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return '<p>No changelog available.</p>';
        }
        
        // Convert markdown to HTML (basic conversion)
        $changelog = esc_html($body);
        $changelog = nl2br($changelog);
        
        // Convert markdown headers
        $changelog = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $changelog);
        
        // Convert markdown lists
        $changelog = preg_replace('/^- (.+)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $changelog);
        
        // Wrap consecutive li elements in ul
        $changelog = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul>$0</ul>', $changelog);
        
        // Convert bold and italic
        $changelog = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $changelog);
        
        return $changelog;
    }
    
    /**
     * Fix directory name after download
     * GitHub archives have different folder names, we need to rename it
     *
     * @param string $source Source directory
     * @param string $remote_source Remote source
     * @param object $upgrader Upgrader instance
     * @param array $hook_extra Extra hook data
     * @return string Fixed source path
     */
    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;
        
        // Check if this is our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }
        
        // Get the correct directory name
        $corrected_source = trailingslashit($remote_source) . $this->plugin_slug . '/';
        
        // If the source is already correct, return it
        if ($source === $corrected_source) {
            return $source;
        }
        
        // Move/rename the directory
        if ($wp_filesystem->move($source, $corrected_source, true)) {
            return $corrected_source;
        }
        
        return $source;
    }
    
    /**
     * Add plugin action links
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=legalpress-plugin-updater') . '">' . __('Updater Settings', 'legalpress-push-notifications') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('LegalPress Plugin Updater', 'legalpress-push-notifications'),
            __('Plugin Updater', 'legalpress-push-notifications'),
            'manage_options',
            'legalpress-plugin-updater',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('legalpress_plugin_github_updater', 'legalpress_plugin_github_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));
        
        add_settings_section(
            'legalpress_plugin_github_settings_section',
            __('GitHub Repository Settings', 'legalpress-push-notifications'),
            array($this, 'settings_section_callback'),
            'legalpress-plugin-updater'
        );
        
        add_settings_field(
            'github_username',
            __('GitHub Username/Organization', 'legalpress-push-notifications'),
            array($this, 'render_username_field'),
            'legalpress-plugin-updater',
            'legalpress_plugin_github_settings_section'
        );
        
        add_settings_field(
            'github_repo',
            __('Repository Name', 'legalpress-push-notifications'),
            array($this, 'render_repo_field'),
            'legalpress-plugin-updater',
            'legalpress_plugin_github_settings_section'
        );
        
        add_settings_field(
            'github_token',
            __('Access Token (Optional)', 'legalpress-push-notifications'),
            array($this, 'render_token_field'),
            'legalpress-plugin-updater',
            'legalpress_plugin_github_settings_section'
        );
    }
    
    /**
     * Sanitize settings
     *
     * @param array $input Input values
     * @return array Sanitized values
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['github_username'])) {
            $sanitized['github_username'] = sanitize_text_field($input['github_username']);
        }
        
        if (isset($input['github_repo'])) {
            $sanitized['github_repo'] = sanitize_text_field($input['github_repo']);
        }
        
        if (isset($input['github_token'])) {
            $sanitized['github_token'] = sanitize_text_field($input['github_token']);
        }
        
        // Clear cache when settings change
        delete_transient('legalpress_plugin_github_response');
        
        return $sanitized;
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . esc_html__('Configure your GitHub repository settings for automatic plugin updates.', 'legalpress-push-notifications') . '</p>';
    }
    
    /**
     * Render username field
     */
    public function render_username_field() {
        $settings = get_option('legalpress_plugin_github_settings', array());
        $value = !empty($settings['github_username']) ? $settings['github_username'] : 'CypherNinjaa';
        ?>
        <input type="text" name="legalpress_plugin_github_settings[github_username]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Your GitHub username or organization name.', 'legalpress-push-notifications'); ?></p>
        <?php
    }
    
    /**
     * Render repo field
     */
    public function render_repo_field() {
        $settings = get_option('legalpress_plugin_github_settings', array());
        $value = !empty($settings['github_repo']) ? $settings['github_repo'] : 'legalpress-push-notifications';
        ?>
        <input type="text" name="legalpress_plugin_github_settings[github_repo]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('The name of your GitHub repository.', 'legalpress-push-notifications'); ?></p>
        <?php
    }
    
    /**
     * Render token field
     */
    public function render_token_field() {
        $settings = get_option('legalpress_plugin_github_settings', array());
        $value = !empty($settings['github_token']) ? $settings['github_token'] : '';
        ?>
        <input type="password" name="legalpress_plugin_github_settings[github_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" autocomplete="new-password" />
        <p class="description">
            <?php esc_html_e('Optional: Required for private repositories or to avoid rate limits.', 'legalpress-push-notifications'); ?>
            <a href="https://github.com/settings/tokens" target="_blank"><?php esc_html_e('Generate a token', 'legalpress-push-notifications'); ?></a>
        </p>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $github_data = $this->get_github_data();
        $github_version = $github_data ? ltrim($github_data->tag_name, 'v') : false;
        $has_update = $github_version && version_compare($github_version, $this->current_version, '>');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('LegalPress Plugin Updater', 'legalpress-push-notifications'); ?></h1>
            
            <!-- Status Card -->
            <div class="legalpress-plugin-updater-card">
                <h2><?php esc_html_e('Update Status', 'legalpress-push-notifications'); ?></h2>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Plugin', 'legalpress-push-notifications'); ?></th>
                        <td><strong><?php echo esc_html($this->plugin_data['Name']); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Current Version', 'legalpress-push-notifications'); ?></th>
                        <td><code><?php echo esc_html($this->current_version); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Latest Version (GitHub)', 'legalpress-push-notifications'); ?></th>
                        <td>
                            <?php if ($github_version) : ?>
                                <code><?php echo esc_html($github_version); ?></code>
                                <?php if ($has_update) : ?>
                                    <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                                    <span style="color: #dba617;"><?php esc_html_e('Update available!', 'legalpress-push-notifications'); ?></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                    <span style="color: #00a32a;"><?php esc_html_e('You are up to date!', 'legalpress-push-notifications'); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
                                <span style="color: #d63638;"><?php esc_html_e('Could not fetch version info', 'legalpress-push-notifications'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($github_data && !empty($github_data->published_at)) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Release Date', 'legalpress-push-notifications'); ?></th>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($github_data->published_at))); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Repository', 'legalpress-push-notifications'); ?></th>
                        <td>
                            <a href="https://github.com/<?php echo esc_attr($this->github_username); ?>/<?php echo esc_attr($this->github_repo); ?>" target="_blank">
                                <?php echo esc_html($this->github_username . '/' . $this->github_repo); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <?php if ($has_update) : ?>
                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-primary">
                            <?php esc_html_e('Update Plugin Now', 'legalpress-push-notifications'); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" id="legalpress-plugin-check-update" class="button">
                        <?php esc_html_e('Check for Updates', 'legalpress-push-notifications'); ?>
                    </button>
                    <button type="button" id="legalpress-plugin-clear-cache" class="button">
                        <?php esc_html_e('Clear Cache', 'legalpress-push-notifications'); ?>
                    </button>
                    <span id="legalpress-plugin-update-status" style="margin-left: 10px;"></span>
                </p>
            </div>
            
            <!-- Settings Form -->
            <div class="legalpress-plugin-updater-card">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('legalpress_plugin_github_updater');
                    do_settings_sections('legalpress-plugin-updater');
                    submit_button(__('Save Settings', 'legalpress-push-notifications'));
                    ?>
                </form>
            </div>
            
            <!-- Changelog -->
            <?php if ($github_data && !empty($github_data->body)) : ?>
            <div class="legalpress-plugin-updater-card">
                <h2><?php esc_html_e('Latest Release Notes', 'legalpress-push-notifications'); ?></h2>
                <div class="legalpress-plugin-changelog">
                    <?php echo wp_kses_post($this->parse_changelog($github_data->body)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- How to Create a Release -->
            <div class="legalpress-plugin-updater-card">
                <h2><?php esc_html_e('How to Create a New Release', 'legalpress-push-notifications'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Update the version number in the main plugin file header', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('Commit and push all changes to GitHub', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('Go to your GitHub repository → Releases → Create a new release', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('Create a new tag (e.g., v1.1.0) matching your plugin version', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('Add release notes describing the changes', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('Publish the release', 'legalpress-push-notifications'); ?></li>
                    <li><?php esc_html_e('WordPress will automatically detect the new version!', 'legalpress-push-notifications'); ?></li>
                </ol>
            </div>
        </div>
        
        <style>
            .legalpress-plugin-updater-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
                max-width: 800px;
            }
            .legalpress-plugin-updater-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #dcdcde;
            }
            .legalpress-plugin-changelog {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 4px;
                max-height: 300px;
                overflow-y: auto;
            }
            .legalpress-plugin-changelog h3,
            .legalpress-plugin-changelog h4 {
                margin: 15px 0 10px 0;
            }
            .legalpress-plugin-changelog ul {
                margin: 10px 0 10px 20px;
            }
            .legalpress-plugin-changelog li {
                margin: 5px 0;
            }
            #legalpress-plugin-update-status {
                display: inline-block;
                vertical-align: middle;
            }
            .legalpress-plugin-updater-card ol {
                margin-left: 20px;
            }
            .legalpress-plugin-updater-card ol li {
                margin: 8px 0;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Check for updates
            $('#legalpress-plugin-check-update').on('click', function() {
                var $btn = $(this);
                var $status = $('#legalpress-plugin-update-status');
                
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;"></span> <?php esc_html_e('Checking...', 'legalpress-push-notifications'); ?>');
                
                $.post(ajaxurl, {
                    action: 'legalpress_plugin_check_update',
                    nonce: '<?php echo wp_create_nonce('legalpress_plugin_github_update'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.html('<span style="color:#00a32a;">' + response.data.message + '</span>');
                        if (response.data.has_update) {
                            location.reload();
                        }
                    } else {
                        $status.html('<span style="color:#d63638;">' + response.data + '</span>');
                    }
                });
            });
            
            // Clear cache
            $('#legalpress-plugin-clear-cache').on('click', function() {
                var $btn = $(this);
                var $status = $('#legalpress-plugin-update-status');
                
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;"></span>');
                
                $.post(ajaxurl, {
                    action: 'legalpress_plugin_clear_cache',
                    nonce: '<?php echo wp_create_nonce('legalpress_plugin_github_update'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.html('<span style="color:#00a32a;"><?php esc_html_e('Cache cleared!', 'legalpress-push-notifications'); ?></span>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Check for update
     */
    public function ajax_check_update() {
        check_ajax_referer('legalpress_plugin_github_update', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'legalpress-push-notifications'));
        }
        
        // Clear cache first
        delete_transient('legalpress_plugin_github_response');
        
        // Fetch fresh data
        $github_data = $this->get_github_data();
        
        if (!$github_data) {
            wp_send_json_error(__('Could not connect to GitHub', 'legalpress-push-notifications'));
        }
        
        $github_version = ltrim($github_data->tag_name, 'v');
        $has_update = version_compare($github_version, $this->current_version, '>');
        
        if ($has_update) {
            wp_send_json_success(array(
                'has_update' => true,
                'message' => sprintf(
                    /* translators: %s: New version number */
                    __('Update available: v%s', 'legalpress-push-notifications'),
                    $github_version
                ),
            ));
        } else {
            wp_send_json_success(array(
                'has_update' => false,
                'message' => __('You are running the latest version!', 'legalpress-push-notifications'),
            ));
        }
    }
    
    /**
     * AJAX: Clear cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('legalpress_plugin_github_update', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'legalpress-push-notifications'));
        }
        
        delete_transient('legalpress_plugin_github_response');
        delete_site_transient('update_plugins');
        
        wp_send_json_success();
    }
}
