import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, ErrorNotice, Icon, Notice, SkeletonCard, ToastProvider, cx, useUid } from '../core3/ui';
import { ChecksList, ScoreRing } from '../shared/components';
import HistoryList from '../shared/HistoryList';
import { FaqPanel, GapsPanel, LinksPanel, MetaPanel, SchemaPanel } from '../shared/panels';
import { insertFaq, insertLink, insertOutline, replaceContent, syncField } from './bridge';

/**
 * Collapsible sidebar section.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.title    Title.
 * @param {string}  props.icon     Icon.
 * @param {*}       props.badge    Badge content.
 * @param {boolean} props.open     Open.
 * @param {Function} props.onToggle Toggle.
 * @param {*}       props.children Body.
 * @return {Element} Section.
 */
function Section( { title, icon, badge, open, onToggle, children } ) {
	const id = useUid( 'dev3se-sec' );
	return (
		<section className={ cx( 'dev3se-sec', open && 'is-open' ) }>
			<h2 className="dev3se-sec__h">
				<button type="button" className="dev3se-sec__toggle" aria-expanded={ open } aria-controls={ id } onClick={ onToggle }>
					<Icon name={ icon } size={ 16 } />
					<span className="dev3se-sec__title">{ title }</span>
					{ badge }
					<Icon name="chevronDown" size={ 16 } />
				</button>
			</h2>
			{ open && (
				<div className="dev3se-sec__body" id={ id }>
					{ children }
				</div>
			) }
		</section>
	);
}

/**
 * DEV3 AI SEO sidebar.
 *
 * @return {Element} Sidebar.
 */
export default function Sidebar() {
	const { postId, saving, dirty } = useSelect( ( s ) => {
		const ed = s( 'core/editor' );
		return {
			postId: ed.getCurrentPostId(),
			saving: ed.isSavingPost() && ! ed.isAutosavingPost(),
			dirty: ed.isEditedPostDirty(),
		};
	}, [] );
	const [ snapshot, setSnapshot ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( 'meta' );
	const [ historyKey, setHistoryKey ] = useState( 0 );
	const [ faqSeed, setFaqSeed ] = useState( null );
	const wasSaving = useRef( false );

	const load = () => {
		if ( ! postId ) {
			return;
		}
		setError( null );
		request( `posts/${ postId }` )
			.then( ( snap ) => {
				setSnapshot( snap );
				setHistoryKey( ( k ) => k + 1 );
			} )
			.catch( setError );
	};
	useEffect( load, [ postId ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		request( 'status' ).then( setStatus ).catch( () => setStatus( { keySet: false, ai: { enabled: false } } ) );
	}, [] );
	// Re-analyse after each manual save.
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			load();
		}
		wasSaving.current = saving;
	}, [ saving ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const adapter = ( snapshot && snapshot.adapter ) || config.adapter || {};
	const editor = {
		sync: ( field, value ) => syncField( adapter, field, value ),
		insertLink,
		insertOutline,
		insertFaq,
	};
	const toggle = ( id ) => () => setOpen( open === id ? '' : id );
	const pendingContent = snapshot ? snapshot.pending.filter( ( d ) => d.field === 'content' ).length : 0;
	const pendingFields = snapshot ? snapshot.pending.filter( ( d ) => d.field !== 'content' ).length : 0;
	const common = { snapshot, status, reload: load, editor };

	return (
		<ToastProvider>
			<div className="dev3se-root dev3se-sidebar">
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ! snapshot && ! error && <SkeletonCard lines={ 4 } /> }
				{ snapshot && (
					<>
						<div className="dev3se-sidebar__top">
							<ScoreRing score={ snapshot.score } size={ 56 } />
							<div>
								<p className="dev3se-label">{ __( 'SEO score', 'dev3-ai-seo-assistant' ) }</p>
								<p className="dev3se-help">
									{ sprintf( /* translators: %s: SEO plugin */ __( 'Writing into %s', 'dev3-ai-seo-assistant' ), adapter.label ) }
								</p>
							</div>
						</div>
						{ dirty && <Notice tone="info">{ __( 'Unsaved changes: checks use the last saved version. Save to refresh them.', 'dev3-ai-seo-assistant' ) }</Notice> }
						{ pendingContent > 0 && (
							<Notice tone="warning">
								{ sprintf( /* translators: %d: drafts */ _n( '%d content draft waits in the Review queue.', '%d content drafts wait in the Review queue.', pendingContent, 'dev3-ai-seo-assistant' ), pendingContent ) }{ ' ' }
								{ config.canManage && <a href={ `${ config.adminUrl }admin.php?page=${ config.slug }#/review` }>{ __( 'Open queue', 'dev3-ai-seo-assistant' ) }</a> }
							</Notice>
						) }
						<Section title={ __( 'Checks', 'dev3-ai-seo-assistant' ) } icon="check" open={ open === 'checks' } onToggle={ toggle( 'checks' ) } badge={ <Badge tone={ snapshot.checks.some( ( c ) => c.status === 'bad' ) ? 'danger' : 'neutral' }>{ snapshot.checks.filter( ( c ) => c.status !== 'good' ).length }</Badge> }>
							<ChecksList checks={ snapshot.checks } />
						</Section>
						<Section title={ __( 'Snippet & meta', 'dev3-ai-seo-assistant' ) } icon="search" open={ open === 'meta' } onToggle={ toggle( 'meta' ) } badge={ pendingFields ? <Badge tone="warning">{ pendingFields }</Badge> : null }>
							<MetaPanel { ...common } />
						</Section>
						<Section title={ __( 'Schema', 'dev3-ai-seo-assistant' ) } icon="columns" open={ open === 'schema' } onToggle={ toggle( 'schema' ) }>
							<SchemaPanel { ...common } />
						</Section>
						<Section title={ __( 'Internal links', 'dev3-ai-seo-assistant' ) } icon="external" open={ open === 'links' } onToggle={ toggle( 'links' ) } badge={ <Badge>{ snapshot.stats.outbound }</Badge> }>
							<LinksPanel { ...common } />
						</Section>
						<Section title={ __( 'Content gaps & outline', 'dev3-ai-seo-assistant' ) } icon="list" open={ open === 'gaps' } onToggle={ toggle( 'gaps' ) }>
							<GapsPanel
								{ ...common }
								onFaq={ ( qs ) => {
									setFaqSeed( qs );
									setOpen( 'faq' );
								} }
							/>
						</Section>
						<Section title={ __( 'FAQ', 'dev3-ai-seo-assistant' ) } icon="help" open={ open === 'faq' } onToggle={ toggle( 'faq' ) }>
							<FaqPanel key={ faqSeed ? faqSeed.join( '|' ) : 'faq' } { ...common } seed={ faqSeed } />
						</Section>
						<Section title={ __( 'History', 'dev3-ai-seo-assistant' ) } icon="clock" open={ open === 'history' } onToggle={ toggle( 'history' ) }>
							<HistoryList
								postId={ postId }
								refreshKey={ historyKey }
								onUndone={ ( row ) => {
									if ( row.field === 'content' ) {
										replaceContent( row.before );
									} else {
										syncField( adapter, row.field, row.before );
									}
									load();
								} }
							/>
						</Section>
					</>
				) }
			</div>
		</ToastProvider>
	);
}
