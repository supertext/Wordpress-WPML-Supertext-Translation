// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const STATE = path.join( __dirname, '.auth', 'state.json' );

/**
 * Logs into wp-admin once and saves the session for the other tests.
 */
test( 'authenticate', async ( { page } ) => {
	const user = process.env.WP_USER || '';
	const pass = process.env.WP_PASS || '';

	await page.goto( 'wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );

	// Wait until we're in wp-admin.
	await page.waitForURL( /wp-admin/, { timeout: 30000 } );
	await expect( page.locator( '#wpadminbar, #adminmenu' ).first() ).toBeVisible();

	fs.mkdirSync( path.dirname( STATE ), { recursive: true } );
	await page.context().storageState( { path: STATE } );
} );
