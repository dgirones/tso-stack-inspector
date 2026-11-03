=== TSO Stack Inspector ===

Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: shortcode, block, maintenance, gutenberg, uninstall
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
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
* **Export CSV / Print PDF** — download scan results, orphans, or inactive-plugin audits as CSV, or open a printable report after a scan
* **Refresh signatures** — rebuild the cached plugin profile without waiting 24 hours
* **Options Cleaner link** — jump to TSO Options & Tables Cleaner when installed to remove leftover options and tables after uninstall
* **Site index** — built in the background when you open the admin screen (or when you scan); reused for faster scans and audits
* **Background scan** — optional checkbox to keep a large scan running via WP-Cron after you close the admin tab; reopen the screen later to see the finished results
* **Ignore list** — hide noisy shortcodes, blocks, or meta/option keys from results
* **Grouped results** — scan hits grouped by location, with copy-tag and a pre-uninstall checklist
* **Orphans → Replace** — jump from an orphan tag to the Replace tab already filled in
* **Admin UI languages** — English, Spanish, and Catalan in the Tools screen; community translations via translate.wordpress.org after publication

**How it works**

The plugin reads your content and widget options locally. It does not send data to external services. To see what visitors really get, a plugin scan also requests a sample of pages from your own site (loopback requests, nothing leaves your server): your home page, the latest posts and page, shop pages, one entry and archive of each public content type and one page per custom template, up to 12 in all. It looks for files and markup of the scanned plugin in them. For sitemap, SEO and feed plugins it also reads robots.txt, the sitemaps it declares and your feed. The pages read are reused for two minutes while nothing that changes them has changed, and a page that answered 404 is skipped for a week, so scans do not fill your 404 log. Plugin signatures (shortcodes, blocks, meta prefixes) are discovered by scanning plugin PHP sources and cached for 24 hours. The site content index is stored under uploads and rebuilt when content or settings change.

**Multisite**

Works on WordPress Multisite: scans, caches, and settings are per-site. Uninstalling from the network admin cleans up every site in the network, not just the main site.

**Limitations**

* Static code scan may miss dynamically registered shortcodes or blocks
* Meta prefix detection is heuristic; always review results before uninstalling
* Very large sites run scans in batches; tick **Background scan** to let a long scan keep going via WP-Cron after you close the tab, or keep the tab open otherwise

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
* A short list of your own public URLs that answered 404 when sampled, in a transient (one week)
* The last read of your own public pages, as compressed JSON under `wp-content/uploads/tso-stack-inspector/` (reused for two minutes, removed on uninstall)

No data is transmitted to external servers: the only requests the plugin makes go to your own site. Scans run only when an administrator starts them from Tools → TSO Stack Inspector.

== Screenshots ==

1. Shortcode scan results grouped by location, ready to review before uninstalling a plugin.
2. Risk verdict banner — a clear safe / review / risk summary shown after a scan.
3. Inactive plugins audit — see at a glance which deactivated plugins still leave shortcodes, blocks, options, or meta behind.
4. Scan settings — background scan, history size limit, and ignore list.

== Changelog ==

= 1.0.4 =
* Replace refuses to edit content when the undo backup cannot be saved, and backup/history file names are harder to guess; Replace and Cancel now recover from network errors instead of freezing
* Faster, more reliable scans: no posts processed twice when two requests overlap, much faster orphan finder, fixed double-encoded plugin links, and uninstall also removes leftover lock files
* A merely activated plugin is no longer reported as "do not uninstall": only real usage (content, widgets, code calling it, tables with data, post types, scheduled tasks, dependencies, public output) blocks the verdict
* The public site check now reads a sample of pages (home, latest posts and page, shop pages, one entry and archive per content type, one page per template), reuses it for two minutes, skips pages that answer 404, and only reads robots.txt, sitemaps and feed for sitemap, SEO and feed plugins; the verdict lists the files and class names found and where
* New: the verdict is graded with a priority (low/medium/high) and a confidence level (high/medium/low) with the reason, indirect hints are labelled, and widgets left in the inactive area and old non-public post types are reported with their volume and last edit date
* Fewer false positives (log options and constants of other plugins, bundled libraries, Free/Pro editions, drop-ins, mu-plugins, the site's own domain) and new checks for Classic Editor, One User Avatar, UpdraftPlus and Options & Tables Cleaner

= 1.0.3 =
* An active plugin is no longer reported as "likely safe to uninstall": new "in use" checks (hooks, cron, REST, post types, taxonomies, tables, dependencies, theme and other plugins) also used by the inactive audit and theme scan
* Scans also cover custom fields, excerpts, term descriptions, non-autoloaded options, non-public post types and shortcodes/blocks hardcoded in theme templates; fewer false positives (widgets, core options)
* Better signature discovery (constants, loops, page builders, JavaScript-only blocks, runtime widgets) and more reliable orphan detection
* Compact header and cache strip, faster AJAX tab switching, CSV export opens correctly in Spanish/Catalan Excel, background scans chained as one-off cron events
* Theme scan falls back to the active theme; shortcode/block risk wording is tag-scoped; Refresh signatures starts at once

= 1.0.2 =
* Support (Ko-fi) donation button in the admin header
* New: Auto/Day/Night plugin visuals 
* Scan, compare and replace buttons stay disabled until a plugin, shortcode, block or theme is chosen
* History "View" now scrolls to the loaded results and reports errors
* Built-in signatures for Elementor and Elementor Pro (elementor-template shortcode, _elementor_ meta)
* Replace no longer strips backslashes from post content (block JSON, escaped quotes), also when undoing
* Core blocks can be searched as core/paragraph or paragraph; blocks stored in page-builder JSON are detected
* Orphan finder no longer flags shortcodes/blocks of active plugins that only register on the front end

= 1.0.1 =
* Faster first load: the admin screen opens immediately and prepares plugin signatures in the background with a progress notice
* Plugin signature discovery walks each plugin folder once (skipping vendor/node_modules) and reads each file once
* Single-file plugins are no longer profiled with the whole plugins folder
* Site index checks no longer decode the cached index on every page load
* Faster site index build (time-boxed steps with primed post caches) and faster cached scans/audits (posts are loaded only on a match)
* Site index stores compact shortcode/block digests instead of full post content and page-builder meta (much smaller, faster to load and scan)
* Obsolete site-index files are deleted when a new index is saved instead of piling up in uploads
* Scans and the inactive-plugins audit wait for the background signature warm-up instead of building every profile inside one request
* Inactive-plugins audit reads user/term/comment meta keys once instead of once per plugin

= 1.0.0 =
* First version
