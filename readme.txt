=== RankOut Connector ===
Contributors: rankout
Tags: seo, rankout, mcp, oauth
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress site to RankOut so approved SEO/AEO/GEO fixes can be reviewed and applied automatically.

== Description ==

RankOut Connector lets a RankOut agency account:

* Read this site's content, on-page SEO fields (Yoast / All in One SEO / Rank Math, whichever is active), and basic site health.
* Apply a change to a post/page or its SEO fields, but only after your RankOut agency has explicitly approved it — this plugin never makes a change RankOut hasn't asked for by name, and every write it makes is logged under Settings → RankOut Connector → Recent changes.
* Be disconnected at any time from Settings → RankOut Connector, with no need to go back to RankOut first.

Access is granted through a standard OAuth 2.1 + PKCE consent screen — you approve exactly which permissions RankOut receives, and can revoke them at any time. No API key or password is ever typed into RankOut; nothing secret ships inside this plugin.

= Permissions =

RankOut requests only the permissions it needs for the feature you're using. Every granted permission is listed in this site's consent screen and, again, on the Settings → RankOut Connector page.

== Installation ==

1. Upload the `rankout-connector` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In RankOut, go to the client's Data Sources page and choose "Connect WordPress" — you'll be redirected here to approve the connection.

== Changelog ==

= 1.0.0 =
* Initial release: OAuth 2.1 + PKCE authorization server, MCP tool endpoint, content/SEO/site-health/history tools.
