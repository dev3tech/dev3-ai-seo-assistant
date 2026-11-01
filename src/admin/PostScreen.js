import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ErrorNotice, Inline, PageHeader, SkeletonCard, Tabs } from '../core3/ui';
import { ChecksList, ScoreRing } from '../shared/components';
import HistoryList from '../shared/HistoryList';
import { FaqPanel, GapsPanel, LinksPanel, MetaPanel, SchemaPanel } from '../shared/panels';

const SECTIONS = [ 'meta', 'schema', 'links', 'gaps', 'faq', 'history' ];

/**
 * One post in the admin (works for classic-editor sites too): checks + every DEV3 panel.
 *
 * @param {Object}   props         Props.
 * @param {number}   props.postId  Post id.
 * @param {string}   props.section Initial section.
 * @param {Object}   props.status  Status.
 * @param {Function} props.onUsage After AI use.
 * @param {Function} props.refresh Refresh status.
 * @return {Element} Screen.
 */
export default function PostScreen( { postId, section, status, onUsage, refresh } ) {
	const [ snapshot, setSnapshot ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( SECTIONS.includes( section ) ? section : 'meta' );
	const [ key, setKey ] = useState( 0 );
	const [ seed, setSeed ] = useState( null );

	const load = () => {
		setError( null );
		request( `posts/${ postId }` )
			.then( ( s ) => {
				setSnapshot( s );
				setKey( ( k ) => k + 1 );
				refresh();
			} )
			.catch( setError );
	};
	useEffect( load, [ postId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return (
			<>
				<PageHeader title={ __( 'Post', 'dev3-ai-seo-assistant' ) } back={ { href: '#/bulk', label: __( 'Bulk optimize', 'dev3-ai-seo-assistant' ) } } />
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! snapshot ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const common = { snapshot, status, reload: load, editor: null, onUsage };
	const tabs = [
		{ id: 'meta', label: __( 'Snippet & meta', 'dev3-ai-seo-assistant' ) },
		{ id: 'schema', label: __( 'Schema', 'dev3-ai-seo-assistant' ) },
		{ id: 'links', label: __( 'Internal links', 'dev3-ai-seo-assistant' ) },
		{ id: 'gaps', label: __( 'Content gaps', 'dev3-ai-seo-assistant' ) },
		{ id: 'faq', label: __( 'FAQ', 'dev3-ai-seo-assistant' ) },
		{ id: 'history', label: __( 'History', 'dev3-ai-seo-assistant' ) },
	];
	return (
		<>
			<PageHeader
				eyebrow={ `${ snapshot.post.type } · ${ snapshot.post.status }` }
				title={ snapshot.post.title || __( '(no title)', 'dev3-ai-seo-assistant' ) }
				back={ { href: '#/bulk', label: __( 'Bulk optimize', 'dev3-ai-seo-assistant' ) } }
				actions={
					<Inline>
						{ snapshot.post.editUrl && (
							<Button icon="edit" href={ snapshot.post.editUrl }>
								{ __( 'Edit post', 'dev3-ai-seo-assistant' ) }
							</Button>
						) }
						<Button icon="external" href={ snapshot.post.url } target="_blank" rel="noreferrer">
							{ __( 'View', 'dev3-ai-seo-assistant' ) }
						</Button>
					</Inline>
				}
			/>
			<div className="dev3se-postgrid">
				<div className="dev3se-postgrid__side">
					<Card title={ __( 'SEO score', 'dev3-ai-seo-assistant' ) }>
						<div className="dev3se-scorehead">
							<ScoreRing score={ snapshot.score } size={ 72 } />
							<p className="dev3se-help">{ sprintf( /* translators: 1: words, 2: SEO plugin */ __( '%1$d words · writing into %2$s', 'dev3-ai-seo-assistant' ), snapshot.stats.words, snapshot.adapter.label ) }</p>
						</div>
						<ChecksList checks={ snapshot.checks } />
					</Card>
				</div>
				<div className="dev3se-postgrid__main">
					<div className="dev3se-tabscroll">
						<Tabs tabs={ tabs } value={ tab } onChange={ setTab } label={ __( 'Post tools', 'dev3-ai-seo-assistant' ) } />
					</div>
					<Card>
						{ tab === 'meta' && <MetaPanel { ...common } /> }
						{ tab === 'schema' && <SchemaPanel { ...common } /> }
						{ tab === 'links' && <LinksPanel { ...common } /> }
						{ tab === 'gaps' && (
							<GapsPanel
								{ ...common }
								onFaq={ ( qs ) => {
									setSeed( qs );
									setTab( 'faq' );
								} }
							/>
						) }
						{ tab === 'faq' && <FaqPanel key={ seed ? seed.join( '|' ) : 'faq' } { ...common } seed={ seed } /> }
						{ tab === 'history' && <HistoryList postId={ postId } refreshKey={ key } onUndone={ load } /> }
					</Card>
				</div>
			</div>
		</>
	);
}
