<?php
/**
 * Per-post routes (editor sidebar + admin): snapshot, meta/schema/FAQ/link drafts, content gaps.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Rest;

use Dev3\AiSeoAssistant\Ai\Generator;
use Dev3\AiSeoAssistant\Content\Analyzer;
use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Content\Basic;
use Dev3\AiSeoAssistant\Core3\Api\Ai;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Core3\Rest\Base_Controller;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Links\Suggester;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Faq_Markup;
use Dev3\AiSeoAssistant\Seo\Fields;
use Dev3\AiSeoAssistant\Seo\Schema_Output;
use Dev3\AiSeoAssistant\Service\Drafter;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3se/v1/posts.
 */
class Post_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$id   = '/posts/(?P<id>\d+)';
		$post = array( $this, 'can_edit_post' );
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_generate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			$id,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'snapshot' ),
				'permission_callback' => $post,
			)
		);
		$routes = array(
			'/meta'        => 'meta',
			'/schema'      => 'schema',
			'/links'       => 'links',
			'/links/draft' => 'links_draft',
			'/gaps'        => 'gaps',
			'/faq'         => 'faq',
			'/faq/draft'   => 'faq_draft',
			'/faq/meta'    => 'faq_meta',
		);
		foreach ( $routes as $suffix => $method ) {
			register_rest_route(
				self::NS,
				$id . $suffix,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => $post,
				)
			);
		}
	}

	/**
	 * Permission: generate + may edit this post (+ post type enabled).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_post( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'dev3se_not_found', __( 'Post not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'dev3se_forbidden', __( 'You do not have permission to edit this post.', 'dev3-ai-seo-assistant' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( ! in_array( $post->post_type, Settings::post_types(), true ) ) {
			return new \WP_Error( 'dev3se_type_disabled', __( 'DEV3 AI SEO Assistant is not enabled for this post type. Turn it on in Settings.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Request post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Post
	 */
	private function post( \WP_REST_Request $request ) {
		return get_post( (int) $request['id'] );
	}

	/**
	 * Whether AI was asked for and is usable.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function wants_ai( \WP_REST_Request $request ) {
		return 'basic' !== $request->get_param( 'mode' );
	}

	/**
	 * GET /status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return $this->respond(
			array(
				'adapter'   => Adapters::current()->describe(),
				'detected'  => Adapters::detect(),
				'adapters'  => Adapters::describe(),
				'fields'    => Fields::describe(),
				'ai'        => Ai::status(),
				'keySet'    => '' !== Settings_Store::api_key(),
				'counts'    => Drafts::counts(),
				'issues'    => Audit::issues(),
				'postTypes' => array_values(
					array_map(
						static fn( $t ) => array(
							'value' => $t->name,
							'label' => $t->labels->singular_name,
						),
						get_post_types( array( 'public' => true ), 'objects' )
					)
				),
				'limits'    => array(
					'title'   => Fields::max( 'seo_title' ),
					'descMin' => (int) Settings::get( 'description_min', 120 ),
					'descMax' => Fields::max( 'meta_description' ),
				),
			)
		);
	}

	/**
	 * GET /posts: bulk list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_posts( \WP_REST_Request $request ) {
		$args = array(
			'type'     => sanitize_key( (string) $request->get_param( 'type' ) ),
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'issue'    => sanitize_key( (string) $request->get_param( 'issue' ) ),
			'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'ids_only' => (bool) $request->get_param( 'ids_only' ),
			'ids'      => array_filter( array_map( 'intval', explode( ',', (string) $request->get_param( 'ids' ) ) ) ),
		);
		return $this->respond( Audit::query( $args ) );
	}

	/**
	 * GET /posts/<id>: snapshot + pending drafts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function snapshot( \WP_REST_Request $request ) {
		$post            = $this->post( $request );
		$data            = Analyzer::snapshot( $post->ID );
		$data['pending'] = Drafts::query(
			array(
				'post_id'  => $post->ID,
				'status'   => 'pending',
				'per_page' => 50,
			)
		)['items'];
		return $this->respond( $data );
	}

	/**
	 * POST /posts/<id>/meta { fields[], mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function meta( \WP_REST_Request $request ) {
		$fields = (array) $request->get_param( 'fields' );
		$fields = $fields ? array_map( 'sanitize_key', $fields ) : Fields::META;
		$res    = Drafter::meta(
			$this->post( $request ),
			$fields,
			array(
				'mode'   => $this->wants_ai( $request ) ? 'ai' : 'basic',
				'source' => sanitize_key( (string) ( $request->get_param( 'source' ) ? $request->get_param( 'source' ) : 'editor' ) ),
			)
		);
		return $this->respond( $res );
	}

	/**
	 * POST /posts/<id>/schema { mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function schema( \WP_REST_Request $request ) {
		$res = Drafter::meta( $this->post( $request ), array( 'schema' ), array( 'mode' => $this->wants_ai( $request ) ? 'ai' : 'basic' ) );
		return $this->respond( $res );
	}

	/**
	 * POST /posts/<id>/links { mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function links( \WP_REST_Request $request ) {
		$res = Suggester::suggest( $this->post( $request ), (int) $request->get_param( 'limit' ), $this->wants_ai( $request ) );
		return $this->respond( $res );
	}

	/**
	 * POST /posts/<id>/links/draft { links[] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function links_draft( \WP_REST_Request $request ) {
		$links = (array) $request->get_param( 'links' );
		return $this->respond( Drafter::links( $this->post( $request ), array_filter( $links, 'is_array' ), 'admin' ) );
	}

	/**
	 * POST /posts/<id>/gaps { keyword }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function gaps( \WP_REST_Request $request ) {
		$post    = $this->post( $request );
		$related = array();
		if ( Suggester::ai_ready() ) {
			$hits = Suggester::by_embeddings( $post, 8, array( $post->ID ) );
			if ( ! is_wp_error( $hits ) ) {
				foreach ( $hits as $hit ) {
					$related[] = array(
						'id'    => $hit['id'],
						'title' => \Dev3\AiSeoAssistant\Content\Analyzer::title( $hit['id'] ),
						'url'   => (string) get_permalink( $hit['id'] ),
						'score' => $hit['score'],
					);
				}
			}
		}
		$res = Generator::gaps( $post, sanitize_text_field( (string) $request->get_param( 'keyword' ) ), $related );
		if ( ! is_wp_error( $res ) ) {
			$res['basic'] = $this->basic_structure( $post );
		}
		return $this->respond( $res );
	}

	/**
	 * Non-AI structure notes for the gaps panel.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function basic_structure( $post ) {
		$snapshot = Analyzer::snapshot( $post->ID );
		return array(
			'words'    => $snapshot['stats']['words'],
			'headings' => $snapshot['stats']['headings'],
			'checks'   => array_values( array_filter( $snapshot['checks'], static fn( $c ) => 'good' !== $c['status'] ) ),
		);
	}

	/**
	 * POST /posts/<id>/faq { count, mode }: FAQ items + block markup (not saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function faq( \WP_REST_Request $request ) {
		$post  = $this->post( $request );
		$count = (int) $request->get_param( 'count' );
		$count = $count > 0 ? $count : (int) Settings::get( 'faq_count', 5 );
		if ( $this->wants_ai( $request ) ) {
			$res = Generator::faq( $post, $count );
			if ( is_wp_error( $res ) ) {
				return $this->respond( $res );
			}
		} else {
			$items = Basic::faq( $post );
			if ( ! $items ) {
				return $this->respond( new \WP_Error( 'dev3se_no_faq', __( 'No question headings found. Add headings that end with "?" or use DEV3 AI.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) ) );
			}
			$res = array(
				'items'  => $items,
				'model'  => '',
				'tokens' => 0,
			);
		}
		$block        = Adapters::current()->faq_block();
		$res['block'] = '' !== $block ? $block : 'core/details';
		$res['html']  = Faq_Markup::blocks( $res['items'], $block, true );
		return $this->respond( $res );
	}

	/**
	 * POST /posts/<id>/faq/draft { items }: content draft (admin flow).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function faq_draft( \WP_REST_Request $request ) {
		return $this->respond( Drafter::faq( $this->post( $request ), (array) $request->get_param( 'items' ), 'admin' ) );
	}

	/**
	 * POST /posts/<id>/faq/meta { items }: stores the FAQ the editor inserted (for core-block schema).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function faq_meta( \WP_REST_Request $request ) {
		$post  = $this->post( $request );
		$items = Faq_Markup::clean( (array) $request->get_param( 'items' ) );
		if ( $items ) {
			update_post_meta( $post->ID, Schema_Output::FAQ_META, wp_slash( (string) wp_json_encode( $items ) ) );
		} else {
			delete_post_meta( $post->ID, Schema_Output::FAQ_META );
		}
		return $this->respond( array( 'items' => $items ) );
	}
}
