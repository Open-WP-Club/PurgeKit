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

	public function test_a_layer_that_keeps_failing_is_reported_once_per_call(): void {
		$n = $this->notifier();
		$n->notifyLayerStreak( array() );
		$this->assertCount( 0, $this->http->requests );
		$n->notifyLayerStreak( array( 'cf' => array( 'label' => 'Cloudflare', 'count' => 3, 'message' => 'Rate limited' ) ) );
		$this->assertCount( 1, $this->http->requests );
		$this->assertStringContainsString( 'Cloudflare: 3 failed purges in a row', $this->http->requests[0]['args']['body'] );
		$this->assertCount( 1, $this->mailer->sent );
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

	private function with_channel( string $id, string $url ): Notifier {
		$n = $this->notifier( false, '' );
		$this->secrets->set( Notifier::CHANNELS[ $id ]['secret'], $url );
		return $n;
	}

	public function test_discord_gets_a_message_that_cannot_ping_anyone(): void {
		$url = 'https://discord.com/api/webhooks/123456789/abc-DEF_ghi';
		$n   = $this->with_channel( 'discord', $url );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertSame( $url, $this->http->requests[0]['url'] );
		$body = json_decode( $this->http->requests[0]['args']['body'], true );
		$this->assertStringContainsString( 'cloudflare: failed', $body['content'] );
		$this->assertSame( array( 'parse' => array() ), $body['allowed_mentions'] );
		$this->assertLessThanOrEqual( 2000, mb_strlen( $body['content'] ) );
	}

	public function test_teams_gets_an_adaptive_card(): void {
		$url = 'https://prod-12.westeurope.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?sig=x';
		$n   = $this->with_channel( 'teams', $url );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$body = json_decode( $this->http->requests[0]['args']['body'], true );
		$this->assertSame( 'message', $body['type'] );
		$this->assertSame( 'AdaptiveCard', $body['attachments'][0]['content']['type'] );
		$this->assertStringContainsString( 'cloudflare: failed', $body['attachments'][0]['content']['body'][1]['text'] );
	}

	public function test_the_generic_webhook_gets_facts_it_can_act_on(): void {
		$n = $this->with_channel( 'webhook', 'https://hooks.example.com/purgekit' );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$body = json_decode( $this->http->requests[0]['args']['body'], true );
		$this->assertSame( 'flush_failed', $body['event'] );
		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 'cron', $body['trigger'] );
		$this->assertSame( array( 'cloudflare' => 'Invalid <token>' ), $body['failed'] );
		$this->assertSame( 'https://a.test', $body['url'] );
		$n->notifyStorm( 5, 600, true );
		$this->clock->t += 20 * 60 * 1000;
		$n->sendTest();
		$events = array_map( static fn( $r ) => json_decode( $r['args']['body'], true )['event'] ?? '', $this->http->requests );
		$this->assertContains( 'test', $events );
	}

	public function test_addresses_are_checked_per_channel(): void {
		$this->assertTrue( Notifier::isUrl( 'discord', 'https://discordapp.com/api/webhooks/1/x' ) );
		$this->assertFalse( Notifier::isUrl( 'discord', 'https://evil.test/api/webhooks/1/x' ) );
		$this->assertFalse( Notifier::isUrl( 'discord', 'https://discord.com.evil.test/api/webhooks/1/x' ) );
		$this->assertTrue( Notifier::isUrl( 'teams', 'https://contoso.webhook.office.com/webhookb2/abc' ) );
		$this->assertFalse( Notifier::isUrl( 'teams', 'https://webhook.office.com.evil.test/abc' ) );
		$this->assertTrue( Notifier::isUrl( 'webhook', 'https://hooks.example.com/a?b=1' ) );
		foreach ( array( 'http://hooks.example.com/a', 'https://localhost/a', 'https://127.0.0.1/a', 'https://10.0.0.5/a', 'https://192.168.1.2/a', 'https://169.254.169.254/latest', 'https://[::1]/a', 'https://user:pw@hooks.example.com/a', 'https://intranet/a', 'https://db.internal/a', 'ftp://hooks.example.com/a' ) as $bad ) {
			$this->assertFalse( Notifier::isUrl( 'webhook', $bad ), $bad );
		}
		$this->assertTrue( Notifier::isUrl( 'webhook', 'https://8.8.8.8/a' ), 'A public address is fine.' );
		$this->assertFalse( Notifier::isUrl( 'nope', 'https://hooks.example.com/a' ) );
	}

	public function test_a_saved_address_that_no_longer_matches_is_ignored(): void {
		$n = $this->with_channel( 'webhook', 'http://10.0.0.1/hook' );
		$this->assertSame( array(), $n->channels() );
		$n->notify( 'cron', $this->run_with( Run::FAILED ) );
		$this->assertCount( 0, $this->http->requests );
	}
}
