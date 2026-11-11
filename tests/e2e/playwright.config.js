// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );
require( 'dotenv' ).config( { path: __dirname + '/.env.local' } );

const RAW      = process.env.SITE_URL || 'http://localhost';
// Ensure a trailing slash so relative paths (no leading slash) keep any subdirectory.
const SITE_URL = RAW.endsWith( '/' ) ? RAW : RAW + '/';

module.exports = defineConfig( {
	testDir: __dirname,
	timeout: 120000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL: SITE_URL,
		screenshot: 'off', // We take explicit, animation-safe screenshots in the tests.
		trace: 'retain-on-failure',
		video: 'off',
		ignoreHTTPSErrors: true,
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.js/, use: { channel: process.env.PW_CHANNEL || undefined } },
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ], channel: process.env.PW_CHANNEL || undefined, storageState: __dirname + '/.auth/state.json' },
			dependencies: [ 'setup' ],
		},
	],
} );
