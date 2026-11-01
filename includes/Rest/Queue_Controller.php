<?php
/**
 * Review queue, history/undo, bulk runs, embeddings index and site reports.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Rest;

use Dev3\AiSeoAssistant\Ai\Generator;
use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Core3\Core\Capabilities;
use Dev3\AiSeoAssistant\Core3\Core\Jobs;
use Dev3\AiSeoAssistant\Core3\Rest\Base_Controller;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Data\History;
use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Links\Index;
use Dev3\AiSeoAssistant\Service\Applier;
use Dev3\AiSeoAssistant\Service\Bulk;

defined( 'ABSPATH' ) || exit;

/**
 * Queue routes.
 */
class Queue_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			array( '/drafts', \WP_REST_Server::READABLE, 'drafts', 'can_generate' ),
			array( '/drafts/(?P<id>\d+)/apply', \WP_REST_Server::CREATABLE, 'apply', 'can_draft' ),
			array( '/drafts/(?P<id>\d+)/reject', \WP_REST_Server::CREATABLE, 'reject', 'can_draft' ),
			array( '/drafts/bulk', \WP_REST_Server::CREATABLE, 'drafts_bulk', 'can_manage' ),
			array( '/drafts/apply-run', \WP_REST_Server::CREATABLE, 'apply_run', 'can_manage' ),
			array( '/runs', \WP_REST_Server::READABLE, 'runs', 'can_manage' ),
			array( '/history', \WP_REST_Server::READABLE, 'history', 'can_generate' ),
			array( '/history/(?P<id>\d+)/undo', \WP_REST_Server::CREATABLE, 'undo', 'can_history' ),
			array( '/history/batch/undo', \WP_REST_Server::CREATABLE, 'undo_batch', 'can_manage' ),
			array( '/bulk', \WP_REST_Server::CREATABLE, 'bulk', 'can_manage' ),
			array( '/index', \WP_REST_Server::READABLE, 'index_status', 'can_manage' ),
			array( '/index', \WP_REST_Server::CREATABLE, 'index_start', 'can_manage' ),
			array( '/report', \WP_REST_Server::READABLE, 'report', 'can_manage' ),
			array( '/report/topics', \WP_REST_Server::CREATABLE, 'topics', 'can_manage' ),
			array( '/links/report', \WP_REST_Server::READABLE, 'links_report', 'can_manage' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( $this, $r[2] ),
					'permission_callback' => array( $this, $r[3] ),
				)
			);
		}
	}

	/**
	 * Permission for one draft: generate + may edit its post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_draft( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$draft = Drafts::get( (int) $request['id'] );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3se_not_found', __( 'Draft not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		return $this->post_cap( $draft['post_id'] );
	}

	/**
	 * Permission for one history row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_history( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$row = History::get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'dev3se_not_found', __( 'History entry not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		return $this->post_cap( $row['post_id'] );
	}

	/**
	 * Edit-post capability check.
	 *
	 * @param int $post_id Post id.
	 * @return bool|\WP_Error
	 */
	private function post_cap( $post_id ) {
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return new \WP_Error( 'dev3se_forbidden', __( 'You do not have permission to edit this post.', 'dev3-ai-seo-assistant' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Non-managers may only list drafts/history of a post they can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function scope( \WP_REST_Request $request ) {
		if ( Capabilities::can_manage() ) {
			return true;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id ) {
			return new \WP_Error( 'dev3se_forbidden', __( 'Choose a post.', 'dev3-ai-seo-assistant' ), array( 'status' => 403 ) );
		}
		return $this->post_cap( $post_id );
	}

	/**
	 * GET /drafts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function drafts( \WP_REST_Request $request ) {
		$scope = $this->scope( $request );
		if ( true !== $scope ) {
			return $this->respond( $scope );
		}
		$res           = Drafts::query(
			array(
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'post_id'  => (int) $request->get_param( 'post_id' ),
				'job_id'   => (int) $request->get_param( 'job_id' ),
				'field'    => sanitize_key( (string) $request->get_param( 'field' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);
		$res['counts'] = Drafts::counts();
		return $this->respond( $res );
	}

	/**
	 * POST /drafts/<id>/apply { value?, force? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( \WP_REST_Request $request ) {
		$value = $request->has_param( 'value' ) ? (string) $request->get_param( 'value' ) : null;
		return $this->respond( Applier::apply( (int) $request['id'], $value, (bool) $request->get_param( 'force' ) ) );
	}

	/**
	 * POST /drafts/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject( \WP_REST_Request $request ) {
		return $this->respond( Applier::reject( (int) $request['id'] ) );
	}

	/**
	 * POST /drafts/bulk { ids[], action: apply|reject }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function drafts_bulk( \WP_REST_Request $request ) {
		$action = 'reject' === $request->get_param( 'action' ) ? 'reject' : 'apply';
		$ids    = array_slice( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ), 0, 200 );
		$batch  = 'apply' === $action ? 'bulk-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 100, 999 ) : '';
		$done   = 0;
		$failed = array();
		foreach ( $ids as $id ) {
			$draft = Drafts::get( $id );
			if ( ! $draft || ! current_user_can( 'edit_post', $draft['post_id'] ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => __( 'Not found or not allowed.', 'dev3-ai-seo-assistant' ),
				);
				continue;
			}
			$res = 'reject' === $action ? Applier::reject( $id ) : Applier::apply( $id, null, (bool) $request->get_param( 'force' ), $batch );
			if ( is_wp_error( $res ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => $res->get_error_message(),
				);
			} else {
				++$done;
			}
		}
		return $this->respond( compact( 'done', 'failed', 'batch' ) );
	}

	/**
	 * POST /drafts/apply-run { job_id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply_run( \WP_REST_Request $request ) {
		return $this->respond( Bulk::start_apply( (int) $request->get_param( 'job_id' ) ), 201 );
	}

	/**
	 * GET /runs: bulk runs with draft counts.
	 *
	 * @return \WP_REST_Response
	 */
	public function runs() {
		$runs = array();
		foreach ( Drafts::runs( 20 ) as $run ) {
			$job          = Jobs::get( $run['job_id'] );
			$run['job']   = $job;
			$run['mode']  = $job ? (string) ( $job['args']['mode'] ?? '' ) : '';
			$run['posts'] = $job ? (int) $job['total'] : 0;
			$runs[]       = $run;
		}
		$active = Jobs::active( Bulk::JOB_DRAFTS );
		return $this->respond(
			array(
				'runs'   => $runs,
				'active' => $active,
			)
		);
	}

	/**
	 * GET /history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ) {
		$scope = $this->scope( $request );
		if ( true !== $scope ) {
			return $this->respond( $scope );
		}
		return $this->respond(
			History::query(
				array(
					'post_id'  => (int) $request->get_param( 'post_id' ),
					'batch'    => sanitize_text_field( (string) $request->get_param( 'batch' ) ),
					'field'    => sanitize_key( (string) $request->get_param( 'field' ) ),
					'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'page'     => (int) $request->get_param( 'page' ),
					'per_page' => (int) $request->get_param( 'per_page' ),
				)
			)
		);
	}

	/**
	 * POST /history/<id>/undo { force? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function undo( \WP_REST_Request $request ) {
		return $this->respond( Applier::undo( (int) $request['id'], (bool) $request->get_param( 'force' ) ) );
	}

	/**
	 * POST /history/batch/undo { batch, force? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function undo_batch( \WP_REST_Request $request ) {
		$batch = sanitize_text_field( (string) $request->get_param( 'batch' ) );
		if ( '' === $batch ) {
			return $this->respond( new \WP_Error( 'dev3se_no_batch', __( 'Missing batch.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Applier::undo_batch( $batch, (bool) $request->get_param( 'force' ) ) );
	}

	/**
	 * POST /bulk { ids[] | filter{}, fields[], mode, only_empty }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		if ( ! $ids && is_array( $request->get_param( 'filter' ) ) ) {
			$f   = (array) $request->get_param( 'filter' );
			$ids = Audit::query(
				array(
					'type'     => sanitize_key( (string) ( $f['type'] ?? '' ) ),
					'status'   => sanitize_key( (string) ( $f['status'] ?? '' ) ),
					'issue'    => sanitize_key( (string) ( $f['issue'] ?? '' ) ),
					'search'   => sanitize_text_field( (string) ( $f['search'] ?? '' ) ),
					'ids_only' => true,
				)
			)['ids'];
		}
		$res = Bulk::start(
			$ids,
			array(
				'fields'     => array_map( 'sanitize_key', (array) $request->get_param( 'fields' ) ),
				'mode'       => sanitize_key( (string) $request->get_param( 'mode' ) ),
				'only_empty' => (bool) $request->get_param( 'only_empty' ),
			)
		);
		return $this->respond( $res, 201 );
	}

	/**
	 * GET /index.
	 *
	 * @return \WP_REST_Response
	 */
	public function index_status() {
		return $this->respond( Index::stats() );
	}

	/**
	 * POST /index { all? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index_start( \WP_REST_Request $request ) {
		$res = Index::start( (bool) $request->get_param( 'all' ) );
		if ( is_wp_error( $res ) && 'dev3se_index_fresh' === $res->get_error_code() ) {
			return $this->respond(
				array(
					'fresh'   => true,
					'message' => $res->get_error_message(),
				)
			);
		}
		return $this->respond( $res, 201 );
	}

	/**
	 * GET /report.
	 *
	 * @return \WP_REST_Response
	 */
	public function report() {
		$report                  = Audit::report();
		$report['runs']          = Drafts::runs( 5 );
		$report['applied30']     = History::count_since( 30 );
		$report['counts_drafts'] = Drafts::counts();
		return $this->respond( $report );
	}

	/**
	 * POST /report/topics { count }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function topics( \WP_REST_Request $request ) {
		$count = (int) $request->get_param( 'count' );
		return $this->respond( Generator::topics( Audit::rows(), $count > 0 ? $count : 8 ) );
	}

	/**
	 * GET /links/report: inbound/outbound per post + orphans.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function links_report( \WP_REST_Request $request ) {
		$graph   = Graph::instance();
		$inbound = $graph->inbound_counts();
		$out_map = $graph->outbound_map();
		$rows    = array();
		foreach ( $inbound as $id => $in ) {
			$rows[] = array(
				'id'       => (int) $id,
				'title'    => \Dev3\AiSeoAssistant\Content\Analyzer::title( $id ),
				'inbound'  => (int) $in,
				'outbound' => isset( $out_map[ $id ] ) ? count( $out_map[ $id ] ) : 0,
				'editUrl'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		if ( 'orphans' === $filter ) {
			$rows = array_values( array_filter( $rows, static fn( $r ) => 0 === $r['inbound'] ) );
		}
		usort( $rows, static fn( $a, $b ) => 0 !== ( $a['inbound'] <=> $b['inbound'] ) ? $a['inbound'] <=> $b['inbound'] : strnatcasecmp( $a['title'], $b['title'] ) );
		$total = count( $rows );
		$links = array_sum( array_map( static fn( $r ) => $r['outbound'], $rows ) );
		return $this->respond(
			array(
				'items'   => array_slice( $rows, 0, 500 ),
				'total'   => $total,
				'orphans' => count( $graph->orphans() ),
				'links'   => $links,
			)
		);
	}
}
