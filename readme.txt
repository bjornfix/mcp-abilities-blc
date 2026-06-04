=== MCP Abilities - Broken Link Checker ===
Contributors: devenia
Tags: mcp, ai, broken links, seo
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose Broken Link Checker (BLC) operations as MCP abilities: list broken links, replace broken URLs in content, auto-fix redirected links, and clear the local BLC queue.

== Description ==

This plugin registers MCP abilities for the WordPress Abilities API so automation tools can interact with Broken Link Checker local data.

Included abilities:

* `blc/list-tables`
* `blc/list-broken-links`
* `blc/get-notification-settings`
* `blc/add-notification-recipient`
* `blc/replace-url-in-content`
* `blc/auto-fix-broken-links`
* `blc/clear-queue`
* `blc/delete-links` (surgical cleanup of stale BLC records by `link_id`)

Requires:

* Abilities API plugin
* MCP Adapter plugin (to expose abilities over MCP)
* Broken Link Checker plugin (for BLC functionality)

== Installation ==

1. Install and activate Abilities API.
2. Install and activate MCP Adapter.
3. Install and activate Broken Link Checker.
4. Install and activate this plugin.

== Changelog ==

= 0.1.4 =
* Add BLC notification settings abilities for reading recipients and adding an email recipient across legacy and modern BLC options.

= 0.1.3 =
* Add `blc/delete-links` for deleting stale BLC records (and related instances) by `link_id`.

= 0.1.1 =
* Improve BLC availability detection by accepting detected BLC tables on sites where runtime symbols are not exposed.

= 0.1.0 =
* Initial release with BLC list/fix/queue MCP abilities.
