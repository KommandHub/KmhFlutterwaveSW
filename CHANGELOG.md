# 0.9.0-beta.2

Pre-release for internal development, QA and sandbox/staging testing. Fixes
issues found in end-to-end sandbox testing of `0.9.0-beta.1`; several are
security-relevant, so upgrading is strongly recommended.

**Upgrading from 0.9.0-beta.1:** the plugin's technical name changed from
`KommandhubFlutterwaveSW` to `KmhFlutterwaveSW`, so Shopware treats it as a new
plugin. Uninstall the old plugin, install and activate `KmhFlutterwaveSW`, then
re-enter the plugin settings (API keys, webhook secret hash). The payment method
and existing orders are kept; bank details customers saved under beta.1 are not
carried over and must be entered again.

- Security: a payment is now accepted only for the order it was created for. Previously an earlier successful payment of the same amount could be reused to mark a different order as paid.
- Security: `refund.completed` webhooks now confirm the refund status with Flutterwave instead of trusting the webhook body.
- Security: checkout is blocked with a clear message when the API key does not match the mode (a test key in live mode, or a live key in sandbox mode).
- Security: bank-account verification is rate limited (10 lookups per customer per hour), and only the account Flutterwave verified can be saved, with the name Flutterwave returned.
- Fixed: customers returning from card 3-D Secure landed on an error page although their payment had succeeded. Flutterwave now redirects to a dedicated, tamper-proof return URL.
- Fixed: every `refund.completed` webhook failed, so refunds were never completed in Shopware.
- Fixed: the over-refund check did not see earlier refunds; it now reads Flutterwave's complete refund history.
- Fixed: refunds that Flutterwave settles immediately are completed straight away instead of waiting for a webhook.
- Fixed: a webhook secret hash configured for a single sales channel is now accepted.
- Fixed: the bank-verification form in the customer account did not load.
- Fixed: untranslated message keys in the bank-details form and at checkout.
- Fixed (Administration): further refunds are possible after a partial refund; the refund dialog closes after success; a refund can no longer be submitted twice; the transaction state updates right after a refund.
- Calls to Flutterwave now time out after 30 seconds instead of blocking checkout.
- Supports Shopware 6.6 and 6.7.

# 0.9.0-beta.1

Pre-release for internal development, QA and sandbox/staging testing. Not yet
submitted to the Shopware Store. The public API and namespaces may still
change before `1.0.0`.

- Flutterwave payment for Shopware 6: card, bank transfer and mobile money.
- Payment verification checks status, amount and currency before an order is marked paid.
- Refunds from the order detail page, including partial refunds, with a live refund history and a server-side over-refund guard.
- Dedicated "Flutterwave refund" admin permission that can be assigned to roles (depends on the order editor permission).
- Webhook handling for `charge.completed` and `refund.completed`, with signature verification and idempotent, replay-safe processing.
- Bank-account verification in the customer account (account resolution via Flutterwave), with an optional BVN field.
- Amounts are sent to Flutterwave in major units, as its API expects, and compared exactly using each currency's own decimal precision — including zero- and three-decimal currencies (e.g. RWF, UGX, KWD). The plugin does not create currencies or languages in the shop.
- Plugin interface translated into English, German and French.
- Configurable logging (scoped per sales channel), sandbox/live mode and a minimum refund amount.
- Supports Shopware 6.6 and 6.7.
