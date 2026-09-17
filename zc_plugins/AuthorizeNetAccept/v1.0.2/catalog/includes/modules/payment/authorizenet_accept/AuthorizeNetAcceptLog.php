<?php
/**
 * Authorize.Net Accept.js Payments -- the transaction table and the log files.
 *
 * Every gateway call gets one row in the plugin's table (the checkout charge,
 * then any refund, capture or void from the order page). The request stored
 * there is the MASKED one: no transaction key, no nonce, no card number. The
 * response is stored whole; the gateway never echoes a card number back beyond
 * its last four digits.
 *
 * The log files are opt-in (Debug Mode), except in Sandbox mode where they are
 * always written, because a sandbox is where someone is trying to see what
 * went over the wire.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class AuthorizeNetAcceptLog
{
    /**
     * Insert one row. Returns the new id, or 0 when there is no table to write
     * to (the plugin's datafile has not been loaded) or no database object.
     */
    public static function record(array $row): int
    {
        global $db;
        if (!defined('TABLE_AUTHORIZENET_ACCEPT') || !is_object($db)) {
            return 0;
        }
        $defaults = [
            'orders_id' => 0,
            'customers_id' => 0,
            'session_id' => '',
            'transaction_type' => '',
            'trans_id' => '',
            'ref_trans_id' => '',
            'network_trans_id' => '',
            'response_code' => 0,
            'reason_code' => '',
            'response_text' => '',
            'auth_code' => '',
            'avs_code' => '',
            'cvv_code' => '',
            'account_type' => '',
            'account_number' => '',
            'amount' => 0,
            'currency' => '',
            'payment_source' => '',
            'request_json' => '',
            'response_json' => '',
        ];
        $row = array_merge($defaults, array_intersect_key($row, $defaults));

        $sql = "INSERT INTO " . TABLE_AUTHORIZENET_ACCEPT . "
                    (orders_id, customers_id, session_id, transaction_type, trans_id, ref_trans_id, network_trans_id,
                     response_code, reason_code, response_text, auth_code, avs_code, cvv_code, account_type, account_number,
                     amount, currency, payment_source, request_json, response_json, date_added)
                VALUES
                    (:orders_id, :customers_id, :session_id, :transaction_type, :trans_id, :ref_trans_id, :network_trans_id,
                     :response_code, :reason_code, :response_text, :auth_code, :avs_code, :cvv_code, :account_type, :account_number,
                     :amount, :currency, :payment_source, :request_json, :response_json, now())";
        $sql = $db->bindVars($sql, ':orders_id', $row['orders_id'], 'integer');
        $sql = $db->bindVars($sql, ':customers_id', $row['customers_id'], 'integer');
        $sql = $db->bindVars($sql, ':session_id', substr((string)$row['session_id'], 0, 255), 'string');
        $sql = $db->bindVars($sql, ':transaction_type', substr((string)$row['transaction_type'], 0, 40), 'string');
        $sql = $db->bindVars($sql, ':trans_id', substr((string)$row['trans_id'], 0, 64), 'string');
        $sql = $db->bindVars($sql, ':ref_trans_id', substr((string)$row['ref_trans_id'], 0, 64), 'string');
        $sql = $db->bindVars($sql, ':network_trans_id', substr((string)$row['network_trans_id'], 0, 64), 'string');
        $sql = $db->bindVars($sql, ':response_code', $row['response_code'], 'integer');
        $sql = $db->bindVars($sql, ':reason_code', substr((string)$row['reason_code'], 0, 16), 'string');
        $sql = $db->bindVars($sql, ':response_text', substr((string)$row['response_text'], 0, 255), 'string');
        $sql = $db->bindVars($sql, ':auth_code', substr((string)$row['auth_code'], 0, 16), 'string');
        $sql = $db->bindVars($sql, ':avs_code', substr((string)$row['avs_code'], 0, 4), 'string');
        $sql = $db->bindVars($sql, ':cvv_code', substr((string)$row['cvv_code'], 0, 4), 'string');
        $sql = $db->bindVars($sql, ':account_type', substr((string)$row['account_type'], 0, 32), 'string');
        $sql = $db->bindVars($sql, ':account_number', substr((string)$row['account_number'], 0, 32), 'string');
        $sql = $db->bindVars($sql, ':amount', $row['amount'], 'float');
        $sql = $db->bindVars($sql, ':currency', substr((string)$row['currency'], 0, 3), 'string');
        $sql = $db->bindVars($sql, ':payment_source', substr((string)$row['payment_source'], 0, 48), 'string');
        $sql = $db->bindVars($sql, ':request_json', (string)$row['request_json'], 'string');
        $sql = $db->bindVars($sql, ':response_json', (string)$row['response_json'], 'string');
        $db->Execute($sql);

        return (int)$db->insert_ID();
    }

    /**
     * The checkout charge is recorded before the order exists; this ties the
     * row to the order once Zen Cart has an id for it.
     */
    public static function attachOrder(int $orders_id, int $row_id): void
    {
        global $db;
        if (!defined('TABLE_AUTHORIZENET_ACCEPT') || !is_object($db) || $orders_id <= 0 || $row_id <= 0) {
            return;
        }
        $db->Execute(
            "UPDATE " . TABLE_AUTHORIZENET_ACCEPT . "
                SET orders_id = " . (int)$orders_id . "
              WHERE id = " . (int)$row_id . "
              LIMIT 1"
        );
    }

    /**
     * Every row for an order, oldest first.
     */
    public static function transactionsForOrder(int $orders_id): array
    {
        global $db;
        $rows = [];
        if (!defined('TABLE_AUTHORIZENET_ACCEPT') || !is_object($db) || $orders_id <= 0) {
            return $rows;
        }
        $result = $db->Execute(
            "SELECT id, transaction_type, trans_id, ref_trans_id, response_code, reason_code, response_text,
                    auth_code, avs_code, cvv_code, account_type, account_number, amount, currency, payment_source, date_added
               FROM " . TABLE_AUTHORIZENET_ACCEPT . "
              WHERE orders_id = " . (int)$orders_id . "
              ORDER BY id"
        );
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }
        return $rows;
    }

    /**
     * The amount of an approved charge or authorization by its transaction
     * id, for recording a full capture or a void, where the gateway does not
     * echo an amount back. 0.0 when unknown.
     */
    public static function amountForTransaction(string $trans_id): float
    {
        global $db;
        if (!defined('TABLE_AUTHORIZENET_ACCEPT') || !is_object($db) || $trans_id === '') {
            return 0.0;
        }
        $result = $db->Execute(
            "SELECT amount
               FROM " . TABLE_AUTHORIZENET_ACCEPT . "
              WHERE trans_id = '" . zen_db_input($trans_id) . "'
                AND transaction_type IN ('authOnlyTransaction', 'authCaptureTransaction')
                AND response_code IN (1, 4)
              ORDER BY id DESC
              LIMIT 1"
        );
        return $result->EOF ? 0.0 : (float)$result->fields['amount'];
    }

    /**
     * Write a log file. Only called when the module has decided logging is on.
     * The file name carries the transaction id when there is one, so a log can
     * be matched to the Merchant Interface.
     */
    public static function writeFile(string $kind, string $transId, string $body): void
    {
        $dir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : (defined('DIR_FS_SQL_CACHE') ? DIR_FS_SQL_CACHE : '');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $safeId = preg_replace('/[^A-Za-z0-9]/', '', $transId);
        $name = 'authnet_accept_' . preg_replace('/[^a-z0-9]/', '', strtolower($kind))
            . ($safeId !== '' ? '_' . $safeId : '')
            . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.log';
        @file_put_contents(rtrim($dir, '/\\') . '/' . $name, $body);
    }
}
