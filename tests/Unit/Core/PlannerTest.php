<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Planner;
use PurgeKit\Core\UrlExpander;

final class PlannerTest extends TestCase {
	private function planner( array $expanded = array() ): Planner {
		$expander = new class( $expanded ) implements UrlExpander {
			public function __construct( private array $urls ) {}
			public function expand( Change $change ): array {
				return $this->urls;
			}
		};
		return new Planner( $expander, 'https://site.test' );
	}

	public function test_site_wide_wins(): void {
		$plan = $this->planner()->plan( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ), Change::siteWide( 'menu' ) ) );
		$this->assertTrue( $plan->all );
	}

	public function test_drops_other_host_and_fragment_and_empty(): void {
		$plan = $this->planner()->plan( array( Change::forUrls( 'post', array( 'https://evil.test/a', 'https://site.test/a#x', '', 'https://site.test/a' ) ) ) );
		$this->assertFalse( $plan->all );
		$this->assertSame( array( 'https://site.test/a' ), $plan->urls );
	}

	public function test_merges_expander_urls(): void {
		$plan = $this->planner( array( 'https://site.test/', 'https://site.test/cat/' ) )->plan( array( Change::forUrls( 'post', array( 'https://site.test/a' ) ) ) );
		$this->assertSame( array( 'https://site.test/a', 'https://site.test/', 'https://site.test/cat/' ), $plan->urls );
	}

	public function test_no_changes_gives_empty_plan(): void {
		$this->assertTrue( $this->planner()->plan( array() )->isEmpty() );
	}

	public function test_non_http_scheme_is_dropped(): void {
		$plan = $this->planner()->plan( array( Change::forUrls( 'post', array( 'javascript://site.test/a', 'ftp://site.test/a' ) ) ) );
		$this->assertTrue( $plan->isEmpty() );
	}
}
