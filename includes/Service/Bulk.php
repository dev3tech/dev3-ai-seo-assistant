<?php
/**
 * Background jobs (core Jobs): bulk draft generation and "apply all pending drafts of a run".
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Service;

use Dev3\AiSeoAssistant\Core3\Core\Jobs;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk runs.
 */
class Bulk {

	const JOB_DRAFTS = 'dev3se_bulk';
	const JOB_APPLY  = 'dev3se_apply_run';
	const MAX_POSTS  = 2000;

	/**
	 * Registers job handlers (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB_DRAFTS, array( self::class, 'run_drafts' ) );
		Jobs::register( self::JOB_APPLY, array( self::class, 'run_apply' ) );
	}

	/**
	 * Starts a draft run.
	 *
	 * @param int[] $ids  Post ids.
	 * @param array $args { fields, mode, only_empty }.
	 * @return array|\WP_Error Job.
	 */
	public static function start( array $ids, array $args ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$ids = array_values( array_filter( $ids, static fn( $id ) => in_array( get_post_type( $id ), Settings::post_types(), true ) && current_user_can( 'edit_post', $id ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'dev3se_no_posts', __( 'Select at least one post.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > self::MAX_POSTS ) {
			/* translators: %d: limit */
			return new \WP_Error( 'dev3se_too_many', sprintf( __( 'One run can process up to %d posts. Narrow the filter or run it in parts.', 'dev3-ai-seo-assistant' ), self::MAX_POSTS ), array( 'status' => 400 ) );
		}
		$fields = Drafter::fields( (array) ( $args['fields'] ?? array() ) );
		if ( ! $fields ) {
			return new \WP_Error( 'dev3se_no_fields', __( 'Pick at least one field the active SEO plugin supports.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$mode = 'basic' === ( $args['mode'] ?? 'ai' ) ? 'basic' : 'ai';
		$id   = Jobs::create(
			self::JOB_DRAFTS,
			$ids,
			array(
				'fields'     => $fields,
				'mode'       => $mode,
				'only_empty' => ! empty( $args['only_empty'] ),
			),
			'basic' === $mode ? 25 : 3
		);
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Runs as the user who started the job (drafts and history record who did it).
	 *
	 * @param array    $job Job.
	 * @param callable $work Work.
	 * @return mixed
	 */
	private static function as_owner( array $job, callable $work ) {
		$previous = get_current_user_id();
		if ( ! empty( $job['user_id'] ) && (int) $job['user_id'] !== $previous ) {
			wp_set_current_user( (int) $job['user_id'] );
		}
		try {
			return $work();
		} finally {
			if ( get_current_user_id() !== $previous ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/**
	 * Job handler: drafts for a chunk of posts.
	 *
	 * @param array $items Post ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_drafts( array $items, array $job ) {
		return self::as_owner(
			$job,
			static function () use ( $items, $job ) {
				$out  = array();
				$args = (array) $job['args'];
				foreach ( $items as $id ) {
					$post = get_post( (int) $id );
					if ( ! $post ) {
						$out[ $id ] = __( 'Post not found.', 'dev3-ai-seo-assistant' );
						continue;
					}
					$res = Drafter::meta(
						$post,
						(array) ( $args['fields'] ?? array() ),
						array(
							'mode'       => $args['mode'] ?? 'ai',
							'source'     => 'bulk',
							'job_id'     => (int) $job['id'],
							'only_empty' => ! empty( $args['only_empty'] ),
							'context'    => 'cron',
						)
					);
					if ( is_wp_error( $res ) ) {
						// A paused AI (cap/disabled) fails the rest of the chunk the same way: stop early.
						if ( in_array( $res->get_error_code(), array( 'dev3se_cap_reached', 'dev3se_ai_disabled', 'dev3se_no_api_key', 'dev3se_invalid_key' ), true ) ) {
							return $res;
						}
						$out[ $id ] = $res->get_error_message();
					} else {
						$out[ $id ] = true;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Starts "apply all pending drafts of a run".
	 *
	 * @param int $run_id Draft job id.
	 * @return array|\WP_Error Job.
	 */
	public static function start_apply( $run_id ) {
		$ids = Drafts::pending_ids_for_job( $run_id );
		if ( ! $ids ) {
			return new \WP_Error( 'dev3se_nothing_pending', __( 'This run has no pending drafts.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$id = Jobs::create( self::JOB_APPLY, $ids, array( 'run' => (int) $run_id ), 50 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler: applies drafts (history batch "run-<id>").
	 *
	 * @param array $items Draft ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_apply( array $items, array $job ) {
		return self::as_owner(
			$job,
			static function () use ( $items, $job ) {
				$out   = array();
				$batch = 'run-' . (int) ( $job['args']['run'] ?? 0 );
				foreach ( $items as $id ) {
					$draft = Drafts::get( (int) $id );
					if ( $draft && ! current_user_can( 'edit_post', $draft['post_id'] ) ) {
						$out[ $id ] = __( 'You cannot edit this post.', 'dev3-ai-seo-assistant' );
						continue;
					}
					$res        = Applier::apply( (int) $id, null, false, $batch );
					$out[ $id ] = is_wp_error( $res ) ? $res->get_error_message() : true;
				}
				return $out;
			}
		);
	}
}
