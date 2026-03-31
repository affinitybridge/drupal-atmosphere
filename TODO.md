# ATmosphere - Code Quality TODO

## High Priority

### Service locator anti-pattern: `\Drupal::service()` inside DI classes

- [ ] `src/Transformer/TransformerBase.php` — `languageManager` is a nullable property set by subclasses rather than injected via a shared constructor. Consider requiring it in the base constructor or using an abstract getter.

### `hexToRgb()` visibility

- [ ] `src/Transformer/PublicationTransformer.php:163` — `public static` but only used internally; should be `private static`

## Medium Priority

### Missing `@param`/`@return` docblock tags on public methods

Per Drupal API documentation standards, all public methods need full docblocks with `@param` and `@return`.

- [ ] `src/Service/ConnectionManager.php` — `getDid()`, `getHandle()`, `getPdsEndpoint()`, `getTokenEndpoint()`, `getAuthServer()`, `getAccessToken()`, `getRefreshToken()`, `getDpopJwk()`, `getExpiresAt()` missing `@return`
- [ ] `src/Service/ApiClient.php` — `get()`, `post()`, `uploadBlob()`, `applyWrites()`, `getRecord()`, `listRecords()`, `putRecord()` missing `@param`/`@return`
- [ ] `src/Service/Publisher.php` — `update()`, `delete()`, `deleteByTids()`, `syncPublication()` missing `@param`/`@return`
- [ ] `src/OAuth/Client.php` — `clientId()`, `redirectUri()`, `accessToken()`, `dpopJwk()`, `disconnect()` missing `@return`
- [ ] `src/Controller/BackfillController.php` — `count()`, `batch()` missing `@return`

### `SettingsForm` constructor parameter naming

- [ ] `src/Form/SettingsForm.php:26` — `$config_factory` should be `$configFactory` per Drupal OOP variable naming convention (camelCase)

## Low Priority

### `TidGenerator::isValid()` — modernize string check

- [ ] `src/Transformer/TidGenerator.php` — Replace `strpos() === FALSE` with `str_contains()` (PHP 8.0+)

### Boolean constant casing inconsistency

The `.module` file uses `TRUE`/`FALSE` while all `src/` classes use `true`/`false`. Consider standardizing to lowercase throughout.

- [ ] `atmosphere.module` — Multiple instances of uppercase `TRUE`/`FALSE`

### Missing `use` import

- [ ] `src/Transformer/DocumentTransformer.php` — References `\Drupal\atmosphere\ContentParser\ContentParserInterface` with full namespace inline instead of importing with `use`

## Resolved

The following issues were fixed in the latest pull from remote:

- [x] `\Drupal::service('file_system')` in DocumentTransformer, PostTransformer, PublicationTransformer — now injected via `FileSystemInterface`
- [x] `\Drupal::entityTypeManager()` in PostTransformer — now injected via `EntityTypeManagerInterface`
- [x] `\Drupal::languageManager()` in TransformerBase — now uses injected `LanguageManagerInterface`
- [x] `$GLOBALS['base_url']` in ClientMetadataController, DocumentTransformer, PublicationTransformer — replaced with `UrlGeneratorInterface`
- [x] `\Drupal::logger()` in Publisher — now uses injected `LoggerInterface`
- [x] `PreviewSubscriber` JSON double-encoding bug — fixed to `new JsonResponse($record)`
- [x] `truncateText()` now uses `grapheme_strlen`/`grapheme_substr` with `mb_` fallback
