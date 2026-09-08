=== TSO Stack Inspector ===

Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: shortcode, block, maintenance, gutenberg, uninstall
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall.

== Description ==

TSO Stack Inspector helps agencies and site owners answer a critical question before removing a plugin: **where is it still used?**

Scan posts, pages, custom post types, reusable blocks, Site Editor templates, widgets, navigation menus, and wp_options for:

* **Shortcodes** registered by a plugin or entered manually
* **Gutenberg blocks** declared by a plugin
* **Post meta prefixes** inferred from plugin code (including page-builder JSON meta)

**Features**

* **By plugin** — pick an installed plugin and run a batched scan before uninstall
* **Risk verdict** — after each scan, a clear safe / review / risk summary for uninstall decisions
* **Replace (dry-run)** — preview and apply shortcode/block renames in post content (batched, with confirmation and undo backups)
* **Orphans** — find shortcodes/blocks still in content but no longer registered
* **History diff** — compare two saved scans
* **Inactive plugins audit** — quickly see which deactivated plugins still leave traces (uses the site index)
* **Shortcode finder** — search any shortcode tag across the site
* **Block finder** — search a block namespace (e.g. `contact-form-7/contact-form-selector`)
* **Theme scan** — active or inactive themes
* **Compare plugins** — shared vs exclusive signatures before migrations
* **Inventory** — list registered shortcodes and discovered plugin signatures
* **Scan history** — limited, auto-pruned, with delete/clear
* **Export CSV / Print PDF** — download scan results or open a printable report after a scan
* **Refresh signatures** — rebuild the cached plugin profile without waiting 24 hours
* **Options Cleaner link** — jump to TSO Options & Tables Cleaner when installed to remove leftover options and tables after uninstall
* **Site index** — built in the background when you open the admin screen (or when you scan); reused for faster scans and audits
* **Ignore list** — hide noisy shortcodes, blocks, or meta/option keys from results
* **Grouped results** — scan hits grouped by location, with copy-tag and a pre-uninstall checklist
* **Orphans → Replace** — jump from an orphan tag to the Replace tab already filled in
* **Export CSV** — scan results, orphans, and inactive-plugin audits
* **Admin UI languages** — English, Spanish, and Catalan in the Tools screen; community translations via translate.wordpress.org after publication

**How it works**

The plugin reads your content and widget options locally. It does not send data to external services. Plugin signatures (shortcodes, blocks, meta prefixes) are discovered by scanning plugin PHP sources and cached for 24 hours. The site content index is stored under uploads and rebuilt when content or settings change.

**Limitations**

* Static code scan may miss dynamically registered shortcodes or blocks
* Meta prefix detection is heuristic; always review results before uninstalling
* Very large sites run scans in batches; keep the admin tab open until completion

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins screen
3. Open **Tools → TSO Stack Inspector** (or click **Open inspector** under the plugin on the Plugins screen)

== Frequently Asked Questions ==

= Where is the admin menu? =

Go to **Tools → TSO Stack Inspector**. Administrators also see **Open inspector** under the plugin name on **Plugins → Installed Plugins**.

= Does this delete anything? =

Scans, orphans, and audits are read-only. The optional **Replace** tab can edit `post_content` (shortcode/block renames) after a dry-run preview, with short-lived undo backups — always keep your own site backup. Stack Inspector does not delete plugins, options, or database tables; use TSO Options & Tables Cleaner for leftover options/tables after uninstall.

= Does it work with Elementor or other page builders? =

Yes, partially. The scanner also inspects common builder meta keys such as `_elementor_data` for embedded shortcodes and blocks.

= Will a scan slow down my site? =

Scans run in the admin in small batches and only when you start them. Front-end visitors are not affected.

== Privacy Policy ==

TSO Stack Inspector stores only:

* Your admin UI language preference in user meta (`tso_stack_inspector_ui_lang`)
* Optional scan settings in `wp_options` (`tso_stack_inspector_scan_settings`), including history size limit
* A short index of recent completed scans in `wp_options` (`tso_stack_inspector_scan_history`)
* Temporary scan job data in transients (auto-expire)
* Optional site-index cache, scan-history JSON, and replace-backup JSON under `wp-content/uploads/tso-stack-inspector/` (local disk only; directory is denied via `.htaccess` / `web.config` + empty `index.html`; removed on uninstall when possible)
* Replace backup index in `wp_options` (`tso_stack_inspector_replace_backups`, ~48h retention)

No data is transmitted to external servers. Scans run only when an administrator starts them from Tools → TSO Stack Inspector.

== Changelog ==

= 1.0.0 =
* Plugin, shortcode, block, and theme scans with batched AJAX
* Widget detection by option name and active sidebar placement
* Site Editor templates, reusable blocks, menus, and wp_options scanning
* Plugin signature discovery (shortcodes, blocks, meta/option prefixes) with refresh
* Safer prefix matching (skip core WP keys, peer-plugin keys, analytics meta noise)
* Auto-built site content index (background batches; does not block the Tools screen)
* Ignore list in Settings to hide noisy tags/keys
* Grouped scan results, copy tag, and a pre-uninstall checklist
* Orphans finder with Replace deep link and CSV export
* Inactive plugins audit with CSV export
* Uninstall risk verdict (safe / review / risk)
* Replace tab: dry-run, batched apply, backups (~48h) with Undo
* History with delete/clear and diff between two scans
* Compare plugins (shared vs exclusive signatures) in a table layout
* Export CSV / printable report; Options Cleaner deep link when available
* CA / ES / EN admin UI; Plugins screen description follows WP locale
* Blog and Donate links on the Plugins screen
* WP-CLI: `tsosi scan`, `tsosi orphans`, `tsosi audit-inactive`; Multisite network admin entry
