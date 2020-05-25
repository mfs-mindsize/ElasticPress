<?php // phpcs:ignore
/**
 * Test comment indexable functionality
 *
 * @package elasticpress
 */

namespace ElasticPressTest;

use ElasticPress;

/**
 * Test comment indexable class
 */
class TestComment extends BaseTestCase {
	/**
	 * Checking if HTTP request returns 404 status code.
	 *
	 * @var boolean
	 */
	public $is_404 = false;

	/**
	 * Setup each test.
	 *
	 * @since 3.5
	 */
	public function setUp() {
		global $wpdb;
		parent::setUp();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		ElasticPress\Features::factory()->activate_feature( 'comments' );
		ElasticPress\Features::factory()->setup_features();

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'comment' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'comment' )->sync_manager->sync_queue = [];

		// Need to call this since it's hooked to init.
		ElasticPress\Features::factory()->get_registered_feature( 'comments' )->search_setup();
	}

	/**
	 * Clean up after each test. Reset our mocks
	 *
	 * @since 3.5
	 */
	public function tearDown() {
		parent::tearDown();

		$this->deleteAllComments();

		// Make sure no one attached to this.
		remove_filter( 'ep_sync_comments_allow_hierarchy', array( $this, 'ep_allow_multiple_level_comments_sync' ), 100 );
		$this->fired_actions = array();
	}

	/**
	 * Deletes all comments from the database.
	 *
	 * @return void
	 */
	public function deleteAllComments() {

		foreach( get_comments() as $comment ) {
			wp_delete_comment( $comment->comment_ID, true );
		}
	}

	/**
	 * Create test comments.
	 */
	public function createComments( $number = 4, $has_child = false ) {
		$parent_comment_id = $child_comment_id = 0;
		$comments_id = [];

		$post_id = Functions\create_and_sync_post();

		for( $i = 1; $i <= $number; $i++ ) {
			$comments_id = Functions\create_and_sync_comment( 'Test comment ' . $i, $post_id );
		}

		if( $has_child ) {
			$parent_id = Functions\create_and_sync_comment( 'Test parent comment ', $post_id );
			$child_id  = Functions\create_and_sync_comment( 'Test child comment ', $post_id, $parent_id );
		}

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		return [
			'post_id'           => $post_id,
			'parent_comment_id' =>  $parent_comment_id,
			'child_comment_id'  => $child_comment_id,
			'comments_id'       => $comments_id,
		];
	}

	/**
	 * Test a simple comment sync
	 *
	 * @since 3.5
	 * @group post
	 */
	public function testCommentSync() {
		add_action(
			'ep_sync_comment_on_transition',
			function() {
				$this->fired_actions['ep_sync_comment_on_transition'] = true;
			}
		);

		$post_id = Functions\create_and_sync_post();

		$comment_id = wp_insert_comment( [
			'comment_content' => 'Test comment',
			'comment_post_ID' => $post_id,
		] );

		$this->assertEquals( 1, count( ElasticPress\Indexables::factory()->get( 'comment' )->sync_manager->sync_queue ) );

		ElasticPress\Indexables::factory()->get( 'comment' )->index( $comment_id );

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$this->assertArrayHasKey( 'ep_sync_comment_on_transition', $this->fired_actions );

		$this->assertTrue( ! empty( $this->fired_actions['ep_sync_comment_on_transition'] ) );

		$comment = ElasticPress\Indexables::factory()->get( 'comment' )->get( $comment_id );

		$this->assertnotEmpty( $comment );
	}

	/**
	 * Test a simple comment sync with meta
	 *
	 * @since 3.5
	 * @group comment
	 */
	public function testCommentSyncMeta() {
		$post_id = Functions\create_and_sync_post();

		$comment_id = wp_insert_comment( [
			'comment_content' => 'Test comment',
			'comment_post_ID' => $post_id,
		] );

		update_comment_meta( $comment_id, 'new_meta', 'test' );

		ElasticPress\Indexables::factory()->get( 'comment' )->index( $comment_id );

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$comment = ElasticPress\Indexables::factory()->get( 'comment' )->get( $comment_id );

		$this->assertEquals( 'test', $comment['meta']['new_meta'][0]['value'] );
	}

	/**
	 * Test a simple comment sync on meta update
	 *
	 * @since 3.5
	 * @group comment
	 */
	public function testCommentSyncOnMetaUpdate() {
		$post_id = Functions\create_and_sync_post();

		$comment_id = wp_insert_comment( [
			'comment_content' => 'Test comment',
			'comment_post_ID' => $post_id,
		] );

		update_comment_meta( $comment_id, 'test_key', true );

		$this->assertEquals( 1, count( ElasticPress\Indexables::factory()->get( 'comment' )->sync_manager->sync_queue ) );
		$this->assertnotEmpty( ElasticPress\Indexables::factory()->get( 'comment' )->sync_manager->add_to_queue( $comment_id ) );
	}

	/**
	 * Test comment sync kill.
	 *
	 * @since 3.5
	 * @group comment
	 */
	public function testCommentSyncKill() {
		$post_id = Functions\create_and_sync_post();

		$created_comment_id = wp_insert_comment( [
			'comment_content' => 'Test comment',
			'comment_post_ID' => $post_id,
		] );

		add_action(
			'ep_sync_comment_on_transition',
			function() {
				$this->fired_actions['ep_sync_comment_on_transition'] = true;
			}
		);

		add_filter(
			'ep_comment_sync_kill',
			function( $kill, $comment_id ) use ( $created_comment_id ) {
				if ( $created_comment_id === $comment_id ) {
					return true;
				}

				return $kill;
			},
			10,
			2
		);

		ElasticPress\Indexables::factory()->get( 'comment' )->sync_manager->action_sync_on_update( $created_comment_id );

		$this->assertArrayNotHasKey( 'ep_sync_comment_on_transition', $this->fired_actions );
	}

	/**
	 * Test a basic comment query with and without ElasticPress
	 *
	 * @since 3.3
	 * @group comment
	 */
	public function testBasicCommentQuery() {

		$this->createComments( 3 );

		// First try without ES and make sure everything is right.
		$comments = (new \WP_Comment_Query())->query( [] );

		foreach ( $comments as $comment ) {
			$this->assertTrue( empty( $comment->elasticsearch ) );
		}

		$this->assertEquals( 3, count( $comments ) );

		// Now try with Elasticsearch.
		$comments = (new \WP_Comment_Query())->query( [
			'ep_integrate' => true,
		] );

		foreach ( $comments as $comment ) {
			$this->assertTrue( $comment->elasticsearch );
		}

		$this->assertEquals( 3, count( $comments ) );

		// Test some of the filters and defaults.
		$return_2 = function() {
			return 2;
		};

		add_filter( 'ep_max_results_window', $return_2 );

		// Now try with Elasticsearch.
		$comments = (new \WP_Comment_Query())->query( [
			'ep_integrate' => true,
		] );

		$this->assertEquals( 2, count( $comments ) );

		remove_filter( 'ep_max_results_window', $return_2 );
	}

	public function testCommentQueryNumber() {
		$this->createComments();

		$comments = (new \WP_Comment_Query())->query( [
			'ep_integrate' => true,
			'number' => 2,
		] );

		foreach ( $comments as $comment ) {
			$this->assertTrue( $comment->elasticsearch );
		}

		$this->assertEquals( 2, count( $comments ) );
	}

}