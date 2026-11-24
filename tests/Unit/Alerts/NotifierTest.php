<?php
namespace PurgeKit\Tests\Unit\Alerts;

use PHPUnit\Framework\TestCase;
use PurgeKit\Alerts\Notifier;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Run;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Settings\Settings;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeMailer;
use PurgeKit\Tests\Fakes\FakeStore;

final class NotifierTest extends TestCase {
	private const HOOK = 'https://hooks.slack.com/services/T000/B000/XXXX';
	private FakeHttp $http;
	private FakeMailer $mailer;
	private FakeStore $store;
	private Settings $settings;
	private Secrets $secrets;
	private FakeClock $clock;

	private function notifier( bool $slack = true, string $email = 'ops@example.com' ): Notifier {
		$this->http     = new FakeHttp();
		$this->mailer   = new FakeMailer();
		$this->store    = new FakeStore();
		$this->settings = new Settings( $this->store );
		$this->secrets  = new Secrets( $this->store, 'k' );
		$this->clock    = new FakeClock();
		if ( $slack ) {
			$this->secrets->set( Notifier::SLACK_SECRET, self::HOOK );
		}
		if ( '' !== $email ) {
			$this->settings->update( array( 'alert_email' => $email ) );
		}
		return new Notifier( $this->http, $this->mailer, $this->settings, $this->secrets, $this->store, $this->clock, 'My Site', 'https://a.test' );
	}

	private function run_with( string $status, string $layer_status = LayerResult::FAILED ): Run {
		return new Run( $status, array( new LayerResult( 'cloudflare', $layer_status, 'Invalid <token>' ) ) );
	}

	public function test_failed_run_goes_to_slack_and_email(): void {
		$n = $this->notifier();
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( self::HOOK, $this->http->requests[0]['url'] );
		$text = json_decode( $this->http->requests[0]['args']['body'], true )['text'];
		$this->assertStringContainsString( 'cloudflare: failed', $text );
		$this->assertStringContainsString( 'Invalid &lt;token&gt;', $text, 'Slack text is escaped.' );
		$this->assertCount( 1, $this->mailer->sent );
		$this->assertSame( 'ops@example.com', $this->mailer->sent[0]['to'] );
		$this->assertStringContainsString( 'My Site', $this->mailer->sent[0]['subject'] );
	}

	public function test_ok_and_skipped_runs_are_silent(): void {
		$n = $this->notifier();
		$n->notify( 'cron', $this->run_with( Run::OK, LayerResult::OK ) );
		$n->notify( 'cron', $this->run_with( Run::SKIPPED, LayerResult::SKIPPED ) );
		$this->assertCount( 0, $this->http->requests );
		$this->assertCount( 0, $this->mailer->sent );
	}

	public function test_partial_alerts_by_default_but_not_when_set_to_failed_only(): void {
		$n = $this->notifier();
		$n->notify( 'cron', $this->run_with( Run::PARTIAL ) );
		$this->assertCount( 1, $this->mailer->sent );

		$n = $this->notifier();
		$this->settings->update( array( 'alert_on' => 'failed' ) );
		$n->notify( 'cron', $this->run_with( Run::PARTIAL ) );
		$this->assertCount( 0, $this->mailer->sent );
	}

	public function test_alerts_are_throttled(): void {
		$n = $this->notifier();
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertCount( 1, $this->mailer->sent );
		$this->clock->t += 16 * 60 * 1000;
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertCount( 2, $this->mailer->sent );
	}

	public function test_nothing_configured_sends_nothing_and_does_not_start_the_throttle(): void {
		$n = $this->notifier( false, '' );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertSame( array(), $n->channels() );
		$this->assertNull( $this->store->get( 'alert_last' ) );
	}

	public function test_non_slack_urls_are_rejected(): void {
		$n = $this->notifier( false, '' );
		$this->secrets->set( Notifier::SLACK_SECRET, 'https://evil.test/hook' );
		$this->assertSame( array(), $n->channels() );
		$this->assertFalse( Notifier::isSlackUrl( 'http://hooks.slack.com/services/T/B/X' ) );
		$this->assertFalse( Notifier::isSlackUrl( 'https://hooks.slack.com.evil.test/services/T/B/X' ) );
		$this->assertTrue( Notifier::isSlackUrl( self::HOOK ) );
	}

	public function test_a_failing_channel_does_not_stop_the_other_or_throw(): void {
		$n = $this->notifier();
		$this->http->queue( new HttpException( 'timeout to ' . self::HOOK ) );
		$result = $n->sendTest();
		$this->assertStringNotContainsString( 'T000', $result['slack'], 'The webhook URL is redacted.' );
		$this->assertSame( 'ok', $result['email'] );
	}

	public function test_slack_http_error_is_reported(): void {
		$n = $this->notifier( true, '' );
		$this->http->queue( new HttpResponse( 404, 'no_service' ) );
		$this->assertSame( 'Slack answered HTTP 404', $n->sendTest()['slack'] );
	}

	public function test_mail_not_accepted_is_reported(): void {
		$n                = $this->notifier( false );
		$this->mailer->ok = false;
		$this->assertStringContainsString( 'not accepted', $n->sendTest()['email'] );
	}

	public function test_invalid_email_is_not_stored(): void {
		$this->notifier( false, '' );
		$this->settings->update( array( 'alert_email' => 'not-an-email' ) );
		$this->assertSame( '', $this->settings->alertEmail() );
	}
}
