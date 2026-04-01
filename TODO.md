# ATmosphere - Code Quality TODO

## Open

### Service locator anti-pattern: nullable `languageManager` in base class

- [ ] `src/Transformer/TransformerBase.php` — `languageManager` is a nullable property set by concrete subclasses (`PostTransformer`) rather than declared in the base constructor. This is acceptable for the current two-subclass hierarchy where only `PostTransformer` needs language context. If a third transformer needs `getLangs()`, consider adding an abstract method or moving the property to a shared constructor.

## Resolved

The following issues have been fixed:

- [x] `src/Transformer/PublicationTransformer.php` — `hexToRgb()` changed from `public static` to `private static`
- [x] `src/Form/SettingsForm.php:26` — `$config_factory` renamed to `$configFactory` per Drupal OOP camelCase convention
- [x] `src/Transformer/TidGenerator.php` — `strpos() === FALSE` replaced with `str_contains()`
- [x] `src/Transformer/DocumentTransformer.php` — Added `use Drupal\atmosphere\ContentParser\ContentParserInterface` import; removed inline FQCN
- [x] `atmosphere.module` — Uppercase `TRUE`/`FALSE` standardized to lowercase
- [x] `src/Service/ApiClient.php` — Uppercase `TRUE`/`FALSE` standardized; added `@param`/`@return` docblocks to all public methods
- [x] `src/Service/Publisher.php` — Uppercase `TRUE`/`FALSE` standardized; added `@param`/`@return` docblocks to `publish()`, `update()`, `delete()`, `deleteByTids()`, `syncPublication()`
- [x] `src/Service/ConnectionManager.php` — Added `@return` docblocks to `isConnected()`, `getConnection()`, `getDid()`, `getHandle()`, `getPdsEndpoint()`, `getTokenEndpoint()`, `getAuthServer()`, `getAccessToken()`, `getRefreshToken()`, `getDpopJwk()`, `getExpiresAt()`
- [x] `src/OAuth/Client.php` — Uppercase `TRUE`/`FALSE` standardized; added `@return` docblocks to `clientId()`, `redirectUri()`, `accessToken()`, `dpopJwk()`, `disconnect()`
- [x] `src/Controller/BackfillController.php` — Added `@param`/`@return` docblocks to `count()` and `batch()`
- [x] `\Drupal::service('file_system')` in DocumentTransformer, PostTransformer, PublicationTransformer — now injected via `FileSystemInterface`
- [x] `\Drupal::entityTypeManager()` in PostTransformer — now injected via `EntityTypeManagerInterface`
- [x] `\Drupal::languageManager()` in TransformerBase — now uses injected `LanguageManagerInterface`
- [x] `$GLOBALS['base_url']` in ClientMetadataController, DocumentTransformer, PublicationTransformer — replaced with `UrlGeneratorInterface`
- [x] `\Drupal::logger()` in Publisher — now uses injected `LoggerInterface`
- [x] `PreviewSubscriber` JSON double-encoding bug — fixed to `new JsonResponse($record)`
- [x] `truncateText()` now uses `grapheme_strlen`/`grapheme_substr` with `mb_` fallback
