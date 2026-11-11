# Tests

Two suites. Neither is shipped in the plugin zip.

## Unit tests — `tests/unit/` (PHPUnit + Brain Monkey)

Fast, isolated tests of the plugin's logic with WordPress functions mocked (no database, no site). Run in CI on every push (`.github/workflows/unit-tests.yml`) on PHP 8.2 (the oldest supported) and 8.5, together with PHPStan.

Requirements: PHP 8.2+ with `dom`, `mbstring`, `libxml`, and Composer.

```bash
cd tests/unit
composer install
composer test        # vendor/bin/phpunit
composer phpstan     # static analysis, see docs/DEVELOPER.md → Code quality and security checks
```

Covers: the **content splitter** and **writer** (block comments, empty blocks and shortcodes kept, one text per block, putting translations back, YOOtheme layouts field by field, the order API's language tags), the **AI client** (languages without a mapping refused without a request; WPML codes and locales such as `pt-br`/`pt_BR`, `zh-hans`/`zh_CN`; *Translation review* routing; error messages) and the **AI settings** sanitizing, **Language_Codes**, translation files (`Translation_File_Glue` with a fake store), the human-order `Client`, `Callback`, `Writeback` parsing (`data-st-ctx`), `Services`, `Settings`, `Draft_Preview`, the VibeBoost client, the **Commando Center** glue (key check, reports, ping, messages, the WPML language mapping; a fake transport and `SUPERTEXT_REPORT_URL` on `127.0.0.1`, so nothing reaches the live center) and the **interface translations** (German, French, Italian complete, `.mo` current).

## End-to-end tests — `tests/e2e/` (Playwright)

Drive a real browser against a WordPress site with WPML and this plugin.

```bash
cd tests/e2e
cp .env.local.example .env.local   # SITE_URL / WP_USER / WP_PASS (gitignored)
npm install
npx playwright install chromium    # or PW_CHANNEL=chrome to use an installed Chrome
npx playwright test                # the tests
npm run docs:screenshots           # the screenshots of docs/images/
```

- `supertext.spec.js`: the settings page (status, AI settings, language mapping, professional settings), the Orders, Translation files and Debug pages, the bulk actions in the pages list, and an AI translation from the editor's Supertext box (page *Welcome to the Supertext demo*, the demo's sample page; skipped if it doesn't exist).
- `docs.spec.js`: the screenshots of the user and installation guides, cropped to the relevant part.

### Stand-ins (no WPML licence, no API key)

`tests/e2e/stand-in/` holds two must-use plugins for a **test site only** (copy them to `wp-content/mu-plugins/`):

- `wpml-stand-in.php` emulates exactly the WPML hooks the plugin uses (`includes/Wpml/Wpml.php`) with English, German (`de_CH`), French and Italian. It does nothing when the real WPML is active.
- `supertext-stand-in.php` answers the Supertext AI API with real German, French and Italian translations of the demo's sample page (unknown texts come back unchanged), rejects invalid language pairs like the real API, and answers Plugin Health reports, so a test site never shows up in the dashboard.

The screenshots in `docs/images/` were taken on WordPress with both stand-ins and the demo's sample content (`demo/docker/setup.php`).
