#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, not committed) on a FRESH demo with WPML and its
 * languages but WITHOUT this plugin (DEMO_SKIP_PLUGIN=1) and with the Supertext stand-in, so the API key
 * check works without a real key. The administrator uploads the plugin zip, activates it, enters the API
 * key and checks the status and the language mapping. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   SITE_URL=http://wpml-docs.test PLUGIN_ZIP=/path/supertext-wpml.zip DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
 *     npm run movie:install
 *
 * MOVIE_OUT: where the MP4 goes (default tests/e2e/movie/out/Wordpress-WPML-Supertext-Translation-install.mp4).
 * The key typed in is a made-up placeholder, never a real one. Voice and ffmpeg as in movie.mjs.
 */
import pw from '@playwright/test'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const { chromium } = pw
const B = (process.env.SITE_URL || 'http://wpml-docs.test').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Wordpress-WPML-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const zip = need('PLUGIN_ZIP')

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo administrator (not recorded): installing plugins is an administrator's task.
await page.goto(`${B}/wp-login.php`)
await page.fill('#user_login', need('DEMO_ADMIN_EMAIL'))
await page.fill('#user_pass', need('DEMO_ADMIN_PASSWORD'))
await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')])
await page.goto(`${B}/wp-admin/plugins.php`)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wpml-movie-install-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
/** Clicks something that loads a new page, keeping cursor and caption (the loading is cut). */
async function go(locator) {
  await kit.moveTo(locator)
  await ripple()
  await kit.hold(250)
  kit.pause()
  await Promise.all([page.waitForNavigation({ timeout: 120_000 }), locator.click()])
  await page.waitForLoadState('load')
  await kit.sync()
  kit.resume()
}

// 1. Requirements: WordPress with WPML.
await kit.say('You need WordPress with WPML, set up with your languages. Download the plugin zip from the latest release on GitHub.', {
  caption: 'WordPress with <b>WPML</b> · plugin zip from the latest GitHub release',
  during: () => kit.moveTo(page.locator('tr[data-slug="sitepress-multilingual-cms"]').first()),
})

// 2. Upload and activate.
await kit.say('In Plugins, click Add New Plugin, then Upload Plugin.', {
  caption: '<b>Plugins → Add New Plugin → Upload Plugin</b>',
  during: async () => {
    await go(page.locator('.page-title-action').first())
    await kit.click(page.locator('.upload-view-toggle').first())
    await kit.hold(300)
  },
})
await kit.say('Choose the zip file and click Install Now.', {
  caption: 'Choose <b>supertext-wpml.zip</b>, then <b>Install Now</b>',
  during: async () => {
    await kit.moveTo(page.locator('#pluginzip'))
    await page.setInputFiles('#pluginzip', zip)
    await kit.hold(500)
    await go(page.locator('#install-plugin-submit'))
  },
})
await kit.say('Then activate it.', {
  caption: '<b>Activate Plugin</b>',
  during: () => go(page.locator('a.button-primary', { hasText: /Activate/ }).first()),
})

// 3. API key.
await kit.say('Open Supertext, Settings. Enter your Supertext API key. You generate it on supertext.com, under Integrations, API.', {
  caption: '<b>Supertext → Settings</b>: enter your API key (supertext.com → Integrations → API)',
  during: async () => {
    await go(page.locator('#toplevel_page_supertext-wpml > a').first())
    const key = page.locator('#supertext-ai-api-key')
    await key.scrollIntoViewIfNeeded()
    await kit.type(key, 'st-demo-key-0000')
    await kit.hold(300)
  },
})
await kit.say('Save the AI settings. Supertext checks the key right away.', {
  caption: '<b>Save AI settings</b>: the key is checked',
  during: async () => {
    const save = page.locator('form:has(#supertext-ai-api-key) input[type="submit"]').first()
    await save.scrollIntoViewIfNeeded()
    await go(save)
    await page.evaluate(() => window.scrollTo(0, 0))
    await kit.sync()
    await kit.moveTo(page.locator('.st-card').first())
  },
})

// 4. Status and languages.
await kit.say('The status shows that WPML, your languages and the connection to Supertext work.', {
  caption: 'Status: WPML, languages and the Supertext connection',
  during: () => kit.hold(600),
})
await kit.say('Each WPML language is mapped to a Supertext language. Change it if you need another variant, such as Swiss German.', {
  caption: 'Language mapping: one Supertext language per WPML language',
  during: async () => {
    const mapping = page.locator('select[id^="supertext-lang-"], .st-card select').first()
    if (await mapping.count()) await kit.moveTo(mapping)
    await kit.hold(600)
  },
})
await kit.say("That's it. Editors now find the Supertext box in the editor, and the Supertext actions in the posts and pages lists.", {
  caption: 'Done: the <b>Supertext</b> box and the bulk actions are ready',
  during: () => kit.hold(400),
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'WPML', repo: 'Wordpress-WPML-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
