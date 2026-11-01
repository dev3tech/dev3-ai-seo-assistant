import { contextDiff, htmlText, lengthTone, linkInHtml, pixelWidth, scoreTone, stripComments, truncatePx } from '../../src/shared/seo';
import { parseParams, qs } from '../../src/admin/util';

describe( 'snippet helpers', () => {
	it( 'estimates pixel widths (wide letters cost more than narrow ones)', () => {
		expect( pixelWidth( 'WWWW' ) ).toBeGreaterThan( pixelWidth( 'iiii' ) );
		expect( pixelWidth( '' ) ).toBe( 0 );
		expect( pixelWidth( 'abc', 20 ) ).toBe( 2 * pixelWidth( 'abc', 10 ) );
	} );

	it( 'truncates long titles at a word with an ellipsis', () => {
		const long = 'The complete beginner guide to brewing great espresso at home without expensive gear';
		const out = truncatePx( long, 300, 20 );
		expect( out.endsWith( ' …' ) ).toBe( true );
		expect( pixelWidth( out, 20 ) ).toBeLessThanOrEqual( 320 );
		expect( truncatePx( 'Short', 580 ) ).toBe( 'Short' );
	} );

	it( 'rates lengths', () => {
		expect( lengthTone( 0, 120, 155 ) ).toBe( 'empty' );
		expect( lengthTone( 100, 120, 155 ) ).toBe( 'warn' );
		expect( lengthTone( 140, 120, 155 ) ).toBe( 'good' );
		expect( lengthTone( 170, 120, 155 ) ).toBe( 'bad' );
		expect( scoreTone( 85 ) ).toBe( 'success' );
		expect( scoreTone( 60 ) ).toBe( 'warning' );
		expect( scoreTone( 10 ) ).toBe( 'danger' );
	} );
} );

describe( 'link insertion (mirrors Links\\Linker)', () => {
	it( 'links the first whole-word occurrence outside existing links', () => {
		const html = 'Read about <a href="/x">espresso grinders</a> and how espresso grinders differ.';
		const out = linkInHtml( html, 'espresso grinders', 'https://site.test/grinders/' );
		expect( out ).toBe( 'Read about <a href="/x">espresso grinders</a> and how <a href="https://site.test/grinders/">espresso grinders</a> differ.' );
	} );

	it( 'is case-insensitive as a fallback and keeps the original case', () => {
		expect( linkInHtml( 'Milk Frothing basics', 'milk frothing', '/m/' ) ).toBe( '<a href="/m/">Milk Frothing</a> basics' );
	} );

	it( 'never matches inside words, tags or attributes', () => {
		expect( linkInHtml( 'Decaffeinated coffee', 'caffeinated', '/c/' ) ).toBeNull();
		expect( linkInHtml( '<img alt="coffee beans"> nothing else', 'coffee beans', '/b/' ) ).toBeNull();
		expect( linkInHtml( 'x', '', '/b/' ) ).toBeNull();
	} );

	it( 'handles escaped ampersands', () => {
		expect( linkInHtml( 'Tips for milk &amp; foam today', 'milk & foam', '/f/' ) ).toBe( 'Tips for <a href="/f/">milk &amp; foam</a> today' );
	} );
} );

describe( 'content diff', () => {
	it( 'collapses long unchanged text and drops block comments', () => {
		const same = 'lorem ipsum dolor sit amet '.repeat( 30 );
		const parts = contextDiff( `<!-- wp:paragraph --><p>${ same }</p><!-- /wp:paragraph -->`, `<!-- wp:paragraph --><p>${ same }</p><!-- /wp:paragraph --><p>New FAQ</p>` );
		const text = parts.map( ( p ) => p.value ).join( '' );
		expect( text ).not.toContain( 'wp:paragraph' );
		expect( text ).toContain( '…' );
		expect( parts.some( ( p ) => p.type === 'add' && p.value.includes( 'FAQ' ) ) ).toBe( true );
		expect( stripComments( 'a<!-- x -->b' ) ).toBe( 'ab' );
		expect( htmlText( '<p>Hello <b>world</b></p>' ) ).toBe( 'Hello world' );
	} );
} );

describe( 'admin url helpers', () => {
	it( 'parses hash params and builds query strings', () => {
		expect( parseParams( [ 'ids=1,2', 'issue=no_title', 'junk' ] ) ).toEqual( { ids: '1,2', issue: 'no_title' } );
		expect( qs( { a: 1, b: '', c: 'x y', d: false } ) ).toBe( 'a=1&c=x%20y' );
	} );
} );
