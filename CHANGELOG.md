# Changelog

All notable changes to Supertext for WPML. A release is published when a new `## X.Y.Z — YYYY-MM-DD` section appears below *Unreleased* (see docs/DEVELOPER.md → Releasing).

## Unreleased

First version, the WPML counterpart of Supertext for Polylang (independent plugin).

- AI translation of posts, pages and other WPML-translatable post types into WPML languages: the *Supertext* box in the editor (Translate / Translate again, with a warning before overwriting) and the *Supertext AI Translation* bulk action (never overwrites). Translations are created as drafts and linked to the original in WPML.
- What is translated: title, content (block by block, formatting kept), excerpt, custom fields WPML translates, untranslated categories and tags, YOOtheme Pro layouts; WPML's *Copy* / *Copy once* fields are copied. A translation's original is never overwritten.
- Settings page with status (WPML, languages, API key check), AI translation settings (API key, environment, language mapping with formality and Translation review per WPML language; `SUPERTEXT_API_KEY` as fallback) and professional translation settings.
- Professional (human) translation orders with live quote, orders page, REST callback and write-back into WPML.
- Translation files: XLIFF 1.2 / HTML export and import.
- Version reports, API key check and ping for Supertext Plugin Health (plugin id `supertext-wordpress-wpml`).
- Interface in English, German, French and Italian.
- Demo image (WordPress + WPML from `WPML_ZIP_URL`, on Railway as WordPress-WPML), unit tests, Playwright tests with stand-ins for WPML and the Supertext API, screenshots for the guides.
