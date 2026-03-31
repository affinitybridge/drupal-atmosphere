# ATmosphere Module Roadmap

Future improvements and enhancements, organized by theme.

---

## Robustness & Error Handling

### Configurable image field mapping
The module currently hardcodes a search through `field_image`, `field_featured_image`, `field_hero_image`, and `field_thumbnail` to find cover images. Add a per-content-type configuration for which image field to use, with a fallback to auto-detection by field type.

### Logging for silent failures
Image upload failures in `DocumentTransformer` and `PostTransformer` are currently swallowed silently (catch returns NULL). Add `logger->warning()` calls so administrators can diagnose upload issues from the Drupal watchdog.

### Queue retry limits
Queue workers currently throw `RequeueException` on any failure, creating infinite retry loops for permanent errors (e.g., deleted PDS accounts, invalid records). Implement a retry counter — store attempt count in the queue item data and abandon after a configurable number of retries, logging the permanent failure.

### Graceful handling of broken encryption keys
If `hash_salt` changes (site migration, settings.php update), all encrypted tokens become unrecoverable. Detect decryption failures and present a clear admin message ("Connection lost — please reconnect") rather than throwing cryptic errors.

### Body field fallback
The module assumes all syncable content types have a `body` field. Support a configurable "content field" per content type, falling back to any long text field when `body` doesn't exist.

---

## Publishing Features

### Selective per-node publishing
Add a checkbox to the node form's ATmosphere sidebar allowing authors to opt individual nodes in or out of AT Protocol publishing, independent of the global auto-publish setting. Store as a boolean base field (`atmosphere_publish`).

### Immediate publish option
Currently all publishing is async via the Queue API, which depends on cron frequency. Add an option (admin setting or per-node) to publish synchronously on save for sites that want immediate visibility on Bluesky.

### Draft preview
Allow editors to preview the `site.standard.document` and `app.bsky.feed.post` records that would be created — before actually publishing. Show a "Preview AT Protocol records" tab or modal on the node edit form.

### Publish status indicator
Enhance the node form sidebar to show richer status: "Queued for publishing", "Published (last synced: date)", "Sync failed (reason)". Track last sync timestamp and last error in additional base fields or state.

### Selective record types
Some sites may want to publish `site.standard.document` records without cross-posting to Bluesky (or vice versa). Add a configuration to choose which record types to create: documents only, Bluesky posts only, or both.

---

## Content & Formatting

### Bundled Markdown content parser
Ship a default implementation of `ContentParserInterface` that converts Drupal's filtered HTML body to Markdown, producing records compatible with Leaflet, WhiteWind, and other Atmosphere readers. This is the most requested content format for `site.standard.document`.

### Rich text facets from body content
Currently facets (links, mentions, hashtags) are only extracted from the composed Bluesky post text. Consider also extracting and storing facets from the document's textContent for richer rendering in Atmosphere readers.

### Configurable post text template
The Bluesky post text is currently composed as "title + excerpt + URL". Allow site builders to configure the template using token replacement (e.g., `[node:title]\n\n[node:summary]\n\n[node:url]`), with grapheme-aware truncation still enforced.

### Open Graph / meta tag integration
When building the Bluesky embed card, pull the title, description, and image from Open Graph meta tags (if the `metatag` module is installed) instead of only checking hardcoded field names. This ensures the embed card matches what other platforms show.

---

## AT Protocol & Ecosystem

### Alter hooks for all record types
`DocumentTransformer` has `hook_atmosphere_document_alter()` but `PostTransformer` and `PublicationTransformer` do not. Add `hook_atmosphere_bsky_post_alter()` and `hook_atmosphere_publication_alter()` for consistency with the WordPress plugin's filter system.

### Support for additional lexicons
The architecture (transformers, publisher, queue workers) could support additional AT Protocol record types beyond `app.bsky.feed.post` and `site.standard.document`. Consider a plugin-based transformer system where contrib modules can register new collection types to publish alongside the core two.

### Bidirectional sync
Currently the module only pushes from Drupal to AT Protocol. Explore pulling engagement data back — like counts, reposts, and replies from Bluesky — and displaying them on the Drupal node page or in Views.

### Multiple account support
The module currently supports a single AT Protocol connection per site. For multi-author sites, consider per-user connections where each author publishes under their own DID, using Drupal's user entity to store individual connection state.

### PDS self-hosting awareness
For organizations running their own PDS (relevant to the Canadian/European data sovereignty use case), add configuration to specify a custom PDS endpoint and skip the standard handle resolution chain.

---

## Administration & UX

### Bulk operations via Views
Expose "Publish to ATmosphere" and "Remove from ATmosphere" as Views bulk operations, so administrators can select multiple nodes and sync them without using the backfill tool.

### Drush commands
Add Drush commands for common operations:
- `drush atmosphere:status` — show connection state, queue sizes, last sync
- `drush atmosphere:publish <nid>` — immediately publish a specific node
- `drush atmosphere:backfill` — run backfill from the CLI
- `drush atmosphere:disconnect` — disconnect the AT Protocol account

### Admin dashboard
Add a lightweight dashboard at `/admin/config/services/atmosphere/dashboard` showing: connection health, number of synced nodes, queue depths, recent errors, and last successful cron token refresh.

### Configuration export safety
The module stores connection tokens in State API (not exported) and publishing config in Config API (exported). Document this clearly and add a config export validation warning if someone attempts to export with an active connection — the tokens won't transfer to another environment.

---

## Testing & CI

### Get PHPUnit running in CI
Set up a GitHub Actions workflow that installs PHP extensions (`mbstring`, `xml`), runs `composer install`, and executes `vendor/bin/phpunit`. The 79 existing unit tests should pass without a Drupal installation.

### Kernel tests
Add Drupal kernel tests that verify: module installs cleanly, base fields are created on node entities, config schema is valid, and services can be instantiated from the container.

### Integration tests with a test PDS
Set up a local PDS (via the official Docker image) for integration testing. Test the full flow: OAuth connect, publish a node, verify the record exists on the PDS, update, delete.

### Test coverage for SettingsForm
The settings form handles both connected and disconnected states, OAuth initiation via `TrustedRedirectResponse`, and config saving. Add functional tests covering both states.

---

## Performance

### Blob upload caching
When the same image is used as both the document cover and the Bluesky post thumbnail, it's currently uploaded twice. Cache blob references (keyed by file entity ID + PDS endpoint) to avoid duplicate uploads.

### Batch queue processing
For backfill of large content libraries, consider using Drupal's Batch API instead of (or alongside) the Queue API. Batch gives immediate visual progress and doesn't depend on cron.

### Lazy transformer instantiation
All three transformers are instantiated as services even when the module doesn't need them (e.g., on pages with no syncable content). Consider making them lazy services or moving to a factory pattern.
