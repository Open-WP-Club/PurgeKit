<?php
namespace PurgeKit\Tests\Integration\Detect;

use PurgeKit\Core\Change;
use PurgeKit\Detect\PostUrlExpander;

final class PostUrlExpanderTest extends \WP_UnitTestCase {
	public function test_post_expands_to_permalink_home_term_author_date_and_feed(): void {
		$cat  = self::factory()->category->create();
		$id   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );
		$urls = ( new PostUrlExpander() )->expand( Change::forUrls( 'post', array(), array( 'post_id' => $id ) ) );
		$this->assertContains( get_permalink( $id ), $urls );
		$this->assertContains( home_url( '/' ), $urls );
		$this->assertContains( get_category_link( $cat ), $urls );
		$this->assertContains( get_feed_link(), $urls );
		$this->assertContains( get_author_posts_url( (int) get_post( $id )->post_author ), $urls );
		$this->assertSame( $urls, array_values( array_unique( $urls ) ) );
	}

	public function test_filter_can_add_urls(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_filter( 'purgekit_post_urls', static fn( $u ) => array_merge( $u, array( 'https://example.org/extra/' ) ) );
		$this->assertContains( 'https://example.org/extra/', ( new PostUrlExpander() )->expand( Change::forUrls( 'post', array(), array( 'post_id' => $id ) ) ) );
	}

	public function test_unknown_post_yields_nothing(): void {
		$this->assertSame( array(), ( new PostUrlExpander() )->expand( Change::forUrls( 'post', array(), array( 'post_id' => 999999 ) ) ) );
	}
}
