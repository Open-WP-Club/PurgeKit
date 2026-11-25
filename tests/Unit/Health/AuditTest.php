<?php
namespace PurgeKit\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Health;
use PurgeKit\Health\Audit;

final class AuditTest extends TestCase {
	private const DAY = 86400;

	public function test_webhook_key(): void {
		$now = 1000 * self::DAY;
		$this->assertNull( Audit::webhookKey( false, 0, null, $now ) );
		$this->assertSame( Health::CRITICAL, Audit::webhookKey( true, 12, $now, $now )['status'] );
		$this->assertSame( Health::RECOMMENDED, Audit::webhookKey( true, 40, null, $now )['status'] );
		$this->assertSame( Health::RECOMMENDED, Audit::webhookKey( true, 40, $now - 400 * self::DAY, $now )['status'] );
		$this->assertSame( Health::GOOD, Audit::webhookKey( true, 40, $now - 10 * self::DAY, $now )['status'] );
	}

	public function test_cloudflare(): void {
		$now = 1000 * self::DAY;
		$ok  = array( 'status' => 'active', 'expires_on' => null );
		$zone = array( 'status' => 'ok', 'message' => '' );
		$this->assertNull( Audit::cloudflare( false, false, null, null, $now ) );
		$this->assertSame( Health::RECOMMENDED, Audit::cloudflare( true, false, null, null, $now )['status'] );
		$this->assertSame( Health::CRITICAL, Audit::cloudflare( true, true, array( 'status' => 'disabled', 'expires_on' => null ), $zone, $now )['status'] );
		$this->assertSame( Health::CRITICAL, Audit::cloudflare( true, true, $ok, array( 'status' => 'failed', 'message' => 'nope' ), $now )['status'] );
		$soon = gmdate( 'c', $now + 5 * self::DAY );
		$this->assertSame( Health::RECOMMENDED, Audit::cloudflare( true, true, array( 'status' => 'active', 'expires_on' => $soon ), $zone, $now )['status'] );
		$past = gmdate( 'c', $now - self::DAY );
		$this->assertSame( Health::CRITICAL, Audit::cloudflare( true, true, array( 'status' => 'active', 'expires_on' => $past ), $zone, $now )['status'] );
		$this->assertSame( Health::GOOD, Audit::cloudflare( true, true, null, $zone, $now )['status'], 'An unreadable token check is not a failure.' );
	}

	public function test_slack_alerts_cron_storm_and_layers(): void {
		$this->assertNull( Audit::slack( null ) );
		$this->assertSame( Health::GOOD, Audit::slack( array( 'status' => 'ok', 'message' => '' ) )['status'] );
		$this->assertSame( Health::CRITICAL, Audit::slack( array( 'status' => 'gone', 'message' => 'x' ) )['status'] );
		$this->assertSame( Health::RECOMMENDED, Audit::slack( array( 'status' => 'unreachable', 'message' => 'x' ) )['status'] );
		$this->assertSame( Health::RECOMMENDED, Audit::alerts( array() )['status'] );
		$this->assertSame( Health::GOOD, Audit::alerts( array( 'email' ) )['status'] );
		$this->assertNull( Audit::cron( true, false ) );
		$this->assertNull( Audit::cron( false, true ) );
		$this->assertNotNull( Audit::cron( true, true ) );
		$this->assertNull( Audit::storm( null, false ) );
		$this->assertStringContainsString( '5 full flushes in 10 minutes', Audit::storm( array( 'count' => 5, 'window' => 600 ), true )['description'] );
		$rows = Audit::failingLayers( array( 'a' => array( 'status' => 'failed', 'message' => 'boom' ), 'b' => array( 'status' => 'ok', 'message' => '' ) ), array( 'a' => 'A', 'b' => 'B' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'A failed its last purge', $rows[0]['label'] );
	}
}
