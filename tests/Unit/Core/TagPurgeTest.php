<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Executor;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Layers\ApiLayer;
use PurgeKit\Layers\ProviderSpecs;
use PurgeKit\Layers\Registry;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeLayer;
use PurgeKit\Tests\Fakes\FakeStore;

final class TagPurgeTest extends TestCase {
	private function fastly( FakeHttp $http, FakeStore $settings ): ApiLayer {
		$secrets = new Secrets( new FakeStore(), 'k' );
		$secrets->set( 'FASTLY_TOKEN', 'TOKEN' );
		$settings->set( 'fastly_service_id', 'svc' );
		return new ApiLayer( ProviderSpecs::all()['fastly'], $http, $secrets, $settings );
	}

	public function test_planner_adds_tags_only_when_asked(): void {
		$expander = new class() implements UrlExpander {
			public function expand( Change $change ): array {
				return array( 'https://site.test/p/' );
			}
		};
		$change   = Change::forUrls( 'post', array(), array( 'post_id' => 7 ) );
		$off      = ( new Planner( $expander, 'https://site.test' ) )->plan( array( $change ) );
		$on       = ( new Planner( $expander, 'https://site.test', array(), array( Planner::class, 'tagsFor' ) ) )->plan( array( $change ) );
		$this->assertSame( array(), $off->tags );
		$this->assertSame( array( 'p-7' ), $on->tags );
		$this->assertSame( array( 't-3' ), Planner::tagsFor( new Change( 'term', array(), false, array( 'term_id' => 3 ) ) ) );
	}

	public function test_a_plan_with_only_tags_is_not_empty(): void {
		$this->assertFalse( ( new PurgePlan( false, array(), array( 'p-1' ) ) )->isEmpty() );
		$this->assertTrue( ( new PurgePlan( false ) )->isEmpty() );
	}

	public function test_fastly_purges_urls_then_tags_in_one_batched_call(): void {
		$http  = new FakeHttp();
		$layer = $this->fastly( $http, new FakeStore() );
		$run   = ( new Executor( new Registry( array( $layer ) ), new FakeClock() ) )->run( new PurgePlan( false, array( 'https://site.test/a/' ), array( 'p-1', 'home' ) ) );
		$this->assertSame( Run::OK, $run->status );
		$this->assertCount( 2, $http->requests );
		$tag = $http->requests[1];
		$this->assertSame( 'https://api.fastly.com/service/svc/purge', $tag['url'] );
		$this->assertSame( 'p-1 home', $tag['args']['headers']['Surrogate-Key'] );
		$this->assertArrayNotHasKey( 'Fastly-Soft-Purge', $tag['args']['headers'] );
	}

	public function test_soft_purge_adds_the_header_to_url_and_tag_purges_but_not_to_purge_all(): void {
		$http     = new FakeHttp();
		$settings = new FakeStore();
		$settings->set( 'soft_purge', true );
		$layer = $this->fastly( $http, $settings );
		$layer->purgeUrls( array( 'https://site.test/a/' ) );
		$layer->purgeTags( array( 'p-1' ) );
		$layer->purgeAll();
		$this->assertSame( '1', $http->requests[0]['args']['headers']['Fastly-Soft-Purge'] );
		$this->assertSame( '1', $http->requests[1]['args']['headers']['Fastly-Soft-Purge'] );
		$this->assertArrayNotHasKey( 'Fastly-Soft-Purge', $http->requests[2]['args']['headers'] );
	}

	public function test_tags_are_sent_in_batches_of_256(): void {
		$http  = new FakeHttp();
		$layer = $this->fastly( $http, new FakeStore() );
		$layer->purgeTags( array_map( static fn( $i ) => "p-$i", range( 1, 300 ) ) );
		$this->assertCount( 2, $http->requests );
		$this->assertCount( 256, explode( ' ', $http->requests[0]['args']['headers']['Surrogate-Key'] ) );
	}

	public function test_a_layer_without_tag_support_only_gets_the_urls(): void {
		$layer = new FakeLayer( 'plain', 10 );
		( new Executor( new Registry( array( $layer ) ), new FakeClock() ) )->run( new PurgePlan( false, array( 'https://site.test/a/' ), array( 'p-1' ) ) );
		$this->assertSame( array( array( 'urls', array( 'https://site.test/a/' ) ) ), $layer->calls );
	}
}
