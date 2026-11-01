/**
 * Pure helpers shared by the admin app and the editor sidebar (unit-tested).
 */
import { __ } from '@wordpress/i18n';
import config from '../core3/config';
import { diffWords } from '../core3/ui';

export const META_FIELDS = [ 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description' ];
export const SCHEMA_FIELDS = [ 'schema_type', 'schema_jsonld' ];

export const FIELD_LABELS = {
	seo_title: __( 'SEO title', 'dev3-ai-seo-assistant' ),
	meta_description: __( 'Meta description', 'dev3-ai-seo-assistant' ),
	focus_keyword: __( 'Focus keyword', 'dev3-ai-seo-assistant' ),
	og_title: __( 'Social title', 'dev3-ai-seo-assistant' ),
	og_description: __( 'Social description', 'dev3-ai-seo-assistant' ),
	schema_type: __( 'Schema type', 'dev3-ai-seo-assistant' ),
	schema_jsonld: __( 'Schema (JSON-LD)', 'dev3-ai-seo-assistant' ),
	content: __( 'Post content', 'dev3-ai-seo-assistant' ),
};

export const fieldLabel = ( key ) => FIELD_LABELS[ key ] || key;

/**
 * Limits from boot config.
 *
 * @return {Object} { title, descMin, descMax }.
 */
export function limits() {
	return { title: 60, descMin: 120, descMax: 155, ...( config.limits || {} ) };
}

// Approximate Arial widths (px at 1em = 1000 units / 1000) for Google's snippet font.
const NARROW = 'fijlrtI.,;:!|\'`';
const WIDE = 'mwMW@%';
const CAPS = /[A-Z0-9]/;

/**
 * Estimated rendered width in px (Arial-like font).
 *
 * @param {string} text Text.
 * @param {number} size Font size in px.
 * @return {number} Width.
 */
export function pixelWidth( text, size = 20 ) {
	let units = 0;
	for ( const ch of String( text || '' ) ) {
		if ( ch === ' ' ) {
			units += 0.28;
		} else if ( NARROW.includes( ch ) ) {
			units += 0.27;
		} else if ( WIDE.includes( ch ) ) {
			units += 0.86;
		} else if ( CAPS.test( ch ) ) {
			units += 0.66;
		} else {
			units += 0.53;
		}
	}
	return Math.round( units * size );
}

/**
 * Truncates text to a pixel budget with an ellipsis, as search results do.
 *
 * @param {string} text  Text.
 * @param {number} max   Max px.
 * @param {number} size  Font size.
 * @return {string} Display text.
 */
export function truncatePx( text, max, size = 20 ) {
	const value = String( text || '' );
	if ( pixelWidth( value, size ) <= max ) {
		return value;
	}
	let out = '';
	for ( const ch of value ) {
		if ( pixelWidth( out + ch + '…', size ) > max ) {
			break;
		}
		out += ch;
	}
	const space = out.lastIndexOf( ' ' );
	return ( space > out.length * 0.6 ? out.slice( 0, space ) : out ).replace( /[\s,;:\-–—]+$/, '' ) + ' …';
}

/**
 * Length status of a value.
 *
 * @param {number} len Length.
 * @param {number} min Min (0 = none).
 * @param {number} max Max.
 * @return {string} good | warn | bad | empty.
 */
export function lengthTone( len, min, max ) {
	if ( ! len ) {
		return 'empty';
	}
	if ( len > max ) {
		return 'bad';
	}
	if ( min && len < min ) {
		return 'warn';
	}
	return 'good';
}

/**
 * Word diff with long unchanged stretches collapsed to "…" (for whole-post content drafts).
 *
 * @param {string} before  Before.
 * @param {string} after   After.
 * @param {number} context Characters of context kept around each change.
 * @return {Array} Parts.
 */
export function contextDiff( before, after, context = 80 ) {
	const parts = diffWords( stripComments( before ), stripComments( after ) );
	return parts.map( ( p, i ) => {
		if ( p.type !== 'same' || p.value.length <= context * 2 + 10 ) {
			return p;
		}
		const first = i === 0;
		const last = i === parts.length - 1;
		const head = first ? '' : p.value.slice( 0, context );
		const tail = last ? '' : p.value.slice( -context );
		return { type: 'same', value: `${ head }${ head || tail ? ' … ' : '…' }${ tail }` };
	} );
}

/**
 * Drops block comment delimiters (they are noise in a reader-facing diff).
 *
 * @param {string} html Content.
 * @return {string} Content.
 */
export function stripComments( html ) {
	return String( html || '' )
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /\n{3,}/g, '\n\n' );
}

const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

/**
 * Wraps the first linkable occurrence of an anchor in HTML (never inside a tag or an existing link).
 * Mirrors Links\Linker::link_in() on the server.
 *
 * @param {string} html   Paragraph HTML.
 * @param {string} anchor Anchor text.
 * @param {string} url    URL.
 * @return {string|null} New HTML or null.
 */
export function linkInHtml( html, anchor, url ) {
	const needle = String( anchor || '' ).trim();
	if ( ! needle ) {
		return null;
	}
	const escaped = needle.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	const needles = Array.from( new Set( [ needle, escaped ] ) );
	const parts = String( html || '' ).split( /(<[^>]+>)/ );
	let inLink = 0;
	for ( let i = 0; i < parts.length; i++ ) {
		const part = parts[ i ];
		if ( ! part ) {
			continue;
		}
		if ( part[ 0 ] === '<' ) {
			if ( /^<a\b/i.test( part ) ) {
				inLink++;
			} else if ( /^<\/a>/i.test( part ) ) {
				inLink = Math.max( 0, inLink - 1 );
			}
			continue;
		}
		if ( inLink ) {
			continue;
		}
		for ( const n of needles ) {
			for ( const flags of [ 'u', 'iu' ] ) {
				const re = new RegExp( `(^|[^\\p{L}\\p{N}])(${ escapeRe( n ) })(?![\\p{L}\\p{N}])`, flags );
				const m = re.exec( part );
				if ( m ) {
					const start = m.index + m[ 1 ].length;
					const safeUrl = String( url ).replace( /"/g, '&quot;' );
					parts[ i ] = `${ part.slice( 0, start ) }<a href="${ safeUrl }">${ m[ 2 ] }</a>${ part.slice( start + m[ 2 ].length ) }`;
					return parts.join( '' );
				}
			}
		}
	}
	return null;
}

/**
 * Plain text of HTML.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function htmlText( html ) {
	const el = document.createElement( 'div' );
	el.innerHTML = String( html || '' );
	return ( el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * Score tone.
 *
 * @param {number} score Score.
 * @return {string} success | warning | danger.
 */
export function scoreTone( score ) {
	if ( score >= 80 ) {
		return 'success';
	}
	return score >= 50 ? 'warning' : 'danger';
}

/**
 * Badge tone of a check status.
 *
 * @param {string} status good | warn | bad.
 * @return {string} Tone.
 */
export function checkTone( status ) {
	return { good: 'success', warn: 'warning', bad: 'danger' }[ status ] || 'neutral';
}

/**
 * Whether an error is a 409 conflict.
 *
 * @param {Object} err Error envelope.
 * @return {boolean} Conflict.
 */
export const isConflict = ( err ) => !! err && err.code === 'dev3se_conflict';

/**
 * Human label of a draft status.
 *
 * @param {string} status Status.
 * @return {string} Label.
 */
export function statusLabel( status ) {
	return (
		{
			pending: __( 'Pending', 'dev3-ai-seo-assistant' ),
			applied: __( 'Applied', 'dev3-ai-seo-assistant' ),
			rejected: __( 'Rejected', 'dev3-ai-seo-assistant' ),
			superseded: __( 'Replaced', 'dev3-ai-seo-assistant' ),
		}[ status ] || status
	);
}

export const statusTone = ( status ) => ( { pending: 'warning', applied: 'success', rejected: 'neutral', superseded: 'neutral' }[ status ] || 'neutral' );

/**
 * Formats an ISO date for lists.
 *
 * @param {string} iso ISO date.
 * @return {string} Local date/time.
 */
export function when( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? '' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}
