<?php
/**
 * Authorize.Net Accept.js Payments -- bridge for Zen Cart 1.5.8 through 2.0.x.
 *
 * Those releases look for a payment module only in this folder and never in
 * zc_plugins, so this file hands off to the module inside the plugin. Upload
 * it, with the matching language bridge at
 * includes/languages/english/modules/payment/lang.authorizenet_accept.php,
 * ONLY on Zen Cart 1.5.8 through 2.0.x. From 2.1.0 on the plugin's own copy
 * is found first and this file is ignored, so it can stay through an upgrade.
 *
 * If the plugin folder is removed while this file is left behind, a
 * placeholder class stands in: it shows under Modules > Payment as not
 * installed, with a title that says what to delete, instead of a fatal error.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!function_exists('authorizenet_accept_bridge_file')) {
    /**
     * The path of a file inside the plugin, or '' when the plugin folder is
     * gone. The version the Plugin Manager has installed is preferred; with
     * none recorded (the language file is asked for before the install), the
     * highest version folder present is used.
     */
    function authorizenet_accept_bridge_file($relative)
    {
        global $installedPlugins;
        $base = DIR_FS_CATALOG . 'zc_plugins/AuthorizeNetAccept/';
        $versions = [];
        if (is_array($installedPlugins)) {
            foreach ($installedPlugins as $plugin) {
                if (is_array($plugin) && isset($plugin['unique_key'], $plugin['version']) && $plugin['unique_key'] === 'AuthorizeNetAccept') {
                    $versions[] = $plugin['version'];
                }
            }
        }
        $folders = glob($base . 'v*', GLOB_ONLYDIR);
        if (is_array($folders) && $folders !== []) {
            $folders = array_map('basename', $folders);
            usort($folders, 'version_compare');
            $versions = array_merge($versions, array_reverse($folders));
        }
        foreach ($versions as $version) {
            $file = $base . $version . '/' . $relative;
            if (is_file($file)) {
                return $file;
            }
        }
        return '';
    }
}

$anaBridgeModule = authorizenet_accept_bridge_file('catalog/includes/modules/payment/authorizenet_accept.php');
if ($anaBridgeModule !== '') {
    require_once $anaBridgeModule;
} elseif (!class_exists('authorizenet_accept', false)) {
    /**
     * The plugin folder is missing. Everything Modules > Payment and the
     * checkout's payment class may call is here, and all of it says "no".
     */
    class authorizenet_accept
    {
        public $code = 'authorizenet_accept';
        public $title = 'Authorize.net (Accept.js): the plugin folder zc_plugins/AuthorizeNetAccept is missing. Put it back, or delete includes/modules/payment/authorizenet_accept.php and includes/languages/english/modules/payment/lang.authorizenet_accept.php.';
        public $description = '';
        public $enabled = false;
        public $sort_order = 0;
        public $order_status = 0;

        public function check()
        {
            return 0;
        }

        public function install()
        {
            return 'failed';
        }

        public function remove()
        {
            return false;
        }

        public function keys()
        {
            return [];
        }

        public function update_status()
        {
        }

        public function javascript_validation()
        {
            return '';
        }

        public function selection()
        {
            return false;
        }

        public function pre_confirmation_check()
        {
        }

        public function confirmation()
        {
            return false;
        }

        public function process_button()
        {
            return '';
        }

        public function before_process()
        {
        }

        public function after_process()
        {
        }

        public function get_error()
        {
            return false;
        }
    }
}
unset($anaBridgeModule);
