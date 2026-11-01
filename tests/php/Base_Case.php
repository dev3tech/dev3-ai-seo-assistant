<?php
/**
 * Shared fixtures: posts, a fake Gemini (pre_http_request), REST helpers.
 *
 * @package Dev3\AiSeoAssistant
 */

use Dev3\AiSeoAssistant\Ai\Prompt_Library;
use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Content\Corpus;
use Dev3\AiSeoAssistant\Core3\Api\Ai;
use Dev3\AiSeoAssistant\Core3\Api\Prompts;
use Dev3\AiSeoAssistant\Core3\Core\Cache;
use Dev3\AiSeoAssistant\Core3\Core\Schema;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Core3\Data\Usage_Repository;
use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Seo\Adapters;

abstract class Dev3se_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake responses (each [ status, body ] or callable); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SE_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Prompts::reset();
		Prompt_Library::register();
		Adapters::reset();
		Graph::reset();
		Audit::flush();
		delete_transient( 'dev3se_graph' );
		delete_transient( 'dev3se_terms' );
		delete_option( 'dev3se_index_queue' );
		Corpus::bump();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3se_drafts" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3se_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3se_core_embeddings" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3se_core_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3se_core_cache" );
		// phpcs:enable
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'dev3se_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3se_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3se_retry_delay_ms', '__return_zero' );
		remove_filter( 'dev3se_jobs_use_action_scheduler', '__return_false' );
		Adapters::reset();
		parent::tear_down();
	}

	/**
	 * Fake Gemini.
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array|mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : $this->json_response( array() ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Structured-output response.
	 *
	 * @param array $data Decoded output.
	 * @return array
	 */
	protected function json_response( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 400,
					'candidatesTokenCount' => 200,
					'totalTokenCount'      => 600,
				),
			),
		);
	}

	/**
	 * Embedding response: vector per text from a callback( text ) => float[].
	 *
	 * @param callable $fn Vector for a text.
	 * @return callable
	 */
	protected function embed_response( callable $fn ) {
		return static function ( $req ) use ( $fn ) {
			$out = array();
			foreach ( $req['body']['requests'] as $r ) {
				$out[] = array( 'values' => $fn( $r['content']['parts'][0]['text'] ) );
			}
			return array( 200, array( 'embeddings' => $out ) );
		};
	}

	/**
	 * Creates a post.
	 *
	 * @param array $a { title, content, status, type, excerpt, slug }.
	 * @return int
	 */
	protected function post( array $a = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_title'   => $a['title'] ?? 'How to brew espresso at home',
				'post_content' => $a['content'] ?? '<!-- wp:paragraph --><p>Brewing espresso at home needs a good grinder and fresh beans. This espresso guide covers the basics.</p><!-- /wp:paragraph -->',
				'post_status'  => $a['status'] ?? 'publish',
				'post_type'    => $a['type'] ?? 'post',
				'post_excerpt' => $a['excerpt'] ?? '',
				'post_name'    => $a['slug'] ?? '',
				'post_author'  => $a['author'] ?? get_current_user_id(),
			)
		);
		Corpus::bump();
		Graph::reset();
		Audit::flush();
		return $id;
	}

	/**
	 * Logs in a user of a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function login( $role = 'administrator' ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * REST call with a valid nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3se/v1.
	 * @param array  $params Params.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array() ) {
		$req = new WP_REST_Request( $method, '/dev3se/v1/' . ltrim( $route, '/' ) );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Forces an adapter for the test.
	 *
	 * @param string $id Adapter id.
	 */
	protected function adapter( $id ) {
		Settings_Store::update( array( 'seo_adapter' => $id ) );
		Settings_Store::flush();
		Adapters::reset();
		Audit::flush();
	}
}
