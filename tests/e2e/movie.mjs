#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) on a FRESH demo with WPML, the Supertext
 * stand-in (tests/e2e/stand-in/) and the sample content, like `npm run docs:screenshots`: the sample page
 * has no translations yet. The demo editor opens "Welcome to the Supertext demo", translates it into
 * German from the Supertext box and opens the German draft. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   SITE_URL=http://wpml-docs.test DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/ (git-ignored);
 * MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/e2e/movie/out/Wordpress-WPML-Supertext-Translation.mp4, git-ignored, never committed). Needs ffmpeg.
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
const out = process.env.MOVIE_OUT ?? here('movie/out/Wordpress-WPML-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const PAGE = 'Welcome to the Supertext demo'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo editor (WordPress's Editor role) — not recorded.
await page.goto(`${B}/wp-login.php`)
await page.fill('#user_login', need('DEMO_EDITOR_EMAIL'))
await page.fill('#user_pass', need('DEMO_EDITOR_PASSWORD'))
await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')])
await page.goto(`${B}/wp-admin/edit.php?post_type=page&lang=en&s=${encodeURIComponent(PAGE)}`)
const editUrl = await page.locator('#the-list a.row-title', { hasText: PAGE }).first().getAttribute('href')

async function openEditor(url) {
  await page.goto(url)
  const guide = page.locator('.components-modal__frame')
  if (await guide.first().waitFor({ state: 'visible', timeout: 6000 }).then(() => true).catch(() => false)) {
    await page.keyboard.press('Escape')
    await guide.first().waitFor({ state: 'hidden' }).catch(() => {})
  }
  await page.locator('.editor-styles-wrapper, iframe[name="editor-canvas"]').first().waitFor()
  await page.waitForTimeout(1200)
}

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wpml-movie-'))
await openEditor(editUrl)
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const box = page.locator('#supertext-wpml-translate')

// 1. The English page in the block editor.
await kit.say("Here's a page in English, in WordPress with WPML.", { caption: 'A page in English, in WordPress with WPML' })

// 2. The Supertext box: Translate into German.
await kit.say('The Supertext box lists your other WPML languages. Click Translate next to German.', {
  caption: 'In the <b>Supertext</b> box, click <b>Translate</b> next to <b>German</b>',
  during: async () => {
    await box.scrollIntoViewIfNeeded()
    await kit.moveTo(box)
    await kit.hold(400)
    await kit.moveTo(box.locator('tr', { hasText: 'German' }).locator('a.button-primary').first())
  },
  after: 0,
})
const go = box.locator('tr', { hasText: 'German' }).locator('a.button-primary').first()
await kit.say('Supertext translates the page.', {
  caption: 'Supertext translates the page…',
  during: async () => {
    await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
    await kit.hold(250)
    kit.pause() // the translation and the reload are cut
    await Promise.all([page.waitForNavigation({ timeout: 300_000 }), go.click()])
    await page.locator('#supertext-wpml-translate').waitFor({ timeout: 300_000 })
    await page.waitForTimeout(1500)
    await page.locator('#supertext-wpml-translate').scrollIntoViewIfNeeded()
    await kit.sync()
    kit.resume()
  },
})

// 3. The result: a German draft, linked in WPML.
await kit.say('A few seconds later, the German translation is there, as a draft linked to the English page in WPML.', {
  caption: 'Done: a German draft, linked to the original in WPML',
  during: () => kit.moveTo(page.locator('#supertext-wpml-translate')),
})
await kit.say("Let's open it.", {
  caption: 'Click <b>German</b> to open the translation',
  during: async () => {
    const open = page.locator('#supertext-wpml-translate td a', { hasText: 'German' }).first()
    await kit.moveTo(open)
    await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
    await kit.hold(250)
    kit.pause() // loading the editor is cut
    const href = await open.getAttribute('href')
    await openEditor(new URL(href, B).href)
    await kit.sync()
    kit.resume()
  },
})
await kit.say('The German page is ready. Review it, and publish it as usual.', {
  caption: 'The German page is ready – review it and publish',
  during: async () => { await kit.hold(400); await kit.moveTo(page.locator('.editor-post-publish-button, .editor-post-publish-panel__toggle').first()) },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'WPML', repo: 'Wordpress-WPML-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
