import { __ } from '@wordpress/i18n';

/**
 * Hash params like ["ids=1,2", "issue=no_title"] → { ids: '1,2', issue: 'no_title' }.
 *
 * @param {string[]} params Params.
 * @return {Object} Map.
 */
export function parseParams( params = [] ) {
	const out = {};
	params.forEach( ( p ) => {
		const i = String( p ).indexOf( '=' );
		if ( i > 0 ) {
			out[ decodeURIComponent( p.slice( 0, i ) ) ] = decodeURIComponent( p.slice( i + 1 ) );
		}
	} );
	return out;
}

/**
 * Query string from an object (empty values skipped).
 *
 * @param {Object} obj Values.
 * @return {string} Query.
 */
export function qs( obj ) {
	return Object.entries( obj )
		.filter( ( [ , v ] ) => v !== '' && v !== undefined && v !== null && v !== false )
		.map( ( [ k, v ] ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( v ) }` )
		.join( '&' );
}

/**
 * Issue filter options.
 *
 * @param {Object} status Status (issues map).
 * @return {Array} Options.
 */
export function issueOptions( status ) {
	const issues = ( status && status.issues ) || {};
	return [ { value: '', label: __( 'Any issue', 'dev3-ai-seo-assistant' ) }, ...Object.entries( issues ).map( ( [ value, label ] ) => ( { value, label } ) ) ];
}

/**
 * Post type options.
 *
 * @param {Object}  status Status.
 * @param {boolean} all    Include "All".
 * @return {Array} Options.
 */
export function typeOptions( status, all = true ) {
	const types = ( status && status.postTypes ) || [];
	return [ ...( all ? [ { value: '', label: __( 'All types', 'dev3-ai-seo-assistant' ) } ] : [] ), ...types ];
}

export const STATUS_OPTIONS = [
	{ value: '', label: __( 'Any status', 'dev3-ai-seo-assistant' ) },
	{ value: 'publish', label: __( 'Published', 'dev3-ai-seo-assistant' ) },
	{ value: 'draft', label: __( 'Draft', 'dev3-ai-seo-assistant' ) },
	{ value: 'pending', label: __( 'Pending review', 'dev3-ai-seo-assistant' ) },
	{ value: 'future', label: __( 'Scheduled', 'dev3-ai-seo-assistant' ) },
	{ value: 'private', label: __( 'Private', 'dev3-ai-seo-assistant' ) },
];

/**
 * Admin URL of a screen in this app.
 *
 * @param {string} path Hash path.
 * @return {string} Href.
 */
export const appHref = ( path ) => `#/${ path }`;
