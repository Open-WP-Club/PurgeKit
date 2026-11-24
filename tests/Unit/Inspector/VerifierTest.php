<?php
namespace PurgeKit\Tests\Unit\Inspector;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Inspector\Inspector;
use PurgeKit\Inspector\Verifier;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeLayer;

final class VerifierTest extends TestCase {
	private FakeHttp $http;

	private function verifier( ?string $served = null ): Verifier {
		$this->http = new FakeHttp();
		$layer      = new FakeLayer( 'cloudflare', 50 );
		$layer->served = $served;
		return new Verifier( new Inspector( $this->http, new Registry( array( $layer ) ), new FakeClock(), 5 ), 'https://site.test' );
	}

	private function run_for( PurgePlan $plan ): Run {
		return new Run( Run::OK, array(), $plan );
	}

	public function test_everything_plan_checks_only_the_home_page(): void {
		$v   = $this->verifier();
		$out = $v->verify( $this->run_for( PurgePlan::everything() ) );
		$this->assertSame( Verifier::VERIFIED, $out['status'] );
		$this->assertSame( array( 'https://site.test/' ), array_column( $out['checked'], 'url' ) );
		$this->assertCount( 2, $this->http->requests, 'Visitor plus cache-busted request.' );
		$this->assertSame( 5, $this->http->requests[0]['args']['timeout'] );
		$this->assertStringContainsString( 'purgekit_nocache=', $this->http->requests[1]['url'] );
	}

	public function test_checks_home_plus_at_most_two_purged_own_urls(): void {
		$v   = $this->verifier();
		$out = $v->verify(
			$this->run_for(
				PurgePlan::forUrls( array( 'https://evil.test/x', 'https://site.test/', 'https://site.test/a', 'ftp://site.test/z', 'https://site.test/b', 'https://site.test/c' ) )
			)
		);
		$this->assertSame( array( 'https://site.test/', 'https://site.test/a', 'https://site.test/b' ), array_column( $out['checked'], 'url' ) );
		$this->assertCount( 6, $this->http->requests );
	}

	public function test_names_the_layer_that_still_serves_a_stale_copy(): void {
		$v = $this->verifier( 'Cloudflare edge cache (HIT)' );
		$this->http->queue( new HttpResponse( 200, 'old' ), new HttpResponse( 200, 'new' ) );
		$out = $v->verify( $this->run_for( PurgePlan::everything() ) );
		$this->assertSame( Verifier::STALE, $out['status'] );
		$this->assertSame( 'cloudflare', $out['layer'] );
		$this->assertStringContainsString( 'CLOUDFLARE still serves a stale copy of https://site.test/', $out['message'] );
	}

	public function test_unreachable_pages_are_reported_as_unchecked(): void {
		$v = $this->verifier();
		$this->http->queue( new HttpException( 'timeout' ) );
		$out = $v->verify( $this->run_for( PurgePlan::everything() ) );
		$this->assertSame( Verifier::UNCHECKED, $out['status'] );
		$this->assertStringContainsString( 'Could not check', $out['message'] );
	}

	public function test_one_reachable_page_is_enough_to_verify(): void {
		$v = $this->verifier();
		$this->http->queue( new HttpException( 'timeout' ), new HttpResponse( 200, 'a' ), new HttpResponse( 200, 'a' ) );
		$out = $v->verify( $this->run_for( PurgePlan::forUrls( array( 'https://site.test/a' ) ) ) );
		$this->assertSame( Verifier::VERIFIED, $out['status'] );
		$this->assertSame( 'Verified 1 of 2 pages.', $out['message'] );
	}
}
