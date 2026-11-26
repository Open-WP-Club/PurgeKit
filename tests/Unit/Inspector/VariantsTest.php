<?php
namespace PurgeKit\Tests\Unit\Inspector;

use PHPUnit\Framework\TestCase;
use PurgeKit\Inspector\Variants;
use PurgeKit\Inspector\Inspector;
use PurgeKit\Inspector\Cacheability;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHttp;

final class VariantsTest extends TestCase {
	/** @dataProvider invalidVariants */
	public function test_invalid_device_and_language_are_rejected( string $device, string $language ): void {
		$this->expectException( \InvalidArgumentException::class );
		Variants::headers( $device, $language );
	}

	public function invalidVariants(): array {
		return array( array( 'bot', '' ), array( 'desktop', "bg\r\nCookie: secret" ), array( 'mobile', 'bg,en' ), array( 'desktop', str_repeat( 'a-', 30 ) . 'bg' ) );
	}

	public function test_both_requests_use_identical_variant_headers_and_keep_query_parameters(): void {
		$http = new FakeHttp();
		$headers = Variants::headers( 'mobile', 'bg-BG' );
		$i = new Inspector( $http, new Registry( array() ), new FakeClock() );
		$i->inspect( 'https://site.test/bg/?currency=BGN', $headers );
		$c = new Cacheability( $http, new Registry( array() ) );
		$c->check( 'https://site.test/bg/?currency=BGN', $headers );
		$this->assertCount( 4, $http->requests );
		foreach ( $http->requests as $r ) {
			$this->assertSame( Variants::MOBILE, $r['args']['headers']['User-Agent'] );
			$this->assertSame( 'bg-BG', $r['args']['headers']['Accept-Language'] );
			$this->assertSame( 0, $r['args']['redirection'] );
			$this->assertStringContainsString( 'currency=BGN', $r['url'] );
			$this->assertArrayNotHasKey( 'Cookie', $r['args']['headers'] );
		}
		$this->assertSame( array( 'User-Agent' => Variants::DESKTOP ), Variants::headers( 'desktop', '' ) );
	}
}
