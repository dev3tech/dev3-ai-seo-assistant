/**
 * Feature panels shared by the editor sidebar and the admin post screen. `editor` (sidebar only)
 * inserts content into blocks and syncs the SEO plugin's editor store; without it, content changes
 * become reviewable content drafts.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, Badge, Button, Checkbox, EmptyState, ErrorNotice, Icon, Inline, Notice, Select, Stack, TextField, Textarea, useToast } from '../core3/ui';
import { ChecksList, LengthMeter, ScoreRing, SerpPreview } from './components';
import DraftItem from './DraftItem';
import { META_FIELDS, fieldLabel } from './seo';

const SCHEMA_KINDS = [ 'schema_type', 'schema_jsonld' ];

/**
 * Whether AI can run (status from GET status).
 *
 * @param {Object} status Status.
 * @return {boolean} Ready.
 */
export const aiReady = ( status ) => !! status && !! status.keySet && !! status.ai && status.ai.enabled && ! status.ai.cap_reached;

/**
 * Notice shown when AI is unavailable.
 *
 * @param {Object} props        Props.
 * @param {Object} props.status Status.
 * @return {Element|null} Notice.
 */
export function AiOffNotice( { status } ) {
	if ( ! status || aiReady( status ) ) {
		return null;
	}
	let text = __( 'Add a Google Gemini API key in API Config to use DEV3 AI. Basic suggestions work without it.', 'dev3-ai-seo-assistant' );
	if ( status.ai && ! status.ai.enabled ) {
		text = __( 'AI features are turned off. Basic suggestions still work.', 'dev3-ai-seo-assistant' );
	} else if ( status.ai && status.ai.cap_reached ) {
		text = __( 'AI paused: the monthly AI budget is reached. Basic suggestions still work.', 'dev3-ai-seo-assistant' );
	}
	return (
		<Notice tone="info" actions={ config.canManage ? <Button size="sm" variant="link" href={ `${ config.adminUrl }admin.php?page=${ config.slug }#/api` }>{ __( 'API Config', 'dev3-ai-seo-assistant' ) }</Button> : null }>
			{ text }
		</Notice>
	);
}

/**
 * Pending drafts list.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.drafts   Drafts.
 * @param {Function} props.onDone   After apply/reject.
 * @param {Object}   props.editor   Editor bridge.
 * @return {Element|null} List.
 */
function Drafts( { drafts, onDone, editor } ) {
	if ( ! drafts.length ) {
		return null;
	}
	return (
		<Stack gap={ 12 }>
			{ drafts.map( ( d ) => (
				<DraftItem key={ d.id } draft={ d } onDone={ onDone } onSynced={ editor ? editor.sync : undefined } />
			) ) }
		</Stack>
	);
}

/**
 * Meta panel: snippet preview, current values, generate (AI/basic), field drafts.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.snapshot Snapshot.
 * @param {Object}   props.status   Status.
 * @param {Function} props.reload   Reload snapshot.
 * @param {Object}   props.editor   Editor bridge.
 * @param {Function} props.onUsage  After AI use.
 * @return {Element} Panel.
 */
export function MetaPanel( { snapshot, status, reload, editor, onUsage = () => {} } ) {
	const supported = ( snapshot.adapter.fields || [] ).filter( ( f ) => META_FIELDS.includes( f ) );
	const [ fields, setFields ] = useState( () => supported.filter( ( f ) => [ 'seo_title', 'meta_description', 'focus_keyword' ].includes( f ) ) );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ skipped, setSkipped ] = useState( [] );
	const toast = useToast();
	const values = snapshot.values || {};
	const pending = ( snapshot.pending || [] ).filter( ( d ) => META_FIELDS.includes( d.field ) );
	const ready = aiReady( status );

	const run = ( mode ) => {
		setBusy( mode );
		setError( null );
		setSkipped( [] );
		request( `posts/${ snapshot.post.id }/meta`, { method: 'POST', data: { fields, mode } } )
			.then( ( res ) => {
				setSkipped( res.skipped || [] );
				if ( ! res.drafts.length ) {
					toast.info( __( 'No new suggestions: the current values already match.', 'dev3-ai-seo-assistant' ) );
				}
				reload();
			} )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				if ( mode === 'ai' ) {
					onUsage();
				}
			} );
	};

	const toggle = ( f, on ) => setFields( on ? [ ...fields, f ] : fields.filter( ( x ) => x !== f ) );
	const title = values.seo_title || snapshot.defaults.seo_title;

	return (
		<Stack gap={ 16 }>
			<SerpPreview title={ title } description={ values.meta_description } url={ snapshot.post.url } siteName={ config.siteName } isDefault={ ! values.seo_title } />
			<dl className="dev3se-values">
				{ supported.map( ( f ) => (
					<div key={ f }>
						<dt>{ fieldLabel( f ) }</dt>
						<dd>
							{ values[ f ] ? values[ f ] : <span className="dev3se-muted">{ __( 'Not set', 'dev3-ai-seo-assistant' ) }</span> }
							{ values[ f ] ? <LengthMeter field={ f } value={ values[ f ] } /> : null }
						</dd>
					</div>
				) ) }
			</dl>
			<fieldset className="dev3se-fieldset">
				<legend>{ __( 'Fields to write', 'dev3-ai-seo-assistant' ) }</legend>
				<div className="dev3se-checkgrid">
					{ supported.map( ( f ) => (
						<Checkbox key={ f } label={ fieldLabel( f ) } checked={ fields.includes( f ) } onChange={ ( on ) => toggle( f, on ) } />
					) ) }
				</div>
			</fieldset>
			<AiOffNotice status={ status } />
			<Inline>
				<AiButton onClick={ () => run( 'ai' ) } loading={ busy === 'ai' } disabled={ ! fields.length || ! ready || !! busy } />
				<Button icon="bolt" onClick={ () => run( 'basic' ) } loading={ busy === 'basic' } disabled={ ! fields.length || !! busy }>
					{ __( 'Basic suggestions', 'dev3-ai-seo-assistant' ) }
				</Button>
			</Inline>
			{ error && <ErrorNotice error={ error } onRetry={ () => run( busy || 'ai' ) } onDismiss={ () => setError( null ) } /> }
			{ skipped.length > 0 && <p className="dev3se-help">{ sprintf( /* translators: %s: field list */ __( 'Unchanged: %s.', 'dev3-ai-seo-assistant' ), skipped.map( fieldLabel ).join( ', ' ) ) }</p> }
			<Drafts drafts={ pending } onDone={ reload } editor={ editor } />
		</Stack>
	);
}

/**
 * Schema panel.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.snapshot Snapshot.
 * @param {Object}   props.status   Status.
 * @param {Function} props.reload   Reload.
 * @param {Object}   props.editor   Editor bridge.
 * @param {Function} props.onUsage  After AI use.
 * @return {Element} Panel.
 */
export function SchemaPanel( { snapshot, status, reload, editor, onUsage = () => {} } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const pending = ( snapshot.pending || [] ).filter( ( d ) => SCHEMA_KINDS.includes( d.field ) );
	const adapter = snapshot.adapter;
	const run = ( mode ) => {
		setBusy( mode );
		setError( null );
		request( `posts/${ snapshot.post.id }/schema`, { method: 'POST', data: { mode } } )
			.then( reload )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	return (
		<Stack gap={ 12 }>
			<dl className="dev3se-values">
				<div>
					<dt>{ fieldLabel( 'schema_type' ) }</dt>
					<dd>{ snapshot.values.schema_type || <span className="dev3se-muted">{ __( 'SEO plugin default', 'dev3-ai-seo-assistant' ) }</span> }</dd>
				</div>
				<div>
					<dt>{ __( 'Extra schema', 'dev3-ai-seo-assistant' ) }</dt>
					<dd>{ snapshot.schemaTypes && snapshot.schemaTypes.length ? snapshot.schemaTypes.map( ( t ) => <Badge key={ t } tone="info">{ t }</Badge> ) : <span className="dev3se-muted">{ __( 'None', 'dev3-ai-seo-assistant' ) }</span> }</dd>
				</div>
			</dl>
			<p className="dev3se-help">
				{ adapter.id === 'yoast'
					? __( 'The schema type is written into Yoast’s Schema tab. Extra JSON-LD pieces are added to Yoast’s schema graph.', 'dev3-ai-seo-assistant' )
					: adapter.id === 'rankmath'
					? __( 'The schema type replaces the main Article/WebPage type in Rank Math’s graph. Extra JSON-LD pieces are added to it.', 'dev3-ai-seo-assistant' )
					: __( 'DEV3 prints the schema as JSON-LD in the page head.', 'dev3-ai-seo-assistant' ) }
			</p>
			<AiOffNotice status={ status } />
			<Inline>
				<AiButton onClick={ () => run( 'ai' ) } loading={ busy === 'ai' } disabled={ ! aiReady( status ) || !! busy }>
					{ __( 'Suggest schema with DEV3 AI', 'dev3-ai-seo-assistant' ) }
				</AiButton>
				<Button icon="bolt" onClick={ () => run( 'basic' ) } loading={ busy === 'basic' } disabled={ !! busy }>
					{ __( 'Basic', 'dev3-ai-seo-assistant' ) }
				</Button>
			</Inline>
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
			<Drafts drafts={ pending } onDone={ reload } editor={ editor } />
		</Stack>
	);
}

/**
 * Highlights the anchor in a context sentence.
 *
 * @param {Object} props         Props.
 * @param {string} props.text    Text.
 * @param {string} props.anchor  Anchor.
 * @return {Element} Text.
 */
function Highlight( { text, anchor } ) {
	const i = anchor ? text.toLowerCase().indexOf( anchor.toLowerCase() ) : -1;
	if ( i < 0 ) {
		return <span>{ text }</span>;
	}
	return (
		<span>
			{ text.slice( 0, i ) }
			<mark className="dev3se-anchor">{ text.slice( i, i + anchor.length ) }</mark>
			{ text.slice( i + anchor.length ) }
		</span>
	);
}

/**
 * Internal links panel.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.snapshot Snapshot.
 * @param {Object}   props.status   Status.
 * @param {Function} props.reload   Reload.
 * @param {Object}   props.editor   Editor bridge (insertLink).
 * @param {Function} props.onUsage  After AI use.
 * @return {Element} Panel.
 */
export function LinksPanel( { snapshot, status, reload, editor, onUsage = () => {} } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ inserted, setInserted ] = useState( {} );
	const [ draft, setDraft ] = useState( null );
	const ready = aiReady( status );

	const run = ( mode ) => {
		setBusy( mode );
		setError( null );
		setDraft( null );
		request( `posts/${ snapshot.post.id }/links`, { method: 'POST', data: { mode } } )
			.then( ( res ) => {
				setResult( res );
				setPicked( Object.fromEntries( res.suggestions.filter( ( s ) => s.anchor ).map( ( s ) => [ s.target_id, true ] ) ) );
				setInserted( {} );
			} )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const insert = ( s ) => {
		if ( editor.insertLink( s ) ) {
			setInserted( { ...inserted, [ s.target_id ]: true } );
			toast.success( sprintf( /* translators: %s: post title */ __( 'Link to “%s” added. Save the post to keep it.', 'dev3-ai-seo-assistant' ), s.title ) );
		} else {
			toast.warning( __( 'The anchor text is no longer in that paragraph. Run the suggestions again.', 'dev3-ai-seo-assistant' ) );
		}
	};

	const makeDraft = () => {
		const links = result.suggestions.filter( ( s ) => s.anchor && picked[ s.target_id ] ).map( ( s ) => ( { paragraph: s.paragraph, anchor: s.anchor, url: s.url, target_id: s.target_id } ) );
		setBusy( 'draft' );
		request( `posts/${ snapshot.post.id }/links/draft`, { method: 'POST', data: { links } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const count = Object.values( picked ).filter( Boolean ).length;

	return (
		<Stack gap={ 12 }>
			<p className="dev3se-help">
				{ sprintf( /* translators: 1: outbound links, 2: inbound links */ __( 'This post links to %1$d posts; %2$d posts link here.', 'dev3-ai-seo-assistant' ), snapshot.stats.outbound, snapshot.stats.inbound ) }
			</p>
			<Inline>
				<AiButton onClick={ () => run( 'ai' ) } loading={ busy === 'ai' } disabled={ ! ready || !! busy }>
					{ __( 'Find links with DEV3 AI', 'dev3-ai-seo-assistant' ) }
				</AiButton>
				<Button icon="search" onClick={ () => run( 'basic' ) } loading={ busy === 'basic' } disabled={ !! busy }>
					{ __( 'Basic match', 'dev3-ai-seo-assistant' ) }
				</Button>
			</Inline>
			{ ! ready && <p className="dev3se-help">{ __( 'Basic match compares words across your posts. DEV3 AI uses meaning (embeddings) and picks natural anchors.', 'dev3-ai-seo-assistant' ) }</p> }
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
			{ result && ! result.suggestions.length && <EmptyState compact icon="search" title={ __( 'No related posts found', 'dev3-ai-seo-assistant' ) } description={ __( 'Publish more posts on this topic or lower the minimum similarity in Settings.', 'dev3-ai-seo-assistant' ) } /> }
			{ result && result.suggestions.length > 0 && (
				<ul className="dev3se-links">
					{ result.suggestions.map( ( s ) => (
						<li key={ s.target_id } className={ inserted[ s.target_id ] ? 'is-done' : '' }>
							<div className="dev3se-links__head">
								{ ! editor && s.anchor ? (
									<Checkbox label={ s.title } checked={ !! picked[ s.target_id ] } onChange={ ( on ) => setPicked( { ...picked, [ s.target_id ]: on } ) } />
								) : (
									<strong>{ s.title }</strong>
								) }
								<Badge tone="info">{ `${ Math.round( s.score * 100 ) }%` }</Badge>
							</div>
							{ s.anchor ? (
								<p className="dev3se-links__ctx">
									<Highlight text={ s.context } anchor={ s.anchor } />
								</p>
							) : (
								<p className="dev3se-help">{ __( 'Related, but no natural anchor text was found. Add a sentence that mentions it.', 'dev3-ai-seo-assistant' ) }</p>
							) }
							{ s.reason && <p className="dev3se-help">{ s.reason }</p> }
							{ editor && s.anchor && (
								<Button size="sm" icon={ inserted[ s.target_id ] ? 'check' : 'plus' } onClick={ () => insert( s ) } disabled={ !! inserted[ s.target_id ] }>
									{ inserted[ s.target_id ] ? __( 'Inserted', 'dev3-ai-seo-assistant' ) : __( 'Insert link', 'dev3-ai-seo-assistant' ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ ! editor && result && result.suggestions.some( ( s ) => s.anchor ) && ! draft && (
				<Button variant="primary" icon="diff" onClick={ makeDraft } loading={ busy === 'draft' } disabled={ ! count }>
					{ sprintf( /* translators: %d: links */ _n( 'Review %d link as a draft', 'Review %d links as a draft', count, 'dev3-ai-seo-assistant' ), count ) }
				</Button>
			) }
			{ draft && (
				<DraftItem
					draft={ draft }
					onDone={ () => {
						setDraft( null );
						setResult( null );
						reload();
					} }
				/>
			) }
		</Stack>
	);
}

/**
 * Content gaps + outline panel.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.snapshot Snapshot.
 * @param {Object}   props.status   Status.
 * @param {Object}   props.editor   Editor bridge (insertOutline).
 * @param {Function} props.onUsage  After AI use.
 * @param {Function} props.onFaq    ( questions ) => void: send questions to the FAQ panel.
 * @return {Element} Panel.
 */
export function GapsPanel( { snapshot, status, editor, onUsage = () => {}, onFaq } ) {
	const toast = useToast();
	const [ keyword, setKeyword ] = useState( ( snapshot.values.focus_keyword || '' ).split( ',' )[ 0 ].trim() );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ res, setRes ] = useState( null );
	const ready = aiReady( status );

	const run = () => {
		setBusy( true );
		setError( null );
		request( `posts/${ snapshot.post.id }/gaps`, { method: 'POST', data: { keyword } } )
			.then( setRes )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	const missing = res ? res.outline.filter( ( o ) => ! o.exists ) : [];
	const insert = () => {
		const n = editor.insertOutline( missing );
		toast.success( sprintf( /* translators: %d: sections */ _n( '%d section added at the end of the post.', '%d sections added at the end of the post.', n, 'dev3-ai-seo-assistant' ), n ) );
	};
	const copy = () => {
		const text = res.outline.map( ( o ) => `${ o.level === 3 ? '  ' : '' }${ o.heading }${ o.notes ? ` — ${ o.notes }` : '' }` ).join( '\n' );
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( () => toast.success( __( 'Outline copied.', 'dev3-ai-seo-assistant' ) ) );
		}
	};

	return (
		<Stack gap={ 12 }>
			<TextField label={ __( 'Search phrase to cover', 'dev3-ai-seo-assistant' ) } value={ keyword } onChange={ setKeyword } placeholder={ snapshot.post.title } help={ __( 'Defaults to the focus keyword.', 'dev3-ai-seo-assistant' ) } />
			<AiOffNotice status={ status } />
			<AiButton onClick={ run } loading={ busy } disabled={ ! ready }>
				{ __( 'Find content gaps with DEV3 AI', 'dev3-ai-seo-assistant' ) }
			</AiButton>
			{ ! ready && (
				<div>
					<p className="dev3se-label">{ __( 'Structure checks (no AI)', 'dev3-ai-seo-assistant' ) }</p>
					<ChecksList checks={ snapshot.checks.filter( ( c ) => [ 'length', 'subheadings', 'kw_heading', 'kw_intro', 'alt' ].includes( c.id ) ) } />
				</div>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ run } onDismiss={ () => setError( null ) } /> }
			{ res && (
				<Stack gap={ 16 }>
					<div className="dev3se-gapscore">
						<ScoreRing score={ res.score } size={ 56 } />
						<div>
							<p className="dev3se-label">{ sprintf( /* translators: %s: keyword */ __( 'Coverage for “%s”', 'dev3-ai-seo-assistant' ), res.keyword ) }</p>
							<p>{ res.summary }</p>
						</div>
					</div>
					{ res.missing.length > 0 && (
						<div>
							<p className="dev3se-label">{ __( 'Missing subtopics', 'dev3-ai-seo-assistant' ) }</p>
							<ul className="dev3se-bullets">
								{ res.missing.map( ( m, i ) => (
									<li key={ i }>
										<strong>{ m.topic }</strong> <span className="dev3se-muted">{ m.why }</span>
									</li>
								) ) }
							</ul>
						</div>
					) }
					{ res.questions.length > 0 && (
						<div>
							<p className="dev3se-label">{ __( 'Questions searchers ask', 'dev3-ai-seo-assistant' ) }</p>
							<ul className="dev3se-bullets">
								{ res.questions.map( ( q, i ) => (
									<li key={ i }>{ q }</li>
								) ) }
							</ul>
							{ onFaq && (
								<Button size="sm" icon="list" onClick={ () => onFaq( res.questions ) }>
									{ __( 'Answer these in an FAQ', 'dev3-ai-seo-assistant' ) }
								</Button>
							) }
						</div>
					) }
					{ res.terms.length > 0 && (
						<div>
							<p className="dev3se-label">{ __( 'Terms to mention', 'dev3-ai-seo-assistant' ) }</p>
							<div className="dev3se-chips">
								{ res.terms.map( ( t ) => (
									<Badge key={ t }>{ t }</Badge>
								) ) }
							</div>
						</div>
					) }
					{ res.outline.length > 0 && (
						<div>
							<p className="dev3se-label">{ __( 'Suggested outline', 'dev3-ai-seo-assistant' ) }</p>
							<ol className="dev3se-outlinelist">
								{ res.outline.map( ( o, i ) => (
									<li key={ i } className={ `is-h${ o.level }` }>
										<span>
											<strong>{ o.heading }</strong> { o.exists && <Badge tone="success">{ __( 'In post', 'dev3-ai-seo-assistant' ) }</Badge> }
										</span>
										{ o.notes && <span className="dev3se-muted">{ o.notes }</span> }
									</li>
								) ) }
							</ol>
							<Inline>
								{ editor && missing.length > 0 && (
									<Button size="sm" variant="primary" icon="plus" onClick={ insert }>
										{ sprintf( /* translators: %d: sections */ _n( 'Insert %d missing section', 'Insert %d missing sections', missing.length, 'dev3-ai-seo-assistant' ), missing.length ) }
									</Button>
								) }
								<Button size="sm" icon="copy" onClick={ copy }>
									{ __( 'Copy outline', 'dev3-ai-seo-assistant' ) }
								</Button>
							</Inline>
						</div>
					) }
					{ res.related && res.related.length > 0 && (
						<div>
							<p className="dev3se-label">{ __( 'Related posts on your site', 'dev3-ai-seo-assistant' ) }</p>
							<ul className="dev3se-bullets">
								{ res.related.map( ( r ) => (
									<li key={ r.id }>
										<a href={ r.url } target="_blank" rel="noreferrer">
											{ r.title }
										</a>
									</li>
								) ) }
							</ul>
						</div>
					) }
				</Stack>
			) }
		</Stack>
	);
}

/**
 * FAQ panel.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.snapshot Snapshot.
 * @param {Object}   props.status   Status.
 * @param {Function} props.reload   Reload.
 * @param {Object}   props.editor   Editor bridge (insertFaq).
 * @param {Function} props.onUsage  After AI use.
 * @param {Array}    props.seed     Questions sent from the gaps panel.
 * @return {Element} Panel.
 */
export function FaqPanel( { snapshot, status, reload, editor, onUsage = () => {}, seed } ) {
	const toast = useToast();
	const [ count, setCount ] = useState( '5' );
	const [ items, setItems ] = useState( () => ( seed && seed.length ? seed.map( ( q ) => ( { question: q, answer: '' } ) ) : [] ) );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ meta, setMeta ] = useState( null );
	const block = snapshot.adapter.faqBlock || 'core/details';
	const ready = aiReady( status );

	const run = ( mode ) => {
		setBusy( mode );
		setError( null );
		setDraft( null );
		request( `posts/${ snapshot.post.id }/faq`, { method: 'POST', data: { mode, count: Number( count ) } } )
			.then( ( res ) => {
				setItems( res.items );
				setMeta( { model: res.model, tokens: res.tokens } );
			} )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const set = ( i, key, value ) => setItems( items.map( ( it, j ) => ( j === i ? { ...it, [ key ]: value } : it ) ) );
	const valid = items.filter( ( it ) => it.question.trim() && it.answer.trim() );

	const insert = () => {
		editor.insertFaq( valid, block );
		request( `posts/${ snapshot.post.id }/faq/meta`, { method: 'POST', data: { items: valid } } ).catch( () => {} );
		toast.success( __( 'FAQ block added at the end of the post. Save the post to keep it.', 'dev3-ai-seo-assistant' ) );
		setItems( [] );
	};

	const makeDraft = () => {
		setBusy( 'draft' );
		request( `posts/${ snapshot.post.id }/faq/draft`, { method: 'POST', data: { items: valid } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const blockLabel = { 'yoast/faq-block': __( 'Yoast FAQ block', 'dev3-ai-seo-assistant' ), 'rank-math/faq-block': __( 'Rank Math FAQ block', 'dev3-ai-seo-assistant' ) }[ block ] || __( 'Details blocks + DEV3 FAQ schema', 'dev3-ai-seo-assistant' );

	return (
		<Stack gap={ 12 }>
			<p className="dev3se-help">{ sprintf( /* translators: %s: block name */ __( 'Inserted as: %s (adds FAQPage schema).', 'dev3-ai-seo-assistant' ), blockLabel ) }</p>
			<Select
				label={ __( 'Questions', 'dev3-ai-seo-assistant' ) }
				value={ count }
				onChange={ setCount }
				options={ [ 3, 4, 5, 6, 8 ].map( ( n ) => ( { value: String( n ), label: String( n ) } ) ) }
			/>
			<AiOffNotice status={ status } />
			<Inline>
				<AiButton onClick={ () => run( 'ai' ) } loading={ busy === 'ai' } disabled={ ! ready || !! busy }>
					{ __( 'Write FAQ with DEV3 AI', 'dev3-ai-seo-assistant' ) }
				</AiButton>
				<Button icon="list" onClick={ () => run( 'basic' ) } loading={ busy === 'basic' } disabled={ !! busy }>
					{ __( 'From question headings', 'dev3-ai-seo-assistant' ) }
				</Button>
			</Inline>
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
			{ items.length > 0 && (
				<section className="dev3se-ui-draft" aria-label={ __( 'FAQ draft', 'dev3-ai-seo-assistant' ) }>
					<header className="dev3se-ui-draft__head">
						<div className="dev3se-ui-draft__title">
							<span className="dev3se-ui-draft__spark" aria-hidden="true">
								<Icon name="sparkles" size={ 16 } />
							</span>
							<h3>{ __( 'FAQ draft', 'dev3-ai-seo-assistant' ) }</h3>
							<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-seo-assistant' ) }</Badge>
						</div>
					</header>
					<div className="dev3se-ui-draft__body">
						<Stack gap={ 12 }>
							{ items.map( ( it, i ) => (
								<div key={ i } className="dev3se-faqitem">
									<TextField label={ sprintf( /* translators: %d: number */ __( 'Question %d', 'dev3-ai-seo-assistant' ), i + 1 ) } value={ it.question } onChange={ ( v ) => set( i, 'question', v ) } />
									<Textarea label={ __( 'Answer', 'dev3-ai-seo-assistant' ) } value={ it.answer } rows={ 3 } onChange={ ( v ) => set( i, 'answer', v ) } />
									<Button size="sm" variant="ghost" icon="trash" onClick={ () => setItems( items.filter( ( x, j ) => j !== i ) ) }>
										{ __( 'Remove', 'dev3-ai-seo-assistant' ) }
									</Button>
								</div>
							) ) }
							<Button size="sm" icon="plus" onClick={ () => setItems( [ ...items, { question: '', answer: '' } ] ) }>
								{ __( 'Add question', 'dev3-ai-seo-assistant' ) }
							</Button>
						</Stack>
					</div>
					<footer className="dev3se-ui-draft__foot">
						<div className="dev3se-ui-draft__meta">{ meta && meta.model ? <span>{ meta.model }</span> : null }</div>
						<div className="dev3se-ui-draft__actions">
							<Button variant="ghost" icon="x" onClick={ () => setItems( [] ) }>
								{ __( 'Discard', 'dev3-ai-seo-assistant' ) }
							</Button>
							{ editor ? (
								<Button variant="primary" icon="plus" onClick={ insert } disabled={ ! valid.length }>
									{ __( 'Insert FAQ block', 'dev3-ai-seo-assistant' ) }
								</Button>
							) : (
								<Button variant="primary" icon="diff" onClick={ makeDraft } loading={ busy === 'draft' } disabled={ ! valid.length }>
									{ __( 'Review as content draft', 'dev3-ai-seo-assistant' ) }
								</Button>
							) }
						</div>
					</footer>
				</section>
			) }
			{ draft && (
				<DraftItem
					draft={ draft }
					onDone={ () => {
						setDraft( null );
						setItems( [] );
						reload();
					} }
				/>
			) }
		</Stack>
	);
}
