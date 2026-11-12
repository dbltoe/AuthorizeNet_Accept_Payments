# Configuration

All settings are under Admin > Modules > Payment > Authorize.net (Accept.js).

| Setting | Default | What it does |
|---|---|---|
| Enable Authorize.net (Accept.js) Module | True | Offer the module at checkout. |
| API Login ID | (blank) | From the Merchant Interface, Account > Settings > API Credentials & Keys. Use the sandbox account's when Transaction Mode is Sandbox. |
| Transaction Key | (blank) | From the same page. Stored with the password display; never sent to the browser. |
| Public Client Key | (blank) | Account > Settings > Manage Public Client Key. Sent to the browser for Accept.js; it can't be used to run transactions. |
| Transaction Mode | Sandbox | Sandbox: the Authorize.Net sandbox, with sandbox credentials, nothing charged. Test: your live account with every request flagged as a test, nothing charged. Production: live processing through a paid Authorize.Net account (monthly gateway fee plus per-transaction fees) with a merchant account behind it; see INSTALL.md, "Going live". |
| Authorization Type | Authorize+Capture | Authorize+Capture charges the card at checkout. Authorize reserves the funds; capture them from the order page within 30 days. |
| Request CVV Number | True | Ask for the card's security code. Keep it on. |
| Currency Supported | USD | The currency your Authorize.Net account settles in. Orders in another currency are converted with your store's exchange rates before submission; tax, shipping and line items are then omitted because they wouldn't add up. |
| Sort order of display | 0 | Position among the payment methods; lowest first. |
| Payment Zone | none | Offer the module only to customers whose billing address is in this zone. |
| Set Completed Order Status | Processing | Status for orders that were authorized and captured. |
| Set Authorized (Uncaptured) Order Status | Pending | Status for authorize-only orders until the funds are captured. |
| Set Refunded Order Status | Pending | Status given after a refund or void from the order page. |
| Set Held-For-Review Order Status | Pending | Status for orders the gateway's fraud filters hold. Approve or decline those in the Merchant Interface; the order stays in this status until you change it. |
| Gateway Receipt Email | False | Have Authorize.Net email its own receipt to the customer, on top of the store's order email. |
| Duplicate Window (seconds) | 120 | The gateway rejects a second transaction that matches an earlier one within this window. 0 turns the check off. |
| Send Line Items | True | Send the ordered products (up to 30) with the transaction so they show in the Merchant Interface. |
| Debug Mode | Off | Log File writes each gateway call to the store's logs folder with the key and nonce masked. Log and Email also emails the store owner about failed calls. Sandbox mode always writes the log. |

## Where the credentials come from

Merchant Interface > Account > Settings > Security Settings > General
Security Settings:

- **API Credentials & Keys** shows the API Login ID and lets you obtain a new
  Transaction Key (answer the security question). A new key disables the old
  one within 24 hours.
- **Manage Public Client Key** shows or creates the Public Client Key.

The sandbox Merchant Interface (https://sandbox.authorize.net) has the same
pages for the sandbox account.

## Statuses in practice

- Approved and captured: Completed Order Status.
- Approved, authorize-only: Authorized (Uncaptured) Order Status; capture
  from the order page moves it to the Completed status.
- Held for review (the gateway's fraud filters, response code 4): the order
  is accepted with the Held-For-Review status and a note in its history.
  Approve or decline in the Merchant Interface, then update the order.
- Declined or error: the customer is returned to the payment page with the
  gateway's reason; no order is created. The attempt is still recorded in the
  transaction table (without an order id) for troubleshooting.

## Settings survive a Remove

Modules > Payment > Remove keeps a copy of every setting except the on/off
switch in a single row of the configuration table
(`AUTHORIZENET_ACCEPT_SETTINGS_STASH`, in the payment modules group, so no
configuration page lists it). The next Install writes those values over the
defaults, deletes the row, and shows a message saying how many were restored.
Plugin Manager > Uninstall deletes the row without restoring anything.

## Logs

Log files are named `authnet_accept_<kind>_<transaction id>_<date>.log` and
land in the store's logs folder, so a log can be matched to a transaction in
the Merchant Interface. They never contain the transaction key, the nonce or
a card number.
