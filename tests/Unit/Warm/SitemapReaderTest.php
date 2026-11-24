<?php
namespace PurgeKit\Tests\Unit\Warm;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Warm\SitemapReader;

final class SitemapReaderTest extends TestCase {
	private const NS = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

	private FakeHttp $http;

	private function reader(): SitemapReader {
		$this->http = new FakeHttp();
		return new SitemapReader( $this->http, 'https://site.test' );
	}

	private function urlset( string ...$locs ): HttpResponse {
		return new HttpResponse( 200, '<?xml version="1.0"?><urlset ' . self::NS . '>' . implode( '', array_map( static fn( $l ) => "<url><loc>$l</loc></url>", $locs ) ) . '</urlset>' );
	}

	private function index( string ...$locs ): HttpResponse {
		return new HttpResponse( 200, '<?xml version="1.0"?><sitemapindex ' . self::NS . '>' . implode( '', array_map( static fn( $l ) => "<sitemap><loc>$l</loc></sitemap>", $locs ) ) . '</sitemapindex>' );
	}

	public function test_reads_the_index_and_its_first_sub_sitemaps_only(): void {
		$r = $this->reader();
		$this->http->queue(
			$this->index( 'https://site.test/a.xml', 'https://site.test/b.xml', 'https://site.test/c.xml', 'https://site.test/d.xml' ),
			$this->urlset( 'https://site.test/1/', 'https://site.test/2/' ),
			$this->urlset( 'https://site.test/3/' ),
			$this->urlset( 'https://site.test/4/' )
		);
		$this->assertSame( array( 'https://site.test/1/', 'https://site.test/2/', 'https://site.test/3/', 'https://site.test/4/' ), $r->urls( 50 ) );
		$this->assertCount( 4, $this->http->requests, 'The index plus three sub-sitemaps; the fourth is not fetched.' );
		$this->assertSame( 'https://site.test/wp-sitemap.xml', $this->http->requests[0]['url'] );
	}

	public function test_respects_the_limit_and_the_hard_maximum(): void {
		$r    = $this->reader();
		$locs = array_map( static fn( $i ) => "https://site.test/$i/", range( 1, 150 ) );
		$this->http->queue( $this->urlset( ...$locs ), $this->urlset( ...$locs ) );
		$this->assertCount( 5, $r->urls( 5 ) );
		$this->assertCount( 100, $r->urls( 5000 ) );
		$this->assertSame( array(), $r->urls( 0 ) );
	}

	public function test_stops_fetching_sub_sitemaps_once_the_limit_is_reached(): void {
		$r = $this->reader();
		$this->http->queue( $this->index( 'https://site.test/a.xml', 'https://site.test/b.xml' ), $this->urlset( 'https://site.test/1/', 'https://site.test/2/' ) );
		$this->assertSame( array( 'https://site.test/1/' ), $r->urls( 1 ) );
		$this->assertCount( 2, $this->http->requests );
	}

	public function test_foreign_hosts_schemes_and_credentials_are_dropped_and_not_fetched(): void {
		$r = $this->reader();
		$this->http->queue(
			$this->index( 'https://evil.test/s.xml', 'https://site.test/ok.xml', 'file:///etc/passwd', 'https://user:pw@site.test/x.xml' ),
			$this->urlset( 'https://site.test/1/', 'https://evil.test/2/', 'ftp://site.test/3/', 'javascript:alert(1)', 'https://SITE.test/4/', 'https://site.test/1/' )
		);
		$this->assertSame( array( 'https://site.test/1/', 'https://SITE.test/4/' ), $r->urls( 20 ) );
		$this->assertSame( array( 'https://site.test/wp-sitemap.xml', 'https://site.test/ok.xml' ), array_column( $this->http->requests, 'url' ) );
	}

	public function test_a_plain_urlset_at_the_root_works(): void {
		$r = $this->reader();
		$this->http->queue( $this->urlset( 'https://site.test/x/' ) );
		$this->assertSame( array( 'https://site.test/x/' ), $r->urls( 20 ) );
	}

	public function test_entities_and_doctypes_are_rejected(): void {
		$r   = $this->reader();
		$xml = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset ' . self::NS . '><url><loc>https://site.test/&x;</loc></url></urlset>';
		$this->http->queue( new HttpResponse( 200, $xml ) );
		$this->assertSame( array(), $r->urls( 20 ) );
	}

	public function test_oversized_malformed_and_failing_responses_give_nothing(): void {
		$r = $this->reader();
		$this->http->queue( new HttpResponse( 200, str_repeat( ' ', SitemapReader::MAX_BYTES + 1 ) . '<urlset></urlset>' ) );
		$this->assertSame( array(), $r->urls( 20 ) );
		$r = $this->reader();
		$this->http->queue( new HttpResponse( 200, '<urlset><url><loc>' ) );
		$this->assertSame( array(), $r->urls( 20 ) );
		$r = $this->reader();
		$this->http->queue( new HttpResponse( 404, '' ) );
		$this->assertSame( array(), $r->urls( 20 ) );
		$r = $this->reader();
		$this->http->queue( new HttpException( 'down' ) );
		$this->assertSame( array(), $r->urls( 20 ) );
		$r = $this->reader();
		$this->http->queue( new HttpResponse( 200, '<html><a href="https://site.test/">x</a></html>' ) );
		$this->assertSame( array(), $r->urls( 20 ) );
	}

	public function test_falls_back_to_the_seo_plugin_sitemap_when_core_one_is_missing(): void {
		$r = $this->reader();
		$this->http->queue(
			new HttpResponse( 404, 'Not found' ),
			$this->index( 'https://site.test/post-sitemap.xml' ),
			$this->urlset( 'https://site.test/hello/' )
		);
		$this->assertSame( array( 'https://site.test/hello/' ), $r->urls( 10 ) );
		$this->assertSame(
			array( 'https://site.test/wp-sitemap.xml', 'https://site.test/sitemap_index.xml', 'https://site.test/post-sitemap.xml' ),
			array_column( $this->http->requests, 'url' )
		);
	}

	public function test_walks_every_standard_location_and_stops_at_the_first_valid_one(): void {
		$r = $this->reader();
		$this->http->queue(
			new HttpResponse( 404, '' ),
			new HttpResponse( 200, '<html>a soft 404 page</html>' ),
			new HttpResponse( 404, '' ),
			$this->urlset( 'https://site.test/seopress/' )
		);
		$this->assertSame( array( 'https://site.test/seopress/' ), $r->urls( 10 ) );
		$this->assertSame(
			array( 'wp-sitemap.xml', 'sitemap_index.xml', 'sitemap.xml', 'sitemaps.xml' ),
			array_map( static fn( $x ) => basename( $x['url'] ), $this->http->requests )
		);
	}

	public function test_gives_up_quietly_when_no_location_has_a_sitemap(): void {
		$r = $this->reader();
		$this->http->queue( new HttpException( 'timeout' ), new HttpResponse( 404, '' ), new HttpResponse( 500, '' ), new HttpResponse( 404, '' ) );
		$this->assertSame( array(), $r->urls( 10 ) );
		$this->assertCount( 4, $this->http->requests );
	}

	public function test_custom_paths_are_validated(): void {
		$http   = new FakeHttp();
		$reader = new SitemapReader( $http, 'https://site.test', array( 'custom/map.xml', '../etc/passwd.xml', 'https://evil.test/s.xml', '/abs.xml', 'a b.xml', 5 ) );
		$http->queue( new HttpResponse( 404, '' ) );
		$reader->urls( 5 );
		$this->assertSame( array( 'https://site.test/custom/map.xml' ), array_column( $http->requests, 'url' ) );
	}
}
