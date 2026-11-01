import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import BulkTab from '../../src/admin/BulkTab';
import { SerpPreview } from '../../src/shared/components';
import DraftItem from '../../src/shared/DraftItem';
import HistoryList from '../../src/shared/HistoryList';
import { FaqPanel, LinksPanel, MetaPanel } from '../../src/shared/panels';
import { change, click, render } from './render';

const STATUS = {
	keySet: true,
	ai: { enabled: true, cap_reached: false },
	adapter: { id: 'yoast', label: 'Yoast SEO', fields: [ 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'schema_type' ], faqBlock: 'yoast/faq-block' },
	issues: { no_description: 'No meta description' },
	postTypes: [ { value: 'post', label: 'Post' } ],
	counts: { pending: 0 },
};

const SNAPSHOT = {
	post: { id: 7, title: 'Espresso guide', url: 'https://site.test/espresso-guide/', type: 'post', status: 'publish' },
	adapter: STATUS.adapter,
	values: { seo_title: '', meta_description: '', focus_keyword: 'espresso', og_title: '', og_description: '', schema_type: '' },
	defaults: { seo_title: 'Espresso guide - Site' },
	stats: { outbound: 1, inbound: 0, words: 420 },
	checks: [],
	pending: [],
	schemaTypes: [],
};

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const urlOf = ( call ) => call[ 0 ].url;

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

describe( 'DraftItem', () => {
	const draft = { id: 11, post_id: 7, field: 'seo_title', kind: 'meta', original: 'Old title', draft: 'Espresso at Home: The Complete Guide', alternatives: [ 'Home Espresso Guide' ], status: 'pending', source: 'editor', model: 'gemini-2.5-flash', tokens: 120 };

	it( 'applies the chosen alternative and syncs the editor', async () => {
		apiFetch.mockResolvedValueOnce( { value: 'Home Espresso Guide', draft: { ...draft, status: 'applied' } } );
		const onDone = jest.fn();
		const onSynced = jest.fn();
		const view = await render( wrap( <DraftItem draft={ draft } onDone={ onDone } onSynced={ onSynced } /> ) );
		await click( view.getByText( 'Option 2' ) );
		await click( view.getByText( 'Apply' ) );
		await view.flush();
		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url ).toContain( 'drafts/11/apply' );
		expect( call.data.value ).toBe( 'Home Espresso Guide' );
		expect( call.data.force ).toBe( false );
		expect( onSynced ).toHaveBeenCalledWith( 'seo_title', 'Home Espresso Guide' );
		expect( onDone ).toHaveBeenCalledWith( 'applied', expect.anything() );
	} );

	it( 'shows the live value on a conflict and can force', async () => {
		apiFetch
			.mockRejectedValueOnce( { code: 'dev3se_conflict', message: 'changed' } )
			.mockResolvedValueOnce( { ...SNAPSHOT, values: { ...SNAPSHOT.values, seo_title: 'Edited by a human' } } )
			.mockResolvedValueOnce( { value: draft.draft } );
		const onDone = jest.fn();
		const view = await render( wrap( <DraftItem draft={ draft } onDone={ onDone } /> ) );
		await click( view.getByText( 'Apply' ) );
		await view.flush();
		await view.flush();
		expect( view.queryByText( /Edited by a human/ ) ).toBeTruthy();
		await click( view.getByText( 'Apply anyway' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data.force ).toBe( true );
		expect( onDone ).toHaveBeenCalled();
	} );

	it( 'rejects', async () => {
		apiFetch.mockResolvedValueOnce( { ...draft, status: 'rejected' } );
		const onDone = jest.fn();
		const view = await render( wrap( <DraftItem draft={ draft } onDone={ onDone } /> ) );
		await click( view.getByText( 'Reject' ) );
		await view.flush();
		expect( urlOf( apiFetch.mock.calls[ 0 ] ) ).toContain( 'drafts/11/reject' );
		expect( onDone ).toHaveBeenCalledWith( 'rejected', expect.anything() );
	} );

	it( 'renders content drafts as a collapsed diff', async () => {
		const content = { ...draft, id: 12, field: 'content', kind: 'faq', original: '<p>Intro</p>', draft: '<p>Intro</p><p>FAQ answer</p>', note: 'FAQ with 1 question', alternatives: [] };
		const view = await render( wrap( <DraftItem draft={ content } onDone={ () => {} } /> ) );
		expect( view.queryByText( 'FAQ with 1 question' ) ).toBeTruthy();
		expect( view.$( '.dev3se-content-draft ins' ) ).toBeTruthy();
	} );
} );

describe( 'panels', () => {
	it( 'MetaPanel generates drafts for the picked fields', async () => {
		apiFetch.mockResolvedValueOnce( { drafts: [ { id: 1 } ], skipped: [] } );
		const reload = jest.fn();
		const view = await render( wrap( <MetaPanel snapshot={ SNAPSHOT } status={ STATUS } reload={ reload } /> ) );
		expect( view.queryByText( 'Espresso guide - Site' ) ).toBeTruthy();
		await click( view.getByText( 'Generate with DEV3 AI' ) );
		await view.flush();
		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url ).toContain( 'posts/7/meta' );
		expect( call.data.mode ).toBe( 'ai' );
		expect( call.data.fields ).toEqual( [ 'seo_title', 'meta_description', 'focus_keyword' ] );
		expect( reload ).toHaveBeenCalled();
	} );

	it( 'MetaPanel offers basic suggestions when AI is off', async () => {
		apiFetch.mockResolvedValueOnce( { drafts: [], skipped: [ 'focus_keyword' ] } );
		const view = await render( wrap( <MetaPanel snapshot={ SNAPSHOT } status={ { ...STATUS, keySet: false } } reload={ () => {} } /> ) );
		expect( view.$( '.dev3se-ui-btn--ai, button[disabled]' ) ).toBeTruthy();
		await click( view.getByText( 'Basic suggestions' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.mode ).toBe( 'basic' );
		expect( view.queryByText( /Unchanged: Focus keyword/ ) ).toBeTruthy();
	} );

	it( 'LinksPanel inserts links through the editor bridge', async () => {
		apiFetch.mockResolvedValueOnce( { method: 'embeddings', suggestions: [ { target_id: 3, title: 'Grinders', url: '/g/', score: 0.81, paragraph: 0, paragraph_text: 'Pick good espresso grinders.', anchor: 'espresso grinders', context: 'Pick good espresso grinders.', reason: '' } ] } );
		const editor = { insertLink: jest.fn( () => true ), sync: jest.fn() };
		const view = await render( wrap( <LinksPanel snapshot={ SNAPSHOT } status={ STATUS } reload={ () => {} } editor={ editor } /> ) );
		await click( view.getByText( 'Find links with DEV3 AI' ) );
		await view.flush();
		expect( view.$( 'mark.dev3se-anchor' ).textContent ).toBe( 'espresso grinders' );
		await click( view.getByText( 'Insert link' ) );
		expect( editor.insertLink ).toHaveBeenCalledWith( expect.objectContaining( { target_id: 3 } ) );
		expect( view.queryByText( 'Inserted' ) ).toBeTruthy();
	} );

	it( 'LinksPanel (admin) turns picked links into a content draft', async () => {
		apiFetch
			.mockResolvedValueOnce( { method: 'lexical', suggestions: [ { target_id: 3, title: 'Grinders', url: '/g/', score: 0.4, paragraph: 0, paragraph_text: 'x', anchor: 'grinders', context: 'good grinders', reason: '' } ] } )
			.mockResolvedValueOnce( { id: 40, post_id: 7, field: 'content', kind: 'links', original: '<p>good grinders</p>', draft: '<p>good <a href="/g/">grinders</a></p>', note: 'Adds 1 internal link', alternatives: [] } );
		const view = await render( wrap( <LinksPanel snapshot={ SNAPSHOT } status={ STATUS } reload={ () => {} } editor={ null } /> ) );
		await click( view.getByText( 'Basic match' ) );
		await view.flush();
		await click( view.getByText( 'Review 1 link as a draft' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data.links ).toEqual( [ { paragraph: 0, anchor: 'grinders', url: '/g/', target_id: 3 } ] );
		expect( view.queryByText( 'Adds 1 internal link' ) ).toBeTruthy();
	} );

	it( 'FaqPanel inserts the SEO plugin FAQ block and stores the FAQ', async () => {
		apiFetch.mockResolvedValueOnce( { items: [ { question: 'Is it hard?', answer: 'No.' } ], model: 'gemini-2.5-flash', tokens: 50 } ).mockResolvedValueOnce( { items: [] } );
		const editor = { insertFaq: jest.fn() };
		const view = await render( wrap( <FaqPanel snapshot={ SNAPSHOT } status={ STATUS } reload={ () => {} } editor={ editor } /> ) );
		await click( view.getByText( 'Write FAQ with DEV3 AI' ) );
		await view.flush();
		await click( view.getByText( 'Insert FAQ block' ) );
		await view.flush();
		expect( editor.insertFaq ).toHaveBeenCalledWith( [ { question: 'Is it hard?', answer: 'No.' } ], 'yoast/faq-block' );
		expect( urlOf( apiFetch.mock.calls[ 1 ] ) ).toContain( 'posts/7/faq/meta' );
	} );
} );

describe( 'history', () => {
	it( 'undoes a change and asks before forcing over newer edits', async () => {
		const row = { id: 5, post_id: 7, field: 'meta_description', before: 'Old', after: 'New text', user: 'admin', created_at: '2026-10-25T10:00:00+00:00', undone_at: null };
		apiFetch
			.mockResolvedValueOnce( { items: [ row ], total: 1 } )
			.mockRejectedValueOnce( { code: 'dev3se_conflict', message: 'changed' } )
			.mockResolvedValueOnce( { ...row, undone_at: '2026-10-25T11:00:00+00:00' } );
		const onUndone = jest.fn();
		const view = await render( wrap( <HistoryList postId={ 7 } onUndone={ onUndone } /> ) );
		await view.flush();
		await click( view.getByText( 'Undo' ) );
		await view.flush();
		await click( view.getByText( 'Undo anyway' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data.force ).toBe( true );
		expect( onUndone ).toHaveBeenCalled();
		expect( view.queryByText( 'Undone' ) ).toBeTruthy();
	} );
} );

describe( 'bulk screen', () => {
	it( 'starts a background run for the selected posts', async () => {
		apiFetch.mockImplementation( ( { url, method } ) => {
			if ( url.includes( 'runs' ) ) {
				return Promise.resolve( { runs: [], active: null } );
			}
			if ( url.includes( '/bulk' ) && method === 'POST' ) {
				return Promise.resolve( { id: 9, status: 'queued', total: 2, done: 0, failed: 0, percent: 0, errors: [] } );
			}
			if ( url.includes( 'jobs/9' ) ) {
				return new Promise( () => {} );
			}
			return Promise.resolve( { items: [ { id: 1, title: 'A', type: 'post', status: 'publish', score: 40, keyword: '', title_len: 30, seo_title: '', desc_len: 0, words: 100, inbound: 0, outbound: 0, issues: [ 'no_description' ] } ], total: 1 } );
		} );
		const view = await render( wrap( <BulkTab status={ STATUS } params={ { ids: '1,2' } } onUsage={ () => {} } refresh={ () => {} } /> ) );
		await view.flush();
		await click( view.getByText( 'Draft 2 posts' ) );
		await view.flush();
		const post = apiFetch.mock.calls.find( ( c ) => c[ 0 ].method === 'POST' )[ 0 ];
		expect( post.data.ids ).toEqual( [ 1, 2 ] );
		expect( post.data.mode ).toBe( 'ai' );
		expect( post.data.only_empty ).toBe( true );
		expect( view.queryByText( 'Drafting SEO fields' ) ).toBeTruthy();
	} );

	it( 'filters by issue from the dashboard link', async () => {
		apiFetch.mockImplementation( () => Promise.resolve( { items: [], total: 0, runs: [] } ) );
		const view = await render( wrap( <BulkTab status={ STATUS } params={ { issue: 'no_description' } } onUsage={ () => {} } refresh={ () => {} } /> ) );
		await view.flush();
		expect( apiFetch.mock.calls.some( ( c ) => c[ 0 ].url.includes( 'issue=no_description' ) ) ).toBe( true );
		const search = view.$( 'input[type="text"]' );
		await change( search, 'espresso' );
		await view.flush();
		expect( apiFetch.mock.calls.some( ( c ) => c[ 0 ].url.includes( 'search=espresso' ) ) ).toBe( true );
	} );
} );

describe( 'SerpPreview', () => {
	it( 'shows breadcrumbs and a missing-description hint', async () => {
		const view = await render( <SerpPreview title="Espresso at Home" description="" url="https://site.test/blog/espresso/" siteName="Bean Blog" /> );
		expect( view.queryByText( 'site.test › blog › espresso' ) ).toBeTruthy();
		expect( view.queryByText( /No meta description/ ) ).toBeTruthy();
		await click( view.getByText( 'Mobile' ) );
		expect( view.$( '.dev3se-serp.is-mobile' ) ).toBeTruthy();
	} );
} );
