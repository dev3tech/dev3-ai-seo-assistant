/**
 * Editor bridge with mocked wp.data / wp.blocks stores (no editor needed).
 */
const dispatched = {};
const stores = {};

jest.mock(
	'@wordpress/data',
	() => ( {
		select: ( name ) => stores[ name ],
		dispatch: ( name ) => dispatched[ name ],
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/blocks',
	() => ( {
		createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks } ),
		getBlockType: ( name ) => ( [ 'yoast/faq-block', 'core/details' ].includes( name ) ? { name } : undefined ),
		parse: ( html ) => [ { name: 'core/freeform', attributes: { content: html } } ],
	} ),
	{ virtual: true }
);

const { faqBlocks, findParagraph, insertLink, insertOutline, syncField } = require( '../../src/editor/bridge' );

beforeEach( () => {
	Object.keys( dispatched ).forEach( ( k ) => delete dispatched[ k ] );
	Object.keys( stores ).forEach( ( k ) => delete stores[ k ] );
} );

describe( 'SEO plugin store sync', () => {
	it( 'updates Yoast fields', () => {
		stores[ 'yoast-seo/editor' ] = {};
		dispatched[ 'yoast-seo/editor' ] = { updateData: jest.fn(), setFocusKeyword: jest.fn(), setArticleType: jest.fn(), setPageType: jest.fn() };
		const adapter = { id: 'yoast', store: 'yoast-seo/editor' };
		expect( syncField( adapter, 'seo_title', 'New title' ) ).toBe( true );
		expect( dispatched[ 'yoast-seo/editor' ].updateData ).toHaveBeenCalledWith( { title: 'New title' } );
		syncField( adapter, 'meta_description', 'Desc' );
		expect( dispatched[ 'yoast-seo/editor' ].updateData ).toHaveBeenCalledWith( { description: 'Desc' } );
		syncField( adapter, 'focus_keyword', 'espresso' );
		expect( dispatched[ 'yoast-seo/editor' ].setFocusKeyword ).toHaveBeenCalledWith( 'espresso' );
		syncField( adapter, 'schema_type', 'BlogPosting' );
		expect( dispatched[ 'yoast-seo/editor' ].setArticleType ).toHaveBeenCalledWith( 'BlogPosting' );
		syncField( adapter, 'schema_type', 'FAQPage' );
		expect( dispatched[ 'yoast-seo/editor' ].setPageType ).toHaveBeenLastCalledWith( 'FAQPage' );
	} );

	it( 'updates Rank Math fields (title + SERP title, keyword list)', () => {
		stores[ 'rank-math' ] = {};
		const d = { updateTitle: jest.fn(), updateSerpTitle: jest.fn(), updateDescription: jest.fn(), updateSerpDescription: jest.fn(), updateKeywords: jest.fn() };
		dispatched[ 'rank-math' ] = d;
		const adapter = { id: 'rankmath', store: 'rank-math' };
		syncField( adapter, 'seo_title', 'T' );
		expect( d.updateTitle ).toHaveBeenCalledWith( 'T' );
		expect( d.updateSerpTitle ).toHaveBeenCalledWith( 'T' );
		syncField( adapter, 'focus_keyword', 'espresso, home espresso , ' );
		expect( d.updateKeywords ).toHaveBeenCalledWith( 'espresso,home espresso' );
	} );

	it( 'does nothing when the store is missing (native adapter or plugin not loaded)', () => {
		expect( syncField( { id: 'native', store: '' }, 'seo_title', 'x' ) ).toBe( false );
		expect( syncField( { id: 'yoast', store: 'yoast-seo/editor' }, 'seo_title', 'x' ) ).toBe( false );
	} );
} );

describe( 'content insertion', () => {
	const blocks = [
		{ clientId: 'a', name: 'core/heading', attributes: { content: 'Intro' }, innerBlocks: [] },
		{ clientId: 'g', name: 'core/group', attributes: {}, innerBlocks: [ { clientId: 'b', name: 'core/paragraph', attributes: { content: 'Choose good espresso grinders first.' }, innerBlocks: [] } ] },
	];

	it( 'finds the paragraph inside groups and inserts the link', () => {
		stores[ 'core/block-editor' ] = { getBlocks: () => blocks };
		dispatched[ 'core/block-editor' ] = { updateBlockAttributes: jest.fn() };
		const s = { paragraph_text: 'Choose good espresso grinders first.', anchor: 'espresso grinders', url: '/g/' };
		expect( findParagraph( blocks, s ).clientId ).toBe( 'b' );
		expect( insertLink( s ) ).toBe( true );
		expect( dispatched[ 'core/block-editor' ].updateBlockAttributes ).toHaveBeenCalledWith( 'b', { content: 'Choose good <a href="/g/">espresso grinders</a> first.' } );
		expect( insertLink( { ...s, paragraph_text: 'missing', anchor: 'nothing here' } ) ).toBe( false );
	} );

	it( 'appends outline headings with notes as placeholders', () => {
		dispatched[ 'core/block-editor' ] = { insertBlocks: jest.fn() };
		expect( insertOutline( [ { level: 2, heading: 'Milk & foam', notes: 'Explain steaming' }, { level: 3, heading: 'Latte art', notes: '' } ] ) ).toBe( 2 );
		const inserted = dispatched[ 'core/block-editor' ].insertBlocks.mock.calls[ 0 ][ 0 ];
		expect( inserted ).toHaveLength( 4 );
		expect( inserted[ 0 ].attributes ).toEqual( { level: 2, content: 'Milk &amp; foam' } );
		expect( inserted[ 1 ].attributes.placeholder ).toBe( 'Explain steaming' );
		expect( inserted[ 2 ].attributes.level ).toBe( 3 );
	} );

	it( 'builds the Yoast FAQ block, and core details when the plugin block is missing', () => {
		const items = [ { question: 'Is <it> hard?', answer: 'No.' } ];
		const yoast = faqBlocks( items, 'yoast/faq-block' );
		expect( yoast[ 1 ].name ).toBe( 'yoast/faq-block' );
		expect( yoast[ 1 ].attributes.questions[ 0 ] ).toMatchObject( { question: 'Is &lt;it&gt; hard?', jsonAnswer: 'No.' } );
		const rm = faqBlocks( items, 'rank-math/faq-block' );
		expect( rm[ 1 ].name ).toBe( 'core/details' );
		expect( rm[ 1 ].innerBlocks[ 0 ].attributes.content ).toBe( 'No.' );
	} );
} );
