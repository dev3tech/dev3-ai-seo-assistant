<?php
/**
 * REST permissions, per-post endpoints, review queue, bulk jobs, reports.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Core3\Core\Jobs;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Data\History;

class RestTest extends Dev3se_Base_Case {

	public function test_permissions_nonce_capabilities_and_post_ownership() {
		$admin = $this->login();
		$id    = $this->post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( array( 'GET', 'status' ), array( 'GET', "posts/{$id}" ), array( 'POST', "posts/{$id}/meta" ), array( 'GET', 'drafts' ), array( 'POST', 'bulk' ) ) as $r ) {
			$this->assertSame( 403, $this->rest( $r[0], $r[1] )->get_status(), implode( ' ', $r ) . ' blocks subscribers' );
		}
		$author = $this->login( 'author' );
		$this->assertSame( 403, $this->rest( 'GET', "posts/{$id}" )->get_status(), 'Authors cannot open others’ posts' );
		$own = $this->post( array( 'author' => $author ) );
		$this->assertSame( 200, $this->rest( 'GET', "posts/{$own}" )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', 'posts' )->get_status(), 'Bulk list is for managers' );
		$this->assertSame( 403, $this->rest( 'GET', 'drafts' )->get_status(), 'Authors must scope the queue to a post' );
		$this->assertSame( 200, $this->rest( 'GET', 'drafts', array( 'post_id' => $own ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', 'history', array( 'post_id' => $id ) )->get_status() );

		wp_set_current_user( $admin );
		$req = new WP_REST_Request( 'GET', '/dev3se/v1/posts/' . $id );
		$this->assertSame( 403, rest_do_request( $req )->get_status(), 'Missing nonce is rejected' );
		$this->assertSame( 404, $this->rest( 'GET', 'posts/999999' )->get_status() );
		$attachment = self::factory()->attachment->create_object( 'x.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertSame( 400, $this->rest( 'GET', "posts/{$attachment}" )->get_status(), 'Disabled post types are refused' );
	}

	public function test_snapshot_and_meta_endpoint_create_drafts_then_apply_and_undo() {
		$this->login();
		$this->adapter( 'yoast' );
		$id   = $this->post();
		$snap = $this->rest( 'GET', "posts/{$id}" )->get_data();
		$this->assertSame( 'yoast', $snap['adapter']['id'] );
		$this->assertSame( 'yoast/faq-block', $snap['adapter']['faqBlock'] );
		$this->assertArrayHasKey( 'checks', $snap );
		$this->assertSame( array(), $snap['pending'] );

		$this->responses = array(
			$this->json_response(
				array(
					'titles'         => array( 'Espresso at Home Guide' ),
					'descriptions'   => array( 'Everything you need to brew espresso at home, from grind size to timing, in one friendly beginner guide.' ),
					'focus_keyword'  => 'espresso at home',
					'og_title'       => 'x',
					'og_description' => 'y',
				)
			),
		);
		$res = $this->rest( 'POST', "posts/{$id}/meta", array( 'fields' => array( 'seo_title', 'meta_description' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 2, $res->get_data()['drafts'] );
		$this->assertCount( 2, $this->rest( 'GET', "posts/{$id}" )->get_data()['pending'] );

		$draft = $res->get_data()['drafts'][0];
		$ok    = $this->rest( 'POST', "drafts/{$draft['id']}/apply", array( 'value' => 'Espresso at Home: Guide' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'Espresso at Home: Guide', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$again = $this->rest( 'POST', "drafts/{$draft['id']}/apply" );
		$this->assertSame( 409, $again->get_status() );

		$hist = $this->rest( 'GET', 'history', array( 'post_id' => $id ) )->get_data();
		$this->assertSame( 1, $hist['total'] );
		$undo = $this->rest( 'POST', "history/{$hist['items'][0]['id']}/undo" );
		$this->assertSame( 200, $undo->get_status() );
		$this->assertSame( '', get_post_meta( $id, '_yoast_wpseo_title', true ) );

		$reject = $this->rest( 'POST', "drafts/{$res->get_data()['drafts'][1]['id']}/reject" );
		$this->assertSame( 'rejected', $reject->get_data()['status'] );
	}

	public function test_conflict_returns_409_and_force_applies() {
		$this->login();
		$this->adapter( 'rankmath' );
		$id    = $this->post();
		$draft = Drafts::create(
			array(
				'post_id'  => $id,
				'field'    => 'meta_description',
				'original' => '',
				'draft'    => 'AI description',
			)
		);
		update_post_meta( $id, 'rank_math_description', 'Typed by a human' );
		$res = $this->rest( 'POST', "drafts/{$draft}/apply" );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'dev3se_conflict', $res->get_data()['code'] );
		$this->assertSame( 200, $this->rest( 'POST', "drafts/{$draft}/apply", array( 'force' => true ) )->get_status() );
		$this->assertSame( 'AI description', get_post_meta( $id, 'rank_math_description', true ) );
	}

	public function test_bulk_job_creates_drafts_only_then_apply_run_and_batch_undo() {
		$this->login();
		$this->adapter( 'yoast' );
		$ids = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$ids[] = $this->post( array( 'title' => "Espresso tip number {$i}" ) );
		}
		Settings_Store::update( array( 'api_key' => '' ) );
		Settings_Store::flush();
		$res = $this->rest(
			'POST',
			'bulk',
			array(
				'filter' => array( 'search' => 'espresso tip' ),
				'fields' => array( 'seo_title', 'meta_description', 'focus_keyword' ),
				'mode'   => 'basic',
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$job = $res->get_data();
		$this->assertSame( 20, $job['total'] );
		Jobs::run( $job['id'], 30 );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertCount( 0, $this->requests, 'Basic mode makes no AI call' );
		$queue = Drafts::query(
			array(
				'job_id'   => $job['id'],
				'per_page' => 100,
			)
		);
		$this->assertSame( 60, $queue['total'] );
		foreach ( $ids as $id ) {
			$this->assertSame( '', get_post_meta( $id, '_yoast_wpseo_title', true ), 'Bulk never writes before review' );
		}

		$runs = $this->rest( 'GET', 'runs' )->get_data();
		$this->assertSame( $job['id'], $runs['runs'][0]['job_id'] );
		$this->assertSame( 60, $runs['runs'][0]['pending'] );

		$apply = $this->rest( 'POST', 'drafts/apply-run', array( 'job_id' => $job['id'] ) )->get_data();
		Jobs::run( $apply['id'], 30 );
		$this->assertSame( 'done', Jobs::get( $apply['id'] )['status'] );
		$this->assertNotSame( '', get_post_meta( $ids[0], '_yoast_wpseo_title', true ) );
		$this->assertSame( 60, History::query( array( 'batch' => 'run-' . $job['id'] ) )['total'] );

		$undo = $this->rest( 'POST', 'history/batch/undo', array( 'batch' => 'run-' . $job['id'] ) )->get_data();
		$this->assertSame( 60, $undo['undone'] );
		$this->assertSame( '', get_post_meta( $ids[0], '_yoast_wpseo_title', true ) );
	}

	public function test_bulk_ai_run_with_mocked_gemini() {
		$this->login();
		$this->adapter( 'rankmath' );
		$a               = $this->post( array( 'title' => 'Alpha espresso' ) );
		$b               = $this->post( array( 'title' => 'Beta espresso' ) );
		$this->responses = array(
			$this->json_response(
				array(
					'titles'         => array( 'AI title' ),
					'descriptions'   => array( 'AI description that is long enough to be useful for a search snippet in results.' ),
					'focus_keyword'  => 'espresso',
					'og_title'       => 'x',
					'og_description' => 'y',
				)
			),
		);
		$job = $this->rest( 'POST', 'bulk', array( 'ids' => array( $a, $b ), 'fields' => array( 'seo_title' ), 'mode' => 'ai' ) )->get_data();
		Jobs::run( $job['id'], 30 );
		$this->assertSame( 2, Drafts::query( array( 'job_id' => $job['id'] ) )['total'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_bulk_validation_and_drafts_bulk_endpoint() {
		$this->login();
		$this->assertSame( 400, $this->rest( 'POST', 'bulk', array( 'ids' => array(), 'fields' => array( 'seo_title' ) ) )->get_status() );
		$id = $this->post();
		$this->assertSame( 400, $this->rest( 'POST', 'bulk', array( 'ids' => array( $id ), 'fields' => array( 'bogus' ) ) )->get_status() );
		$d1  = Drafts::create( array( 'post_id' => $id, 'field' => 'seo_title', 'draft' => 'T' ) );
		$d2  = Drafts::create( array( 'post_id' => $id, 'field' => 'focus_keyword', 'draft' => 'k' ) );
		$res = $this->rest( 'POST', 'drafts/bulk', array( 'ids' => array( $d1, $d2, 999 ), 'action' => 'apply' ) )->get_data();
		$this->assertSame( 2, $res['done'] );
		$this->assertCount( 1, $res['failed'] );
		$this->assertStringStartsWith( 'bulk-', $res['batch'] );
	}

	public function test_links_faq_gaps_endpoints() {
		$this->login();
		$this->adapter( 'yoast' );
		Settings_Store::update( array( 'api_key' => '' ) );
		Settings_Store::flush();
		$target = $this->post( array( 'title' => 'Burr grinder guide', 'content' => '<p>About the burr grinder and espresso.</p>' ) );
		$source = $this->post( array( 'title' => 'Espresso basics', 'content' => '<p>Buy a burr grinder first, it matters for espresso.</p><h2>Is espresso strong?</h2><p>Yes, per ounce.</p>' ) );

		$links = $this->rest( 'POST', "posts/{$source}/links", array( 'mode' => 'basic' ) )->get_data();
		$this->assertSame( 'lexical', $links['method'] );
		$s     = $links['suggestions'][0];
		$draft = $this->rest( 'POST', "posts/{$source}/links/draft", array( 'links' => array( $s ) ) );
		$this->assertSame( 200, $draft->get_status() );
		$this->assertStringContainsString( 'href="' . get_permalink( $target ) . '"', $draft->get_data()['draft'] );
		$bad = $this->rest( 'POST', "posts/{$source}/links/draft", array( 'links' => array( array( 'paragraph' => 0, 'anchor' => 'x', 'url' => get_permalink( $source ) ) ) ) );
		$this->assertSame( 400, $bad->get_status(), 'Self links are refused' );

		$faq = $this->rest( 'POST', "posts/{$source}/faq", array( 'mode' => 'basic' ) )->get_data();
		$this->assertSame( 'yoast/faq-block', $faq['block'] );
		$this->assertSame( 'Is espresso strong?', $faq['items'][0]['question'] );
		$this->assertStringContainsString( '<!-- wp:yoast/faq-block', $faq['html'] );
		$this->assertSame( 200, $this->rest( 'POST', "posts/{$source}/faq/meta", array( 'items' => $faq['items'] ) )->get_status() );
		$this->assertNotEmpty( get_post_meta( $source, '_dev3se_faq', true ) );

		$gaps = $this->rest( 'POST', "posts/{$source}/gaps" );
		$this->assertSame( 400, $gaps->get_status(), 'No key: gaps explain the missing key' );
		$this->assertSame( 'dev3se_no_api_key', $gaps->get_data()['code'] );
	}

	public function test_status_report_index_and_link_report() {
		$this->login();
		$this->post();
		$status = $this->rest( 'GET', 'status' )->get_data();
		$this->assertSame( 'yoast', $status['detected'] );
		$this->assertTrue( $status['keySet'] );
		$this->assertCount( 5, $status['adapters'] );
		$report = $this->rest( 'GET', 'report' )->get_data();
		$this->assertSame( 1, $report['total'] );
		$this->assertArrayHasKey( 'no_description', $report['counts'] );
		$index = $this->rest( 'GET', 'index' )->get_data();
		$this->assertSame( 1, $index['total'] );
		$this->assertSame( 1, $index['stale'] );
		$lr = $this->rest( 'GET', 'links/report', array( 'filter' => 'orphans' ) )->get_data();
		$this->assertSame( 1, $lr['orphans'] );
		$list = $this->rest( 'GET', 'posts', array( 'issue' => 'no_keyword' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertArrayHasKey( 'editUrl', $list['items'][0] );
	}

	public function test_settings_sanitising() {
		$this->login();
		$res = $this->rest(
			'POST',
			'settings',
			array(
				'seo_adapter'     => 'nope',
				'title_max'       => 500,
				'description_min' => 200,
				'description_max' => 150,
				'post_types'      => array( 'post', 'not_a_type' ),
				'link_min_score'  => 5,
				'tone'            => 'friendly',
			)
		)->get_data();
		$this->assertSame( 'auto', $res['seo_adapter'] );
		$this->assertSame( 80, $res['title_max'] );
		$this->assertSame( 150, $res['description_max'] );
		$this->assertLessThan( 150, $res['description_min'] );
		$this->assertSame( array( 'post' ), $res['post_types'] );
		$this->assertSame( 0.95, $res['link_min_score'] );
		$this->assertSame( 'friendly', $res['tone'] );
	}
}
