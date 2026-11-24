<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\NginxFastcgiLayer;
use PurgeKit\Tests\Fakes\FakeStore;

final class NginxFastcgiLayerTest extends TestCase {
	private string $dir;
	private FakeStore $settings;

	protected function setUp(): void {
		$this->dir      = sys_get_temp_dir() . '/purgekit-nginx-' . bin2hex( random_bytes( 4 ) );
		$this->settings = new FakeStore();
		mkdir( $this->dir, 0777, true );
		$this->settings->set( 'nginx_cache_path', $this->dir );
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->dir );
	}

	/** Writes a file the way nginx lays it out: binary header, then "\nKEY: key\n", then the page. */
	private function cache( NginxFastcgiLayer $l, string $key, string $body = 'page' ): string {
		$file = $l->file_for( $key );
		@mkdir( dirname( $file ), 0777, true );
		file_put_contents( $file, "\x05\x00\x00\x00binary\nKEY: $key\n\x00\x00HTTP/1.1 200 OK\r\n\r\n$body" );
		return $file;
	}

	public function test_key_and_path_follow_nginx_rules(): void {
		$l = new NginxFastcgiLayer( $this->settings );
		$this->assertSame( 'httpsGETexample.com/blog/?a=1', $l->key_for( 'https://example.com/blog/?a=1' ) );
		$md5 = md5( 'httpsGETexample.com/' );
		$this->assertSame( $this->dir . '/' . substr( $md5, -1 ) . '/' . substr( $md5, -3, 2 ) . '/' . $md5, $l->file_for( 'httpsGETexample.com/' ) );
	}

	public function test_custom_key_template_and_levels(): void {
		$this->settings->set( 'nginx_cache_key', '$scheme://$host$request_uri' );
		$this->settings->set( 'nginx_cache_levels', '2' );
		$l = new NginxFastcgiLayer( $this->settings );
		$this->assertSame( 'https://example.com/x', $l->key_for( 'https://example.com/x' ) );
		$md5 = md5( 'a' );
		$this->assertSame( $this->dir . '/' . substr( $md5, -2 ) . '/' . $md5, $l->file_for( 'a' ) );
	}

	public function test_purge_urls_deletes_only_the_matching_file(): void {
		$l     = new NginxFastcgiLayer( $this->settings );
		$a     = $this->cache( $l, 'httpsGETexample.com/a/' );
		$b     = $this->cache( $l, 'httpsGETexample.com/b/' );
		$r     = $l->purgeUrls( array( 'https://example.com/a/' ) );
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertFileDoesNotExist( $a );
		$this->assertFileExists( $b );
	}

	public function test_purge_all_deletes_cache_files_but_not_other_files(): void {
		$l      = new NginxFastcgiLayer( $this->settings );
		$a      = $this->cache( $l, 'httpsGETexample.com/a/' );
		$b      = $this->cache( $l, 'httpsGETexample.com/b/' );
		$other  = $this->dir . '/notes.txt';
		$fake   = $this->dir . '/' . md5( 'x' ); // right name, no KEY header
		file_put_contents( $other, 'keep me' );
		file_put_contents( $fake, 'no key header here' );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertFileDoesNotExist( $a );
		$this->assertFileDoesNotExist( $b );
		$this->assertFileExists( $other );
		$this->assertFileExists( $fake );
	}

	public function test_symlinks_are_never_followed_or_deleted(): void {
		$l      = new NginxFastcgiLayer( $this->settings );
		$target = sys_get_temp_dir() . '/purgekit-target-' . bin2hex( random_bytes( 4 ) );
		file_put_contents( $target, "x\nKEY: k\n" );
		symlink( $target, $this->dir . '/' . md5( 'k' ) );
		$l->purgeAll();
		$this->assertFileExists( $target );
		unlink( $target );
		unlink( $this->dir . '/' . md5( 'k' ) );
	}

	public function test_detection_and_test_messages(): void {
		$this->assertTrue( ( new NginxFastcgiLayer( $this->settings ) )->detect() );
		$this->assertSame( LayerResult::OK, ( new NginxFastcgiLayer( $this->settings ) )->test()->status );
		$none = new NginxFastcgiLayer( new FakeStore() );
		$this->assertFalse( $none->detect() );
		$this->assertSame( LayerResult::SKIPPED, $none->test()->status );
		$this->settings->set( 'nginx_cache_path', '/definitely/not/here' );
		$missing = new NginxFastcgiLayer( $this->settings );
		$this->assertFalse( $missing->detect() );
		$this->assertSame( LayerResult::FAILED, $missing->test()->status );
	}

	public function test_relative_or_traversal_paths_are_rejected(): void {
		foreach ( array( 'relative/path', $this->dir . '/../etc', '..' ) as $bad ) {
			$this->settings->set( 'nginx_cache_path', $bad );
			$this->assertFalse( ( new NginxFastcgiLayer( $this->settings ) )->detect(), $bad );
		}
	}

	public function test_fingerprint_header(): void {
		$l = new NginxFastcgiLayer( $this->settings );
		$this->assertNotNull( $l->servedFrom( array( 'x-fastcgi-cache' => 'HIT' ) ) );
		$this->assertNull( $l->servedFrom( array( 'x-fastcgi-cache' => 'MISS' ) ) );
	}
}
