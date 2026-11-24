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

	public function test_huge_url_lists_are_truncated_but_report_the_total(): void {
		$urls = array_map( static fn( $i ) => "https://a.test/$i", range( 1, 1000 ) );
		$run  = new Run( Run::OK, array(), PurgePlan::forUrls( $urls ) );
		$this->repo->record( 'cli', $run, array() );
		$row = $this->repo->recent( 1, 0 )[0];
		$this->assertCount( 200, $row['urls'] );
		$this->assertSame( 1000, $row['urls_total'] );
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

	public function test_annotate_stores_the_verification_and_old_rows_have_none(): void {
		$first  = $this->repo->record( 'a', $this->run_of(), array() );
		$second = $this->repo->record( 'b', $this->run_of(), array() );
		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( $first, $second );
		$this->repo->annotate( $second, array( 'status' => 'stale', 'message' => 'Cloudflare still serves a stale copy of https://a.test/.', 'layer' => 'cloudflare', 'checked' => array() ) );
		$rows = $this->repo->recent( 10, 0 );
		$this->assertSame( 'stale', $rows[0]['verification']['status'] );
		$this->assertNull( $rows[1]['verification'] );
	}

	public function test_install_is_idempotent_and_adds_the_column_to_an_old_table(): void {
		global $wpdb;
		$wpdb->query( 'ALTER TABLE ' . $this->repo->table() . ' DROP COLUMN verify_json' );
		$this->repo->install();
		$this->repo->install();
		$this->assertNotEmpty( $wpdb->get_results( 'SHOW COLUMNS FROM ' . $this->repo->table() . " LIKE 'verify_json'" ) );
	}
}
