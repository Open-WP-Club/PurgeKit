<?php
namespace PurgeKit\Alerts;

use PurgeKit\Core\Clock;
use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Mailer;
use PurgeKit\Core\Run;
use PurgeKit\Core\Store;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Settings\Settings;

/** Tells a human when a flush went wrong: Slack (incoming webhook) and/or email. Never throws. */
final class Notifier {
	public const SLACK_SECRET = 'SLACK_WEBHOOK_URL';
	/** Only real Slack webhook URLs, so this cannot be turned into a request to an arbitrary host. */
	public const SLACK_PATTERN = '#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_-]+$#';
	private const THROTTLE_MS  = 15 * 60 * 1000;
	private const LAST         = 'alert_last';

	public function __construct(
		private Http $http,
		private Mailer $mailer,
		private Settings $settings,
		private Secrets $secrets,
		private Store $state,
		private Clock $clock,
		private string $site_name,
		private string $site_url
	) {}

	public static function isSlackUrl( string $url ): bool {
		return 1 === preg_match( self::SLACK_PATTERN, $url );
	}

	/** Called after each flush. Alerts on failed runs, and on partial runs when so configured. */
	public function notify( string $source, Run $run ): void {
		if ( ! $this->wanted( $run->status ) ) {
			return;
		}
		$now  = $this->clock->now();
		$last = (int) $this->state->get( self::LAST, 0 );
		if ( $last > 0 && $now - $last < self::THROTTLE_MS ) {
			return;
		}
		if ( ! $this->channels() ) {
			return;
		}
		$this->state->set( self::LAST, $now );
		$this->deliver( $this->subject( $run ), $this->body( $source, $run ) );
	}

	/** Sends a test message to every configured channel. @return array<string, string> channel => "ok" or an error. */
	public function sendTest(): array {
		return $this->deliver( 'PurgeKit test alert', "This is a test alert from PurgeKit on {$this->site_name} ({$this->site_url}).\nIf you can read this, alerts work." );
	}

	/** @return string[] */
	public function channels(): array {
		$out = array();
		if ( $this->slackUrl() ) {
			$out[] = 'slack';
		}
		if ( '' !== $this->settings->alertEmail() ) {
			$out[] = 'email';
		}
		return $out;
	}

	private function wanted( string $status ): bool {
		return Run::FAILED === $status || ( Run::PARTIAL === $status && 'failed' !== $this->settings->alertOn() );
	}

	private function deliver( string $subject, string $body ): array {
		$result = array();
		$url    = $this->slackUrl();
		if ( $url ) {
			$result['slack'] = $this->slack( $url, "*$subject*\n$body" );
		}
		$to = $this->settings->alertEmail();
		if ( '' !== $to ) {
			try {
				$result['email'] = $this->mailer->send( $to, $subject, $body ) ? 'ok' : 'The mail was not accepted for delivery.';
			} catch ( \Throwable $e ) {
				$result['email'] = $e->getMessage();
			}
		}
		return $result;
	}

	private function slack( string $url, string $text ): string {
		// Slack's own escaping rules for message text.
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
		try {
			$r = $this->http->request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => json_encode( array( 'text' => $text ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					'timeout' => 5,
				)
			);
		} catch ( HttpException $e ) {
			return 'Slack request failed: ' . str_replace( $url, '[webhook]', $e->getMessage() );
		}
		return $r->status >= 200 && $r->status < 300 ? 'ok' : 'Slack answered HTTP ' . $r->status;
	}

	private function slackUrl(): ?string {
		$url = (string) $this->secrets->get( self::SLACK_SECRET );
		return self::isSlackUrl( $url ) ? $url : null;
	}

	private function subject( Run $run ): string {
		return sprintf( '[%s] PurgeKit flush %s', $this->site_name, $run->status );
	}

	private function body( string $source, Run $run ): string {
		$lines = array( "Site: {$this->site_url}", "Trigger: $source" );
		foreach ( $run->results as $r ) {
			if ( LayerResult::FAILED === $r->status ) {
				$lines[] = "- {$r->layer}: failed" . ( '' !== $r->message ? " ({$r->message})" : '' );
			}
		}
		return implode( "\n", $lines );
	}
}
