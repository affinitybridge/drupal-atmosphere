# ATmosphere

A Drupal module that publishes content to the [AT Protocol](https://atproto.com/) (Bluesky and [standard.site](https://standard.site)) via native OAuth authentication.

When you publish a node in Drupal, ATmosphere creates both a Bluesky post (`app.bsky.feed.post`) and a standard.site document (`site.standard.document`) on your Personal Data Server (PDS) in a single atomic operation.

## Features

- **OAuth 2.1 authentication** with PKCE, DPoP (Demonstration of Proof-of-Possession), and PAR (Pushed Authorization Requests)
- **Automatic publishing** of configured content types on node create/update/delete
- **Atomic writes** - Bluesky post and standard.site document created in a single `applyWrites` call
- **Rich text facets** - links, @mentions (resolved to DIDs), and #hashtags extracted automatically
- **Image support** - cover images and thumbnails uploaded as blobs with size validation
- **Site publication sync** - site name/slogan changes propagate to your `site.standard.publication` record
- **Backfill** - batch-publish existing content via an AJAX UI
- **Content preview** - append `?atproto` to any node URL to inspect the AT Protocol records
- **Extensible content parsing** via `hook_atmosphere_content_parser`

## Requirements

- Drupal 10.3+ or Drupal 11
- PHP 8.2+
- PHP extensions: `openssl`, `sodium`
- Composer dependency: `web-token/jwt-library` ^4.1

## Installation

```bash
composer require drupal/atmosphere
drush en atmosphere
```

## Setup

1. Navigate to **Administration > Configuration > Web services > ATmosphere** (`/admin/config/services/atmosphere`)
2. Enter your AT Protocol handle (e.g. `yourname.bsky.social` or a custom domain handle)
3. Complete the OAuth authorization flow - you'll be redirected to your authorization server to grant access
4. Configure which content types to publish and whether to auto-publish on node creation

### Domain Handle Verification

If you use your Drupal site's domain as your AT Protocol handle, ATmosphere serves the `/.well-known/atproto-did` endpoint automatically for handle verification.

### Site Publication

ATmosphere creates a `site.standard.publication` record representing your site. The `/.well-known/site.standard.publication` endpoint is served automatically for discovery.

## How It Works

### Publishing

When a node is created or updated:
1. The module queues the node for processing
2. A **Document Transformer** builds a `site.standard.document` record (title, URL, excerpt, cover image, full text, tags, timestamps)
3. A **Post Transformer** builds an `app.bsky.feed.post` record (text truncated to 300 graphemes, link/mention/hashtag facets, embed card or thumbnail)
4. Both records are written atomically to your PDS via a DPoP-signed API request
5. The resulting AT-URIs and CIDs are stored back on the node for future updates/deletes

### Security

- OAuth tokens are encrypted at rest using libsodium (key derived from Drupal's `hash_salt`)
- All API requests are signed with DPoP proofs (ES256)
- DPoP nonces are tracked and automatically retried
- PKCE is used for the authorization code flow

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Auto-publish | Automatically publish nodes when created | `true` |
| Content types | Which node types to sync | `article` |

## Permissions

| Permission | Description |
|------------|-------------|
| Administer ATmosphere | Connect/disconnect account, configure settings |
| Preview AT Protocol records | View `?atproto` JSON preview on node pages |

## Queue Processing

Publishing happens asynchronously via Drupal's queue system. Records are processed during cron runs. To process manually:

```bash
drush queue:run atmosphere_publish
drush queue:run atmosphere_update
drush queue:run atmosphere_delete
drush queue:run atmosphere_sync_publication
```

## Backfill

To publish existing content that was created before ATmosphere was installed:

1. Go to the ATmosphere settings page
2. Click the **Backfill** button
3. The module will batch-process all published nodes of your configured content types that haven't been synced yet

## License

GPL-2.0-or-later
