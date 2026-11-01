import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, formatNumber } from '../core3/ui';
import { ScoreRing } from '../shared/components';
import { when } from '../shared/seo';
import IndexCard from './IndexCard';

/**
 * Dashboard: adapter, site score, issue KPIs (linking to a filtered bulk view), runs, index.
 *
 * @param {Object} props        Props.
 * @param {Object} props.status Status.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { status } ) {
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'report' ).then( setReport ).catch( setError );
	};
	useEffect( load, [] );

	const adapter = status ? status.adapter : null;
	const issues = report ? Object.entries( report.counts ).filter( ( [ , n ] ) => n > 0 ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] ) : [];
	const bands = report ? report.bands : { good: 0, ok: 0, poor: 0 };
	const total = report ? Math.max( 1, report.total ) : 1;

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-seo-assistant' ) }
				description={ __( 'Search snippet health across your site, and what DEV3 AI is working on.', 'dev3-ai-seo-assistant' ) }
				actions={
					<Button variant="primary" icon="bolt" href="#/bulk">
						{ __( 'Bulk optimize', 'dev3-ai-seo-assistant' ) }
					</Button>
				}
			/>
			{ status && ! status.keySet && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-seo-assistant' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-seo-assistant' ) }</Button> }>
					{ __( 'Add a Google Gemini API key to write titles, descriptions, schema, FAQs and find internal links with DEV3 AI. Checks, basic suggestions, reports and history work without it.', 'dev3-ai-seo-assistant' ) }
				</Notice>
			) }
			{ adapter && (
				<Notice tone={ adapter.id === 'native' ? 'warning' : 'success' } title={ sprintf( /* translators: %s: SEO plugin */ __( 'Writing into %s', 'dev3-ai-seo-assistant' ), adapter.label ) } actions={ <Button size="sm" variant="link" href="#/settings">{ __( 'Change', 'dev3-ai-seo-assistant' ) }</Button> }>
					{ adapter.id === 'native'
						? __( 'No SEO plugin detected. DEV3 stores the SEO title, description and schema itself and prints them in the page head. Install Yoast SEO or Rank Math to write into their fields instead.', 'dev3-ai-seo-assistant' )
						: __( 'Applied drafts are saved straight into this SEO plugin’s fields, and every change can be undone from History.', 'dev3-ai-seo-assistant' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! report && ! error && <SkeletonCard lines={ 4 } /> }
			{ report && (
				<>
					<StatGrid>
						<Stat label={ __( 'Average SEO score', 'dev3-ai-seo-assistant' ) } value={ `${ report.average }/100` } icon="chart" tone={ report.average >= 80 ? 'success' : report.average >= 50 ? 'warning' : 'danger' } hint={ sprintf( /* translators: %s: posts */ __( 'Across %s posts', 'dev3-ai-seo-assistant' ), formatNumber( report.total ) ) } />
						<Stat label={ __( 'Missing descriptions', 'dev3-ai-seo-assistant' ) } value={ formatNumber( report.counts.no_description ) } icon="alert" tone={ report.counts.no_description ? 'warning' : 'success' } />
						<Stat label={ __( 'Orphan posts', 'dev3-ai-seo-assistant' ) } value={ formatNumber( report.counts.orphan ) } icon="external" tone={ report.counts.orphan ? 'warning' : 'success' } hint={ __( 'No inbound internal links', 'dev3-ai-seo-assistant' ) } />
						<Stat label={ __( 'Drafts to review', 'dev3-ai-seo-assistant' ) } value={ formatNumber( report.counts_drafts.pending ) } icon="diff" tone="brand" />
						<Stat label={ __( 'Changes applied', 'dev3-ai-seo-assistant' ) } value={ formatNumber( report.applied30 ) } icon="check" tone="success" hint={ __( 'Last 30 days', 'dev3-ai-seo-assistant' ) } />
					</StatGrid>
					<Columns min={ 320 }>
						<Card title={ __( 'Score distribution', 'dev3-ai-seo-assistant' ) } description={ __( 'Share of posts by SEO score.', 'dev3-ai-seo-assistant' ) }>
							<div className="dev3se-dist">
								<ScoreRing score={ report.average } size={ 88 } />
								<div className="dev3se-dist__body">
									<div className="dev3se-dist__bar" role="img" aria-label={ sprintf( /* translators: 1: good, 2: ok, 3: poor */ __( '%1$d good, %2$d need work, %3$d poor', 'dev3-ai-seo-assistant' ), bands.good, bands.ok, bands.poor ) }>
										<span className="is-good" style={ { width: `${ ( 100 * bands.good ) / total }%` } } />
										<span className="is-ok" style={ { width: `${ ( 100 * bands.ok ) / total }%` } } />
										<span className="is-poor" style={ { width: `${ ( 100 * bands.poor ) / total }%` } } />
									</div>
									<ul className="dev3se-legend">
										<li className="is-good">{ sprintf( /* translators: %d: posts */ __( 'Good (80+): %d', 'dev3-ai-seo-assistant' ), bands.good ) }</li>
										<li className="is-ok">{ sprintf( /* translators: %d: posts */ __( 'Needs work (50-79): %d', 'dev3-ai-seo-assistant' ), bands.ok ) }</li>
										<li className="is-poor">{ sprintf( /* translators: %d: posts */ __( 'Poor (under 50): %d', 'dev3-ai-seo-assistant' ), bands.poor ) }</li>
									</ul>
								</div>
							</div>
						</Card>
						<Card title={ __( 'Top issues', 'dev3-ai-seo-assistant' ) } description={ __( 'Open a pre-filtered bulk run for any issue.', 'dev3-ai-seo-assistant' ) } flush>
							{ issues.length ? (
								<ul className="dev3se-issues">
									{ issues.slice( 0, 7 ).map( ( [ id, n ] ) => (
										<li key={ id }>
											<span>{ report.labels[ id ] }</span>
											<Badge tone={ [ 'no_description', 'long_title', 'no_keyword' ].includes( id ) ? 'danger' : 'warning' }>{ formatNumber( n ) }</Badge>
											<Button size="sm" variant="ghost" iconRight="chevronRight" href={ `#/bulk/issue=${ id }` }>
												{ __( 'Fix', 'dev3-ai-seo-assistant' ) }
											</Button>
										</li>
									) ) }
								</ul>
							) : (
								<EmptyState compact icon="success" title={ __( 'No issues found', 'dev3-ai-seo-assistant' ) } />
							) }
						</Card>
					</Columns>
					<Columns min={ 320 }>
						<Card title={ __( 'Recent bulk runs', 'dev3-ai-seo-assistant' ) } flush>
							<ResponsiveTable
								rowKey="job_id"
								rows={ report.runs }
								columns={ [
									{ key: 'job_id', label: __( 'Run', 'dev3-ai-seo-assistant' ), primary: true, render: ( r ) => `#${ r.job_id }` },
									{ key: 'pending', label: __( 'Pending', 'dev3-ai-seo-assistant' ), align: 'end' },
									{ key: 'total', label: __( 'Drafts', 'dev3-ai-seo-assistant' ), align: 'end' },
									{ key: 'created_at', label: __( 'Started', 'dev3-ai-seo-assistant' ), render: ( r ) => when( r.created_at ) },
								] }
								rowActions={ ( r ) => (
									<Button size="sm" href={ `#/review/job=${ r.job_id }` }>
										{ __( 'Review', 'dev3-ai-seo-assistant' ) }
									</Button>
								) }
								empty={ { icon: 'bolt', title: __( 'No bulk runs yet', 'dev3-ai-seo-assistant' ), description: __( 'Pick posts in Bulk optimize and let DEV3 AI draft their meta in the background.', 'dev3-ai-seo-assistant' ), action: <Button href="#/bulk" variant="primary">{ __( 'Start a run', 'dev3-ai-seo-assistant' ) }</Button> } }
							/>
						</Card>
						<IndexCard status={ status } />
					</Columns>
				</>
			) }
		</>
	);
}
