# Developer guide

Architecture, the Supertext API, WPML, tests, CI, the demo and releasing for **Supertext for WPML**. Users: [user guide](USER_GUIDE.md); administrators: [installation guide](INSTALLATION.md).

## Why a separate plugin

[Supertext for Polylang](https://github.com/Supertext/Wordpress-Polylang-Supertext-Translation) registers Supertext as a machine-translation *service* inside Polylang Pro and reuses Polylang's export/import pipeline. WPML has nothing comparable for third parties: its machine translation runs on WPML's servers (Advanced Translation Editor), and translation services go through WPML's Translation Proxy, which needs a partnership with OnTheGoSystems. The old combined plugin ([polylang-supertext](https://github.com/supertext/polylang-supertext)) had both behind one interface; this time the two are independent plugins.

So this plugin brings its own pipeline and talks to WPML only through WPML's **public hooks** (`apply_filters` / `do_action`, see the [WPML hooks reference](https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/)). Everything that doesn't depend on the multilingual plugin (Supertext SDK, order API client, translation files, Plugin Health reporting, preview links) is the same code as the Polylang plugin, in the namespace `Supertext\Wpml`.

## Architecture

```
supertext-wpml.php                 bootstrap: autoloader (Supertext\Wpml\ → includes/), translations, hooks
includes/
  Wpml/Wpml.php, Language.php      everything asked of WPML (hooks only)
  Content/Collector.php            what of a post is translated (one list for AI, orders and files)
  Content/Content_Splitter.php     post content → parts to translate / to keep (no WordPress dependency)
  Content/Writer.php               writes translations into the WPML translation (create + link, or update)
  Machine_Translation/
    Ai_Settings.php                option supertext_wpml_ai, language mapping, its form
    Client.php                     AI translation through the SDK (review routing, error messages)
    Translator.php                 one post into one language: Collector → Client → Writer
    Language_Codes.php             WordPress locale → Supertext code (no WordPress dependency)
    Wp_Http_Transport.php          SDK HTTP over wp_remote_request()
  Admin/
    Page.php                       Supertext → Settings (status, AI, professional) and Debug
    Editor_Box.php                 the Supertext box in the editor
    Bulk_Actions.php               AI / human bulk actions, quote (AJAX)
    Settings.php                   option supertext_wpml_settings (orders, write-back, features, reports)
    Orders_Page.php, Files_Page.php
  Human_Translation/               order API client, translation types, orders registry, REST callback, write-back, source lock
  Files/                           translation files glue (no WordPress dependency) + Wpml_File_Store
  Reporting/                       Commando Center / Plugin Health glue
  Preview/, Integrations/          secret preview links, VibeBoost screenshots, YOOtheme layout walker
  Sdk/, PluginHealth/              bundled copies — never edit (see below)
```

### WPML hooks used (`includes/Wpml/Wpml.php`)

| Need | Hook |
| --- | --- |
| WPML active | `ICL_SITEPRESS_VERSION` and `has_filter( 'wpml_active_languages' )` |
| Languages (code, name, `default_locale`) | `wpml_active_languages` (`skip_missing => 0`) |
| Default / current language, switching | `wpml_default_language`, `wpml_current_language`, `wpml_switch_language` |
| Is a post type / taxonomy translated | `wpml_is_translated_post_type`, `wpml_is_translated_taxonomy` |
| Language and group (`trid`) of a post or term | `wpml_element_language_details` (terms: by term_taxonomy_id) |
| All translations of a group | `wpml_get_element_translations` (`post_<type>`) |
| Term translation | `wpml_object_id` |
| Link a new translation | `wpml_set_element_language_details` (`post_<type>` / `tax_<taxonomy>`, `trid`, `source_language_code`) |
| Custom field settings | `wpml_setting` → `translation-management` → `custom_fields_translation` (0 ignore, 1 copy, 2 translate, 3 copy once) |

A language's Supertext code comes from its WPML **default locale** (`de_CH`), the WPML code (`de`, `pt-br`) only when WPML has no locale. Reading and writing switch WPML's current language to the post's language (`Wpml::in_language()`), because WPML filters term queries by it.

### What is translated (`Content\Collector`)

Each text has a context that is stable for the same source, used as `data-st-id` key (AI), `data-st-ctx` (order files, base64) and XLIFF/HTML segment key (translation files):

| Context | Text | Sent as |
| --- | --- | --- |
| `title` | title | text |
| `excerpt` | excerpt | HTML |
| `content:<n>` | part n of the content (`Content_Splitter`) | HTML |
| `yootheme:<path>` | YOOtheme Pro layout prop (`Integrations\YooTheme\Layout`) | HTML |
| `meta:<key>` | custom field WPML *translates*, single non-numeric string value | text, HTML if it has tags |
| `term:<taxonomy>:<id>` / `term_description:…` | name / description of a term without a translation in the target language | text / HTML |

`Content_Splitter` keeps HTML comments (block delimiters with their JSON attributes, YOOtheme's layout comment, `<!--more-->`), parts without visible text (`<hr/>`, images, shortcodes only, `&nbsp;`) and surrounding white space; every other part — one block's HTML — is one text, so a sentence is never split at its formatting (the rich-text rule of the Supertext API). Filters: `supertext_wpml_meta_keys` (fields to translate), `supertext_wpml_collected_entries` (the whole list).

### Writing (`Content\Writer`)

Creates the translation with `wp_insert_post()` (draft, or published for human orders set to *Published*), links it with `wpml_set_element_language_details` to the source's `trid`, or updates the existing one with `wp_update_post()` (status unchanged unless *Published*). Content is rebuilt from the **source** with the translated parts; title, excerpt and fields fall back to the existing translation's (or, for a new one, the source's) values. *Copy* fields are copied, *copy once* fields and `_thumbnail_id`, `_wp_page_template` only into a new translation. Terms: existing term translation, else a new term from the translated name (slug with `-<code>` if taken) linked with `tax_<taxonomy>`; terms without either are left out; terms of untranslated taxonomies are copied. Parent: the parent's translation. Action after saving: `supertext_wpml_translation_saved( $id, $source, $target, $map )`.

`Machine_Translation\Translator` refuses to translate a translation into its original's language (`supertext_would_overwrite_original`) and, without `$overwrite`, a language that already has a translation (`supertext_already_translated`; the bulk action never overwrites, the editor box asks first).

## Supertext AI translation API

AI file translation API v1 through the bundled **Supertext PHP SDK** ([Supertext-API-SDK-Guide](https://github.com/Supertext/Supertext-API-SDK-Guide)): one HTML file per post (`<div data-st-id="N">` per text), `POST /translate/ai/file` → poll `GET …/{id}/status` (every 2 s, up to 180 s) → `GET …/{id}/translation` → `DELETE`. `Authorization: Supertext-Auth-Key <key>` (a pasted prefix is stripped), HTTP 429 retried up to 4 times (`Retry-After`, else 1/2/4/8 s), `User-Agent: supertext-wordpress-wpml/<version>`. The call blocks the request while polling (`set_time_limit` is raised where allowed). Errors become `WP_Error` codes `supertext_<code>` with a translated message (`Client::to_wp_error()`).

| Filter | Default | Purpose |
| --- | --- | --- |
| `supertext_wpml_endpoint` | the environment's URL, `https://api.supertext.com/v1/` | API base URL |
| `supertext_wpml_review_endpoint` | `SUPERTEXT_REVIEW_URL` (constant, else env), else `https://localizationreview-production.up.railway.app/v1/` | Review service for languages with *Translation review* (`''` = off) |
| `supertext_wpml_language_code` | mapping, else exact match, else SDK default | Supertext target code of a `Wpml\Language` (`''` = unsupported); wins over the drop-down |
| `supertext_wpml_poll_interval` / `_poll_timeout` | 2 / 180 s | Polling |
| `supertext_wpml_meta_keys`, `supertext_wpml_collected_entries` | WPML's *Translate* fields / the collected list | What is sent |
| `supertext_wpml_yootheme_fields`, `supertext_wpml_yootheme_skip_content_types` | `content, title, meta, alt, image_alt` / `code` | YOOtheme props |
| `supertext_wpml_human_services`, `supertext_wpml_quote_currency`, `supertext_wpml_accept_language`, `supertext_wpml_screenshot_endpoint`, `supertext_wpml_order_url_locale` | | Professional orders (as in the Polylang plugin) |

### Language codes

Supertext accepts only the codes of its `GET features` list (the SDK's `Languages`): sources bare (`en`), targets regional for de/en/fr/it/pt (`de-CH`, `en-US`, `pt-BR`), a script for zh/sr (`zh-Hans`, `sr-Latn`), bare for the others (`es`, `nl`). A WPML language resolves by its locale (`Ai_Settings::resolve_target()`, `Language_Codes`, unit-tested): (1) the administrator's drop-down (`supertext_wpml_ai.languages`, WPML code → Supertext code), then the `supertext_wpml_language_code` filter; (2) exact match of the locale (`de_CH` → `de-CH`); (3) the SDK default (`de_DE` → `de-DE`, `zh_CN` → `zh-Hans`, `es_ES` → `es`); (4) the same without `_formal`/`_informal`; (5) else `unsupported_language`: nothing is sent and the editor is told which language to map. One mapping serves both directions (the source is the target code's language, `de-CH` → `de`). Saving keeps only Supertext's targets (`Ai_Settings::sanitize()`), formality only `more`/`less` where Supertext offers it, review as booleans.

### Translation review

A checkbox per WPML language (`supertext_wpml_ai.review`). Translations into such a language go through the review service ([Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation)), which speaks the same API v1 with the same key, stores every segment and serves reviewers' corrections on the next translation. The key check, reports, translation files and orders never use it.

## Professional (human) orders

Same order API client as the Polylang plugin (`Human_Translation\Client`, HTTP Basic with account email + Order API Key, not the SDK): translation types from `GET api/v1/util/ordertype/{ServiceID}` (cached 12 h per environment, ServiceID and back-end language), quote `POST translation/quote` per source language (AJAX `supertext_wpml_quote`), upload of the HTML document (`Human_Translation\Content::render()`, `data-st-ctx` = base64 context, plain-text fields HTML-escaped) and an optional page screenshot (VibeBoost, DocumentTypeId 3), order with `ReferenceData` (signed) and `CallbackUrl` = `/wp-json/supertext-wpml/v1/…`. On completion `Callback` fires `supertext_wpml_order_completed`, `Writeback` downloads the *Final* files and writes them through `Content\Writer`. Order language codes: the WPML locale as BCP 47 (`Language::w3c()`, `de_DE_formal` → `de-DE`). `Source_Lock` blocks deleting a post with an open order.

## Translation files

*Export for translation* / *Import translation* with the SDK's `TranslationFiles` (XLIFF 1.2 or HTML), no API calls. `Files\Translation_File_Glue` (no WordPress dependency, `tests/unit/cases/FileExportImportTest.php`) with `Files\Wpml_File_Store` (segments from `Collector`, permissions, write-back through `Writeback::save_map()` without un-escaping). Job metadata: `plugin` = `supertext-wordpress-wpml`, `reference` = `post:<id>:<WPML code>`, target = the mapped code (else the locale with `-`).

## Commando Center / Plugin Health

Version reports, the automatic key check and the ping (`GET /wp-json/supertext/v1/ping`) through [Supertext Plugin Health](https://github.com/supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (bundled in `includes/PluginHealth/`). Plugin id `supertext-wordpress-wpml`, system `WordPress`. Three keys by name: `AI translation` (`supertext_wpml_ai.api_key`, the SDK's `checkApiKey()`), `Professional orders` (account email + Order API Key, a GET of the order types; always reported, `missing` until both are set) and `Page screenshots (VibeBoost)` (while page screenshots are on or a VibeBoost key is set): a Pro capture (`fullPage=true`) of `https://supertext-key-check.invalid/`, so VibeBoost checks the key first (401/403 invalid) and a valid key's capture fails at once (500 *Screenshot failed* = ok) — nothing is captured. A 401/403 from a real capture also reports the key as invalid. When: saving the AI settings (`update_option_supertext_wpml_ai`) or the professional settings, the Supertext pages and every AI translation (`onRequest`, on `shutdown`), an AI 401/403, **Test connection**, **Send configuration to Supertext** (plugins, themes and the language mapping: every WPML locale with its Supertext code). Setting `version_reports` (on by default), `SUPERTEXT_VERSION_REPORTS` wins, `SUPERTEXT_REPORT_URL` redirects reports (tests). State in `supertext_wpml_reporter`; keys are never stored or sent, only fingerprints.

## Bundled libraries — never edit

- `includes/Sdk/`: Supertext PHP SDK, namespace `Supertext\Wpml\Sdk`. Update: `scripts/vendor-sdk.sh [ref]` (`SDK_DIR=/path/to/clone` for a local clone); `COMMIT` names the revision.
- `includes/PluginHealth/`: Plugin Health, namespace `Supertext\Wpml\PluginHealth`. Update: `scripts/vendor-plugin-health.sh <tag>`.

Fix them in their own repositories, re-run the script, run the tests, commit with a CHANGELOG entry.

## Interface languages

Text domain `supertext-wpml`; German, French and Italian in `languages/supertext-wpml-{de_DE,fr_FR,it_IT}.po` (formal address), loaded for each user's back-end language (regional locales fall back: `de_CH` → `de_DE`). After changing a `.po`: `php scripts/compile-translations.php`. `tests/unit/cases/TranslationFilesTest.php` checks same keys, placeholders and URLs in all three, current `.mo` files, and that every string in the main file and `includes/` (without `Sdk/`, `PluginHealth/`) is translated.

## Local setup and tests

```sh
cd tests/unit
composer install            # PHPUnit 11, Brain Monkey, PHPStan, WordPress stubs
composer test               # unit tests
composer phpstan            # PHPStan level 5
```

Unit tests cover the content splitter and writer (blocks, YOOtheme), the AI client (mapping, review routing, errors, sanitizing the settings), language codes, translation files, order client, callback, write-back parsing, services, settings, preview links, VibeBoost, the Commando/Plugin Health glue (incl. the WPML language mapping) and the interface translations.

End-to-end tests (`tests/e2e/`, Playwright) run against a WordPress site with WPML and the plugin: see [tests/README.md](../tests/README.md). For a site without a WPML licence, `tests/e2e/stand-in/wpml-stand-in.php` emulates exactly the WPML hooks above (mu-plugin, never on a real site), and `supertext-stand-in.php` answers the Supertext API with real translations of the demo's sample page (and swallows Plugin Health reports), so no API key is needed. The screenshots in `docs/images/` are taken by `npm run docs:screenshots` (`tests/e2e/docs.spec.js`) on a site with WPML (5.3.0 so far), the demo's sample content and the Supertext stand-in; WPML's licence notices are hidden in them; regenerate them whenever the UI they show changes.

## Code quality and security checks

- **Unit tests and PHPStan** (`.github/workflows/unit-tests.yml`): PHPUnit on PHP 8.2 and 8.5; PHPStan level 5 on `supertext-wpml.php` and `includes/` with the WordPress stubs (WPML needs none: only hooks are used). Findings inherited from the Polylang plugin's code are in `phpstan-baseline.neon`; new code must not add any.
- **Checks** (`checks.yml`): actionlint and zizmor on every push and PR, dependency review on PRs. Actions pinned to commit SHAs, `persist-credentials: false`, `permissions: contents: read` by default.
- **Links** (`links.yml`): lychee weekly and on docs changes; broken links open the issue "Broken links in the docs" (exclusions in `.lycheeignore`).
- **Support triage** (`support-triage.yml`): issues labelled `support` from the integrations website's support form are answered, fixed on a `claude/support-<n>` branch or labelled `feature-request` by Claude (same file in every plugin repo).
- **Dependabot** (`.github/dependabot.yml`): weekly, Composer (test tools) and GitHub Actions, minor and patch grouped.
- **GitHub settings** (Remy's setup script): secret scanning with push protection, CodeQL default setup, private vulnerability reporting, "Protect main" ruleset.

Before working in this repo, look at its open code scanning and secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

### Dependency updates

Dependabot's minor and patch updates of the test tools and actions are grouped. `phpunit/phpunit` stays on 11 (12 needs PHP 8.3, the tests run on 8.2, the oldest supported PHP).

## Demo site

`demo/Dockerfile` (build context: the repository root) builds WordPress with this plugin; `demo/docker/entrypoint.sh` creates the database, rebuilds `wp-config.php`, installs WordPress once, installs and activates **WPML from `WPML_ZIP_URL`** (WPML is commercial and not on wordpress.org; without it the plugin shows its *requires WPML* notice), runs `demo/docker/setup.php` (WPML languages English, German, French, Italian through SitePress's methods, the demo accounts, the professional translation settings, five English sample pages and posts; with WPML, posts and pages without a language become English) and starts Apache. Every step is idempotent. It runs as the **WordPress-WPML** service in the Railway project *supertext-cms-demos-php* (<https://wordpress-wpml-production.up.railway.app>, Dockerfile path `demo/Dockerfile`, health check `/healthz`, region `ams`, database `wordpress_wpml_demo` on the shared **MySQL-8** service, `DEMO_*` referencing the Contao service's values) and redeploys on every push to `main`. `setup.php` runs WPML's own setup wizard steps (`WPML_Installation`: default language, active languages, site key, finish; tested with WPML 5.3.0) while WPML has no active languages yet, so later changes in WPML stay.

| Variable | Required | Purpose |
| --- | --- | --- |
| `DATABASE_URL` (or `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`) | yes | MySQL server; `WORDPRESS_DB_NAME` (default: the URL's path) is created if missing |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | yes | Administrator, created on the first start, never changed |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | no | Editor (WordPress *Editor* role: can translate every post in every language) |
| `WPML_ZIP_URL` | no | Download URL of the WPML Multilingual CMS zip. On Railway: `http://demo-assets.railway.internal:8080/wpml/sitepress-multilingual-cms.5.3.0.zip`, served by the **demo-assets** service from the private repository `supertext/integrations-demo-assets` on Railway's private network only |
| `WPML_ZIP_TOKEN` | no | Bearer token sent with the download, for a URL that needs one (e.g. the GitHub API URL of a file in a private repository) |
| `WPML_SITE_KEY` | no | WPML's site key (the `OTGS_INSTALLER_SITE_KEY_WPML` constant), registers the demo with wpml.org |
| `SUPERTEXT_API_KEY` | no | AI translation key (used while the settings field is empty) |
| `SUPERTEXT_ACCOUNT_EMAIL`, `SUPERTEXT_ORDER_API_KEY` | no | Professional translation credentials (used while the fields are empty) |
| `VIBEBOOST_API_KEY` | no | VibeBoost key for page screenshots (used while the field is empty) |
| `SUPERTEXT_AI_ENVIRONMENT` | no | Environment of AI translation (`live`, `staging`, `testing`); wins over the setting |
| `SUPERTEXT_HUMAN_ENVIRONMENT` (or `SUPERTEXT_ENVIRONMENT`) | no | Environment of professional orders; wins over the setting |
| `DEMO_SITE_URL`, `WORDPRESS_SALT` | no | Public URL (default `https://$RAILWAY_PUBLIC_DOMAIN`), seed of the auth keys |

Values live only in the hosting platform's variables, never in the repository; the log names variables, never passwords. Copy `demo/.env.example` for a local run. The container has no volume: uploads are lost on redeploy.

## Releasing

1. Move the *Unreleased* entries of `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` heading (leave an empty *Unreleased* above it).
2. Set the same version in `supertext-wpml.php`: the header's `Version:` and `SUPERTEXT_WPML_VERSION`.
3. Push to `main`. `.github/workflows/release.yml` tags `vX.Y.Z`, builds `supertext-wpml-X.Y.Z.zip` and creates the release with the CHANGELOG section as notes (0.x as pre-releases). Without a new version nothing is released; a version file that doesn't match fails the run. Never tag or release by hand.

## Known limitations and roadmap

- AI translation blocks the request while polling; very long content can reach PHP's time limit (a background queue would remove that).
- Custom fields with several values or structured (serialized) values aren't translated; ACF repeaters and flexible content need a dedicated integration (as `pll_after_post_export` integrations did for Polylang).
- Block attributes (e.g. texts some blocks keep in their JSON) and shortcode attributes aren't translated.
- Not ported from the Polylang plugin yet: the string translation grid (WPML String Translation), Gravity Forms, the ACF overview tool.
- WPML's translation status (needs update, ATE jobs) isn't updated: Supertext translations count as translations made in the WordPress editor.
