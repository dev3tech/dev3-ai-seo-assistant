import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, PageHeader, ResponsiveTable, Stat, StatGrid, Toggle, formatNumber } from '../core3/ui';
import IndexCard from './IndexCard';

/**
 * Internal links: embeddings index, link report (inbound/outbound), orphans.
 *
 * @param {Object} props        Props.
 * @param {Object} props.status Status.
 * @return {Element} Screen.
 */
export default function LinksTab( { status } ) {
	const [ orphans, setOrphans ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		setData( null );
		request( `links/report${ orphans ? '?filter=orphans' : '' }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ orphans ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<>
			<PageHeader title={ __( 'Internal links', 'dev3-ai-seo-assistant' ) } description={ __( 'See which posts get links and which are orphans, then add links where they help readers.', 'dev3-ai-seo-assistant' ) } />
			{ data && (
				<StatGrid>
					<Stat label={ __( 'Published posts', 'dev3-ai-seo-assistant' ) } value={ formatNumber( data.total ) } icon="file" />
					<Stat label={ __( 'Internal links', 'dev3-ai-seo-assistant' ) } value={ formatNumber( data.links ) } icon="external" tone="brand" />
					<Stat label={ __( 'Orphan posts', 'dev3-ai-seo-assistant' ) } value={ formatNumber( data.orphans ) } icon="alert" tone={ data.orphans ? 'warning' : 'success' } hint={ __( 'No other post links to them', 'dev3-ai-seo-assistant' ) } />
				</StatGrid>
			) }
			<IndexCard status={ status } />
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card title={ __( 'Link report', 'dev3-ai-seo-assistant' ) } flush actions={ <Toggle label={ __( 'Orphans only', 'dev3-ai-seo-assistant' ) } checked={ orphans } onChange={ setOrphans } /> }>
				<ResponsiveTable
					rows={ data ? data.items : [] }
					loading={ ! data && ! error }
					pageSize={ 20 }
					defaultSort={ { key: 'inbound', dir: 'asc' } }
					columns={ [
						{ key: 'title', label: __( 'Post', 'dev3-ai-seo-assistant' ), primary: true, sortable: true, render: ( r ) => <a href={ `#/post/${ r.id }/links` }>{ r.title || `#${ r.id }` }</a> },
						{ key: 'inbound', label: __( 'Inbound', 'dev3-ai-seo-assistant' ), sortable: true, align: 'end', render: ( r ) => ( r.inbound ? r.inbound : <Badge tone="warning">{ __( 'orphan', 'dev3-ai-seo-assistant' ) }</Badge> ) },
						{ key: 'outbound', label: __( 'Outbound', 'dev3-ai-seo-assistant' ), sortable: true, align: 'end' },
					] }
					rowActions={ ( r ) => (
						<Button size="sm" icon="external" href={ `#/post/${ r.id }/links` }>
							{ __( 'Suggest links', 'dev3-ai-seo-assistant' ) }
						</Button>
					) }
					empty={ { icon: 'external', title: orphans ? __( 'No orphan posts', 'dev3-ai-seo-assistant' ) : __( 'No published posts yet', 'dev3-ai-seo-assistant' ) } }
				/>
			</Card>
		</>
	);
}
