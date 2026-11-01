/**
 * Editor bridge: keeps Yoast / Rank Math editor stores in sync with values DEV3 applied on the server
 * (so a later "Update" saves the same values), and inserts links, outlines and FAQ blocks.
 */
import { createBlock, getBlockType, parse } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { htmlText, linkInHtml } from '../shared/seo';

const ARTICLE_TYPES = [ 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report' ];

const call = ( obj, name, ...args ) => {
	if ( obj && typeof obj[ name ] === 'function' ) {
		obj[ name ]( ...args );
		return true;
	}
	return false;
};

/**
 * Pushes an applied value into the SEO plugin's editor store.
 *
 * @param {Object} adapter Adapter description ({ id, store }).
 * @param {string} field   Field.
 * @param {string} value   Value.
 * @return {boolean} Whether a store was updated.
 */
export function syncField( adapter, field, value ) {
	const store = adapter && adapter.store;
	if ( ! store ) {
		return false;
	}
	let d;
	try {
		if ( ! select( store ) ) {
			return false;
		}
		d = dispatch( store );
	} catch ( e ) {
		return false;
	}
	const v = value || '';
	if ( store === 'yoast-seo/editor' ) {
		switch ( field ) {
			case 'seo_title':
				return call( d, 'updateData', { title: v } );
			case 'meta_description':
				return call( d, 'updateData', { description: v } );
			case 'focus_keyword':
				return call( d, 'setFocusKeyword', v );
			case 'og_title':
				return call( d, 'setFacebookPreviewTitle', v );
			case 'og_description':
				return call( d, 'setFacebookPreviewDescription', v );
			case 'schema_type':
				if ( ARTICLE_TYPES.includes( v ) ) {
					call( d, 'setPageType', '' );
					return call( d, 'setArticleType', v );
				}
				return call( d, 'setPageType', v );
		}
		return false;
	}
	if ( store === 'rank-math' ) {
		switch ( field ) {
			case 'seo_title':
				call( d, 'updateSerpTitle', v );
				return call( d, 'updateTitle', v );
			case 'meta_description':
				call( d, 'updateSerpDescription', v );
				return call( d, 'updateDescription', v );
			case 'focus_keyword':
				return call( d, 'updateKeywords', v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ).join( ',' ) );
			case 'og_title':
				return call( d, 'updateFacebookTitle', v );
			case 'og_description':
				return call( d, 'updateFacebookDescription', v );
		}
	}
	return false;
}

/**
 * HTML of a block's content attribute (string or RichTextData).
 *
 * @param {*} content Content.
 * @return {string} HTML.
 */
export function contentHtml( content ) {
	if ( typeof content === 'string' ) {
		return content;
	}
	if ( content && typeof content.toHTMLString === 'function' ) {
		return content.toHTMLString();
	}
	return content ? String( content ) : '';
}

/**
 * Flat list of blocks (inner blocks included).
 *
 * @param {Array} blocks Blocks.
 * @return {Array} Blocks.
 */
export function flatten( blocks ) {
	return ( blocks || [] ).reduce( ( acc, b ) => acc.concat( [ b ], flatten( b.innerBlocks ) ), [] );
}

const norm = ( s ) => String( s || '' ).replace( /\s+/g, ' ' ).trim().toLowerCase();

/**
 * Finds the paragraph block of a suggestion (same text, or containing the anchor + the paragraph opening).
 *
 * @param {Array}  blocks     Blocks.
 * @param {Object} suggestion { paragraph_text, anchor }.
 * @return {Object|null} Block.
 */
export function findParagraph( blocks, suggestion ) {
	const paras = flatten( blocks ).filter( ( b ) => b.name === 'core/paragraph' );
	const target = norm( suggestion.paragraph_text );
	const exact = paras.find( ( b ) => norm( htmlText( contentHtml( b.attributes.content ) ) ) === target );
	if ( exact ) {
		return exact;
	}
	const opening = target.slice( 0, 40 );
	return paras.find( ( b ) => {
		const text = norm( htmlText( contentHtml( b.attributes.content ) ) );
		return text.includes( norm( suggestion.anchor ) ) && ( ! opening || text.startsWith( opening ) );
	} ) || null;
}

/**
 * Inserts an internal link into its paragraph block.
 *
 * @param {Object} suggestion Suggestion.
 * @return {boolean} Inserted.
 */
export function insertLink( suggestion ) {
	const blocks = select( 'core/block-editor' ).getBlocks();
	const block = findParagraph( blocks, suggestion );
	if ( ! block ) {
		return false;
	}
	const next = linkInHtml( contentHtml( block.attributes.content ), suggestion.anchor, suggestion.url );
	if ( ! next ) {
		return false;
	}
	dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { content: next } );
	return true;
}

const esc = ( s ) => String( s || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

/**
 * Appends outline sections (heading + paragraph with the note as placeholder).
 *
 * @param {Array} items [ { level, heading, notes } ].
 * @return {number} Sections inserted.
 */
export function insertOutline( items ) {
	const blocks = [];
	items.forEach( ( o ) => {
		blocks.push( createBlock( 'core/heading', { level: o.level === 3 ? 3 : 2, content: esc( o.heading ) } ) );
		blocks.push( createBlock( 'core/paragraph', { placeholder: o.notes || __( 'Write this section…', 'dev3-ai-seo-assistant' ) } ) );
	} );
	if ( blocks.length ) {
		dispatch( 'core/block-editor' ).insertBlocks( blocks );
	}
	return items.length;
}

/**
 * Builds FAQ blocks for the active SEO plugin (falls back to core details blocks).
 *
 * @param {Array}  items [ { question, answer } ].
 * @param {string} name  Block name.
 * @return {Array} Blocks.
 */
export function faqBlocks( items, name ) {
	const stamp = Date.now().toString( 36 );
	const heading = createBlock( 'core/heading', { level: 2, content: esc( __( 'Frequently asked questions', 'dev3-ai-seo-assistant' ) ) } );
	if ( name === 'yoast/faq-block' && getBlockType( name ) ) {
		return [
			heading,
			createBlock( name, {
				questions: items.map( ( it, i ) => ( { id: `faq-question-${ stamp }${ i }`, question: esc( it.question ), answer: esc( it.answer ), jsonQuestion: esc( it.question ), jsonAnswer: esc( it.answer ) } ) ),
			} ),
		];
	}
	if ( name === 'rank-math/faq-block' && getBlockType( name ) ) {
		return [ heading, createBlock( name, { questions: items.map( ( it, i ) => ( { id: `faq-question-${ stamp }${ i }`, title: esc( it.question ), content: esc( it.answer ), visible: true } ) ) } ) ];
	}
	if ( getBlockType( 'core/details' ) ) {
		return [ heading, ...items.map( ( it ) => createBlock( 'core/details', { summary: esc( it.question ), className: 'dev3se-faq' }, [ createBlock( 'core/paragraph', { content: esc( it.answer ) } ) ] ) ) ];
	}
	return [
		createBlock(
			'core/group',
			{ className: 'dev3se-faq' },
			[ heading ].concat( ...items.map( ( it ) => [ createBlock( 'core/heading', { level: 3, content: esc( it.question ) } ), createBlock( 'core/paragraph', { content: esc( it.answer ) } ) ] ) )
		),
	];
}

/**
 * Appends the FAQ.
 *
 * @param {Array}  items Items.
 * @param {string} name  Block name.
 */
export function insertFaq( items, name ) {
	dispatch( 'core/block-editor' ).insertBlocks( faqBlocks( items, name ) );
}

/**
 * Replaces the editor content (after a content undo on the server), so a later save keeps it.
 *
 * @param {string} html Content.
 */
export function replaceContent( html ) {
	const editor = dispatch( 'core/editor' );
	if ( editor && typeof editor.resetEditorBlocks === 'function' ) {
		editor.resetEditorBlocks( parse( html || '' ) );
	}
}
