import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, EmptyState, ErrorNotice, PageHeader, SkeletonCard, Stack } from '../core3/ui';
import { AiOffNotice, aiReady } from '../shared/panels';
import { scoreTone } from '../shared/seo';

/**
 * Post list inside a report card.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.posts Posts.
 * @return {Element} List.
 */
function PostList( { posts } ) {
	return (
		<ul className="dev3se-postlist">
			{ posts.map( ( p ) => (
				<li key={ p.id }>
					<a href={ `#/post/${ p.id }` }>{ p.title || `#${ p.id }` }</a>
					<Badge tone={ scoreTone( p.score ) }>{ p.score }</Badge>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Report card.
 *
 * @param {Object} props             Props.
 * @param {string} props.title       Title.
 * @param {string} props.description Description.
 * @param {Array}  props.posts       Posts.
 * @param {string} props.issue       Bulk issue filter.
 * @return {Element} Card.
 */
function ReportCard( { title, description, posts, issue } ) {
	return (
		<Card
			title={ title }
			description={ description }
			footer={
				issue && posts.length ? (
					<Button size="sm" variant="ghost" href={ `#/bulk/issue=${ issue }` }>
						{ __( 'Fix in bulk', 'dev3-ai-seo-assistant' ) }
					</Button>
				) : null
			}
		>
			{ posts.length ? <PostList posts={ posts.slice( 0, 8 ) } /> : <EmptyState compact icon="success" title={ __( 'All clear', 'dev3-ai-seo-assistant' ) } /> }
			{ posts.length > 8 && <p className="dev3se-help">{ sprintf( /* translators: %d: more posts */ __( 'and %d more', 'dev3-ai-seo-assistant' ), posts.length - 8 ) }</p> }
		</Card>
	);
}

/**
 * Content gaps: site audit (duplicates, cannibalisation, thin, orphans, missing meta) + AI topic ideas.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.status  Status.
 * @param {Function} props.onUsage After AI use.
 * @return {Element} Screen.
 */
export default function GapsTab( { status, onUsage } ) {
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ topics, setTopics ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ topicError, setTopicError ] = useState( null );

	useEffect( () => {
		request( 'report' ).then( setReport ).catch( setError );
	}, [] );

	const ideas = () => {
		setBusy( true );
		setTopicError( null );
		request( 'report/topics', { method: 'POST', data: { count: 8 } } )
			.then( setTopics )
			.catch( setTopicError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	const group = ( list, label ) =>
		list.length ? (
			<ul className="dev3se-groups">
				{ list.map( ( g, i ) => (
					<li key={ i }>
						<p className="dev3se-label">{ sprintf( label, g.value ) }</p>
						<PostList posts={ g.posts } />
					</li>
				) ) }
			</ul>
		) : (
			<EmptyState compact icon="success" title={ __( 'All clear', 'dev3-ai-seo-assistant' ) } />
		);

	return (
		<>
			<PageHeader title={ __( 'Content gaps', 'dev3-ai-seo-assistant' ) } description={ __( 'Find what is missing across the site: duplicate titles, posts competing for one keyword, thin pages, orphans, and topics you have not covered yet.', 'dev3-ai-seo-assistant' ) } />
			<Card tone="ai" title={ __( 'Topic ideas', 'dev3-ai-seo-assistant' ) } description={ __( 'DEV3 AI reads your post titles and focus keywords and proposes articles that fill gaps in your topic clusters.', 'dev3-ai-seo-assistant' ) }>
				<Stack gap={ 12 }>
					<AiOffNotice status={ status } />
					<div>
						<AiButton onClick={ ideas } loading={ busy } disabled={ ! aiReady( status ) }>
							{ __( 'Suggest topics with DEV3 AI', 'dev3-ai-seo-assistant' ) }
						</AiButton>
					</div>
					{ topicError && <ErrorNotice error={ topicError } onRetry={ ideas } onDismiss={ () => setTopicError( null ) } /> }
					{ topics && (
						<ol className="dev3se-topics">
							{ topics.topics.map( ( t, i ) => (
								<li key={ i }>
									<strong>{ t.title }</strong>
									<span className="dev3se-topics__kw">
										<Badge tone="brand">{ t.keyword }</Badge>
									</span>
									<p>{ t.why }</p>
									{ t.link_from.length > 0 && (
										<p className="dev3se-help">
											{ __( 'Link to it from:', 'dev3-ai-seo-assistant' ) }{ ' ' }
											{ t.link_from.map( ( p, j ) => (
												<span key={ p.id }>
													{ j > 0 && ', ' }
													<a href={ `#/post/${ p.id }` }>{ p.title }</a>
												</span>
											) ) }
										</p>
									) }
								</li>
							) ) }
						</ol>
					) }
				</Stack>
			</Card>
			{ error && <ErrorNotice error={ error } /> }
			{ ! report && ! error && <SkeletonCard lines={ 6 } /> }
			{ report && (
				<>
					<Columns min={ 320 }>
						<Card title={ __( 'Duplicate SEO titles', 'dev3-ai-seo-assistant' ) } description={ __( 'Several posts share one title, so they compete in search results.', 'dev3-ai-seo-assistant' ) }>
							{ /* translators: %s: title */ group( report.duplicates, __( '“%s”', 'dev3-ai-seo-assistant' ) ) }
						</Card>
						<Card title={ __( 'Keyword cannibalisation', 'dev3-ai-seo-assistant' ) } description={ __( 'Posts that target the same focus keyword. Merge them or give each its own angle.', 'dev3-ai-seo-assistant' ) }>
							{ /* translators: %s: keyword */ group( report.cannibal, __( 'Keyword: %s', 'dev3-ai-seo-assistant' ) ) }
						</Card>
					</Columns>
					<Columns min={ 280 }>
						<ReportCard title={ __( 'No meta description', 'dev3-ai-seo-assistant' ) } posts={ report.noMeta } issue="no_description" />
						<ReportCard title={ __( 'Thin content', 'dev3-ai-seo-assistant' ) } description={ __( 'Under 300 words.', 'dev3-ai-seo-assistant' ) } posts={ report.thin } issue="thin" />
						<ReportCard title={ __( 'Orphan posts', 'dev3-ai-seo-assistant' ) } description={ __( 'No internal links point here.', 'dev3-ai-seo-assistant' ) } posts={ report.orphans } issue="orphan" />
						<ReportCard title={ __( 'Images without alt text', 'dev3-ai-seo-assistant' ) } posts={ report.missingAlt } issue="missing_alt" />
					</Columns>
				</>
			) }
		</>
	);
}
