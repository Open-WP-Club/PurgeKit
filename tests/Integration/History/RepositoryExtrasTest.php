<?php
namespace PurgeKit\Tests\Integration\History;

use PurgeKit\Core\Change;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\History\Repository;

/** The columns and queries added for "who started it", the dashboard and the Impact tab. */
final class RepositoryExtrasTest extends \WP_UnitTestCase {
	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Repository();
		$this->repo->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->repo->table() );
	}

	private function run_of( string $status = Run::OK, ?PurgePlan $plan = null ): Run {
		return new Run( $status, array( new LayerResult( 'cloudflare', LayerResult::OK, '', 5 ) ), $plan ?? PurgePlan::forUrls( array( 'https://a.test/x' ) ) );
	}

	public function test_the_table_has_the_trigger_column_and_the_version_is_current(): void {
		$cols = $GLOBALS['wpdb']->get_col( 'SHOW COLUMNS FROM ' . $this->repo->table() );
		$this->assertContains( 'trigger_json', $cols );
		$this->assertContains( 'verify_json', $cols );
		$this->assertSame( '3', Repository::DB_VERSION );
	}

	public function test_a_flush_by_a_signed_in_user_records_the_user_rules_and_kinds_of_change(): void {
		$id = self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'anna' ) );
		wp_set_current_user( $id );
		$run        = $this->run_of();
		$run->rules = array( 'Posts to the CDN' );
		$this->repo->record( 'manual', $run, array( Change::forUrls( 'post', array() ), Change::forUrls( 'term', array() ), Change::forUrls( 'post', array() ) ) );
		$trigger = $this->repo->recent( 1, 0 )[0]['trigger'];
		$this->assertSame( array( 'id' => $id, 'name' => 'anna' ), $trigger['user'] );
		$this->assertSame( array( 'Posts to the CDN' ), $trigger['rules'] );
		$this->assertSame( array( 'post', 'term' ), $trigger['changes'] );
		$this->assertSame( 3, $trigger['count'] );
		$this->assertArrayHasKey( 'via', $trigger );
	}

	public function test_a_flush_without_a_user_has_no_user_entry(): void {
		wp_set_current_user( 0 );
		$this->repo->record( 'auto', $this->run_of(), array() );
		$trigger = $this->repo->recent( 1, 0 )[0]['trigger'];
		$this->assertArrayNotHasKey( 'user', $trigger );
		$this->assertArrayNotHasKey( 'rules', $trigger );
	}

	public function test_rows_from_before_the_column_still_hydrate(): void {
		$this->repo->record( 'cli', $this->run_of(), array() );
		$GLOBALS['wpdb']->query( 'UPDATE ' . $this->repo->table() . ' SET trigger_json = NULL' );
		$this->assertSame( array(), $this->repo->recent( 1, 0 )[0]['trigger'] );
	}

	public function test_find_returns_one_row_or_null(): void {
		$id = $this->repo->record( 'cli', $this->run_of(), array() );
		$this->assertSame( 'cli', $this->repo->find( $id )['source'] );
		$this->assertNull( $this->repo->find( $id + 999 ) );
	}

	public function test_stats_counts_the_last_day_and_gives_seven_days(): void {
		$this->repo->record( 'a', $this->run_of( Run::OK ), array() );
		$this->repo->record( 'b', $this->run_of( Run::FAILED ), array() );
		$this->repo->record( 'c', $this->run_of( Run::PARTIAL ), array() );
		$GLOBALS['wpdb']->insert( $this->repo->table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'source' => 'old', 'status' => 'ok', 'plan_all' => 1, 'urls_json' => '{}', 'results_json' => '[]' ) );
		$stats = $this->repo->stats();
		ksort( $stats['day'] );
		$this->assertSame( array( 'failed' => 1, 'ok' => 1, 'partial' => 1 ), $stats['day'] );
		$this->assertCount( 7, $stats['week'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $stats['week'][6]['date'] );
		$this->assertSame( 3, $stats['week'][6]['total'] );
		$this->assertSame( 2, $stats['week'][6]['problems'] );
		$this->assertSame( 1, $stats['week'][3]['total'], 'The old flush lands on its own day.' );
	}

	public function test_since_returns_newer_rows_oldest_first_and_respects_the_cap(): void {
		$GLOBALS['wpdb']->insert( $this->repo->table(), array( 'created_at' => '2020-01-01 00:00:00', 'source' => 'ancient', 'status' => 'ok', 'plan_all' => 0, 'urls_json' => '{}', 'results_json' => '[]' ) );
		$this->repo->record( 'first', $this->run_of(), array() );
		$this->repo->record( 'second', $this->run_of(), array() );
		$rows = $this->repo->since( gmdate( 'Y-m-d 00:00:00', time() - DAY_IN_SECONDS ) );
		$this->assertSame( array( 'first', 'second' ), array_column( $rows, 'source' ) );
		$this->assertCount( 1, $this->repo->since( '2000-01-01 00:00:00', 1 ) );
	}
}
