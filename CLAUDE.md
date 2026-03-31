# ATmosphere - Development Guide

## Project Overview

ATmosphere is a Drupal 10/11 module that publishes Drupal content to the AT Protocol (Bluesky + standard.site) via native OAuth 2.1 authentication. It creates both `app.bsky.feed.post` and `site.standard.document` records on the user's Personal Data Server (PDS) using atomic `applyWrites` operations.

## Build & Install

```bash
# Install PHP dependencies
composer install

# Enable the module in Drupal
drush en atmosphere

# Uninstall
drush pmu atmosphere
```

### Requirements

- Drupal ^10.3 || ^11
- PHP >= 8.2 with `ext-openssl` and `ext-sodium`
- `web-token/jwt-library` ^4.1

## Architecture

### Key Flows

1. **OAuth**: `SettingsForm` -> `Client.authorize()` -> `Resolver` (handle -> DID -> PDS -> auth server) -> DPoP+PKCE -> callback -> `ConnectionManager` stores encrypted tokens
2. **Publishing**: Node CRUD hooks -> `Publisher` queues work -> Queue workers use `DocumentTransformer`/`PostTransformer` -> `ApiClient.applyWrites()` (DPoP-signed) -> metadata stored back on node base fields
3. **Site sync**: `SiteConfigSubscriber` detects site name/slogan changes -> queues `PublicationTransformer` -> updates `site.standard.publication` record

### Service Layer (`atmosphere.services.yml`)

- `atmosphere.connection_manager` - Encrypted token/connection state persistence
- `atmosphere.oauth_client` - OAuth 2.1 with PKCE, DPoP, PAR
- `atmosphere.api_client` - DPoP-authenticated XRPC HTTP client with nonce retry
- `atmosphere.publisher` - Orchestrates node publishing/updating/deleting
- `atmosphere.resolver` - AT Protocol identity resolution (handle -> DID -> PDS)
- `atmosphere.dpop` / `atmosphere.encryption` / `atmosphere.nonce_storage` - Security primitives
- `atmosphere.document_transformer` / `atmosphere.post_transformer` / `atmosphere.publication_transformer` - Record builders
- `atmosphere.facet_extractor` - Rich text link/mention/hashtag extraction
- `atmosphere.tid_generator` - AT Protocol TID generation

### Node Base Fields

Six hidden fields are added to all node entities to track AT Protocol record metadata:
`atmosphere_bsky_tid`, `atmosphere_bsky_uri`, `atmosphere_bsky_cid`, `atmosphere_doc_tid`, `atmosphere_doc_uri`, `atmosphere_doc_cid`

### Queue Workers

- `atmosphere_publish` / `atmosphere_update` / `atmosphere_delete` - Node record lifecycle
- `atmosphere_sync_publication` - Site metadata sync

### Routes

- `/admin/config/services/atmosphere` - Settings form
- `/atmosphere/client-metadata` - OAuth client metadata (public)
- `/.well-known/atproto-did` / `/.well-known/site.standard.publication` - AT Protocol discovery (public)
- `/admin/config/services/atmosphere/backfill/*` - AJAX batch backfill

## Code Conventions

- **Strict types**: All PHP files use `declare(strict_types=1)`
- **Drupal coding standards**: PSR-12 with Drupal conventions
- **Dependency injection**: Constructor promotion, services defined in YAML
- **Error handling**: Queue workers log errors and throw `RequeueException` for retries
- **Static save guard**: `Publisher::$isSaving` prevents infinite hook loops during metadata writes

## Configuration

Config lives in `atmosphere.settings`:
- `auto_publish` (bool) - Automatically publish nodes on creation
- `syncable_node_types` (string[]) - Which content types to sync (default: `[article]`)
- `publication_tid` / `publication_uri` - Site publication record references

## Permissions

- `administer atmosphere` (restricted) - Connect/disconnect and configure
- `preview atmosphere records` - View `?atproto` JSON preview on nodes

## Testing

No automated tests yet. Manual testing:
- Append `?atproto` to any published node URL to preview the AT Protocol records (requires `preview atmosphere records` permission)
- Queue processing happens via cron or `drush queue:run atmosphere_publish`

## Notable Implementation Details

- **DPoP nonces**: Stored in expirable key-value with 5-min TTL; auto-retried on 401
- **Encryption**: libsodium secretbox with key derived from Drupal `hash_salt`
- **TID generation**: Microsecond timestamps left-shifted 10 bits + random clock ID, base-32 encoded
- **Text truncation**: Grapheme-aware (not byte-aware) for proper Unicode support
- **Handle resolution**: DNS TXT `_atproto.handle` with HTTPS `.well-known` fallback
- **Image uploads**: Checks 1MB Bluesky limit; uses image style derivatives
- **Content parsers**: Extensible via `hook_atmosphere_content_parser`
