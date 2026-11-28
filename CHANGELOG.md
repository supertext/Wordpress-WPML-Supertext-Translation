# Changelog

All notable changes to Supertext for WPML. A release is published when a new `## X.Y.Z — YYYY-MM-DD` section appears below *Unreleased* (see docs/DEVELOPER.md → Releasing).

## Unreleased

- Added: **links point to the translated pages.** Links to the site's own posts and pages point to their translation in the same language, with anchors and queries kept, in AI translations, orders and imported files. Setting *Point links to the translated pages* (on by default) under Supertext → Settings.
- Added: **localized images are kept.** Translating again keeps a translation's own images (such as localized screenshots) as long as the original's image in that place doesn't change, with the new alternative text and caption. If the original's image is replaced, the translation gets the new one, marked *To check*. The new **Supertext → Images** lists every translated image, original and translation side by side, with *Mark as checked*; the editor box says how many images there are to check.

## 1.4.0 — 2026-11-26

- Added: Indonesian, Romanian, Thai and Vietnamese (`id`, `ro`, `th`, `vi`) can be chosen as Supertext languages, all with formal and informal address (Supertext SDK 0.7.0).
- Added: Indonesian, Romanian, Thai and Vietnamese (`id`, `ro`, `th`, `vi`) can be chosen as Supertext languages, all with formal and informal address (Supertext SDK 0.7.0).
- Docs: the screenshots show real Supertext translations: CI takes them with the live API (secret `SUPERTEXT_API_KEY`); the stand-in translates only without the key, and still answers orders and version reports.

## 1.3.0 — 2026-11-24

- Shortcodes (e.g. `[wpforms id="2784" title="false"]`) are kept exactly as they are in AI translations. Before, the translation could turn their quotes typographic, so WordPress no longer recognised them and the form or gallery was missing from the translated page.

## 1.2.0 — 2026-11-24

- **Supertext → String translations**: translate form fields (WPForms, Gravity Forms, Contact Form 7 with their WPML add-ons) and other WPML string packages with Supertext AI or order a professional translation, or edit them in a grid, as in the Polylang plugin. Needs WPML String Translation.
- Demo: WPML 5.4.0 with WPML String Translation, WPForms Lite and WPForms Multilingual (variables `WPML_ST_ZIP_URL` and `WPML_WPFORMS_ZIP_URL`), and a newsletter form on the new page *Subscribe*.
- Demo: two more ACF blocks, *Bild* (an image field) and *Bildergalerie* (a gallery and a radio field), next to *Text*; the two spacing switches are now one shared field group for all three, the way ACF sites usually build them. Nothing changes in the plugin.
- Demo: the media library is on a volume (`wordpress-wpml-uploads`, mounted at `wp-content/uploads`). Uploaded images used to disappear with the next deploy while their attachments stayed in the database, so pages showing them broke in every language.

## 1.1.0 — 2026-11-13

- ACF blocks are translated: the texts in an ACF block's fields (e.g. `acf/text`), repeaters and groups included, which used to stay in the source language. With ACF Multilingual, each field's translation preference decides; without it, the plugin translates every text field and keeps switches, numbers, links and images. Works for AI translation, human orders and translation files.

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
