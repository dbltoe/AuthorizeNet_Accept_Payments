<?php
/**
 * The Plugin Manager installer: creates the table, refuses old releases,
 * and on uninstall removes the payment module's settings.
 *
 * The refusal cases need PROJECT_VERSION_* constants with other values, and
 * a constant cannot be redefined, so those run in child processes:
 *   php installer_check.php old-zc
 */

require __DIR__ . '/_bootstrap.php';

$mode = $argv[1] ?? 'normal';

define('IS_ADMIN_FLAG', true);
define('DB_PREFIX', 'zen_');
define('TABLE_CONFIGURATION', 'zen_configuration');
define('PLUGIN_INSTALL_SQL_FAILURE', 'x');
if ($mode === 'old-zc') {
    define('PROJECT_VERSION_MAJOR', '2');
    define('PROJECT_VERSION_MINOR', '0.1');
} else {
    define('PROJECT_VERSION_MAJOR', '2');
    define('PROJECT_VERSION_MINOR', '2.2');
}

eval('namespace Zencart\PluginSupport; class ScriptedInstaller {
    protected $dbConn; protected $errorContainer; protected $pluginDir = "";
    public function __construct($d, $e) { $this->dbConn = $d; $this->errorContainer = $e; }
    public function doInstall() { return $this->executeInstall(); }
    public function doUninstall() { return $this->executeUninstall(); }
    public function doUpgrade($v = null) { return $this->executeUpgrade($v); }
    protected function executeInstall() { return true; }
    protected function executeUninstall() { return true; }
    protected function executeUpgrade($v = null) { return true; }
    protected function executeInstallerSql($sql) { return $this->dbConn->Execute($sql) !== false; }
}');

class AnaErrors
{
    public $errors = [];
    public function addError($severity, $message, $useForFriendly = false, $friendly = '')
    {
        $this->errors[] = ['message' => $message, 'friendly' => $useForFriendly ? $message : $friendly];
    }
}

$PLUGIN = ana_plugin_dir();
require $PLUGIN . '/Installer/ScriptedInstaller.php';

if ($mode === 'old-zc') {
    $db = new AnaDb();
    $errors = new AnaErrors();
    $ok = (new ScriptedInstaller($db, $errors))->doInstall();
    check('Zen Cart 2.0.1 is refused', $ok === false);
    check('the refusal names the minimum and the store\'s version', count($errors->errors) === 1 && strpos($errors->errors[0]['friendly'], '2.1.0') !== false && strpos($errors->errors[0]['friendly'], '2.0.1') !== false);
    check('nothing was written', $db->log === []);
    ana_done('old release refused');
}

section('install');
$db = new AnaDb();
$errors = new AnaErrors();
$ok = (new ScriptedInstaller($db, $errors))->doInstall();
check('install succeeds on 2.2.2', $ok === true && $errors->errors === []);
check('one statement: create the table if it is not there', count($db->log) === 1 && strpos($db->log[0], 'CREATE TABLE IF NOT EXISTS zen_authorizenet_accept') === 0);
foreach (['orders_id', 'customers_id', 'session_id', 'transaction_type', 'trans_id', 'ref_trans_id', 'network_trans_id', 'response_code', 'reason_code', 'response_text', 'auth_code', 'avs_code', 'cvv_code', 'account_type', 'account_number', 'amount decimal(15,4)', 'currency char(3)', 'payment_source', 'request_json mediumtext', 'response_json mediumtext', 'date_added datetime'] as $column) {
    check("column $column", strpos($db->log[0], $column) !== false);
}
check('indexed by order and transaction id', strpos($db->log[0], 'KEY idx_ana_orders_id (orders_id)') !== false && strpos($db->log[0], 'KEY idx_ana_trans_id (trans_id)') !== false);
check('InnoDB', strpos($db->log[0], 'ENGINE=InnoDB') !== false);
check('no configuration rows: those belong to Modules > Payment', $db->matching('zen_configuration') === []);

section('upgrade');
$db = new AnaDb();
(new ScriptedInstaller($db, new AnaErrors()))->doUpgrade('v0.9.0');
check('an upgrade re-runs the idempotent table create', count($db->log) === 1 && strpos($db->log[0], 'CREATE TABLE IF NOT EXISTS') === 0);

section('refusals other than the release');
$src = file_get_contents($PLUGIN . '/Installer/ScriptedInstaller.php');
check('PHP 7.4 is the floor', strpos($src, "MIN_PHP_VERSION = '7.4.0'") !== false);
check('curl and json are required', strpos($src, "function_exists('curl_init')") !== false && strpos($src, "function_exists('json_encode')") !== false);
check('refusals go through the error container as friendly messages', substr_count($src, '$this->errorContainer->addError(0, $message, true, $message)') === 1);

section('uninstall');
// The module's own remove() needs its class loadable; STATUS defined means "installed under Modules > Payment".
define('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_STATUS', 'True');
define('MODULE_PAYMENT_AUTHORIZENET_ACCEPT_SORT_ORDER', '0');
$db = new AnaDb();
$db->answers["configuration_key = 'MODULE_PAYMENT_INSTALLED'"] = [['configuration_value' => 'authorizenet_accept.php;cod.php']];
$ok = (new ScriptedInstaller($db, new AnaErrors()))->doUninstall();
check('uninstall succeeds', $ok === true);
check('the module settings are deleted', count($db->matching("DELETE FROM zen_configuration WHERE configuration_key LIKE 'MODULE\\_PAYMENT\\_AUTHORIZENET\\_ACCEPT\\_%'")) === 1);
check('the module leaves MODULE_PAYMENT_INSTALLED', count($db->matching("SET configuration_value = 'cod.php'")) === 1);
check('no settings stash is written and any old one is deleted', $db->matching('INSERT INTO') === [] && count($db->matching("configuration_key = 'AUTHORIZENET_ACCEPT_SETTINGS_STASH'")) >= 1);
check('the transaction table is kept', $db->matching('DROP TABLE') === []);

section('the refusal cases, in child processes');
$php = PHP_BINARY;
$out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' old-zc 2>&1');
check('old-zc child passed', is_string($out) && strpos($out, 'PASS:') !== false && strpos($out, 'FAIL') === false);

ana_done('installer creates the table and refuses cleanly');
