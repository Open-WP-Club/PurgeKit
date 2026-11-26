<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Planner;
use PurgeKit\Core\UrlExpander;
use PurgeKit\Core\RuleSet;
use PurgeKit\Core\DeferredJobs;
use PurgeKit\Tests\Fakes\FakeStore;

final class ReasonsTest extends TestCase {
	private function planner(): Planner {
		return new Planner( new class() implements UrlExpander {
			public function expand( Change $change ): array { return array( 'https://site.test/archive/' ); }
		}, 'https://site.test/', array( '/private/*' ) );
	}

	public function test_each_url_retains_all_causes_without_excluded_urls(): void {
		$p = $this->planner()->plan( array( Change::forUrls( 'post', array( 'https://site.test/old/', 'https://site.test/private/x' ), array( 'post_id' => 10, 'snapshot' => true ) ), Change::forUrls( 'dependency', array( 'https://site.test/page/' ), array( 'post_id' => 11 ) ) ) );
		$this->assertCount( 2, $p->reasons['https://site.test/archive/'] );
		$this->assertStringContainsString( '#10 (previous URL', $p->reasons['https://site.test/old/'][0] );
		$this->assertStringContainsString( 'dependency', $p->reasons['https://site.test/page/'][0] );
		$this->assertArrayNotHasKey( 'https://site.test/private/x', $p->reasons );
	}

	public function test_sitewide_reason_is_preserved_alongside_targeted_causes(): void {
		$p = $this->planner()->plan( array( Change::siteWide( 'switch_theme' ), Change::forUrls( 'post', array(), array( 'post_id' => 12 ) ) ) );
		$this->assertTrue( $p->all );
		$this->assertStringContainsString( 'switch_theme', $p->reasons['*'][0] );
		$this->assertStringContainsString( '#12', $p->reasons['https://site.test/archive/'][0] );
	}

	/** @dataProvider actions */
	public function test_rule_and_original_change_are_available_after_routing_and_deferral( string $action, string $target ): void {
		$set = RuleSet::fromArray( array( array( 'name' => 'CDN later', 'action' => $action, 'on' => array( 'post' ), 'delay' => 30 ) ) );
		$job = $set->routeDetailed( array( Change::forUrls( 'post', array(), array( 'post_id' => 12 ) ) ), $this->planner() )['jobs'][0];
		$this->assertStringContainsString( '#12', $job['plan']->reasons[$target][0] );
		$this->assertStringContainsString( 'CDN later', $job['plan']->reasons[$target][0] );
		$jobs = new DeferredJobs( new FakeStore() );
		$saved = $jobs->add( 100, $job['plan'], null, $job['rules'] );
		$this->assertSame( $job['plan']->reasons, $jobs->take( $saved['id'] )['reasons'] );
	}

	public function actions(): array {
		return array( array( 'default', 'https://site.test/archive/' ), array( 'home', 'https://site.test/' ), array( 'all', '*' ) );
	}
}
