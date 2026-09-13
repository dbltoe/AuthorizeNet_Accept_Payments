# Compatibility

## Zen Cart

| Release | Status | Notes |
|---|---|---|
| 3.0.0 (dev) | Supported | Tested from the lab's master checkout. |
| 2.3.x | Supported | |
| 2.2.x | Supported | The primary test bed. |
| 2.1.0 | Supported | The earliest release that loads a payment module from a plugin. |
| 2.0.x, 1.5.8 and earlier | Not supported | The payment class doesn't look in `zc_plugins`; the installer refuses with a message. |

One codebase for all of them: no `zen_config()`, no PHP 8-only syntax, and
`tests/zc_compat.php` checks every `zen_*` call against real installs of each
release.

## PHP

7.4 through 8.5. The harness lints and runs on each build it can find.

## Checkout flows

- Standard checkout: yes.
- PA-DSS AJAX confirmation (`PADSS_AJAX_CHECKOUT`): yes; the module maps its
  fields through `process_button_ajax()`.
- One Page Checkout (lat9): yes. The checkout script intercepts OPC's Review
  and Confirm buttons the same way it intercepts Continue.

## Templates

Nothing template-specific. The payment block is Zen Cart's own; the only
markup the module adds is inside its fields (an error box and hidden fields),
and the card inputs carry the same ids the AIM module used
(`authorizenet_accept-cc-number` and so on) so any CSS written for AIM's
`authorizenet_aim-cc-number` can be pointed at them with a rename.

## Browsers

Accept.js supports current browsers. The checkout script uses
`Element.closest()` and `addEventListener` capture, which every browser since
2015 has. Without JavaScript the form posts with no nonce and the server
declines the order with a message rather than accepting a card number.

## Other Authorize.Net modules

Runs alongside the core AIM and SIM modules and proseLA's CIM module; it uses
its own table and its own configuration keys. Only one of them should be
enabled at checkout unless you want customers to see two "Credit Card"
entries.

## Currencies

The gateway account settles in one currency (USD, CAD, GBP, EUR, AUD or NZD).
Orders in another store currency are converted before submission using the
store's exchange rates, and the history line records the converted amount.
