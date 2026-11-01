<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiSeoAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3se_settings = get_option( 'dev3se_settings', array() );
if ( empty( $dev3se_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3se_usage', 'dev3se_core_cache', 'dev3se_core_jobs', 'dev3se_core_embeddings', 'dev3se_drafts', 'dev3se_history' ) as $dev3se_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3se_table}" );
}
foreach ( array( 'dev3se_settings', 'dev3se_license', 'dev3se_db_version', 'dev3se_corpus_rev', 'dev3se_index_queue' ) as $dev3se_option ) {
	delete_option( $dev3se_option );
}
delete_transient( 'dev3se_models' );
foreach ( array( 'dev3se_graph', 'dev3se_audit', 'dev3se_terms' ) as $dev3se_transient ) {
	delete_transient( $dev3se_transient );
}
// DEV3-owned post meta (SEO plugin fields that DEV3 wrote stay, they belong to the SEO plugin).
foreach ( array( '_dev3se_schema', '_dev3se_faq', '_dev3se_title', '_dev3se_description', '_dev3se_focus_keyword', '_dev3se_og_title', '_dev3se_og_description', '_dev3se_schema_type' ) as $dev3se_meta ) {
	delete_post_meta_by_key( $dev3se_meta );
}
wp_unschedule_hook( 'dev3se_daily' );
delete_site_transient( 'dev3se_update_info' );
wp_unschedule_hook( 'dev3se_run_job' );
