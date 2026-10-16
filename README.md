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
| Disabled Reporters | Which reporters to turn *off* - built-in or contributed by another module, same list. Opt-out, not opt-in: leave empty (the default) and everything runs, including any reporter a future update or newly-installed companion module adds, with no action needed here. |
| Monitored Log Files | Files (relative to `var/log`) the Logs reporter tails - pre-filled with the System/Exception logs, editable. |
| Storefront Self-Probe | Whether every heartbeat also curls this store's own homepage - see "Storefront self-probe" below. On by default. |
| Log Level | Minimum severity written to `var/log/stacknuts_stackgauge.log`. |

These settings apply to the whole Magento instance (default scope only) - one installation
reports as one site, not one report per store view.

Click **Send Now** after saving to verify the endpoint URL and API key work end to end; a
successful click sends a heartbeat, the config sync and the hourly slot the clock is in now (not
every slot - use `bin/magento stackgauge:send` for that); concurrent clicks are rejected by a lock.

## What gets collected

Everything below is read-only platform/store metadata - never catalog, sales, or customer
data. Full detail on exact fields lives in the dashboard's own documentation.

| Reporter | Payload key | What it covers |
|---|---|---|
| `CoreReporter` | `core` | Edition, Magento version, PHP version, deployment mode, static content deploy state |
| `ModuleReporter` | `modules` | Every registered module (enabled or not) and its resolved version |
| `ComposerReporter` | `composer` | `composer.lock` hash, a small watch-list of key platform package versions, and any package downloaded from outside the common registries |
| `DbSchemaReporter` | `db_schema` | Modules whose code `setup_version` has moved ahead of what's actually applied |
| `PatchReporter` | `patches` | Applied official Adobe Commerce security patches, via `vendor/bin/patch-status` when present |
| `IndexerReporter` | `indexers` | Per-indexer status/mode, plus pending changelog backlog for schedule-mode indexers |
| `CronReporter` | `cron` | Whether cron looks alive, plus last success and next due time per job |
| `CacheReporter` | `cache` | Per-cache-type status/tags, plus the active Full Page Cache backend |
| `RedisReporter` | `redis` | Reachability and version of each Redis-backed cache/session backend, checked separately |
| `SearchReporter` | `search` | Configured search engine and whether it's actually reachable (Elasticsearch/OpenSearch only) |
| `RabbitMqReporter` | `rabbitmq` | Per-queue message/consumer count, if RabbitMQ is configured |
| `DbQueueReporter` | `db_queue` | Per-queue backlog and error count for every queue routed through the "db" message-queue connection |
| `UptimeReporter` | `uptime` | Hourly storefront self-probe (sent with a `StackGauge/<version>` User-Agent) result (reachable / blocked / genuine error page) plus TLS certificate expiry/trust on this store's own https host(s) - see "Storefront self-probe" |
| `DiskSpaceReporter` | `disk` | Free/total bytes for `var/log`, `var/cache`, and media, checked independently |
| `ServerResourcesReporter` | `server` | Load average, CPU core count/busy percent, and memory/swap usage from procfs, with cgroup v2 limits honoured in a container |
| `DatabaseReporter` | `database` | MySQL/MariaDB version and reachability |
| `SalesReporter` | `sales` | Lifetime order/quote counts, hourly order counts and revenue, order rate vs the usual rate for this time of week, and stuck pending-payment orders by payment method |
| `CatalogReporter` | `catalog` | Enabled product count |
| `CouponsReporter` | `coupons` | Active cart price rules and their lifetime redemption counts |
| `InventoryReporter` | `inventory` | Basic in-stock/out-of-stock counts |
| `CustomerSignalsReporter` | `customers` | Customer counts and recent activity |
| `AbandonedCartsReporter` | `abandoned_carts` | Counts of carts with items and no activity in the last 24h |
| `ProductHealthReporter` | `product_health` | Counts of common product data issues (e.g. missing image/price) |
| `PaymentMethodsReporter` | `payments` | Payment methods enabled in at least one store view, and which store views |
| `StoreViewsReporter` | `store_views` | Configured store views, locale, and currency |
| `ThemeReporter` | `themes` | Active frontend and admin themes |
| `PHPExtensionsReporter` | `php_extensions` | Presence and versions of important PHP extensions, as seen by the CLI/cron PHP (not necessarily the web PHP) |
| `LogReporter` | `logs` | Recent log activity and exception summaries for the files configured under Monitored Log Files |
| `ReportReporter` | `reports` | Counts (last hour / 24h) of `var/report` fatal-error reports (storefront and API), the 5 newest with a distilled message and URL path, and the most frequent messages from the last 24h |

Security-posture reporters (`SecurityReporter`, `AdminAccountsReporter`, `ConfigHygieneReporter`)
live in the optional [`StackNuts_StackGaugeSecurity`](https://github.com/StackNuts/magento-stackgauge-security)
companion module, not here - see "Security module" below for why.

`LogReporter` reads each monitored file incrementally - only the bytes appended since its
last run, tracked via a byte offset this module stores per file name (see "Database" below) -
rather than rereading the whole file every time. One consequence: its "recent exceptions" and
tail sections show what's new since the last run, not a constant rehash of the same
historical lines every time, so they can be empty on a quiet run. exception.log's own
occurrence count is the one exception (no pun intended) - it keeps reading as a stable,
ever-growing total across runs, since that's what the dashboard's alerting expects.

## Storefront self-probe

Every heartbeat (every few minutes, see below), this server curls its own homepage and
classifies the result:

- **reachable** - got any HTTP response at all
- **looks_like_error_page** - the body matches Magento's own genuine production-mode fatal
  error page (`pub/errors/default/report.phtml`'s exact text), i.e. Magento itself is broken
- **looks_blocked** - the body matches a common WAF/CDN challenge/interstitial page (e.g.
  Cloudflare's "Just a moment..."), i.e. something in front of the site stopped this probe
  before it ever reached Magento

This distinction is the whole point: an external uptime monitor getting WAF-challenged looks
identical to the site actually being down unless the two are told apart, and this probe runs
from the server itself specifically so it can tell them apart. It never looks at response
headers to decide `looks_blocked` - a site fronted by Cloudflare (or similar) gets a
`cf-ray`-style header on every response, not just challenges, so header presence alone can't
be the signal. Disable it under **Storefront Self-Probe** if you'd rather this server never
make an outbound request to itself.

The same probe also runs once an hour as `UptimeReporter`, alongside every other reporter -
that's the copy with a trackable alert metric (`uptime.reachable`), since alerting attaches to
reporters, not to the heartbeat. The two aren't redundant: the heartbeat copy is what gets you
a signal within minutes instead of waiting up to an hour.

`checkExposedPaths()` also exists on this same `StorefrontProbe` class, for checking a fixed
list of sensitive paths (`.git/HEAD`, `composer.lock`, `app/etc/env.php`, etc.) for HTTP
exposure once a day - but nothing in core StackGauge calls it. It's consumed by
`StackNuts_StackGaugeSecurity`'s `SecurityReporter`, if that optional companion module is
installed; see "Security module" below.

`UptimeReporter` also checks TLS certificate expiry once an hour, alongside its copy of the
self-probe above - deliberately not part of the heartbeat, since a certificate's expiry moves
on the order of weeks, not minutes. `Model\TlsCertificateInspector` opens a direct TLS
connection (with SNI) to port 443 of every distinct host this store's `web/secure/base_url`
resolves to across all store views (capped at 10 hosts; a plain-http dev site with no https
base URL at all simply isn't checked), from this server rather than the dashboard - the same
reasoning as the self-probe: a dev store behind a VPN or firewall that blocks the dashboard's
own IP still gets a real answer. It verifies the certificate the way a browser would first;
if that fails (e.g. self-signed, or an incomplete/expired chain), it retries once with
verification off purely to keep the expiry readable, and reports that host's certificate as
untrusted. The `tls` section lists every checked host's trust state, expiry, issuer, and any
error; the shortest days-remaining across every host that connected also goes out as a
trackable metric (`uptime.tls_days_remaining`), and any untrusted certificate as a separate
finding (`uptime.tls_untrusted_certificate`) - which will fire on an intentionally self-signed
dev/staging certificate, so disable that one rule per site if that's not useful to you.

## Security module

Admin-account hygiene, config hygiene, filesystem exposure, and core-tamper checks live in the
separate [`StackNuts_StackGaugeSecurity`](https://github.com/StackNuts/magento-stackgauge-security)
module, not here. So does content-signature scanning: it checks CMS blocks and pages, admin-editable
HTML/JS config, `pub/` PHP and `generated/code/` for known webshell and Magecart patterns. Its
signature set is downloaded once a day from the public signatures repository on GitHub, verified
against a published SHA-256, and cached; a failed download keeps the previous set, or the copy
bundled with the module. That daily refresh runs in the companion's own cron group, so it never
delays this module's reports. Feed updates can be turned off under **Signature Feed Updates** in the
same StackGauge config section. Not every install wants this - plenty of agencies already have dedicated
security scanning (Sansec, host-level malware scanning, their own tooling), and running a
second, overlapping set of filesystem walks on every client site would just double that cost
for no benefit. Install it like any other reporter-contributing companion module (see
"Pluggable reporters" below) if you want it; its reporters then show up automatically in the
**Disabled Reporters** field above like any other.

## Database

This module creates one table of its own, `stacknuts_stackgauge_log_offset`, to support
`LogReporter`'s incremental reads (see above) - one row per monitored log file name, storing
how far it's read and (for exception.log only) a running occurrence count. Nothing else in
StackGauge persists any state locally. `bin/magento module:uninstall --remove-data` drops this
table; no catalog, sales, or customer data is ever touched.

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

- `stacknuts_stackgauge_send_report` - full collection of the hourly tier, every 20 minutes: the
  tier is split into 3 slots and each run collects one, so every hourly reporter reports once an hour.
- `stacknuts_stackgauge_send_daily_report` / `stacknuts_stackgauge_send_daily_report_slot_1` - full
  collection of the daily tier (e.g. `catalog`/`coupons`), split into 2 slots run at 00:00 and 01:00.
- `stacknuts_stackgauge_send_heartbeat` - lightweight ping, every 5 minutes by default.
- `stacknuts_stackgauge_send_config_sync` - trackable-metric catalog sync, daily by default.

The slot counts (3 hourly, 2 daily) are fixed to match these schedules, and the group runs in its
own cron process (`use_separate_process=1`) so collection and network time don't delay other groups.

Every job, and the maintenance-mode plugin, catch every exception internally - a dashboard
outage or misconfiguration can never fail a cron run or block Magento's own maintenance
commands. No extra crontab entry is needed beyond the standard single `bin/magento cron:run`
entry virtually every Magento install already has.

## CLI

```bash
bin/magento stackgauge:send                  # send everything now: every hourly + daily slot and the config sync
bin/magento stackgauge:send --dry-run        # list which reporters each run would carry, send nothing
bin/magento stackgauge:send hourly:1 --dry-run  # print one run's full payload
bin/magento stackgauge:send daily            # all daily slots (also: hourly, hourly:N, daily:N, current, config)
bin/magento stackgauge:send --force          # send regardless of "Enabled"
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
- Give a number its unit via `unit:` (one of `StackNuts\StackGauge\Api\Unit` - bytes/KB/MB/GB,
  ms/s/min/h/d, `%`) rather than putting it in the label: `number('Used Memory', $mb,
  unit: Unit::MEGABYTES)`, not `number('Used Memory (MB)', $mb)`. The value is sent untouched;
  the dashboard picks a readable scale for display (2048 MB shows as "2 GB").

Alerting on a value: return it via `trackableNumber()` and declare a matching
`StackNuts\StackGauge\Api\MetricDefinition` from `getTrackableMetrics()`
(`MetricCatalogInterface`). Besides the default operator/threshold/window, a definition sets
how severe a breach is - `severity:` (`SEVERITY_WARNING`, the default, or `SEVERITY_CRITICAL`)
and, for a warning, an optional stricter `criticalThreshold:` at which the same alert
escalates to critical (e.g. disk free below 10% = warning, below 5% = critical) - plus the same
`unit:` as its field. All of these are suggestions: each site's alert rule can be tuned on the
dashboard, and a tuned value survives later syncs. Leave units out of `description:`/`impact:`
too - `{value}` and `{threshold}` are filled in with the unit already attached. Declare a
metric's unit once, on its definition: it's copied onto the matching `trackableNumber()` fields
automatically.

Alerting on a yes/no check ("sample data installed", "debug logging on in production"): give
the bool field a `metricKey:` (true when the problem is present) and declare it with
`MetricDefinition::finding($metricKey, $label, $severity, $description, $impact)`. The dashboard
alerts while it's true - per-site enable/disable and severity, no dashboard code needed.

The admin **Disabled Reporters** toggle covers your reporter too, automatically - its options
are derived from every reporter actually registered with `ReporterPool` (see
`Model\System\Config\Source\ReporterList`), not a hardcoded list of built-in codes. Registering
your reporter via `di.xml` the way this section describes is the only step needed; there's no
separate registration for it to become individually toggleable.

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
