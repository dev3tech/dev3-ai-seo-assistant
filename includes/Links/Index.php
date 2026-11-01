<?php
/**
 * Embeddings index of published posts (core Embeddings_Store, type "post"), kept fresh by hash.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Links;

use Dev3\AiSeoAssistant\Content\Analyzer;
use Dev3\AiSeoAssistant\Content\Corpus;
use Dev3\AiSeoAssistant\Core3\Api\Ai;
use Dev3\AiSeoAssistant\Core3\Core\Jobs;
use Dev3\AiSeoAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Index.
 */
class Index {

	const TYPE      = 'post';
	const JOB       = 'dev3se_index';
	const QUEUE_OPT = 'dev3se_index_queue';

	/**
	 * Hooks.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 20, 3 );
		add_action( 'save_post', array( self::class, 'saved' ), 30, 2 );
		add_action( 'before_delete_post', array( self::class, 'deleted' ) );
		add_action( 'shutdown', array( self::class, 'flush_queue' ) );
	}

	/**
	 * Text that represents a post in the index.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function doc_text( $post ) {
		$headings = array_map( static fn( $h ) => $h['text'], array_slice( Analyzer::headings( $post->post_content ), 0, 20 ) );
		$keyword  = Analyzer::primary_keyword( Adapters::read( $post->ID, 'focus_keyword' ) );
		$parts    = array(
			\Dev3\AiSeoAssistant\Content\Analyzer::title( $post ),
			$keyword,
			wp_strip_all_tags( (string) $post->post_excerpt ),
			implode( ' | ', $headings ),
			mb_substr( Analyzer::text( $post->post_content ), 0, 1500 ),
		);
		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	/**
	 * Published post ids => hash of their index text.
	 *
	 * @return array
	 */
	public static function hashes() {
		$out = array();
		Corpus::each(
			static function ( $post ) use ( &$out ) {
				$out[ $post->ID ] = Embeddings_Store::hash( self::doc_text( $post ) );
			},
			array( 'publish' )
		);
		return $out;
	}

	/**
	 * Index status.
	 *
	 * @return array { indexed, total, stale, job }
	 */
	public static function stats() {
		$store  = Embeddings_Store::instance();
		$hashes = self::hashes();
		$stale  = $hashes ? $store->stale( self::TYPE, $hashes ) : array();
		$job    = Jobs::active( self::JOB );
		if ( ! $job ) {
			$recent = Jobs::recent( self::JOB, 1 );
			$job    = $recent ? $recent[0] : null;
		}
		return array(
			'indexed'   => $store->count( self::TYPE ),
			'total'     => count( $hashes ),
			'stale'     => count( $stale ),
			'job'       => $job,
			'available' => Ai::enabled( 'admin' ) && '' !== \Dev3\AiSeoAssistant\Core3\Core\Settings_Store::api_key(),
		);
	}

	/**
	 * Starts (or returns the running) index job for stale posts.
	 *
	 * @param bool $all Re-embed everything.
	 * @return array|\WP_Error Job.
	 */
	public static function start( $all = false ) {
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$store  = Embeddings_Store::instance();
		$hashes = self::hashes();
		foreach ( $store->orphans( self::TYPE, array_keys( $hashes ) ) as $orphan ) {
			$store->delete( self::TYPE, $orphan );
		}
		$ids = $all ? array_keys( $hashes ) : $store->stale( self::TYPE, $hashes );
		if ( ! $ids ) {
			return new \WP_Error( 'dev3se_index_fresh', __( 'The index is already up to date.', 'dev3-ai-seo-assistant' ), array( 'status' => 200 ) );
		}
		$id = Jobs::create( self::JOB, array_values( array_map( 'intval', $ids ) ), array(), 20 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler: embeds a chunk of posts.
	 *
	 * @param array $items Post ids.
	 * @param array $job   Job.
	 * @return array|\WP_Error
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$posts = array();
		$texts = array();
		foreach ( $items as $id ) {
			$post = get_post( (int) $id );
			if ( $post && 'publish' === $post->post_status ) {
				$posts[ (int) $id ] = $post;
				$texts[]            = self::doc_text( $post );
			}
		}
		$out = array_fill_keys( array_map( 'intval', $items ), true );
		if ( ! $texts ) {
			return $out;
		}
		$result = Ai::embed( 'seo_index', $texts, 'RETRIEVAL_DOCUMENT', 'cron' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$store = Embeddings_Store::instance();
		$i     = 0;
		foreach ( $posts as $id => $post ) {
			if ( isset( $result['vectors'][ $i ] ) ) {
				$store->upsert( self::TYPE, $id, $result['vectors'][ $i ], Embeddings_Store::hash( $texts[ $i ] ) );
			} else {
				$out[ $id ] = __( 'No embedding returned.', 'dev3-ai-seo-assistant' );
			}
			++$i;
		}
		return $out;
	}

	/**
	 * Unpublished posts leave the index.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			Embeddings_Store::instance()->delete( self::TYPE, (int) $post->ID );
		}
	}

	/**
	 * Queues published posts for re-embedding (auto index).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public static function saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, Settings::post_types(), true ) ) {
			return;
		}
		if ( ! Settings::get( 'auto_index', true ) || ! Ai::enabled( 'admin' ) || '' === \Dev3\AiSeoAssistant\Core3\Core\Settings_Store::api_key() ) {
			return;
		}
		$queue   = (array) get_option( self::QUEUE_OPT, array() );
		$queue[] = (int) $post_id;
		update_option( self::QUEUE_OPT, array_values( array_unique( array_map( 'intval', $queue ) ) ), false );
	}

	/**
	 * Turns the queue into a job at the end of the request (skips if one is running; it is retried later).
	 */
	public static function flush_queue() {
		$queue = (array) get_option( self::QUEUE_OPT, array() );
		if ( ! $queue || Jobs::active( self::JOB ) ) {
			return;
		}
		delete_option( self::QUEUE_OPT );
		Jobs::create( self::JOB, array_values( array_map( 'intval', $queue ) ), array( 'auto' => true ), 20 );
	}

	/**
	 * Deleted posts leave the index.
	 *
	 * @param int $post_id Post id.
	 */
	public static function deleted( $post_id ) {
		Embeddings_Store::instance()->delete( self::TYPE, (int) $post_id );
	}
}
