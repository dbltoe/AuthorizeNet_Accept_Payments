<?php
/**
 * Authorize.Net Accept.js Payments -- Plugin Manager installer.
 *
 * The installer only creates the transaction table and checks the ground the
 * plugin needs to stand on. The payment module's own settings are created the
 * usual way, when the store owner installs it under Modules > Payment, so the
 * two halves behave exactly like a core payment module.
 *
 * Limited to the ScriptedInstaller API that exists on every supported release
 * (v1.5.8 -> v3.0.0): executeInstallerSql() only, and executeUpgrade() with an
 * optional argument. Every step is idempotent, so an upgrade is a re-run of
 * the install.
 *
 * The transaction table is KEPT on uninstall. It holds the store's payment
 * history (transaction ids, auth codes, refunds and voids), which is what an
 * accountant or a chargeback asks for, and a plugin uninstall is not the place
 * to throw that away.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    const MIN_ZC_VERSION = '1.5.8';
    /**
     * Below this release a payment module is only found in the core folders,
     * so two bridge files there hand off to the plugin. The owner uploads
     * them; this installer never writes into the core folders.
     */
    const BRIDGE_BELOW_ZC_VERSION = '2.1.0';
    const BRIDGE_FOLDER = 'for_zen_cart_1.5.8_to_2.0.x';
    const BRIDGE_FILES = [
        'includes/modules/payment/authorizenet_accept.php',
        'includes/languages/english/modules/payment/lang.authorizenet_accept.php',
    ];
    const MIN_PHP_VERSION = '7.4.0';
    const MODULE_FILE = 'authorizenet_accept.php';

    protected function executeInstall()
    {
        if (!$this->anaRequirementsMet()) {
            return false;
        }
        $this->anaCreateTransactionTable();
        return true;
    }

    /**
     * v2.1 and later pass $oldVersion; declared optional to be safe.
     */
    protected function executeUpgrade($oldVersion = null)
    {
        $this->anaCreateTransactionTable();
        return true;
    }

    protected function executeUninstall()
    {
        // If the payment module was installed under Modules > Payment, take its
        // settings out and remove it from the installed-modules list, the same
        // way the Remove button on that page would. Otherwise the module name
        // lingers in MODULE_PAYMENT_INSTALLED pointing at a file that is gone.
        if (defined('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS')) {
            $moduleFile = $this->anaPluginDir() . '/catalog/includes/modules/payment/' . self::MODULE_FILE;
            if (is_file($moduleFile)) {
                require_once $moduleFile;
                if (class_exists('authorizenet_accept')) {
                    $module = new authorizenet_accept(true);
                    $module->remove(false); // an uninstall forgets the settings; a Remove under Modules > Payment keeps them
                }
            }
        }
        // The settings copy a Modules > Payment Remove leaves for the next Install
        // has no reason to outlive the plugin.
        $this->executeInstallerSql("DELETE FROM " . DB_PREFIX . "configuration WHERE configuration_key = 'AUTHORIZENET_ACCEPT_SETTINGS_STASH'");
        return true;
    }

    /**
     * Refuse cleanly rather than fatal later.
     */
    protected function anaRequirementsMet(): bool
    {
        // The admin always has these from application_top; a command-line
        // installer may not, and "no constant" must not read as version 0.
        if ((!defined('PROJECT_VERSION_MAJOR') || !defined('PROJECT_VERSION_MINOR')) && defined('DIR_FS_CATALOG') && is_file(DIR_FS_CATALOG . 'includes/version.php')) {
            require DIR_FS_CATALOG . 'includes/version.php';
        }
        $zcVersion = defined('PROJECT_VERSION_MAJOR') && defined('PROJECT_VERSION_MINOR')
            ? PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR
            : '0.0.0';
        // 1.5.8a must not read as older than 1.5.8: version_compare() takes a
        // trailing letter for a pre-release, so compare the digits only, as core does.
        $zcNumeric = preg_replace('/[^0-9.]/', '', $zcVersion);
        if (version_compare($zcNumeric, self::MIN_ZC_VERSION, '<')) {
            $this->anaRefuse('Authorize.Net Accept.js Payments needs Zen Cart ' . self::MIN_ZC_VERSION
                . ' or later; this store runs ' . $zcVersion . '.');
            return false;
        }
        if (version_compare($zcNumeric, self::BRIDGE_BELOW_ZC_VERSION, '<')) {
            $missing = [];
            foreach (self::BRIDGE_FILES as $file) {
                if (!defined('DIR_FS_CATALOG') || !is_file(DIR_FS_CATALOG . $file)) {
                    $missing[] = $file;
                }
            }
            if ($missing !== []) {
                $this->anaRefuse('Zen Cart ' . $zcVersion . ' looks for a payment module only in its own includes folder, so on this release two more files are needed.'
                    . ' Upload the includes folder from the package\'s ' . self::BRIDGE_FOLDER . ' folder to the store, then install again. Missing: ' . implode(', ', $missing) . '.');
                return false;
            }
        }
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->anaRefuse('Authorize.Net Accept.js Payments needs PHP ' . self::MIN_PHP_VERSION
                . ' or later; this server runs ' . PHP_VERSION . '.');
            return false;
        }
        if (!function_exists('curl_init')) {
            $this->anaRefuse('Authorize.Net Accept.js Payments needs the PHP curl extension to reach the gateway, and this server does not have it.');
            return false;
        }
        if (!function_exists('json_encode')) {
            $this->anaRefuse('Authorize.Net Accept.js Payments needs the PHP json extension, and this server does not have it.');
            return false;
        }
        return true;
    }

    protected function anaRefuse(string $message): void
    {
        $this->errorContainer->addError(0, $message, true, $message);
    }

    /**
     * One row per gateway call: the checkout charge, and every refund, capture
     * and void done from the order page. Amounts are stored as sent to the
     * gateway; request_json is stored MASKED (no transaction key, no nonce).
     */
    protected function anaCreateTransactionTable(): void
    {
        $table = DB_PREFIX . 'authorizenet_accept';
        $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . $table . " (
                id int(11) unsigned NOT NULL AUTO_INCREMENT,
                orders_id int(11) NOT NULL DEFAULT 0,
                customers_id int(11) NOT NULL DEFAULT 0,
                session_id varchar(255) NOT NULL DEFAULT '',
                transaction_type varchar(40) NOT NULL DEFAULT '',
                trans_id varchar(64) NOT NULL DEFAULT '',
                ref_trans_id varchar(64) NOT NULL DEFAULT '',
                network_trans_id varchar(64) NOT NULL DEFAULT '',
                response_code tinyint(1) NOT NULL DEFAULT 0,
                reason_code varchar(16) NOT NULL DEFAULT '',
                response_text varchar(255) NOT NULL DEFAULT '',
                auth_code varchar(16) NOT NULL DEFAULT '',
                avs_code varchar(4) NOT NULL DEFAULT '',
                cvv_code varchar(4) NOT NULL DEFAULT '',
                account_type varchar(32) NOT NULL DEFAULT '',
                account_number varchar(32) NOT NULL DEFAULT '',
                amount decimal(15,4) NOT NULL DEFAULT 0.0000,
                currency char(3) NOT NULL DEFAULT '',
                payment_source varchar(48) NOT NULL DEFAULT '',
                request_json mediumtext,
                response_json mediumtext,
                date_added datetime NOT NULL DEFAULT '0001-01-01 00:00:00',
                PRIMARY KEY (id),
                KEY idx_ana_orders_id (orders_id),
                KEY idx_ana_trans_id (trans_id)
            ) ENGINE=InnoDB"
        );
    }

    /**
     * The version directory this installer lives in. The base class exposes it
     * on v2.2 and later; derived from __DIR__ so v2.1 works too.
     */
    protected function anaPluginDir(): string
    {
        return dirname(__DIR__);
    }
}
