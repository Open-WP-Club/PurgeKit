<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\UrlList;

final class UrlListTest extends TestCase {
	public function test_small_lists_stay_a_plain_json_list(): void {
		$json = UrlList::encode( array( 'https://a.test/1', 'https://a.test/2' ) );
		$this->assertSame( '["https:\/\/a.test\/1","https:\/\/a.test\/2"]', $json );
		$this->assertSame( 2, UrlList::decode( $json )['total'] );
	}

	public function test_large_lists_are_truncated_but_keep_the_total(): void {
		$urls = array_map( static fn( $i ) => "https://a.test/$i", range( 1, 5000 ) );
		$json = UrlList::encode( $urls );
		$d    = UrlList::decode( $json );
		$this->assertCount( UrlList::MAX, $d['urls'] );
		$this->assertSame( 5000, $d['total'] );
		$this->assertLessThan( 20000, strlen( $json ) );
	}

	public function test_exactly_max_is_not_truncated(): void {
		$urls = array_map( static fn( $i ) => "u$i", range( 1, UrlList::MAX ) );
		$this->assertSame( UrlList::MAX, UrlList::decode( UrlList::encode( $urls ) )['total'] );
		$this->assertSame( $urls, UrlList::decode( UrlList::encode( $urls ) )['urls'] );
	}

	public function test_garbage_decodes_to_empty(): void {
		$this->assertSame( array( 'urls' => array(), 'total' => 0 ), UrlList::decode( 'nope' ) );
	}
}
