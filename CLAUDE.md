# Working on this repository

Supertext for WPML: part of Supertext's translation plugins project (one repo per system). The full, shared rules are in the project doc `claude/plugin-conventions.md`; the ones that matter most here:

- **Docs in the same commit:** every change to behaviour, settings, installation or code structure updates `docs/INSTALLATION.md`, `docs/USER_GUIDE.md` and/or `docs/DEVELOPER.md`, and `CHANGELOG.md` (*Unreleased*). `README.md` stays a short overview ending with the shared plugin list (between the `supertext-plugins` markers). Screenshots in `docs/images/` come from `npm run docs:screenshots` (`tests/e2e/docs.spec.js`) and are regenerated when the UI they show changes.
- **API key links** wherever the key is entered or mentioned: account <https://www.supertext.com/person/en/account/signin>, key <https://www.supertext.com/en/integrations/api> (supertext.com → Integrations → API, Admin role).
- **UI languages:** every new or changed string in English, German, French and Italian (`languages/*.po`, formal address, then `php scripts/compile-translations.php`); `TranslationFilesTest` enforces it.
- **WPML only through its public hooks** (`includes/Wpml/Wpml.php`); `tests/e2e/stand-in/wpml-stand-in.php` must keep emulating every hook the plugin uses.
- **Bundled libraries:** never edit `includes/Sdk/` or `includes/PluginHealth/`; fix them upstream and re-run `scripts/vendor-sdk.sh` / `scripts/vendor-plugin-health.sh`.
- **Checks:** unit tests and PHPStan level 5 (`cd tests/unit && composer test && composer phpstan`), actionlint + zizmor (`--offline`) for workflow changes. No new PHPStan baseline entries.
- **Releases** only by bumping the version (`CHANGELOG.md` section + header `Version:` + `SUPERTEXT_WPML_VERSION`); never tag by hand.
- **Demo accounts** from `DEMO_ADMIN_*` / `DEMO_EDITOR_*`, values only in the hosting platform's variables.
- Before starting, look at the repo's open code scanning and secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.
