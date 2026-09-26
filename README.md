# RankOut Connector (WordPress plugin)

First-party replacement for the legacy Easy MCP AI plugin, built to the exact
contract RankOut's backend already expects — see
`backend/modules/integrations/wpConnector/wpConnectorOAuth.service.ts` and
`wpConnector.service.ts` for the client side of this protocol.

## OAuth callback configuration

The production RankOut callback is allowed by default. Staging and local
dashboards must register their exact callback URI in `wp-config.php` before
connecting:

```php
define( 'RANKOUT_CONNECTOR_ALLOWED_REDIRECT_URIS', array(
	'https://staging-api.example.com/api/wordpress-connector/callback',
	'http://localhost:3001/api/wordpress-connector/callback',
) );
```

Authorization uses exact URI matching. Existing access and refresh tokens are
unaffected by this setting.

Generic public custom-field writes are disabled by default. Sites that need a
specific non-SEO custom field may opt in exact keys (posts/pages only):

```php
define( 'RANKOUT_CONNECTOR_ALLOWED_PUBLIC_META_KEYS', array( 'my_public_seo_field' ) );
```

WooCommerce products and protected metadata remain unavailable through the
generic metadata tools.

## What it implements

- **OAuth 2.1 + PKCE authorization server**, scoped per this plugin instance
  (no client secret — RankOut is a public PKCE client, fixed `client_id`
  `rankout-dashboard`):
  - RFC 8414 discovery at the site root `/.well-known/oauth-authorization-server`
  - RFC 9728 protected-resource metadata at
    `/wp-json/rankout-connector/v1/.well-known/oauth-protected-resource`
  - Consent screen at `/?rankout_connector_oauth=authorize` (requires an
    already-logged-in `manage_options` admin)
  - `POST /wp-json/rankout-connector/v1/oauth/token`
    (`authorization_code` + `refresh_token` grants, refresh rotation)
  - `POST /wp-json/rankout-connector/v1/oauth/revoke`
- **MCP tool endpoint** at `/wp-json/rankout-connector/v1/mcp` — stateless
  Streamable HTTP (JSON-RPC 2.0: `initialize`, `tools/list`, `tools/call`),
  scoped so a token only ever sees/calls tools its granted scope covers.
- **16 tools**, matching the exact names RankOut's backend calls by string
  today (`implementation.service.ts`'s validation map,
  `easyMcp`/`wpConnector` service read paths):
  `wp_get_post`, `wp_get_page`, `wp_update_post`, `wp_update_page`,
  `wp_get_post_meta`, `wp_update_post_meta`, `wp_yoast_get_post_seo`,
  `wp_yoast_update_post_seo`, `wp_aioseo_get_post_seo`,
  `wp_aioseo_update_post_seo`, `wp_rm_get_post_seo`, `wp_rm_update_post_seo`,
  `wp_get_site_health`, `wp_seo_audit_site`, `wp_history_list`,
  `wp_history_get`, `wp_history_diff`, `wp_restore_revision`.
- Every write is logged (before/after snapshot + the WP core revision id,
  when one exists) to a custom table, visible under
  **Settings → RankOut Connector**, where the site admin can also revoke
  access at any time.

## Deliberately not built yet

`WORDPRESS_CONNECTOR_SCOPE`'s full vocabulary (`class-scopes.php`) declares
scopes for media, taxonomies, plugin/theme listing, general settings,
schema.org markup, GEO, AEO, and E-E-A-T — but no backend code calls a tool
under those categories by name yet. Building tools for them now would be
speculative; they're declared (so discovery/consent stay honest about what
*might* be requested later) but intentionally have no implementation behind
them until a real caller exists. Add the tool + flip its scope from
declared-only to load-bearing in `class-tool-registry.php` the same way the
existing 16 are wired.

## Local testing

1. `wp-content/plugins/rankout-connector` (symlink or copy this folder),
   activate it on a real WordPress install reachable over HTTPS from the
   backend (a tunnel like `ngrok`/`cloudflared` works for local dev — the
   OAuth flow's SSRF guard in `wpConnectorOAuth.service.ts` refuses
   localhost/private addresses on purpose).
2. In `backend/.env`, set `WORDPRESS_CONNECTOR_CALLBACK_URL` to this
   backend's own `/api/wordpress-connector/callback` and make sure
   `WORDPRESS_CONNECTOR_CLIENT_ID=rankout-dashboard` (the default).
3. From a client's Data Sources page in the app, connect WordPress with the
   tunnel's HTTPS URL — you'll land on this plugin's consent screen, approve,
   and get redirected back through the backend's callback.
4. Check **Settings → RankOut Connector** on the WordPress site to confirm
   the grant and its scopes, and watch **Recent changes** populate once an
   approved implementation actually writes something.
