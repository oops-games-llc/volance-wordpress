/**
 * Tests for assets/js/volance-detection.js using a minimal fake DOM.
 * Run: node tests/loader.test.mjs
 *
 * A data: URL stands in for the Volance collector, so the test proves that the
 * collector is not even imported until the visitor allows it.
 */
import { pathToFileURL } from 'node:url';
import { resolve, join } from 'node:path';
import { tmpdir } from 'node:os';
import { copyFileSync, unlinkSync } from 'node:fs';
import assert from 'node:assert/strict';

let counter = 0;
let passed = 0;

function makeElement( tag ) {
	return {
		tag,
		children: [],
		attrs: {},
		handlers: {},
		textContent: '',
		className: '',
		parentNode: null,
		setAttribute( name, value ) { this.attrs[ name ] = value; },
		appendChild( child ) { child.parentNode = this; this.children.push( child ); return child; },
		removeChild( child ) { this.children = this.children.filter( ( c ) => c !== child ); child.parentNode = null; },
		addEventListener( type, fn ) { this.handlers[ type ] = fn; },
	};
}

function find( node, predicate ) {
	if ( predicate( node ) ) { return node; }
	for ( const child of node.children || [] ) {
		const hit = find( child, predicate );
		if ( hit ) { return hit; }
	}
	return null;
}

async function boot( { consentMode = 'banner', stored = null, gpc = false } ) {
	const events = [];
	const store = new Map();
	if ( stored ) { store.set( 'k', stored ); }
	globalThis.__collector = { imports: 0, started: null, stopped: 0, snapshots: 0 };

	const field = { value: '' };
	const form = {
		querySelector( selector ) { return selector.includes( 'volance_snapshot' ) ? field : null; },
	};
	const body = makeElement( 'body' );
	const documentStub = {
		body,
		createElement: makeElement,
		createTextNode: ( text ) => ( { text } ),
		addEventListener( type, fn ) { events.push( { type, fn } ); },
	};
	globalThis.document = documentStub;
	globalThis.window = {
		navigator: { globalPrivacyControl: gpc },
		localStorage: {
			getItem: ( key ) => ( store.has( key ) ? store.get( key ) : null ),
			setItem: ( key, value ) => store.set( key, value ),
		},
	};
	const collectorSource = 'export function createSiteCollector(){ globalThis.__collector.imports++; return { start(o){ globalThis.__collector.started = o; }, stop(){ globalThis.__collector.stopped++; }, snapshot(){ globalThis.__collector.snapshots++; return { clientSignals: { webdriver: false }, events: [ { type: "click", t: 5 } ] }; } }; }';
	globalThis.window.volanceDetectionConfig = {
		collector: 'data:text/javascript,' + encodeURIComponent( collectorSource ),
		consentMode,
		field: 'volance_snapshot',
		storageKey: 'k',
		sitePrivacyUrl: 'https://site.example/privacy',
		volanceUrl: 'https://volance.com/privacy',
		strings: { title: 'T', text: 'X', allow: 'Allow', decline: 'Decline', sitePrivacy: 'Site', volancePolicy: 'Volance' },
	};
	const copy = join( tmpdir(), 'volance-loader-' + process.pid + '-' + ( ++counter ) + '.mjs' );
	copyFileSync( resolve( 'assets/js/volance-detection.js' ), copy );
	await import( pathToFileURL( copy ).href );
	unlinkSync( copy );
	return { events, store, body, field, form };
}

const tick = () => new Promise( ( r ) => setTimeout( r, 20 ) );
function ok( label, fn ) { fn(); passed++; }

// 1. Banner mode, no stored choice: banner shown, collector not imported.
{
	const w = await boot( {} );
	const banner = find( w.body, ( n ) => n.className === 'volance-detection-banner' );
	ok( 'banner shown when no choice is stored', () => assert.ok( banner ) );
	ok( 'collector NOT imported before consent', () => assert.equal( globalThis.__collector.imports, 0 ) );
	ok( 'banner text uses textContent only', () => assert.ok( find( banner, ( n ) => n.tag === 'p' && n.textContent === 'X' ) ) );

	// Submit before consent: field stays empty.
	const submit = w.events.find( ( e ) => e.type === 'submit' );
	submit.fn( { target: w.form } );
	ok( 'submit before consent leaves the field empty', () => assert.equal( w.field.value, '' ) );

	// Allow.
	const allow = find( banner, ( n ) => n.tag === 'button' && n.textContent === 'Allow' );
	allow.handlers.click();
	await tick();
	ok( 'collector imported and started with consent:true after Allow', () => {
		assert.equal( globalThis.__collector.imports, 1 );
		assert.deepEqual( globalThis.__collector.started, { consent: true } );
	} );
	ok( 'choice stored', () => assert.equal( w.store.get( 'k' ), 'granted' ) );
	ok( 'banner removed', () => assert.equal( find( w.body, ( n ) => n.className === 'volance-detection-banner' ), null ) );

	submit.fn( { target: w.form } );
	ok( 'submit after consent fills the hidden field with the snapshot', () => {
		const parsed = JSON.parse( w.field.value );
		assert.equal( parsed.events[ 0 ].type, 'click' );
	} );
	submit.fn( { target: { querySelector: () => null } } );
	ok( 'forms without the field are ignored', () => assert.ok( true ) );

	// Withdraw.
	globalThis.window.volanceDetection.setConsent( false );
	ok( 'withdrawing consent stops the collector and stores declined', () => {
		assert.equal( globalThis.__collector.stopped, 1 );
		assert.equal( w.store.get( 'k' ), 'declined' );
	} );
	w.field.value = '';
	submit.fn( { target: w.form } );
	ok( 'after withdrawal the field stays empty', () => assert.equal( w.field.value, '' ) );
}

// 2. Decline: nothing imported.
{
	const w = await boot( {} );
	const banner = find( w.body, ( n ) => n.className === 'volance-detection-banner' );
	find( banner, ( n ) => n.tag === 'button' && n.textContent === 'Decline' ).handlers.click();
	await tick();
	ok( 'Decline never imports the collector', () => assert.equal( globalThis.__collector.imports, 0 ) );
	ok( 'Decline is remembered', () => assert.equal( w.store.get( 'k' ), 'declined' ) );
}

// 3. Stored granted: starts without a banner.
{
	const w = await boot( { stored: 'granted' } );
	await tick();
	ok( 'stored consent starts the collector with no banner', () => {
		assert.equal( globalThis.__collector.imports, 1 );
		assert.equal( find( w.body, ( n ) => n.className === 'volance-detection-banner' ), null );
	} );
}

// 4. Stored declined: nothing at all.
{
	const w = await boot( { stored: 'declined' } );
	await tick();
	ok( 'stored decline: no banner and no import', () => {
		assert.equal( globalThis.__collector.imports, 0 );
		assert.equal( find( w.body, ( n ) => n.className === 'volance-detection-banner' ), null );
	} );
}

// 5. Global Privacy Control: no banner, no import.
{
	const w = await boot( { gpc: true } );
	await tick();
	ok( 'Global Privacy Control: no banner and no import', () => {
		assert.equal( globalThis.__collector.imports, 0 );
		assert.equal( find( w.body, ( n ) => n.className === 'volance-detection-banner' ), null );
	} );
}

// 6. Custom consent mode: no banner; the site's own tool drives it.
{
	const w = await boot( { consentMode: 'custom', stored: 'granted' } );
	await tick();
	ok( 'custom mode ignores stored banner state and shows no banner', () => {
		assert.equal( globalThis.__collector.imports, 0 );
		assert.equal( find( w.body, ( n ) => n.className === 'volance-detection-banner' ), null );
	} );
	const listener = w.events.find( ( e ) => e.type === 'volance-detection:consent' );
	ok( 'custom mode listens for the site consent event', () => assert.equal( typeof listener.fn, 'function' ) );
	listener.fn( { detail: { granted: true } } );
	await tick();
	ok( 'consent event from the site starts the collector', () => assert.equal( globalThis.__collector.imports, 1 ) );
	listener.fn( { detail: { granted: false } } );
	ok( 'withdrawal event stops it', () => assert.equal( globalThis.__collector.stopped, 1 ) );
}

console.log( passed + ' passed' );
