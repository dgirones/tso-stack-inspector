=== TSO Stack Inspector ===

Contributors: deadko
Tags: shortcode, block, maintenance, gutenberg, uninstall
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
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
* **Shortcode finder** — search any shortcode tag across the site
* **Block finder** — search a block namespace (e.g. `contact-form-7/contact-form-selector`)
* **Inventory** — list registered shortcodes and discovered plugin signatures
* **Export CSV / Print PDF** — download scan results or open a printable report after a scan
* **Refresh signatures** — rebuild the cached plugin profile without waiting 24 hours
* **Options Cleaner link** — jump to TSO Options & Tables Cleaner when installed to remove leftover options and tables after uninstall
* **Admin UI languages** — English, Spanish, and Catalan

**How it works**

The plugin reads your content and widget options locally. It does not send data to external services. Plugin signatures (shortcodes, blocks, meta prefixes) are discovered by scanning plugin PHP sources and cached for 24 hours.

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

No. Stack Inspector is read-only. Use TSO Options & Tables Cleaner to remove leftover database data after you uninstall a plugin.

= Does it work with Elementor or other page builders? =

Yes, partially. The scanner also inspects common builder meta keys such as `_elementor_data` for embedded shortcodes and blocks.

= Will a scan slow down my site? =

Scans run in the admin in small batches and only when you start them. Front-end visitors are not affected.

== Privacy Policy ==

TSO Stack Inspector stores only:

* Your admin UI language preference in user meta (`tso_stack_inspector_ui_lang`)
* Optional scan settings in `wp_options`
* Temporary scan job data in transients (auto-expire)

No data is transmitted to external servers.

== Changelog ==

= 1.1.0 =
* Export scan results to CSV or a printable PDF-style report
* Refresh signatures button to rebuild cached plugin profiles on demand
* Discover wp_options prefixes from plugin code; scan non-autoload options
* Broader Site Editor template scan (draft, inherit, and other statuses)
* Inventory and profile tables show option prefixes

= 1.0.4 =
* Per-user scan jobs (no cross-admin transient races)
* Discover blocks from standalone block.json and register_block_type( .../block.json )
* Runtime shortcode/block discovery for active plugins (Reflection-based)
* Fix plugin directory path resolution (case-sensitive hosts)
* Tighter wp_options skip list; remove inline onchange from admin language select

= 1.0.3 =
* Scan Site Editor templates (`wp_template`, `wp_template_part`, `wp_navigation`) and reusable blocks
* Scan wp_options and widget areas for embedded shortcodes/blocks
* Scan summary shows post count, needles, and match count
* Improved block namespace matching (e.g. `tsosk/404-url`)

= 1.0.2 =
* Admin menu label renamed to **TSO Stack Inspector** (under Tools)
* **Open inspector** link on the Plugins screen
* Always load admin bootstrap so the menu registers reliably

= 1.0.1 =
* Plugin Check: remove suppress_filters from post ID collection; use metadata_exists() and get_post_custom_keys() instead of direct SQL for post meta keys

= 1.0.0 =
* Initial release: plugin, shortcode, and block scans with batched AJAX
* Widget and menu scanning
* Plugin signature discovery with 24h cache
* CA / ES / EN admin UI
* Integration link to TSO Options & Tables Cleaner
