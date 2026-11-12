# Changelog

All notable changes to Supertext for WPML. A release is published when a new `## X.Y.Z — YYYY-MM-DD` section appears below *Unreleased* (see docs/DEVELOPER.md → Releasing).

## Unreleased

## 1.0.1 — 2026-11-12

- Status panel: each API key check that works is now one line (*Works · checked …*, full message as tooltip); only a key with a problem shows its explanation underneath.
- Docs: screenshots, the README's demo movie and the install and configuration movie are recorded by the workflow *Docs screenshots and movies* on a fixed address (`http://wpml-docs.test`) registered with its own WPML site key, so they show no WPML licence notices. New demo variable `DEMO_SKIP_PLUGIN`.

## 1.0.0 — 2026-11-11

First release, the WPML counterpart of Supertext for Polylang (independent plugin).

- AI translation of posts, pages and other WPML-translatable post types into WPML languages: the *Supertext* box in the editor (Translate / Translate again, with a warning before overwriting) and the *Supertext AI Translation* bulk action (never overwrites). Translations are created as drafts and linked to the original in WPML.
- What is translated: title, content (block by block, formatting kept), excerpt, custom fields WPML translates, untranslated categories and tags, YOOtheme Pro layouts; WPML's *Copy* / *Copy once* fields are copied. A translation's original is never overwritten.
- Settings page with status (WPML, languages, API key check), AI translation settings (API key, environment, language mapping with formality and Translation review per WPML language; `SUPERTEXT_API_KEY` as fallback) and professional translation settings.
- Professional (human) translation orders with live quote, orders page, REST callback and write-back into WPML. AI translation and professional orders each have their own environment, which `SUPERTEXT_AI_ENVIRONMENT` and `SUPERTEXT_HUMAN_ENVIRONMENT` (or `SUPERTEXT_ENVIRONMENT`) on the server can set. Account email, Order API Key and VibeBoost key can come from `SUPERTEXT_ACCOUNT_EMAIL`, `SUPERTEXT_ORDER_API_KEY` and `VIBEBOOST_API_KEY` on the server, like `SUPERTEXT_API_KEY`.
- Translation files: XLIFF 1.2 / HTML export and import.
- Version reports, API key checks (AI, professional orders — reported as missing until set — and the VibeBoost key of page screenshots, checked without capturing anything) and ping for Supertext Plugin Health (plugin id `supertext-wordpress-wpml`).
- Interface in English, German, French and Italian.
- Demo image (WordPress + WPML from `WPML_ZIP_URL`, set up with WPML's own setup steps, site key from `WPML_SITE_KEY`; on Railway as WordPress-WPML, WPML served by the private demo-assets service), unit tests, Playwright tests with stand-ins for WPML and the Supertext API, screenshots for the guides.
