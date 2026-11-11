// @ts-check
// End-to-end tests against a WordPress site with WPML and this plugin (see tests/README.md).
// The AI test needs an English post titled "Welcome to the Supertext demo" (the demo's
// sample page works too) and a Supertext API key, or the Supertext stand-in
// (tests/e2e/stand-in/supertext-stand-in.php).
const { test, expect } = require( '@playwright/test' );

test.describe( 'Supertext admin pages', () => {
	test( 'Settings page shows the status, the AI and the professional settings', async ( { page } ) => {
		await page.goto( 'wp-admin/admin.php?page=supertext-wpml' );
		await expect( page.getByRole( 'heading', { name: 'Supertext for WPML' } ) ).toBeVisible();
		await expect( page.getByText( 'WPML Multilingual CMS', { exact: true } ) ).toBeVisible();
		await expect( page.locator( '#supertext-ai-api-key' ) ).toBeVisible();
		await expect( page.locator( 'table.supertext-language-mapping' ) ).toBeVisible();
		await expect( page.locator( '#supertext-email' ) ).toBeVisible();
	} );

	test( 'Orders, Translation files and Debug pages render', async ( { page } ) => {
		await page.goto( 'wp-admin/admin.php?page=supertext-wpml-orders' );
		await expect( page.locator( '.wrap' ) ).toBeVisible();
		await page.goto( 'wp-admin/admin.php?page=supertext-wpml-files' );
		await expect( page.getByRole( 'heading', { name: 'Translation files' } ) ).toBeVisible();
		await page.goto( 'wp-admin/admin.php?page=supertext-wpml-debug' );
		await expect( page.getByRole( 'heading', { name: 'Order callbacks', exact: false } ) ).toBeVisible();
	} );

	test( 'Posts list offers the Supertext bulk actions', async ( { page } ) => {
		await page.goto( 'wp-admin/edit.php?post_type=page' );
		await page.selectOption( '#bulk-action-selector-top', 'supertext_ai_translation' );
		await expect( page.locator( '#supertext_target_lang' ) ).toBeVisible();
	} );
} );

test.describe( 'AI translation from the editor', () => {
	const TITLE = 'Welcome to the Supertext demo';

	test( '"' + TITLE + '" translates into the first other language', async ( { page } ) => {
		test.setTimeout( 300000 );
		await page.goto( 'wp-admin/edit.php?post_type=page&s=' + encodeURIComponent( TITLE ) );
		const link = page.locator( '#the-list a.row-title', { hasText: TITLE } ).first();
		test.skip( 0 === ( await link.count() ), 'Page "' + TITLE + '" not found on this site.' );
		await link.click();

		const box = page.locator( '#supertext-wpml-translate' );
		await expect( box ).toBeAttached( { timeout: 60000 } );
		// The block editor's welcome guide would cover the box: close it.
		const guide = page.locator( '.components-modal__frame' );
		if ( await guide.first().waitFor( { state: 'visible', timeout: 8000 } ).then( () => true ).catch( () => false ) ) {
			await page.keyboard.press( 'Escape' );
			await guide.first().waitFor( { state: 'hidden' } ).catch( () => {} );
		}

		page.on( 'dialog', ( dialog ) => dialog.accept() );
		const button = box.locator( 'a.button' ).first();
		await button.click();

		await expect( page.locator( '#supertext-wpml-translate .notice-success' ) ).toBeAttached( { timeout: 240000 } );
	} );
} );
