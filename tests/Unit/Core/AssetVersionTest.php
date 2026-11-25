<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\AssetVersion;

final class AssetVersionTest extends TestCase {
	public function test_own_files_get_the_version_and_others_do_not(): void {
		$this->assertSame( 'https://a.test/style.css?pkv=7', AssetVersion::rewrite( 'https://a.test/style.css', 7, 'a.test' ) );
		$this->assertSame( 'https://a.test/s.css?ver=6.4&pkv=7', AssetVersion::rewrite( 'https://a.test/s.css?ver=6.4', 7, 'A.test' ) );
		$this->assertSame( '/wp-includes/a.js?pkv=7', AssetVersion::rewrite( '/wp-includes/a.js', 7, 'a.test' ) );
		$this->assertSame( '//a.test/a.js?pkv=7', AssetVersion::rewrite( '//a.test/a.js', 7, 'a.test' ) );
		$this->assertSame( 'https://cdn.other.test/a.js', AssetVersion::rewrite( 'https://cdn.other.test/a.js', 7, 'a.test' ), 'Other hosts keep their address.' );
		$this->assertSame( 'data:text/css;base64,AAAA', AssetVersion::rewrite( 'data:text/css;base64,AAAA', 7, 'a.test' ) );
	}

	public function test_an_older_value_is_replaced_and_a_fragment_is_kept(): void {
		$this->assertSame( 'https://a.test/s.css?pkv=9', AssetVersion::rewrite( 'https://a.test/s.css?pkv=7', 9, 'a.test' ) );
		$this->assertSame( 'https://a.test/s.css?x=1&pkv=9', AssetVersion::rewrite( 'https://a.test/s.css?pkv=7&x=1', 9, 'a.test' ) );
		$this->assertSame( 'https://a.test/s.css?pkv=9#frag', AssetVersion::rewrite( 'https://a.test/s.css#frag', 9, 'a.test' ) );
	}

	public function test_version_zero_changes_nothing(): void {
		$this->assertSame( 'https://a.test/s.css', AssetVersion::rewrite( 'https://a.test/s.css', 0, 'a.test' ) );
		$this->assertSame( '', AssetVersion::rewrite( '', 5, 'a.test' ) );
	}
}
