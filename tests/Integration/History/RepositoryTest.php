<?php
namespace PurgeKit\Tests\Integration\History;

use PurgeKit\Core\Change;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\History\Repository;

final class RepositoryTest extends \WP_UnitTestCase {
	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Repository();
		$this->repo->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->repo->table() );
	}

	private function run_of( string $status = Run::OK ): Run {
		return new Run( $status, array( new LayerResult( 'cloudflare', LayerResult::OK, 'fine', 12 ) ), PurgePlan::forUrls( array( 'https://a.test/x' ) ) );
	}

	public function test_record_and_read_back_run(): void {
		$this->repo->record( 'webhook', $this->run_of(), array( Change::forUrls( 'post', array() ) ) );
		$rows = $this->repo->recent( 10, 0 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'webhook', $rows[0]['source'] );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertSame( array( 'https://a.test/x' ), $rows[0]['urls'] );
		$this->assertSame( 'cloudflare', $rows[0]['results'][0]['layer'] );
		$this->assertSame( 12, $rows[0]['duration_ms'] );
	}

	public function test_filter_by_status_and_count(): void {
		$this->repo->record( 'a', $this->run_of( Run::OK ), array() );
		$this->repo->record( 'b', $this->run_of( Run::FAILED ), array() );
		$this->assertCount( 1, $this->repo->recent( 10, 0, array( 'status' => 'failed' ) ) );
		$this->assertSame( 2, $this->repo->count() );
		$this->assertSame( 1, $this->repo->count( array( 'status' => 'failed' ) ) );
	}

	public function test_prune_deletes_only_older_rows(): void {
		$this->repo->record( 'old', $this->run_of(), array() );
		$this->repo->record( 'new', $this->run_of(), array() );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->repo->table() . ' SET created_at = %s WHERE source = %s', gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ), 'old' ) );
		$this->assertSame( 1, $this->repo->prune( 90 ) );
		$this->assertSame( 'new', $this->repo->recent( 10, 0 )[0]['source'] );
	}

	public function test_pagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->repo->record( "s$i", $this->run_of(), array() );
		}
		$this->assertCount( 2, $this->repo->recent( 2, 0 ) );
		$this->assertCount( 1, $this->repo->recent( 2, 4 ) );
	}
}
