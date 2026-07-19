<p align="center">
  <a href="https://kommandhub.com" target="_blank">
    <img src="src/Resources/config/kommandhub.png" alt="Kommandhub Logo">
  </a>
</p>

# Flutterwave Payment for Shopware 6

[![Shopware Plugin CI](https://github.com/KommandHub/KommandhubFlutterwaveSW/actions/workflows/php.yml/badge.svg)](https://github.com/KommandHub/KommandhubFlutterwaveSW/actions/workflows/php.yml)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)
[![Shopware](https://img.shields.io/badge/Shopware-6.6%20%7C%206.7-blue.svg)](https://shopware.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](https://phpstan.org)
![Status](https://img.shields.io/badge/status-beta-orange)

A production-grade **Shopware 6 payment plugin** that integrates the **[Flutterwave](https://flutterwave.com)** payment gateway, enabling merchants to accept secure payments across Africa and beyond through cards, bank transfers, and mobile money.

Developed by [Kommandhub Limited](https://kommandhub.com).

> **Independent integration.** This is an independent, third-party plugin. It is **not** affiliated with, endorsed by, sponsored by, certified by, or officially supported by Flutterwave. "Flutterwave" and the Flutterwave logo are trademarks of their respective owner and are used here only to identify the payment gateway this plugin connects to. See [Trademarks & Disclaimer](#trademarks--disclaimer).

> **Beta release.** Core checkout, webhooks, admin refunds and bank verification are implemented and tested; see [Version Compatibility](#version-compatibility) and the [Roadmap](#roadmap) before deploying to production.

This document is the technical reference for developers **contributing to** the plugin. If you only want to install and configure it on a live shop, the [Installation](#installation) and [Configuration Options](#configuration-options) sections are enough.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Key Features](#key-features)
- [Supported Payment Channels](#supported-payment-channels)
- [Architecture Overview](#architecture-overview)
- [Technology Stack](#technology-stack)
- [Directory Structure](#directory-structure)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Local Development Setup](#local-development-setup)
- [Docker & Docker Compose](#docker--docker-compose)
- [Makefile Commands](#makefile-commands)
- [Configuration Options](#configuration-options)
- [Build & Asset Compilation](#build--asset-compilation)
- [Testing](#testing)
- [Code Quality](#code-quality)
- [Data Handling](#data-handling)
- [Logging & Debugging](#logging--debugging)
- [Security Considerations](#security-considerations)
- [Performance Considerations](#performance-considerations)
- [Deployment](#deployment)
- [CI/CD](#cicd)
- [Version Compatibility](#version-compatibility)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Coding Standards](#coding-standards)
- [Roadmap](#roadmap)
- [License](#license)
- [Trademarks & Disclaimer](#trademarks--disclaimer)
- [Support](#support)

---

## Project Overview

The plugin adds Flutterwave as a native Shopware 6 payment method. It handles the full payment lifecycle:

1. **Initialize** a Flutterwave transaction and redirect the customer to Flutterwave's hosted checkout.
2. **Finalize** the payment on return by verifying the transaction against the Flutterwave API — matching **status, amount, and currency** before the order is marked paid.
3. **Reconcile** payments asynchronously via webhooks (for cases where the customer never returns from the redirect).
4. **Refund** orders (full or partial) from the Administration, guarded by a dedicated permission and a server-side over-refund check. Refunds are asynchronous at Flutterwave (typically 3–15 working days), so the local record stays pending until a `refund.completed` webhook confirms the outcome.

It also provides customer bank-account verification (via Flutterwave's account-resolution endpoint, with an optional BVN field) and correct money handling across the currencies Flutterwave supports, including zero-decimal (e.g. RWF, UGX) and three-decimal (e.g. KWD) currencies.

> The plugin does **not** create currencies or languages in your shop. Amounts are converted correctly for whichever currency an order uses; adding a currency or a language to Shopware remains a normal shop configuration step.
>
> **Amounts on the wire are major units**, not minor units. Flutterwave charges exactly the value it is given (₦100.00 is sent as `100`), unlike some other African payment gateways that expect minor units (kobo/pesewas). This is the single most consequential fact to know before touching `Checkout/Payment` or `Client/` — see [Coding Standards](#coding-standards).

---

## Key Features

- Native Shopware 6 payment method with Flutterwave hosted checkout.
- Payment verification that validates **status + amount + currency** (the return query string is attacker-controllable, so all three are re-checked against the API, never trusted from the redirect).
- Asynchronous reconciliation through webhooks: `charge.completed`, `refund.completed`. Webhook payloads are never trusted directly — every handler re-verifies against the Flutterwave API before mutating an order (see [Security Considerations](#security-considerations) for why this matters more here than for HMAC-signed providers).
- Full and partial refunds from the order detail page, with a **server-side over-refund cap** computed from Flutterwave's own live refund history — not a locally mirrored ledger.
- A dedicated **`flutterwave.refund`** admin permission, assignable to roles (depends on the order editor permission).
- Customer bank-account verification in the account area, with an optional BVN (Bank Verification Number) field that can be shown or made mandatory independently, masked to its last 4 digits wherever displayed.
- Correct amount handling for every currency Flutterwave supports, including 0-decimal (e.g. RWF, UGX) and 3-decimal (e.g. KWD) currencies — amount **comparisons** are always done in minor units for exactness, while amounts **sent to Flutterwave** stay in major units.
- Plugin interface translated into English, German and French (administration + storefront), with full key parity verified across all three locales.
- Configurable, level-filtered logging, scoped per sales channel, with a sandbox/live mode toggle.

---

## Supported Payment Channels

Configurable under **Customization → Payment Options**; leave empty to offer everything available on your Flutterwave account:

- Card
- Bank account (direct debit) and bank transfer
- M-Pesa
- Mobile money (Ghana, Francophone Africa, Uganda, Rwanda, Zambia)
- Barter, QR (NQR), USSD, Credit, Opay

Availability ultimately depends on your Flutterwave account and country configuration.

---

## Architecture Overview

The plugin uses a **feature-first** layout that mirrors Shopware's own plugins (for example, SwagPayPal). Each top-level directory under `src/` is a bounded feature; inside it, code is organized into flat, Symfony-idiomatic folders (`Service`, `Subscriber`, `Handler`, `Struct`, `Event`, `Controller`). There is no `Application/Domain/Infrastructure` layering — a top-level module *is* the boundary, and every class has one obvious home.

**Guiding principles**

- Shopware entry points (payment handler, controllers, subscribers) stay thin and delegate to services. `FlutterwaveTransactionHandler` itself is a pure delegator to `PaymentProcessor`/`FinalizeProcessor`/`RefundProcessor`.
- A change stays inside its feature module; cross-module access goes through a service, not by deep-linking another module's internals.
- New Flutterwave API calls go through a typed `Client/Resource/` class, never raw HTTP.
- Money is always in **major units** on the wire and **minor units** for comparison, converted only through `Util\FlutterwaveCurrencyHelper` — the reverse convention from minor-unit gateways, and the most common source of a wrong-amount bug if assumed otherwise.

**Payment flow**

```text
Customer selects Flutterwave
        │
        ▼
PaymentProcessor.process()  ──►  Flutterwave transaction initialized, tx_ref = order transaction id
        │
        ▼
Redirect to Flutterwave hosted checkout
        │
        ▼
Customer pays ──► redirect back ──► FinalizeProcessor.process()
        │                                   verify(transaction_id): status AND amount AND currency match
        ▼
Order transaction marked "paid"

If the customer never returns, the `charge.completed` webhook reconciles the order
using tx_ref (idempotent: it no-ops if already paid, and ignores a tx_ref that
isn't a Shopware id — e.g. a charge from another integration on the same account).
```

**Refund flow**

```text
Admin opens the order → Flutterwave tab → Refund
        │  (client gate: flutterwave.refund permission + refundable balance)
        ▼
RefundController.refund()  ──►  server gate: _acl flutterwave.refund, min & max
        │                        (over-refund) checks against Flutterwave's LIVE refund history
        ▼
Flutterwave acknowledges the refund request (money moves asynchronously, 3-15 working days)
        │
        ▼
Local refund created in its PENDING state, stamped with Flutterwave's refund id
as `externalReference` — the correlation key the webhook looks it up by
        │
        ▼
`refund.completed` webhook  ──►  RefundCompletedSubscriber finds the pending
        refund by externalReference, re-verifies the status is genuinely final,
        and only then completes or fails it (idempotent against redelivery and
        against a refund already in a final state)
```

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.2+ (8.3 in the dev container) |
| Framework | Shopware 6 (`shopware/core`, `shopware/storefront`), Symfony |
| Admin UI | Vue, Shopware Administration (mid-migration from `sw-*` to the Meteor Component Library's `mt-*`) |
| Admin build | Vite (output committed under `Resources/public/`) |
| Storefront | Twig + vanilla JS plugin (output under `Resources/app/storefront/dist/`) |
| Testing | PHPUnit 11 |
| Static analysis | PHPStan (level 9) |
| Code style | PHP-CS-Fixer |
| Local environment | Docker + Docker Compose (`dockware/shopware`) |
| CI | GitHub Actions |

---

## Directory Structure

```text
KommandhubFlutterwaveSW/
├── src/
│   ├── KommandhubFlutterwaveSW.php     # Plugin base class (lifecycle hooks)
│   ├── Administration/
│   │   └── Controller/RefundController.php  # Admin refund API + refund-history endpoint
│   ├── BankVerification/
│   │   ├── Controller/                   # Storefront account-verification endpoints
│   │   └── Service/                      # Bank validation
│   ├── Checkout/
│   │   ├── Payment/
│   │   │   ├── FlutterwavePaymentHandler.php      # Thin Shopware payment handler
│   │   │   ├── AbstractPaymentHandler.php
│   │   │   ├── Service/                  # PaymentProcessor, FinalizeProcessor,
│   │   │   │                             #   RefundProcessor, RefundAggregator, …
│   │   │   └── Struct/                   # PaymentPayload, FlutterwaveInitializationResponse
│   │   └── Cart/Validation/               # TransactionCartValidator
│   ├── Client/                           # Flutterwave REST client
│   │   ├── FlutterwaveClient.php
│   │   ├── Http/                         # HttpClientInterface + FlutterwaveHttpClient
│   │   └── Resource/                     # One typed class per Flutterwave endpoint
│   │                                     #   (Transaction, Refund, Bank, Bvn, Subaccount)
│   ├── Webhook/
│   │   ├── Controller/                   # Storefront webhook endpoint
│   │   ├── Service/                      # Signature validator, event factory, processor, dedup
│   │   ├── Subscriber/                   # ChargeCompletedSubscriber, RefundCompletedSubscriber
│   │   └── Event/                        # WebhookEvent + charge/refund events
│   ├── Setting/Service/Config.php        # Typed access to system configuration
│   ├── Service/                          # OrderTransactionService, PayloadBuilder
│   ├── Logging/ConfigurableLogger.php    # Level-filtered, sales-channel-scoped logger
│   ├── Exception/                        # Domain exceptions
│   ├── Util/                             # FlutterwaveConstants, FlutterwaveCurrencyHelper,
│   │                                     #   OrderCurrencyResolver
│   ├── Installer/                        # Payment method + custom fields installers
│   └── Resources/
│       ├── config/                       # services.yml, routes.yml, config.xml
│       ├── snippet/                      # Storefront snippets (en-GB, de-DE, fr-FR)
│       ├── views/                        # Twig templates
│       ├── app/administration/           # Vue admin source (src/) + built assets (public/)
│       └── app/storefront/               # Storefront JS source + dist
├── tests/
│   ├── Unit/                             # Mirrors src/, no Shopware kernel (36 test classes)
│   └── Integration/                      # Handler delegation test
├── .github/workflows/php.yml             # CI pipeline
├── composer.json
├── phpstan.dist.neon
├── phpunit.dist.xml
├── .php-cs-fixer.dist.php
├── docker-compose.yml
├── Dockerfile
├── Makefile
├── CHANGELOG.md                          # English; CHANGELOG_<locale>.md for translations
└── README.md
```

> Assets under `Resources/public/` are **generated**. Never hand-edit them — change the source in `Resources/app/administration/src/` and rebuild (see [Build & Asset Compilation](#build--asset-compilation)).

---

## System Requirements

- **Shopware**: `~6.6.0` or `~6.7.0`
- **PHP**: `8.2+`
- **Composer**: 2.x
- **Flutterwave account**: <https://dashboard.flutterwave.com/>
- **For local development**: Docker and Docker Compose
- **For admin/storefront asset builds**: Node.js (provided inside the dev container)

---

## Installation

### Via Composer (recommended)

```bash
composer require kommandhub/flutterwave-sw
bin/console plugin:refresh
bin/console plugin:install --activate KommandhubFlutterwaveSW
bin/console cache:clear
```

### Manual upload

1. Build a ZIP containing at least `src/` and `composer.json`.
2. In the Administration, go to **Extensions → My Extensions → Upload Extension**.
3. Install and activate **Flutterwave for Shopware**.

After installation, assign the **Flutterwave** payment method to your sales channel under **Settings → Shop → Payment Methods**.

---

## Local Development Setup

The repository ships a Docker Compose stack based on [`dockware`](https://dockware.io) that mounts this plugin into a full Shopware install.

```bash
# 1. Clone the repository
git clone <repository-url> KommandhubFlutterwaveSW
cd KommandhubFlutterwaveSW

# 2. Start the stack (builds the container and prepares the shop)
make up

# 3. Install and activate the plugin inside the container
make shell
bin/console plugin:refresh
bin/console plugin:install --activate KommandhubFlutterwaveSW
bin/console cache:clear
exit
```

The plugin directory is mounted at `/var/www/html/custom/static-plugins/KommandhubFlutterwaveSW`; `.git/`, `node_modules/`, and `vendor/` are excluded from the mount. Changes to source files on the host are reflected immediately in the container.

Default dockware credentials:

- **Admin**: user `admin`, password `shopware`
- **Database**: user `root`, password `root`, database `shopware`

---

## Docker & Docker Compose

The stack is defined in [`docker-compose.yml`](docker-compose.yml) and built from a small [`Dockerfile`](Dockerfile):

- **Base image**: `dockware/shopware:6.7.8.0`
- **Added tooling**: [`shopware-cli`](https://sw-cli.fos.gg/) — the static binary is copied from the upstream `shopware/shopware-cli:bin` image (latest stable, ~50 MB, multi-arch), so no package manager or cleanup is involved. The build runs `shopware-cli --version` so a broken install fails the image build rather than the first `make validate-plugin`. Pin it for a reproducible build by passing `--build-arg SHOPWARE_CLI_IMAGE=shopware/shopware-cli:bin@sha256:<digest>`.
- **Container**: `shopware-flutterwave`
- **PHP**: 8.3 (`XDEBUG_ENABLED` toggle available)
- **Persistent volume**: `database` (MySQL data)

```bash
make up        # build + start + prepare
make down      # stop and REMOVE volumes (wipes the database)
make shell     # open a shell in the container
```

> **No host port is published by default.** To reach the Administration from your browser, add a mapping such as `ports: ["80:80"]` to the `shopware` service and reload with `docker compose up -d`. Do **not** use `make restart` for this — it runs `docker compose down -v`, which deletes the database volume and the installed shop.

---

## Makefile Commands

All targets run the underlying tools inside the running container.

| Command | Description |
| --- | --- |
| `make up` | Build, start, and prepare the Shopware container |
| `make down` | Stop the container and remove volumes (wipes the DB) |
| `make build` | Rebuild the container image |
| `make restart` | `down` + `up` (wipes the DB) |
| `make shell` | Open a bash shell in the container |
| `make plugin-list` | List installed plugins |
| `make test` | Run PHPUnit. Filter with `make test FILTER="--filter SomeTest"` |
| `make test-coverage` | Run PHPUnit with a text coverage report |
| `make cs` | PHP-CS-Fixer dry run (no changes) |
| `make cs-fix` | PHP-CS-Fixer, applying fixes |
| `make analyse` | PHPStan static analysis on `src/` |
| `make fixture-load` | Load test fixtures |
| `make resync` | Sync the test config into the shop root |
| `make prepare` | Full project preparation (run by `make up`) |
| `make validate-plugin` | Validate the plugin with `shopware-cli` (`--full --store-compliance`) |
| `make cli` | Run any `shopware-cli` command: `make cli ARGS="extension get-version ."` |
| `make changelog` | Render `CHANGELOG.md` as the Shopware Store would display it |
| `make zip` | Build a distributable plugin zip into `build/` |

`shopware-cli` ships inside the container image (see [Docker & Docker Compose](#docker--docker-compose)), so these run against the same PHP version and installed Shopware as the tests — not whatever is on the host. No local install is required.

**Before committing, run:**

```bash
make cs-fix && make analyse && make test
```

---

## Configuration Options

Configure the plugin under **Extensions → My Extensions → Flutterwave → Configuration**. Options are stored in Shopware's system configuration under the `KommandhubFlutterwaveSW.config.*` domain and read through `Setting\Service\Config`.

| Key | Type | Purpose |
| --- | --- | --- |
| `apiPublicKey` | password | Live public key (`FLWPUBK-...`) |
| `apiSecretKey` | password | Live secret key (`FLWSECK-...`) |
| `secretHash` | password | Live webhook secret hash (see [Security Considerations](#security-considerations)) |
| `enableSandbox` | bool | Use sandbox/test credentials instead of live |
| `apiPublicKeySandbox` | password | Test public key (`FLWPUBK_TEST-...`) |
| `apiSecretKeySandbox` | password | Test secret key (`FLWSECK_TEST-...`) |
| `secretHashSandbox` | password | Sandbox webhook secret hash — Flutterwave allows a different hash per environment |
| `title` | text | Payment method title shown on Flutterwave's checkout |
| `description` | textarea | Payment method description shown on Flutterwave's checkout |
| `logo` | media | Logo shown on Flutterwave's checkout |
| `paymentOptions` | multi-select | Which [payment channels](#supported-payment-channels) to offer; empty = all |
| `refundEnabled` | bool | Allow refunds from the Administration |
| `minimumRefundAmount` | float | Minimum refund amount, in the order currency's **major** units |
| `collectBankData` | bool | Collect customer bank data in the account area |
| `bankCountry` | text | ISO 3166-1 alpha-2 country whose bank list is offered (default `NG`) |
| `showBvnField` | bool | Show the BVN field on the bank-account form |
| `requireBvn` | bool | Make the BVN field required |
| `enableDebugging` | bool | Enable verbose logging |
| `logLevels` | multi-select | PSR-3 levels to log when debugging is enabled |

> Secret keys and the webhook secret hash are stored in Shopware's system configuration, never in code. Unlike the API keys, the secret hash is **not** derived from your Flutterwave credentials — it is a value you choose yourself and must enter identically on both sides (see [Security Considerations](#security-considerations)).

---

## Build & Asset Compilation

PHP has no build step beyond `composer install`. Frontend assets do, and their compiled output is committed to the repository.

**Administration (Vue → Vite)**, output to `Resources/public/administration/`:

```bash
make shell
./bin/build-administration.sh
```

**Storefront**, output to `Resources/app/storefront/dist/`:

```bash
make shell
./bin/build-storefront.sh
```

Rebuild the relevant bundle whenever you change source under `Resources/app/administration/src/` or `Resources/app/storefront/src/`, and commit the regenerated assets together with the source. Keeping route names, service IDs, and custom-field keys stable means most PHP changes require **no** asset rebuild.

---

## Testing

Tests live under `tests/` and mirror `src/`. Each behavior change should ship with a test.

```bash
make test                              # full suite
make test FILTER="--filter RefundControllerTest"
make test-coverage                     # text coverage report
```

- **Unit tests** (`tests/Unit/`, 36 test classes) use plain `PHPUnit\Framework\TestCase` with mocks and require no Shopware kernel. These run in CI.
- **Integration tests** (`tests/Integration/`) confirm the payment handler correctly delegates to its processors, using Shopware's `TestBootstrapper`. These need a booted kernel and database, so they run locally via `make test` rather than in the CI job.
- **End-to-end**: there is no automated E2E suite yet. The payment, refund and webhook flows are validated manually against a running shop with Flutterwave **test** credentials. Automating this is on the [roadmap](#roadmap).

Pure business logic in the admin (`Resources/app/administration/src/service/refund-calculator.js`) has a companion Jest-style spec (`refund-calculator.spec.js`) for a full Shopware admin test runner.

---

## Code Quality

| Tool | Command | Config |
| --- | --- | --- |
| PHPStan (level 9) | `make analyse` | `phpstan.dist.neon` |
| PHP-CS-Fixer | `make cs` / `make cs-fix` | `.php-cs-fixer.dist.php` |
| PHPUnit | `make test` | `phpunit.dist.xml` |

CI enforces PHP lint, PHPStan, code style, and unit tests. It does not currently enforce a coverage threshold; the plugin is at 100% line/method/class coverage locally as of `0.9.0-beta.1`, verified with `make test-coverage`. Kernel-dependent (integration) tests are excluded from CI.

---

## Data Handling

The plugin ships **no schema migrations** — it does not alter the database schema. All setup is performed through Shopware's plugin lifecycle by dedicated installers:

- **Payment method**: created and kept in sync by `Installer\PaymentMethodInstaller`.
- **Custom fields** (Flutterwave reference, transaction id, amount, currency, fee, processed-webhook-event ids, bank profile, BVN): created by `Installer\CustomFieldsInstaller` during install/update.

These run on `install()`/`update()` and are reverted appropriately on uninstall (custom fields — including any stored BVN — are removed only when the user does not keep plugin data).

> `executeComposerCommands()` returns `false`: the plugin's own runtime dependencies (`shopware/core`, `shopware/storefront`) are guaranteed present in any Shopware install, so there is nothing for Shopware to `composer require`/`remove` on install or uninstall. Returning `true` here would shell out to Composer on every lifecycle event for no reason.

---

## Logging & Debugging

Logging goes through `Logging\ConfigurableLogger`, wired to the `flutterwave_channel` Monolog channel (writes to `var/log/`).

- Set `enableDebugging` and pick `logLevels` to control verbose output. Both are resolved **per sales channel** — a merchant who enables debugging on one sales channel gets output for that channel only, not the whole install.
- **Error, critical, alert, and emergency messages are always written**, regardless of the debugging toggle, so production keeps a trail of failures (webhook signature rejections, verification/refund errors).
- Enable Xdebug in the container by setting `XDEBUG_ENABLED=1` in `docker-compose.yml` and restarting.

---

## Security Considerations

- **Webhook authenticity — read this carefully, it differs from most providers.** Flutterwave does **not** sign the webhook payload. It echoes back the static secret hash you configure in its dashboard, verbatim, in a `verif-hash` header. `WebhookSignatureValidator` compares it with `hash_equals` (timing-safe) and rejects a missing or mismatched header, or an unconfigured hash. A valid header proves only that the *sender* knows the shared secret — it proves nothing about the body, since nothing binds the hash to the payload's contents. **Every webhook handler therefore treats the payload as untrusted input**: it extracts only an identifier (a transaction id or refund id) and re-fetches the authoritative state from the Flutterwave API before making any change. This is a deliberate, load-bearing design decision, not an oversight — do not "simplify" a handler to trust payload fields like `status` directly.
- **Payment verification**: an order is marked paid only when the verified transaction matches **status AND amount AND currency**. The return query string comes from an attacker-controllable redirect, so it is never trusted on its own.
- **Refund authorization**: the refund endpoint requires the dedicated `flutterwave.refund` privilege (route `_acl`), and the admin action is gated by the same permission.
- **Over-refund protection**: the server recomputes the refundable balance from Flutterwave's own live refund history (not a locally mirrored ledger) and rejects amounts above it and below the configured minimum. The client-side bound is a UX aid only.
- **Refund idempotency**: `refund.completed` deliveries are matched to a pending refund by `externalReference` (Flutterwave's refund id) and are safe to redeliver — a refund already in a final state, or an event already processed, is a no-op rather than a duplicate transition.
- **Secrets**: API keys and the webhook secret hash live in Shopware's system configuration, not in the codebase or in URLs, and are never written to logs.
- **BVN handling**: collected only when explicitly enabled (off by default), and shown to the customer masked to its last 4 digits wherever it is displayed. It is stored as entered in the customer's custom fields — there is no field-level encryption at rest, a known consideration tracked for `1.0.0`.
- **Money handling**: amounts sent to Flutterwave stay in **major units** — never multiply by 100 for this gateway. Amount **comparisons** (verification, over-refund checks) go through `Util\FlutterwaveCurrencyHelper`, which scales to minor units first so floating-point representation error cannot mask a genuine mismatch, and respects each currency's own decimal count.

---

## Performance Considerations

- Order/transaction reads use targeted DAL criteria with only the associations they need.
- The admin detail view guards against concurrent refund-history loads and loads plugin configuration reactively rather than on a fixed lifecycle tick.
- Refund math is a pure, memoizable computation isolated in `refund-calculator.js`.
- Webhook processing does one API round-trip (verify or fetch-refund-history) per delivery; Flutterwave's own retry behavior on non-200 responses means a slow downstream dependency should fail fast rather than hold the request open.

---

## Deployment

1. Ship the plugin via Composer (`composer require kommandhub/flutterwave-sw`) or an Administration upload.
2. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:update KommandhubFlutterwaveSW
   bin/console cache:clear
   ```
3. Ensure compiled Administration and Storefront assets are built and committed as part of the release.
4. Configure live API keys, set the **live** webhook secret hash to match your Flutterwave dashboard exactly, and disable sandbox mode.
5. Point your Flutterwave webhook URL at `https://<your-shop-domain>/flutterwave/webhook`.
6. Grant the **Flutterwave → Process Flutterwave refunds** permission to the roles that should be able to issue refunds (the built-in admin role already has all privileges).

Validate a release build for the Shopware Store from inside the container, where `shopware-cli` runs against the installed Shopware and full vendor tree:

```bash
make validate-plugin
# equivalently: make cli ARGS="extension validate . --full --store-compliance"
```

Running the same command against a host-only `shopware-cli` reports false positives (see [Troubleshooting](#troubleshooting)) — the container has the dependencies it needs to resolve Shopware's classes.

---

## CI/CD

GitHub Actions (`.github/workflows/php.yml`) runs on pushes to `main`/`develop` and on pull requests:

1. Validate `composer.json`.
2. Install dependencies.
3. Lint all PHP files.
4. PHPStan (level 9).
5. PHP-CS-Fixer (dry run).
6. PHPUnit unit tests (`tests/Unit` only, no coverage threshold enforced).

Integration tests are excluded from this job because they need a booted Shopware kernel; run them locally with `make test`.

---

## Version Compatibility

| Plugin | Shopware | PHP |
| --- | --- | --- |
| `0.9.0-beta.x` | 6.6 and 6.7 | 8.2+ |

A single plugin release supports both Shopware 6.6 and 6.7 (`shopware/core: ~6.6.0 || ~6.7.0`).

> **Pre-1.0 status:** the plugin is on a `0.x` version while it completes sandbox/staging validation (see [Roadmap](#roadmap)). Namespaces and the public API may still change before `1.0.0`. One known, deliberately-unfixed store-compliance finding: `PaymentMethodBlockedError`'s constructor is forward-deprecated for Shopware 6.8's parameter order, a version this plugin does not yet declare support for — reordering the call now would pass wrong values on the plugin's actual 6.6/6.7 range, so it is addressed when 6.8 support is added.

---

## Troubleshooting

**Order status not updating after payment**
- Confirm the webhook endpoint is reachable from Flutterwave and the secret hash matches the mode (test vs live) exactly.
- Check `var/log/` for verification or signature errors.

**Webhooks return 403 / signature validation failed**
- The `secretHash` (or `secretHashSandbox` in sandbox mode) is either unset in the plugin configuration or does not exactly match the value under **Settings → Webhooks** in the Flutterwave dashboard. This is a value you choose, not one of your API keys.

**Bank account verification fails in sandbox with "destbankcode/account_bank must be numeric and only 044 is allowed"**
- Flutterwave's sandbox only resolves accounts against bank code `044` (Access Bank). This is handled automatically — the plugin substitutes `044` for the resolve call while sandbox mode is on and still stores the customer's real selection — but a direct API test outside the plugin will hit this restriction.

**Plugin not visible in the Administration**
```bash
bin/console plugin:refresh
```

**Stale Administration UI or DI errors after changes**
```bash
bin/console cache:clear
```

**Refund action not shown**
- Ensure `refundEnabled` is on, the role has the `flutterwave.refund` permission, and the transaction still has a refundable balance.

**PHPStan can't find `Shopware\Storefront\...` during `shopware-cli` validation**
- `shopware/storefront` must be declared in `require` (it is). Do not point `scanDirectories` at the host's `vendor/` — let Composer install and autoload it. Run `make validate-plugin` rather than a host-installed `shopware-cli`, which lacks this context entirely.

---

## Contributing

1. Create a feature branch off `develop`.
2. Keep changes inside the relevant feature module; reach across modules through services.
3. Add or update tests alongside behavior changes (mirror the `src/` path under `tests/`).
4. Rebuild admin/storefront assets if you touched their source, and commit the output.
5. Run the full local gate before opening a PR:
   ```bash
   make cs-fix && make analyse && make test
   ```
6. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages (`feat:`, `fix:`, `refactor:`, `test:`, …).
7. Open a pull request against `develop`.

---

## Coding Standards

- **PHP style**: PSR-12, enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`).
- **Static analysis**: PHPStan level 9 must pass with no new errors.
- **Architecture**: feature-first modules; one obvious home per class; thin Shopware entry points delegating to services.
- **Flutterwave API**: add new calls as typed classes under `Client/Resource/`, never as raw HTTP.
- **Money — the rule most likely to bite you**: amounts sent to Flutterwave are **major units** (never scale by a currency's decimal count before sending). Amounts being **compared** (verification, refund balance) always go through `Util\FlutterwaveCurrencyHelper`, which scales to minor units for exactness. Mixing these two conventions up is the single easiest way to introduce a 100x overcharge or a false verification mismatch.
- **Custom-field keys**: defined only in `Util\FlutterwaveConstants` (`flutterwave_reference` is the webhook lookup key; `flutterwave_processed_events` backs webhook idempotency).
- **Dependency injection**: services are autowired via the `../../*` glob in `services.yml`. Symfony does not auto-alias an interface to its single implementation — when you add a constructor-injected `*Interface` (e.g. `Client\Http\HttpClientInterface`), add an explicit `alias:` entry.
- **Webhook handlers never trust payload values beyond an identifier.** See [Security Considerations](#security-considerations) — this is the plugin's central security invariant, not a style preference.

---

## Roadmap

Planned for `1.0.0`, once this Beta has been validated in production:

- Subaccounts / split payments.
- Full BVN identity resolution (Flutterwave's consent-portal flow; requires separate approval from Flutterwave and is a larger UX addition than the current bank-account verification).
- Field-level encryption for stored BVN data.
- Store-compliance cleanup for Shopware 6.8 once it is added to the supported range.
- Automated end-to-end coverage, planned in layers, cheapest first:
  1. **Flutterwave sandbox contract tests** — call the real API with test credentials to prove the client matches Flutterwave's contract and that amounts survive the round trip in major units.
  2. **Webhook endpoint tests** — POST valid/invalid `verif-hash` payloads over HTTP.
  3. **Browser tests** — storefront checkout through Flutterwave's hosted page and the Administration refund flow.
  Notes for whoever picks this up: gate the suite behind an env var so it skips by default, refuse to run against live credentials, exclude it from CI, and remember that real webhook delivery needs a public tunnel **and** the tunnel hostname registered as a Shopware sales-channel domain (otherwise Shopware answers `400` before the controller runs).

See [CHANGELOG.md](CHANGELOG.md) for released changes.

---

## License

Licensed under the **Apache License 2.0**. See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.

Apache-2.0 was chosen over a simpler permissive licence for its explicit patent grant, its explicit reservation of trademark rights (§6), and the `NOTICE` mechanism that carries attribution downstream into forks. The licence covers this plugin's **own source code only**. It grants **no rights** in the KommandHub name or logo (see [TRADEMARKS.md](TRADEMARKS.md)), nor in Flutterwave's trademarks, logos, or services (see [Trademarks & Disclaimer](#trademarks--disclaimer)).

**Contributing, security, and conduct:** see [CONTRIBUTING.md](CONTRIBUTING.md) (contributions are under Apache-2.0 via the DCO), [SECURITY.md](SECURITY.md), and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

---

## Trademarks & Disclaimer

This plugin is an **independent, third-party integration** developed and maintained by [Kommandhub Limited](https://kommandhub.com). It is **not** affiliated with, endorsed by, sponsored by, certified by, or officially supported by Flutterwave or any of its affiliates.

"Flutterwave", the Flutterwave logo, and any related names, marks, and logos are trademarks of their respective owner. All other trademarks referenced in this project are the property of their respective owners. These marks are used in this project solely for **nominative purposes** — to identify the third-party payment gateway that this plugin connects to — and their use does not imply any endorsement, partnership, or affiliation.

Use of the Flutterwave payment gateway is subject to Flutterwave's own terms of service and agreements, which are between the merchant and Flutterwave. This plugin merely provides a technical integration and makes no warranty regarding Flutterwave's services. To use it you must hold your own valid Flutterwave account and API credentials.

**KommandHub's own marks** — the "KommandHub" name and logo — are trademarks of Kommandhub Limited. The open-source licence covers the code, not the brand: a fork must be **rebranded** before redistribution. The full policy is in [TRADEMARKS.md](TRADEMARKS.md).

---

## Support

- Email: [info@kommandhub.com](mailto:info@kommandhub.com)
- Website: <https://kommandhub.com>
- Support: <https://kommandhub.com/support>
