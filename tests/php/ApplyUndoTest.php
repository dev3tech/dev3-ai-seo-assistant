<?php
/**
 * Drafts → apply per field (conflict check) → history → undo (single, batch, forced), content drafts.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Data\History;
use Dev3\AiSeoAssistant\Seo\Schema_Output;
use Dev3\AiSeoAssistant\Service\Applier;
use Dev3\AiSeoAssistant\Service\Drafter;

class ApplyUndoTest extends Dev3se_Base_Case {

	private function draft( $post_id, $field, $value, $original = '' ) {
		return Drafts::create(
			array(
				'post_id'  => $post_id,
				'field'    => $field,
				'adapter'  => 'yoast',
				'original' => $original,
				'draft'    => $value,
			)
		);
	}

	public function test_apply_writes_the_field_and_logs_history() {
		$this->login();
		$this->adapter( 'yoast' );
		$id    = $this->post();
		$draft = $this->draft( $id, 'meta_description', 'A fresh description written by DEV3 AI.' );
		$this->assertSame( '', get_post_meta( $id, '_yoast_wpseo_metadesc', true ), 'Drafts change nothing' );

		$res = Applier::apply( $draft, 'Edited: a fresh description.' );
		$this->assertIsArray( $res );
		$this->assertSame( 'Edited: a fresh description.', get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'applied', Drafts::get( $draft )['status'] );
		$this->assertSame( 'Edited: a fresh description.', Drafts::get( $draft )['draft'], 'The applied (edited) value is kept' );
		$this->assertSame( '', $res['history']['before'] );
		$this->assertSame( 'Edited: a fresh description.', $res['history']['after'] );
		$this->assertSame( 'yoast', $res['history']['adapter'] );
		$this->assertWPError( Applier::apply( $draft ), 'A draft applies once' );
	}

	public function test_conflict_blocks_apply_unless_forced() {
		$this->login();
		$this->adapter( 'yoast' );
		$id = $this->post();
		update_post_meta( $id, '_yoast_wpseo_title', 'Human title' );
		$draft = $this->draft( $id, 'seo_title', 'AI title', '' );
		$err   = Applier::apply( $draft );
		$this->assertWPError( $err );
		$this->assertSame( 'dev3se_conflict', $err->get_error_code() );
		$this->assertSame( 409, $err->get_error_data()['status'] );
		$this->assertSame( 'Human title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$ok = Applier::apply( $draft, null, true );
		$this->assertIsArray( $ok );
		$this->assertSame( 'AI title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Human title', $ok['history']['before'] );
	}

	public function test_undo_restores_deletes_and_detects_newer_edits() {
		$this->login();
		$this->adapter( 'rankmath' );
		$id  = $this->post();
		$res = Applier::apply( $this->draft( $id, 'seo_title', 'RM AI title' ) );
		$this->assertSame( 'RM AI title', get_post_meta( $id, 'rank_math_title', true ) );
		$undone = Applier::undo( $res['history']['id'] );
		$this->assertIsArray( $undone );
		$this->assertNotNull( $undone['undone_at'] );
		$this->assertFalse( metadata_exists( 'post', $id, 'rank_math_title' ), 'Undo of a first value deletes the key' );
		$this->assertWPError( Applier::undo( $res['history']['id'] ), 'Undo runs once' );

		$res2 = Applier::apply( $this->draft( $id, 'seo_title', 'Second AI title' ) );
		update_post_meta( $id, 'rank_math_title', 'Edited later' );
		$err = Applier::undo( $res2['history']['id'] );
		$this->assertSame( 'dev3se_conflict', $err->get_error_code() );
		$this->assertSame( 'Edited later', get_post_meta( $id, 'rank_math_title', true ) );
		Applier::undo( $res2['history']['id'], true );
		$this->assertFalse( metadata_exists( 'post', $id, 'rank_math_title' ) );
	}

	public function test_undo_uses_the_adapter_that_was_written_even_after_switching() {
		$this->login();
		$this->adapter( 'yoast' );
		$id  = $this->post();
		$res = Applier::apply( $this->draft( $id, 'focus_keyword', 'espresso' ) );
		$this->adapter( 'rankmath' );
		Applier::undo( $res['history']['id'] );
		$this->assertFalse( metadata_exists( 'post', $id, '_yoast_wpseo_focuskw' ) );
	}

	public function test_batch_undo_skips_conflicts() {
		$this->login();
		$this->adapter( 'yoast' );
		$a = $this->post();
		$b = $this->post( array( 'title' => 'Second post' ) );
		Applier::apply( $this->draft( $a, 'seo_title', 'A title' ), null, false, 'run-5' );
		Applier::apply( $this->draft( $b, 'seo_title', 'B title' ), null, false, 'run-5' );
		update_post_meta( $b, '_yoast_wpseo_title', 'B edited' );
		$res = Applier::undo_batch( 'run-5' );
		$this->assertSame( 1, $res['undone'] );
		$this->assertCount( 1, $res['skipped'] );
		$this->assertSame( '', get_post_meta( $a, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'B edited', get_post_meta( $b, '_yoast_wpseo_title', true ) );
		$this->assertSame( 1, Applier::undo_batch( 'run-5', true )['undone'] );
	}

	public function test_new_draft_supersedes_older_pending_draft_and_reject() {
		$this->login();
		$id  = $this->post();
		$one = $this->draft( $id, 'seo_title', 'One' );
		$two = $this->draft( $id, 'seo_title', 'Two' );
		$this->assertSame( 'superseded', Drafts::get( $one )['status'] );
		$this->assertSame( 'pending', Drafts::get( $two )['status'] );
		$this->assertSame( 'rejected', Applier::reject( $two )['status'] );
		$this->assertWPError( Applier::reject( $two ) );
	}

	public function test_faq_content_draft_applies_and_undoes() {
		$this->login();
		$this->adapter( 'native' );
		$id    = $this->post();
		$draft = Drafter::faq(
			get_post( $id ),
			array(
				array(
					'question' => 'Do I need a grinder?',
					'answer'   => 'Yes, a burr grinder.',
				),
			)
		);
		$this->assertSame( 'content', $draft['field'] );
		$this->assertStringContainsString( 'wp:details', $draft['draft'] );
		$this->assertStringNotContainsString( 'wp:details', get_post( $id )->post_content );
		$res = Applier::apply( $draft['id'] );
		$this->assertIsArray( $res );
		$this->assertStringContainsString( 'Do I need a grinder?', get_post( $id )->post_content );
		$this->assertCount( 1, Schema_Output::faq( $id ), 'FAQ items stored for the schema' );
		Applier::undo( $res['history']['id'] );
		$this->assertStringNotContainsString( 'Do I need a grinder?', get_post( $id )->post_content );
	}

	public function test_faq_replaces_an_existing_seo_plugin_faq_block() {
		$this->login();
		$this->adapter( 'yoast' );
		$id    = $this->post( array( 'content' => "<p>Intro</p>\n\n<!-- wp:yoast/faq-block {\"questions\":[]} -->\n<div class=\"schema-faq wp-block-yoast-faq-block\"></div>\n<!-- /wp:yoast/faq-block -->\n" ) );
		$draft = Drafter::faq(
			get_post( $id ),
			array(
				array(
					'question' => 'Why $5 grinders fail?',
					'answer'   => 'Blades heat beans.',
				),
			)
		);
		$this->assertSame( 1, substr_count( $draft['draft'], '<!-- wp:yoast/faq-block' ) );
		$this->assertStringContainsString( 'Why $5 grinders fail?', $draft['draft'] );
	}

	public function test_history_query_filters() {
		$this->login();
		$id = $this->post();
		Applier::apply( $this->draft( $id, 'seo_title', 'X' ), null, false, 'run-9' );
		$this->assertSame( 1, History::query( array( 'batch' => 'run-9' ) )['total'] );
		$this->assertSame( 0, History::query( array( 'batch' => 'run-1' ) )['total'] );
		$this->assertSame( 1, History::query( array( 'post_id' => $id ) )['total'] );
	}
}
