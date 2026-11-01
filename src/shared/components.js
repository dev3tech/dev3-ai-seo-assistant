/**
 * Shared presentational components: snippet preview, checks list, length meter.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Tabs, cx } from '../core3/ui';
import { checkTone, lengthTone, limits, pixelWidth, truncatePx } from './seo';

/**
 * Google-style search result preview (desktop/mobile).
 *
 * @param {Object} props             Props.
 * @param {string} props.title       SEO title (effective).
 * @param {string} props.description Meta description.
 * @param {string} props.url         Page URL.
 * @param {string} props.siteName    Site name.
 * @param {boolean} props.isDefault  Title is the SEO plugin default.
 * @return {Element} Preview.
 */
export function SerpPreview( { title, description, url, siteName, isDefault } ) {
	const [ device, setDevice ] = useState( 'desktop' );
	const mobile = device === 'mobile';
	const titleShown = truncatePx( title, mobile ? 520 : 580, 20 );
	const descShown = description ? truncatePx( description, mobile ? 640 : 920, 14 ) : __( 'No meta description: search engines will pick text from the page.', 'dev3-ai-seo-assistant' );
	let crumbs = url || '';
	try {
		const u = new URL( url );
		crumbs = [ u.host, ...u.pathname.split( '/' ).filter( Boolean ) ].join( ' › ' );
	} catch ( e ) {}
	return (
		<div className="dev3se-serp-wrap">
			<Tabs
				variant="pills"
				label={ __( 'Preview device', 'dev3-ai-seo-assistant' ) }
				value={ device }
				onChange={ setDevice }
				tabs={ [
					{ id: 'desktop', label: __( 'Desktop', 'dev3-ai-seo-assistant' ) },
					{ id: 'mobile', label: __( 'Mobile', 'dev3-ai-seo-assistant' ) },
				] }
			/>
			<div className={ cx( 'dev3se-serp', mobile && 'is-mobile' ) } aria-label={ __( 'Search result preview', 'dev3-ai-seo-assistant' ) } role="figure">
				<div className="dev3se-serp__site">
					<span className="dev3se-serp__favicon" aria-hidden="true">{ ( siteName || '?' ).slice( 0, 1 ).toUpperCase() }</span>
					<span>
						<span className="dev3se-serp__name">{ siteName }</span>
						<span className="dev3se-serp__url">{ crumbs }</span>
					</span>
				</div>
				<div className="dev3se-serp__title">{ titleShown }</div>
				<div className={ cx( 'dev3se-serp__desc', ! description && 'is-empty' ) }>{ descShown }</div>
			</div>
			{ isDefault && <p className="dev3se-help">{ __( 'Showing the SEO plugin’s default title (no custom SEO title set).', 'dev3-ai-seo-assistant' ) }</p> }
		</div>
	);
}

/**
 * Character + pixel meter for a field value.
 *
 * @param {Object} props       Props.
 * @param {string} props.field seo_title | meta_description | other.
 * @param {string} props.value Value.
 * @return {Element|null} Meter.
 */
export function LengthMeter( { field, value } ) {
	const l = limits();
	const len = String( value || '' ).length;
	let tone = 'good';
	let px = 0;
	let max = 0;
	if ( field === 'seo_title' ) {
		max = l.title;
		px = pixelWidth( value, 20 );
		tone = px > 580 ? 'bad' : lengthTone( len, 30, l.title );
	} else if ( field === 'meta_description' ) {
		max = l.descMax;
		px = pixelWidth( value, 14 );
		tone = lengthTone( len, l.descMin, l.descMax );
	} else {
		return null;
	}
	const pct = Math.min( 100, Math.round( ( 100 * len ) / Math.max( 1, max ) ) );
	return (
		<div className={ cx( 'dev3se-meter', `is-${ tone }` ) }>
			<div className="dev3se-meter__bar" aria-hidden="true">
				<span style={ { width: `${ pct }%` } } />
			</div>
			<span className="dev3se-meter__text">
				{ sprintf( /* translators: 1: characters, 2: limit, 3: pixels */ __( '%1$d / %2$d chars · %3$d px', 'dev3-ai-seo-assistant' ), len, max, px ) }
			</span>
		</div>
	);
}

/**
 * SEO checks list.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.checks Checks.
 * @param {boolean} props.onlyIssues Hide passing checks.
 * @return {Element} List.
 */
export function ChecksList( { checks = [], onlyIssues = false } ) {
	const list = onlyIssues ? checks.filter( ( c ) => c.status !== 'good' ) : checks;
	const label = { good: __( 'Good', 'dev3-ai-seo-assistant' ), warn: __( 'Improve', 'dev3-ai-seo-assistant' ), bad: __( 'Problem', 'dev3-ai-seo-assistant' ) };
	if ( ! list.length ) {
		return <p className="dev3se-help">{ __( 'No issues found.', 'dev3-ai-seo-assistant' ) }</p>;
	}
	return (
		<ul className="dev3se-checks">
			{ list.map( ( c ) => (
				<li key={ c.id } className={ `is-${ c.status }` }>
					<Badge tone={ checkTone( c.status ) } dot>
						{ label[ c.status ] }
					</Badge>
					<span className="dev3se-checks__text">
						<strong>{ c.label }</strong> { c.message }
					</span>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Score ring.
 *
 * @param {Object} props       Props.
 * @param {number} props.score Score 0-100.
 * @param {number} props.size  Size px.
 * @return {Element} Ring.
 */
export function ScoreRing( { score = 0, size = 64 } ) {
	const r = 26;
	const c = 2 * Math.PI * r;
	const tone = score >= 80 ? 'good' : score >= 50 ? 'ok' : 'poor';
	return (
		<div className={ `dev3se-scorering is-${ tone }` } style={ { width: size, height: size } } role="img" aria-label={ sprintf( /* translators: %d: score */ __( 'SEO score %d out of 100', 'dev3-ai-seo-assistant' ), score ) }>
			<svg viewBox="0 0 64 64" width={ size } height={ size } aria-hidden="true">
				<circle cx="32" cy="32" r={ r } className="dev3se-scorering__track" />
				<circle cx="32" cy="32" r={ r } className="dev3se-scorering__value" strokeDasharray={ c } strokeDashoffset={ c - ( c * score ) / 100 } />
			</svg>
			<span className="dev3se-scorering__num">{ score }</span>
		</div>
	);
}
