import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Button, Card, ErrorNotice, Inline, JobProgress, ProgressBar, Skeleton, useToast } from '../core3/ui';
import { aiReady } from '../shared/panels';

/**
 * Embeddings index status + build button (background job).
 *
 * @param {Object} props        Props.
 * @param {Object} props.status Status.
 * @return {Element} Card.
 */
export default function IndexCard( { status } ) {
	const toast = useToast();
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		request( 'index' )
			.then( ( s ) => {
				setStats( s );
				if ( s.job && [ 'queued', 'running' ].includes( s.job.status ) ) {
					setJob( s.job );
				}
			} )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const start = ( all = false ) => {
		setBusy( true );
		setError( null );
		request( 'index', { method: 'POST', data: { all } } )
			.then( ( res ) => {
				if ( res.fresh ) {
					toast.info( res.message );
				} else {
					setJob( res );
				}
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	const pct = stats && stats.total ? Math.round( ( 100 * ( stats.total - stats.stale ) ) / stats.total ) : 0;

	return (
		<Card
			title={ __( 'Internal-link index', 'dev3-ai-seo-assistant' ) }
			description={ __( 'DEV3 AI embeds your published posts so link suggestions match by meaning, not just words.', 'dev3-ai-seo-assistant' ) }
			tone="ai"
		>
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
			{ ! stats && <Skeleton lines={ 2 } /> }
			{ stats && (
				<div className="dev3se-index">
					<ProgressBar value={ pct } tone={ stats.stale ? 'warning' : 'success' } label={ sprintf( /* translators: 1: fresh posts, 2: total */ __( '%1$d of %2$d posts indexed and up to date', 'dev3-ai-seo-assistant' ), stats.total - stats.stale, stats.total ) } />
					{ job ? (
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'Embedding posts', 'dev3-ai-seo-assistant' ) }
							onDone={ () => {
								setJob( null );
								load();
							} }
						/>
					) : (
						<Inline>
							<AiButton onClick={ () => start( false ) } loading={ busy } disabled={ ! aiReady( status ) || ! stats.stale }>
								{ stats.stale ? __( 'Update index with DEV3 AI', 'dev3-ai-seo-assistant' ) : __( 'Index is up to date', 'dev3-ai-seo-assistant' ) }
							</AiButton>
							<Button variant="ghost" icon="refresh" onClick={ () => start( true ) } disabled={ ! aiReady( status ) || busy || ! stats.total }>
								{ __( 'Rebuild all', 'dev3-ai-seo-assistant' ) }
							</Button>
						</Inline>
					) }
					{ ! aiReady( status ) && <p className="dev3se-help">{ __( 'Without AI, link suggestions use word matching (no index needed).', 'dev3-ai-seo-assistant' ) }</p> }
				</div>
			) }
		</Card>
	);
}
