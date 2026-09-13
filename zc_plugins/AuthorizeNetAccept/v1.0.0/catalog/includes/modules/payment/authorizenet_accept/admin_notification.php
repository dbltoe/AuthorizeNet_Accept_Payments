<?php
/**
 * Authorize.Net Accept.js Payments -- the block on the admin order page.
 *
 * Included by authorizenet_accept::admin_notification(), which sets:
 *   $order_id      the order being viewed
 *   $transactions  every row for it from the plugin's table, oldest first
 *   $last          the last APPROVED charge row, or null
 *   $captureOpen   true when that charge is an authorization not yet captured
 *
 * The forms post to Zen Cart's own doRefund / doCapture / doVoid actions on
 * the orders page, which call this module's _doRefund(), _doCapt() and
 * _doVoid(). Field names match what those methods (and the core AIM module
 * before them) expect, so anything already customized around AIM keeps
 * working.
 *
 * @package  AuthorizeNetAccept
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG') || IS_ADMIN_FLAG !== true) {
    die('Illegal Access');
}

$anaResultLabels = [
    AuthorizeNetAcceptApi::RESPONSE_APPROVED => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_APPROVED,
    AuthorizeNetAcceptApi::RESPONSE_DECLINED => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_DECLINED,
    AuthorizeNetAcceptApi::RESPONSE_ERROR => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_ERROR,
    AuthorizeNetAcceptApi::RESPONSE_HELD => MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_RESULT_HELD,
];
$anaTypeLabels = [
    'authCaptureTransaction' => 'Auth + Capture',
    'authOnlyTransaction' => 'Authorize only',
    'priorAuthCaptureTransaction' => 'Capture',
    'refundTransaction' => 'Refund',
    'voidTransaction' => 'Void',
];

$lastTransId = ($last !== null) ? (string)$last['trans_id'] : '';
$lastLast4 = ($last !== null) ? substr((string)$last['account_number'], -4) : '';
$lastAmount = ($last !== null) ? number_format((float)$last['amount'], 2, '.', '') : '';

$html = '<!-- BOF: Authorize.net Accept.js order tools -->' . "\n";
$html .= '<div class="authnet-accept-admin noprint" style="margin:8px 0">' . "\n";

// ---- history ----
$html .= '<strong>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_TRANSACTIONS_TITLE . '</strong> '
    . '<a href="https://account.authorize.net/" rel="noopener noreferrer" target="_blank">' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_MERCHANT_LINK . '</a>' . "\n";
if ($transactions === []) {
    $html .= '<p>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_NO_TRANSACTIONS . '</p>' . "\n";
} else {
    $html .= '<table class="table table-condensed" style="margin:6px 0 10px">' . "\n";
    $html .= '<tr>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_DATE . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_TYPE . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_TRANS_ID . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_RESULT . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AUTH . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AVS_CVV . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_CARD . '</th>'
        . '<th>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_COL_AMOUNT . '</th>'
        . '</tr>' . "\n";
    foreach ($transactions as $t) {
        $code = (int)$t['response_code'];
        $resultLabel = $anaResultLabels[$code] ?? ('Code ' . $code);
        if ($code !== AuthorizeNetAcceptApi::RESPONSE_APPROVED && (string)$t['response_text'] !== '') {
            $resultLabel .= ': ' . zen_output_string_protected((string)$t['response_text']);
        }
        $html .= '<tr>'
            . '<td>' . zen_output_string_protected((string)$t['date_added']) . '</td>'
            . '<td>' . zen_output_string_protected($anaTypeLabels[$t['transaction_type']] ?? (string)$t['transaction_type']) . '</td>'
            . '<td>' . zen_output_string_protected((string)$t['trans_id']) . '</td>'
            . '<td>' . $resultLabel . '</td>'
            . '<td>' . zen_output_string_protected((string)$t['auth_code']) . '</td>'
            . '<td>' . zen_output_string_protected(trim($t['avs_code'] . ' / ' . $t['cvv_code'], ' /')) . '</td>'
            . '<td>' . zen_output_string_protected(trim($t['account_type'] . ' ' . $t['account_number'])) . '</td>'
            . '<td>' . zen_output_string_protected(number_format((float)$t['amount'], 2, '.', '') . ' ' . $t['currency']) . '</td>'
            . '</tr>' . "\n";
    }
    $html .= '</table>' . "\n";
}

// ---- actions ----
$formParams = zen_get_all_get_params(['action']);
$html .= '<table class="noprint"><tr style="vertical-align:top">' . "\n";

// Refund
$html .= '<td><table><tr style="background-color:#dddddd;border-style:dotted"><td class="main">' . "\n";
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TITLE . '<br>' . "\n";
$html .= zen_draw_form('anarefund', FILENAME_ORDERS, $formParams . 'action=doRefund', 'post', '', true) . zen_hide_session_id();
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND . '<br>';
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_AMOUNT_TEXT . ' ' . zen_draw_input_field('refamt', $lastAmount, 'size="10"') . '<br>';
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_CC_NUM_TEXT . ' ' . zen_draw_input_field('cc_number', $lastLast4, 'size="6" maxlength="4"') . '<br>';
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TRANS_ID . ' ' . zen_draw_input_field('trans_id', $lastTransId, 'size="20"') . '<br>';
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_REFUND_CONFIRM_CHECK . zen_draw_checkbox_field('refconfirm', '', false) . '<br>';
$html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_TEXT_COMMENTS . '<br>' . zen_draw_textarea_field('refnote', 'soft', '50', '3', MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_DEFAULT_MESSAGE);
$html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_SUFFIX;
$html .= '<br><input type="submit" class="btn btn-default" name="buttonrefund" value="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_BUTTON_TEXT . '" title="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_REFUND_BUTTON_TEXT . '">';
$html .= '</form></td></tr></table></td>' . "\n";

// Capture (only shown when there is an uncaptured authorization, or the module authorizes only)
if ($captureOpen || $this->authorizeOnly()) {
    $html .= '<td><table><tr style="background-color:#dddddd;border-style:dotted"><td class="main">' . "\n";
    $html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TITLE . '<br>' . "\n";
    $html .= zen_draw_form('anacapture', FILENAME_ORDERS, $formParams . 'action=doCapture', 'post', '', true) . zen_hide_session_id();
    $html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE . '<br>';
    $html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_AMOUNT_TEXT . ' ' . zen_draw_input_field('captamt', '', 'size="10"') . '<br>';
    $html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TRANS_ID . ' ' . zen_draw_input_field('captauthid', $captureOpen ? $lastTransId : '', 'size="20"') . '<br>';
    $html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_CAPTURE_CONFIRM_CHECK . zen_draw_checkbox_field('captconfirm', '', false) . '<br>';
    $html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_TEXT_COMMENTS . '<br>' . zen_draw_textarea_field('captnote', 'soft', '50', '2', MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_DEFAULT_MESSAGE);
    $html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_SUFFIX;
    $html .= '<br><input type="submit" class="btn btn-default" name="btndocapture" value="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_BUTTON_TEXT . '" title="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_CAPTURE_BUTTON_TEXT . '">';
    $html .= '</form></td></tr></table></td>' . "\n";
}

// Void
$html .= '<td><table><tr style="background-color:#dddddd;border-style:dotted"><td class="main">' . "\n";
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_TITLE . '<br>' . "\n";
$html .= zen_draw_form('anavoid', FILENAME_ORDERS, $formParams . 'action=doVoid', 'post', '', true) . zen_hide_session_id();
$html .= MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID . '<br>' . zen_draw_input_field('voidauthid', $lastTransId, 'size="20"');
$html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_TEXT_VOID_CONFIRM_CHECK . zen_draw_checkbox_field('voidconfirm', '', false);
$html .= '<br><br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_TEXT_COMMENTS . '<br>' . zen_draw_textarea_field('voidnote', 'soft', '50', '3', MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_DEFAULT_MESSAGE);
$html .= '<br>' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_SUFFIX;
$html .= '<br><input type="submit" class="btn btn-default" name="ordervoid" value="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_BUTTON_TEXT . '" title="' . MODULE_PAYMENT_AUTHORIZENET_ACCEPT_ENTRY_VOID_BUTTON_TEXT . '">';
$html .= '</form></td></tr></table></td>' . "\n";

$html .= '</tr></table>' . "\n";
$html .= '</div>' . "\n";
$html .= '<!-- EOF: Authorize.net Accept.js order tools -->' . "\n";

return $html;
