// @ts-check
// Captures the screenshots of docs/images/ for the user and installation guides:
// `npm run docs:screenshots` against the demo (or any site with WPML, the plugin, the
// sample content and, for real translations without an API key, the Supertext stand-in
// in tests/e2e/stand-in/). Small images: 1× scale, cropped to the relevant part.
const { test } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const OUT = path.join( __dirname, '..', '..', 'docs', 'images' );
const PAGE = 'Welcome to the Supertext demo';
// WPML's licence and site-key notices aren't part of what the guides show.
const CALM = '*,*::before,*::after{animation:none!important;transition:none!important}.spinner{display:none!important}.components-notice-list,.otgs-installer-notice,.otgs-notice,.wpml-notice{display:none!important}#wpadminbar{position:absolute!important}';

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 * @param {import('@playwright/test').Locator=} target
 */
async function shot( page, name, target ) {
	await page.addStyleTag( { content: CALM } ).catch( () => {} );
	await ( target || page ).screenshot( { path: path.join( OUT, name + '.png' ), animations: 'disabled' } );
	console.log( 'captured ' + name );
}

test( 'capture docs screenshots', async ( { page } ) => {
	test.setTimeout( 300000 );
	fs.mkdirSync( OUT, { recursive: true } );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// Settings: status, AI translation (key + language mapping), professional translation.
	await page.goto( 'wp-admin/admin.php?page=supertext-wpml' );
	// Check the keys first (against the stand-in), so the status shows one line per check.
	await page.getByRole( 'button', { name: 'Test connection' } ).click();
	await page.waitForLoadState( 'load' );
	const cards = page.locator( '.st-card' );
	await shot( page, 'settings-status', cards.nth( 0 ) );
	await shot( page, 'settings-ai', cards.nth( 1 ) );
	await shot( page, 'settings-human', cards.nth( 2 ) );

	// WPML's language setup (only on a site with the real WPML).
	await page.goto( 'wp-admin/admin.php?page=sitepress-multilingual-cms/menu/languages.php' );
	const editor = page.locator( '.wpml-language-editor' ).first();
	if ( await editor.waitFor( { timeout: 15000 } ).then( () => true ).catch( () => false ) ) {
		await shot( page, 'wpml-languages', editor );
	}

	// Bulk actions in the pages list.
	await page.goto( 'wp-admin/edit.php?post_type=page' );
	await page.selectOption( '#bulk-action-selector-top', 'supertext_human_translation' );
	await shot( page, 'bulk-actions', page.locator( '.tablenav.top' ) );

	// The Supertext box in the editor: before, overwrite warning, after.
	await page.goto( 'wp-admin/edit.php?post_type=page&s=' + encodeURIComponent( PAGE ) );
	await page.locator( '#the-list a.row-title', { hasText: PAGE } ).first().click();
	// The block editor's welcome guide would cover the box: close it.
	const guide = page.locator( '.components-modal__frame' );
	if ( await guide.first().waitFor( { state: 'visible', timeout: 8000 } ).then( () => true ).catch( () => false ) ) {
		await page.keyboard.press( 'Escape' );
		await guide.first().waitFor( { state: 'hidden' } ).catch( () => {} );
	}
	const box = page.locator( '#supertext-wpml-translate' );
	await box.scrollIntoViewIfNeeded();
	await shot( page, 'editor-box-before', box );
	const translate = box.locator( 'a.button-primary' ).first();
	if ( await translate.count() ) {
		await translate.click();
		await page.locator( '#supertext-wpml-translate' ).waitFor( { state: 'attached', timeout: 240000 } );
		await page.locator( '#supertext-wpml-translate' ).scrollIntoViewIfNeeded();
		await shot( page, 'editor-box-after', page.locator( '#supertext-wpml-translate' ) );

		// The translated result in the editor.
		const edit = page.locator( '#supertext-wpml-translate td a' ).first();
		if ( await edit.count() ) {
			await edit.click();
			await page.waitForTimeout( 3000 );
			await shot( page, 'translated-page' );
			await page.goBack();
		}
	}
	// "Translate again" asks first: the overwrite warning.
	await page.locator( '#supertext-wpml-translate' ).waitFor( { state: 'attached' } );
	const again = page.locator( '#supertext-wpml-translate a', { hasText: 'Translate again' } ).first();
	if ( await again.count() ) {
		const message = await again.getAttribute( 'onclick' );
		await page.evaluate( ( text ) => {
			const div = document.createElement( 'div' );
			div.id = 'st-confirm-preview';
			div.style.cssText = 'position:fixed;top:120px;left:300px;width:520px;padding:24px;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);font:14px/1.5 sans-serif;z-index:100000';
			div.textContent = text;
			document.body.appendChild( div );
		}, JSON.parse( ( message || '' ).replace( /^.*confirm\(\s*/, '' ).replace( /\s*\);?\s*$/, '' ) || '""' ) );
		await shot( page, 'overwrite-warning', page.locator( '#st-confirm-preview' ) );
	}

	// Translation files.
	await page.goto( 'wp-admin/admin.php?page=supertext-wpml-files' );
	await shot( page, 'translation-files', page.locator( '.wrap' ) );
} );
