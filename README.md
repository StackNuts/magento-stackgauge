# StackNuts StackGauge

[![Latest Version](https://img.shields.io/packagist/v/stacknuts/magento-stackgauge.svg)](https://packagist.org/packages/stacknuts/magento-stackgauge) [![License](https://img.shields.io/packagist/l/stacknuts/magento-stackgauge.svg)](https://github.com/StackNuts/magento-stackgauge/blob/main/LICENSE) [![PHP Version](https://img.shields.io/packagist/php-v/stacknuts/magento-stackgauge.svg)](https://packagist.org/packages/stacknuts/magento-stackgauge)

A lightweight, read-only agent that reports a Magento store's version, patch, and
operational health data to a central StackGauge fleet dashboard - so an agency managing many
client sites can see everyone's update/security posture at a glance, instead of checking
each store by hand.

## Why

External scanners only see what a store exposes publicly - they can't read `composer.json`,
true per-module versions, or DB schema drift. This module runs *inside* Magento and reports
what only the inside can see, on a schedule, to a dashboard your agency controls.

## What it is

- **Push only.** This module calls out over HTTPS; it exposes no inbound endpoint and no new
  attack surface. The dashboard is the server; this module is purely a client.
- **Read-only.** Every built-in reporter only reads store/platform state. Nothing here
  modifies catalog, sales, or customer data.
- **Boring on purpose.** Collect and send, nothing else. Every interesting decision (alerting,
  severity rollups, retention) belongs on the dashboard side, not here.
- **Extensible.** Third-party modules can contribute their own status data via a public
  interface, without ever touching this module's code - see "Pluggable reporters" below.

## Installation

```bash
composer require stacknuts/magento-stackgauge
bin/magento module:enable StackNuts_StackGauge
bin/magento setup:upgrade
```

## Configuration

Go to **Stores > Configuration > Advanced > StackGauge**.

| Field | Notes |
|---|---|
| Enabled | Master switch. Off, no cron job or plugin sends anything - **Send Now** still works while off, so a site can be fully configured and verified before switching this on. |
| Dashboard Endpoint URL | Full ingest URL issued by the dashboard. |
| Site Identifier | Issued by the dashboard when the site is registered; sent in every report. |
| API Key | Bearer token issued by the dashboard for this site. Stored encrypted. |
| HMAC Secret | Shared secret used to sign every report. Stored encrypted. |
| Enabled Reporters | Which built-in reporters run. Third-party reporters (see below) aren't listed here and always run. |
| Monitored Log Files | Files (relative to `var/log`) the Logs reporter tails - pre-filled with the System/Exception logs, editable. |
| Log Level | Minimum severity written to `var/log/stacknuts_stackgauge.log`. |

These settings apply to the whole Magento instance (default scope only) - one installation
reports as one site, not one report per store view.

Click **Send Now** after saving to verify the endpoint URL and API key work end to end; a
successful click sends a heartbeat, both report cadences, and a config sync immediately.

## What gets collected

Everything below is read-only platform/store metadata - never catalog, sales, or customer
data. Full detail on exact fields lives in the dashboard's own documentation.

| Reporter | Payload key | What it covers |
|---|---|---|
| `CoreReporter` | `core` | Edition, Magento version, PHP version, deployment mode, static content deploy state |
| `ModuleReporter` | `modules` | Every registered module (enabled or not) and its resolved version |
| `ComposerReporter` | `composer` | `composer.lock` hash and a small watch-list of key platform package versions |
| `DbSchemaReporter` | `db_schema` | Modules whose code `setup_version` has moved ahead of what's actually applied |
| `PatchReporter` | `patches` | Applied Adobe Quality Patches, via `vendor/bin/patch-status` when present |
| `IndexerReporter` | `indexers` | Per-indexer status/mode, plus pending changelog backlog for schedule-mode indexers |
| `CronReporter` | `cron` | Whether cron looks alive, plus last success and next due time per job |
| `CacheReporter` | `cache` | Per-cache-type status/tags, plus the active Full Page Cache backend |
| `SecurityReporter` | `security` | Whether the admin path is still the default, maintenance-mode flag, sample-data modules present |
| `RedisReporter` | `redis` | Reachability and version of each Redis-backed cache/session backend, checked separately |
| `SearchReporter` | `search` | Configured search engine and whether it's actually reachable (Elasticsearch/OpenSearch only) |
| `RabbitMqReporter` | `rabbitmq` | Per-queue message/consumer count, if RabbitMQ is configured |
| `DiskSpaceReporter` | `disk` | Free/total bytes for `var/log`, `var/cache`, and media, checked independently |
| `DatabaseReporter` | `database` | MySQL/MariaDB version and reachability |
| `SalesReporter` | `sales` | Lifetime order/quote counts, plus hourly order counts and revenue for the recent window |
| `CatalogReporter` | `catalog` | Enabled product count |
| `CouponsReporter` | `coupons` | Active cart price rules and their lifetime redemption counts |
| `InventoryReporter` | `inventory` | Basic in-stock/out-of-stock counts |
| `CustomerSignalsReporter` | `customers` | Customer counts and recent activity |
| `AbandonedCartsReporter` | `abandoned_carts` | Counts of carts with items and no activity in the last 24h |
| `ProductHealthReporter` | `product_health` | Counts of common product data issues (e.g. missing image/price) |
| `PaymentMethodsReporter` | `payments` | Enabled payment methods and key configuration flags |
| `StoreViewsReporter` | `store_views` | Configured store views, locale, and currency |
| `ThemeReporter` | `themes` | Active frontend and admin themes |
| `PHPExtensionsReporter` | `php_extensions` | Presence and versions of important PHP extensions |
| `LogReporter` | `logs` | Recent log activity and exception summaries for the files configured under Monitored Log Files |
| `ReportReporter` | `reports` | Recent `var/report` summaries |

`SecurityReporter` never reports the actual admin path, only whether it's still the
Magento default - the real path stays private to your store.

## Transport / auth

- `POST` to the configured endpoint URL.
- `Authorization: Bearer <api_key>`
- `X-StackGauge-Signature`: hex HMAC-SHA256 of the raw JSON body, using the configured HMAC
  secret.
- `X-StackGauge-Site-Id`: duplicates the site identifier also present in the JSON body.
- Bodies of 1KB or more are gzip-compressed (`Content-Encoding: gzip`) before sending; the
  signature is always computed over the raw, uncompressed body.
- 5s connect / 10s total timeout - a slow or unreachable dashboard can never hang a site's
  cron.

## Cron

Runs in its own `stackgauge` cron group:

- `stacknuts_stackgauge_send_report` - full collection (hourly-cadence reporters), hourly by default.
- `stacknuts_stackgauge_send_daily_report` - full collection (daily-cadence reporters, e.g. `catalog`/`coupons`), daily by default.
- `stacknuts_stackgauge_send_heartbeat` - lightweight ping, every 5 minutes by default.
- `stacknuts_stackgauge_send_config_sync` - trackable-metric catalog sync, daily by default.

Every job, and the maintenance-mode plugin, catch every exception internally - a dashboard
outage or misconfiguration can never fail a cron run or block Magento's own maintenance
commands. No extra crontab entry is needed beyond the standard single `bin/magento cron:run`
entry virtually every Magento install already has.

## CLI

```bash
bin/magento stackgauge:send --dry-run        # print the assembled payload, don't send
bin/magento stackgauge:send                  # send now (skipped if "Enabled" is off)
bin/magento stackgauge:send --force          # send now regardless of "Enabled"
bin/magento stackgauge:send --cadence=daily  # send the daily-cadence reporters instead of hourly
bin/magento stackgauge:send --config-sync    # send the trackable-metric catalog instead of a full report
```

Set **Log Level** to "Info" to have every real send attempt (cron, CLI, **Send Now**) log its
full payload before delivery - useful for verifying what's collected before a dashboard
endpoint even exists.

## Pluggable reporters

Third-party modules can contribute their own named block to the payload without this module
knowing about them in advance, by implementing `StackNuts\StackGauge\Api\ReporterInterface`
and registering it against `ReporterPool`'s `reporters` array from their own `di.xml`.

A real example: [`stacknuts/magento-stackgauge-cloudflare-cache`](https://github.com/StackNuts/magento-stackgauge-cloudflare-cache)
adds a Cloudflare-specific reporter this way, in its own small compatibility module rather
than editing either parent module directly - the recommended pattern to copy for your own
integration.

Shape rules for a reporter's `getStatus()` return value:

- Build every field via `StackNuts\StackGauge\Api\Field\Field::bool()`/`varchar()`/`number()`/
  `datetime()`/`trackableNumber()`, grouped into sections via
  `StackNuts\StackGauge\Api\Section\Section::facts()` or `::table()`. No raw scalars,
  objects, or closures.
- A field's key must stay unique across your reporter's sections.
- Return an empty section rather than throwing when there's nothing to report - a thrown
  exception is logged and surfaces as an error for that cycle instead of your data.
- Keep it small - no raw file contents, no PII, no binary blobs.

The admin **Enabled Reporters** toggle only covers this module's own built-in reporters; a
third-party reporter isn't individually toggleable from that screen.

## Uninstall

```bash
bin/magento module:disable StackNuts_StackGauge
composer remove stacknuts/magento-stackgauge
bin/magento setup:upgrade
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[PolyForm Shield 1.0.0](https://polyformproject.org/licenses/shield/1.0.0). Free to install,
run, and modify - including to report to your own dashboard - but not to build or operate a
competing fleet-monitoring product. See [LICENSE](LICENSE).
