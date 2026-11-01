<?php
/**
 * DEV3 AI paths with a mocked Gemini: meta, schema, FAQ, gaps, topics, embeddings links, index job.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Ai\Generator;
use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Core3\Core\Jobs;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiSeoAssistant\Links\Index;
use Dev3\AiSeoAssistant\Links\Suggester;
use Dev3\AiSeoAssistant\Service\Drafter;

class AiTest extends Dev3se_Base_Case {

	private function meta_data() {
		return array(
			'titles'             => array( 'Espresso at Home: The Complete Beginner Guide to Better Shots Every Single Morning', 'Home Espresso Guide' ),
			'descriptions'       => array( 'Learn to pull rich espresso at home: grind size, dose and timing explained simply, plus the gear that matters.' ),
			'focus_keyword'      => 'Espresso At Home',
			'secondary_keywords' => array( 'home espresso', 'espresso grind size' ),
			'og_title'           => 'Make cafe-quality espresso at home',
			'og_description'     => 'Grind, dose, time: the three things that decide your shot.',
		);
	}

	public function test_meta_drafts_are_trimmed_ranked_and_never_applied() {
		$this->login();
		$this->adapter( 'yoast' );
		$id              = $this->post();
		$this->responses = array( $this->json_response( $this->meta_data() ) );
		$res             = Drafter::meta( get_post( $id ), array( 'seo_title', 'meta_description', 'focus_keyword', 'og_title' ) );
		$this->assertIsArray( $res );
		$this->assertCount( 4, $res['drafts'] );
		$by = array_column( $res['drafts'], null, 'field' );
		$this->assertLessThanOrEqual( 60, mb_strlen( $by['seo_title']['draft'] ), 'Trimmed to the title limit' );
		$this->assertSame( array( 'Home Espresso Guide' ), $by['seo_title']['alternatives'] );
		$this->assertSame( 'espresso at home', $by['focus_keyword']['draft'], 'Yoast takes one keyword' );
		$this->assertSame( 'gemini-2.5-flash', $by['seo_title']['model'] );
		$this->assertSame( '', get_post_meta( $id, '_yoast_wpseo_title', true ), 'Nothing written until applied' );

		$body = $this->requests[0]['body'];
		$this->assertStringContainsString( 'Brewing espresso at home', $body['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 75, $body['generationConfig']['responseSchema']['properties']['titles']['items']['maxLength'] );
	}

	public function test_rank_math_gets_secondary_keywords() {
		$this->login();
		$this->adapter( 'rankmath' );
		$id              = $this->post();
		$this->responses = array( $this->json_response( $this->meta_data() ) );
		$res             = Drafter::meta( get_post( $id ), array( 'focus_keyword' ) );
		$this->assertSame( 'espresso at home, home espresso, espresso grind size', $res['drafts'][0]['draft'] );
	}

	public function test_only_empty_and_unchanged_values_are_skipped() {
		$this->login();
		$this->adapter( 'yoast' );
		$id = $this->post();
		update_post_meta( $id, '_yoast_wpseo_title', 'Hand written' );
		update_post_meta( $id, '_yoast_wpseo_metadesc', 'Learn to pull rich espresso at home: grind size, dose and timing explained simply, plus the gear that matters.' );
		$this->responses = array( $this->json_response( $this->meta_data() ) );
		$res             = Drafter::meta( get_post( $id ), array( 'seo_title', 'meta_description', 'focus_keyword' ), array( 'only_empty' => true ) );
		$this->assertSame( array( 'focus_keyword' ), array_column( $res['drafts'], 'field' ) );
		$this->assertContains( 'seo_title', $res['skipped'] );
		$this->assertContains( 'meta_description', $res['skipped'] );
	}

	public function test_basic_mode_makes_no_ai_call_and_works_without_a_key() {
		$this->login();
		Settings_Store::update( array( 'api_key' => '' ) );
		Settings_Store::flush();
		$id  = $this->post();
		$res = Drafter::meta( get_post( $id ), array( 'seo_title', 'meta_description', 'focus_keyword', 'schema' ), array( 'mode' => 'basic' ) );
		$this->assertCount( 0, $this->requests );
		$fields = array_column( $res['drafts'], 'field' );
		$this->assertContains( 'seo_title', $fields );
		$this->assertContains( 'schema_type', $fields );
		$this->assertSame( 'basic', $res['drafts'][0]['source'] );
	}

	public function test_ai_errors_are_returned_not_swallowed() {
		$this->login();
		Settings_Store::update( array( 'ai_enabled' => false ) );
		Settings_Store::flush();
		$err = Drafter::meta( get_post( $this->post() ), array( 'seo_title' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'dev3se_ai_disabled', $err->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_schema_suggestion_is_validated() {
		$this->login();
		$this->adapter( 'yoast' );
		$id              = $this->post();
		$this->responses = array(
			$this->json_response(
				array(
					'main_type'   => 'TechArticle',
					'pieces_json' => '```json [{"@context":"https://schema.org","@type":"HowTo","name":"Brew espresso","step":[{"@type":"HowToStep","text":"Grind"}]}] ```',
					'reason'      => 'Step-by-step guide.',
				)
			),
		);
		$res = Drafter::meta( get_post( $id ), array( 'schema' ) );
		$by  = array_column( $res['drafts'], null, 'field' );
		$this->assertSame( 'TechArticle', $by['schema_type']['draft'] );
		$pieces = json_decode( $by['schema_jsonld']['draft'], true );
		$this->assertSame( 'HowTo', $pieces[0]['@type'] );
		$this->assertArrayNotHasKey( '@context', $pieces[0] );
		$this->assertSame( 'Step-by-step guide.', $by['schema_jsonld']['note'] );
		$this->assertSame( 2048, $this->requests[0]['body']['generationConfig']['thinkingConfig']['thinkingBudget'], 'Heavy profile' );

		$this->responses = array(
			$this->json_response(
				array(
					'main_type'   => 'Spaceship',
					'pieces_json' => 'not json',
					'reason'      => 'x',
				)
			),
		);
		\Dev3\AiSeoAssistant\Core3\Core\Cache::flush();
		\Dev3\AiSeoAssistant\Core3\Core\Cache::reset_memo();
		$res = Generator::schema( get_post( $id ) );
		$this->assertSame( \Dev3\AiSeoAssistant\Content\Basic::schema_type( get_post( $id ) ), $res['type'], 'Invalid type falls back to the heuristic' );
		$this->assertSame( '', $res['jsonld'] );
	}

	public function test_faq_gaps_and_topics() {
		$this->login();
		$id              = $this->post();
		$this->responses = array(
			$this->json_response(
				array(
					'items' => array(
						array(
							'question' => 'What grind for espresso?',
							'answer'   => '<b>Fine</b>, like salt.',
						),
						array(
							'question' => '',
							'answer'   => 'dropped',
						),
					),
				)
			),
		);
		$faq = Generator::faq( get_post( $id ), 3 );
		$this->assertSame( array( array( 'question' => 'What grind for espresso?', 'answer' => 'Fine, like salt.' ) ), $faq['items'] );

		$this->responses = array(
			$this->json_response(
				array(
					'coverage_score'    => 140,
					'summary'           => 'Covers basics.',
					'missing_subtopics' => array( array( 'topic' => 'Dialing in', 'why' => 'Core skill' ) ),
					'questions'         => array( 'How long should a shot take?' ),
					'terms'             => array( 'crema' ),
					'outline'           => array(
						array(
							'level'   => 2,
							'heading' => 'Dialing in',
							'notes'   => 'Adjust grind.',
						),
						array(
							'level'   => 5,
							'heading' => 'Odd level',
						),
					),
				)
			),
		);
		$gaps = Generator::gaps( get_post( $id ), 'espresso at home' );
		$this->assertSame( 100, $gaps['score'], 'Clamped' );
		$this->assertSame( 'espresso at home', $gaps['keyword'] );
		$this->assertSame( 2, $gaps['outline'][1]['level'], 'Levels normalised to H2/H3' );
		$this->assertFalse( $gaps['outline'][0]['exists'] );

		$this->responses = array(
			$this->json_response(
				array(
					'topics' => array(
						array(
							'title'     => 'Best beans for espresso',
							'keyword'   => 'Espresso Beans',
							'why'       => 'Missing cluster page.',
							'link_from' => array( $id, 999999 ),
						),
					),
				)
			),
		);
		$topics = Generator::topics( Audit::rows() );
		$this->assertSame( 'espresso beans', $topics['topics'][0]['keyword'] );
		$this->assertSame( array( $id ), array_column( $topics['topics'][0]['link_from'], 'id' ), 'Unknown ids dropped' );
	}

	public function test_index_job_embeds_posts_and_suggester_uses_verified_ai_anchors() {
		$this->login();
		$this->adapter( 'yoast' );
		$grinder = $this->post( array( 'title' => 'Burr grinder guide', 'content' => '<p>All about burr grinders for espresso.</p>' ) );
		$milk    = $this->post( array( 'title' => 'Steam milk for lattes', 'content' => '<p>Steaming milk for lattes.</p>' ) );
		$source  = $this->post( array( 'title' => 'Espresso at home', 'content' => '<p>A good burr grinder matters more than the machine.</p><p>Later you can steam milk for a latte.</p>' ) );
		// Vectors: grinder-ish texts point one way, milk-ish another.
		$vec = static function ( $text ) {
			$g = substr_count( strtolower( $text ), 'grinder' );
			$m = substr_count( strtolower( $text ), 'milk' );
			return array( 0.1 + $g, 0.1 + $m, 0.05 );
		};
		$this->responses = array( $this->embed_response( $vec ) );
		$job             = Index::start();
		$this->assertSame( 3, $job['total'] );
		Jobs::run( $job['id'], 20 );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertSame( 3, Embeddings_Store::instance()->count( 'post' ) );
		$this->assertTrue( Index::start() instanceof WP_Error, 'Fresh index has nothing to do' );

		Settings_Store::update( array( 'link_min_score' => 0.1 ) );
		Settings_Store::flush();
		$this->responses = array(
			$this->json_response(
				array(
					'links' => array(
						array(
							'target_id' => $grinder,
							'paragraph' => 0,
							'anchor'    => 'good burr grinder',
							'reason'    => 'Explains grinders.',
						),
						array(
							'target_id' => $milk,
							'paragraph' => 1,
							'anchor'    => 'invented phrase',
						),
					),
				)
			),
		);
		$res = Suggester::suggest( get_post( $source ), 5, true );
		$this->assertSame( 'embeddings', $res['method'] );
		$by = array_column( $res['suggestions'], null, 'target_id' );
		$this->assertSame( 'good burr grinder', $by[ $grinder ]['anchor'] );
		$this->assertSame( 'Explains grinders.', $by[ $grinder ]['reason'] );
		$this->assertSame( 'steam milk', strtolower( $by[ $milk ]['anchor'] ), 'Invented anchor replaced by a lexical match from the title' );
		$this->assertArrayNotHasKey( $source, $by, 'Never links to itself' );
	}

	public function test_lexical_links_without_ai_skip_already_linked_targets() {
		$this->login();
		Settings_Store::update( array( 'api_key' => '' ) );
		Settings_Store::flush();
		$grinder = $this->post( array( 'title' => 'Burr grinder guide', 'content' => '<p>All about burr grinder choice for espresso shots.</p>' ) );
		$milk    = $this->post( array( 'title' => 'Steam milk like a barista', 'content' => '<p>Steam milk for a latte with microfoam.</p>' ) );
		$source  = $this->post( array( 'title' => 'Espresso at home', 'content' => '<p>Buy a burr grinder first.</p><p>Then <a href="' . get_permalink( $milk ) . '">steam milk</a> for a latte.</p>' ) );
		$res     = Suggester::suggest( get_post( $source ), 5, true );
		$this->assertSame( 'lexical', $res['method'] );
		$this->assertCount( 0, $this->requests );
		$ids = array_column( $res['suggestions'], 'target_id' );
		$this->assertContains( $grinder, $ids );
		$this->assertNotContains( $milk, $ids, 'Already linked' );
		$this->assertSame( 'burr grinder', strtolower( $res['suggestions'][0]['anchor'] ) );
	}
}
