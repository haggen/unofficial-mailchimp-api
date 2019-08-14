<?php

/*
 * Plugin Name: Unofficial Mailchimp API
 * Plugin URI: https://github.com/corenzan/unofficial-mailchimp-api
 * Description: Provide integration with Mailchimp API for developers.
 * Version: 1.0.0
 * License: MIT
 * Author: Corenzan
 * Author URI: https://github.com/corenzan
 * Text Domain: unofficial-mailchimp-api
 */

if (!defined('ABSPATH')) {
    exit;
}

define('UNOFFICIAL_MAILCHIMP_API_VERSION', '1.0.0');
define('UNOFFICIAL_MAILCHIMP_API_FILE', __FILE__);
define('UNOFFICIAL_MAILCHIMP_API_PATH', plugin_dir_path(UNOFFICIAL_MAILCHIMP_API_FILE));
define('UNOFFICIAL_MAILCHIMP_API_URL', plugin_dir_url(UNOFFICIAL_MAILCHIMP_API_FILE));

/**
 * @package unofficial-mailchimp-api
 */
class Unofficial_Maichimp_API
{
    static $text_domain = 'unofficial-mailchimp-api';

    static $settings_default = array(
        'key' => '',
        'list_id' => ''
    );

    static function get_settings()
    {
        return array_merge(
            self::$settings_default,
            get_option('unofficial_mailchimp_api_settings', array()),
        );
    }

    private $settings;

    public function __construct()
    {
        register_activation_hook(UNOFFICIAL_MAILCHIMP_API_PATH, array($this, 'activate'));
        register_deactivation_hook(UNOFFICIAL_MAILCHIMP_API_PATH, array($this, 'deactivate'));

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        $this->settings = self::get_settings();

        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'admin_page_init'));
    }

    public function activate()
    {
        update_option('unofficial_mailchimp_api_settings', self::$settings_default);
    }

    public function deactivate()
    {
        delete_option('unofficial_mailchimp_api_settings');
    }

    public function add_settings_link($links)
    {
        $extra_links = array();
        if (current_user_can('manage_options')) {
            $extra_links[] = sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=unofficial-mailchimp-api')), __('Settings', self::$text_domain));
        }
        return array_merge($links, $extra_links);
    }

    public function add_plugin_page()
    {
        $page = add_options_page(
            __('Unofficial Mailchinmp API Settings', self::$text_domain),
            __('Unofficial Mailchinmp API', self::$text_domain),
            'manage_options',
            'unofficial-mailchimp-api',
            array($this, 'admin_page')
        );
    }

    protected function render_message($msg, $msg_type = 'error')
    {
        printf('<div class="%s notice is-dismissible"><p>%s</p></div>', $msg_type, $msg);
    }

    function render_basic_settings()
    {
        ?>
<form method="post" action="options.php">
    <?php
            settings_fields('unofficial_mailchimp_api_settings');
            do_settings_sections('unofficial_mailchimp_api_settings_section');
            submit_button();
            ?>
</form>
<?php
    }

    public function admin_page()
    {
        ?>
<div class="wrap">
    <?php
            $this->render_basic_settings();
            ?>
</div>
<?php
    }


    public function admin_page_init()
    {
        register_setting('unofficial_mailchimp_api_settings', 'unofficial_mailchimp_api_settings', array($this, 'sanitize_basic'));

        add_settings_section('general', __('Unofficial Mailchimp API Settings', self::$text_domain), null, 'unofficial_mailchimp_api_settings_section');
        add_settings_field('key', __('API Key*', self::$text_domain), array($this, 'render_api_key_field'), 'unofficial_mailchimp_api_settings_section', 'general');
        add_settings_field('list_id', __('List ID*', self::$text_domain), array($this, 'render_list_id_field'), 'unofficial_mailchimp_api_settings_section', 'general');
    }

    function obfuscate_api_key($api_key)
    {
        if (!empty($api_key)) {
            $parts = explode('-', $api_key);
            return substr($parts[0], 0, 4) . str_repeat('*', strlen($parts[0]) - 4) . '-' . $parts[1];
        }

        return $api_key;
    }

    function is_key_obfuscated($api_key)
    {
        return strpos($api_key, '*') !== false;
    }

    public function sanitize_basic($input)
    {
        $sanitized_input = array();

        if (empty($input['key'])) {
            add_settings_error('unofficial_mailchimp_api_settings', esc_attr('key'), __('API Key is required', self::$text_domain), 'error');
            return $sanitized_input;
        }

        if ($this->is_key_obfuscated(esc_attr($input['key']))) {
            $sanitized_input['key'] = $this->settings['key'];
        } else {
            $sanitized_input['key'] = sanitize_text_field($input['key']);
        }

        if (empty($input['list_id'])) {
            add_settings_error('unofficial_mailchimp_api_settings', esc_attr('list_id'), __('List ID is required', self::$text_domain), 'error');
            return $sanitized_input;
        }

        $sanitized_input['list_id'] = sanitize_text_field($input['list_id']);

        return $sanitized_input;
    }

    public function render_api_key_field()
    {
        $api_key = $this->obfuscate_api_key($this->settings['key']);

        printf(
            '<input type="text" id="key" name="unofficial_mailchimp_api_settings[key]" class="regular-text" value="%s" /><br /><small>To find your API keys head to your Mailchimp dashboard and click Your username > Account > Extras > API keys.</small>',
            isset($api_key) ? $api_key : ''
        );
    }


    public function render_list_id_field()
    {
        $api_key = $this->settings['key'];

        if (empty($api_key)) {
            echo '<select id="list_id" disabled><optgroup label="Subscriber lists"></optgroup></select>
            <br /><small>Fill in your API key to have your lists listed here.</small>';
            return;
        }

        $lists = self::fetch_lists();
        if ($lists == false) {
            return;
        }

        $list_id = $this->settings['list_id'];

        echo '<select id="list_id" name="unofficial_mailchimp_api_settings[list_id]">
        <option></option>';

        foreach ($lists as $list) {
            printf(
                '<option value="%s"%s>%s</option>',
                $list->id,
                $list_id == $list->id ? ' selected' : '',
                $list->name
            );
        }
        echo '</select>';
    }

    static function api_call($method, $resource, $body = array())
    {
        $settings = self::get_settings();
        $api_key = $settings['key'];
        if (empty($api_key)) {
            return false;
        }

        $datacenter = substr($api_key, strpos($api_key, '-') + 1);

        $url = 'https://' . $datacenter . '.api.mailchimp.com/3.0' . $resource;

        $opts = array(
            'method' => $method,
            'headers' => array(
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode('user:' . $api_key)
            ),
            'body' => array_merge(array(
                'apikey' => $settings['key'],
            ), $body)
        );

        $response = wp_remote_request($url, $opts);
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (WP_DEBUG) {
            var_dump($response);
        }

        if ($status < 200 || $status > 400) {
            return false;
        }
        if (empty($body)) {
            return true;
        }
        return json_decode($body);
    }

    static function fetch_lists()
    {
        $result = self::api_call('GET', '/lists');

        if ($result) {
            return $result->lists;
        }
        return false;
    }

    static function create_list_member($email)
    {
        $settings = self::get_settings();
        $list_id = $settings['list_id'];

        $resource = '/lists/' . $list_id . '/members/' . md5(strtolower($email));
        return self::api_call('POST', $resource, array(
            'email' => $email,
        ));
    }
}

if (is_admin()) {
    $unofficial_mailchimp_api = new Unofficial_Maichimp_API();
}
