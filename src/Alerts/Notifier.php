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

	/**
	 * Chat and webhook channels. `secret` is where the address is kept (encrypted, or a PURGEKIT_ constant),
	 * `field` the name used by the settings form and the REST API.
	 */
	public const CHANNELS = array(
		'slack'   => array(
			'label'  => 'Slack',
			'secret' => 'SLACK_WEBHOOK_URL',
			'field'  => 'slack_webhook_url',
		),
		'discord' => array(
			'label'  => 'Discord',
			'secret' => 'DISCORD_WEBHOOK_URL',
			'field'  => 'discord_webhook_url',
		),
		'teams'   => array(
			'label'  => 'Microsoft Teams',
			'secret' => 'TEAMS_WEBHOOK_URL',
			'field'  => 'teams_webhook_url',
		),
		'webhook' => array(
			'label'  => 'Webhook',
			'secret' => 'ALERT_WEBHOOK_URL',
			'field'  => 'alert_webhook_url',
		),
	);

	private const DISCORD_PATTERN = '#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9._-]+$#';
	// Teams "Workflows" and the older connectors live on these hosts.
	private const TEAMS_PATTERN = '#^https://[A-Za-z0-9.-]+\.(?:webhook\.office\.com|logic\.azure\.com|api\.powerplatform\.com)(?::443)?/[^\s]+$#';
	private const LAST          = 'alert_last';

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

	/** Is this an acceptable address for the channel? Chat services must be on their own hosts; a generic webhook must be public https. */
	public static function isUrl( string $channel, string $url ): bool {
		return match ( $channel ) {
			'slack'   => self::isSlackUrl( $url ),
			'discord' => 1 === preg_match( self::DISCORD_PATTERN, $url ),
			'teams'   => 1 === preg_match( self::TEAMS_PATTERN, $url ),
			'webhook' => self::isPublicHttps( $url ),
			default   => false,
		};
	}

	/** https only, and never a loopback, private or link-local address, so a webhook cannot be aimed at the server's own network. */
	private static function isPublicHttps( string $url ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs without WordPress in unit tests.
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 500 ) {
			return false;
		}
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return str_contains( $host, '.' );
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
		$failed = array();
		foreach ( $run->results as $r ) {
			if ( LayerResult::FAILED === $r->status ) {
				$failed[ $r->layer ] = $r->message;
			}
		}
		$this->deliver(
			$this->subject( $run ),
			$this->body( $source, $run ),
			array(
				'event'   => 'flush_' . $run->status,
				'status'  => $run->status,
				'trigger' => $source,
				'failed'  => $failed,
			)
		);
	}

	/**
	 * Is the saved Slack address alive? Posts an empty payload, which Slack refuses without sending a message:
	 * "no_text" or "invalid_payload" means the address works, 404 or 410 means it was removed.
	 *
	 * @return array{status:string,message:string}|null Null when no Slack address is saved.
	 */
	public function probeSlack(): ?array {
		$url = $this->slackUrl();
		if ( ! $url ) {
			return null;
		}
		try {
			$r = $this->http->request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => '{}',
					'timeout' => 5,
				)
			);
		} catch ( HttpException $e ) {
			return array(
				'status'  => 'unreachable',
				'message' => str_replace( $url, '[webhook]', $e->getMessage() ),
			);
		}
		if ( in_array( $r->status, array( 404, 410 ), true ) ) {
			return array(
				'status'  => 'gone',
				'message' => 'Slack no longer knows this webhook (HTTP ' . $r->status . ').',
			);
		}
		if ( $r->status >= 200 && $r->status < 500 ) {
			return array(
				'status'  => 'ok',
				'message' => '',
			);
		}
		return array(
			'status'  => 'unreachable',
			'message' => 'Slack answered HTTP ' . $r->status . '.',
		);
	}

	/** Tells a human that automatic flushing looks like it is looping. Throttled like every other alert. */
	public function notifyStorm( int $count, int $window_seconds, bool $paused ): void {
		$now  = $this->clock->now();
		$last = (int) $this->state->get( self::LAST . '_storm', 0 );
		if ( ( $last > 0 && $now - $last < self::THROTTLE_MS ) || ! $this->channels() ) {
			return;
		}
		$this->state->set( self::LAST . '_storm', $now );
		$minutes = max( 1, (int) round( $window_seconds / 60 ) );
		$this->deliver(
			sprintf( '[%s] PurgeKit: flush storm', $this->site_name ),
			sprintf(
				"Site: %s\n%d full flushes in %d minutes. A cron job or a plugin may be flushing in a loop.\n%s",
				$this->site_url,
				$count,
				$minutes,
				$paused ? 'Automatic flushing is paused until you resume it in PurgeKit.' : 'Automatic flushing is still on.'
			),
			array(
				'event'   => 'flush_storm',
				'flushes' => $count,
				'minutes' => $minutes,
				'paused'  => $paused,
			)
		);
	}

	/**
	 * One layer failed several purges in a row. Sent once, when the count reaches the limit, so it does not repeat.
	 *
	 * @param array<string,array{label:string,count:int,message:string}> $layers
	 */
	public function notifyLayerStreak( array $layers ): void {
		if ( array() === $layers || ! $this->channels() ) {
			return;
		}
		$lines  = array();
		$failed = array();
		foreach ( $layers as $id => $l ) {
			$lines[]       = sprintf( '%s: %d failed purges in a row. %s', $l['label'], $l['count'], $l['message'] );
			$failed[ $id ] = $l['message'];
		}
		$this->deliver(
			sprintf( '[%s] PurgeKit: a cache layer keeps failing', $this->site_name ),
			sprintf( "Site: %s\n%s", $this->site_url, implode( "\n", $lines ) ),
			array(
				'event'  => 'layer_failing',
				'failed' => $failed,
			)
		);
	}

	/**
	 * A cache itself has a problem (not a failed purge). Each problem is passed in once by HealthWatch.
	 *
	 * @param array<string,array{label:string,issue:string,message:string}> $issues
	 */
	public function notifyCacheHealth( array $issues ): void {
		if ( array() === $issues || ! $this->channels() ) {
			return;
		}
		$lines  = array();
		$failed = array();
		foreach ( $issues as $id => $i ) {
			$lines[]       = sprintf( '%s (%s): %s', $i['label'], 'failed' === $i['issue'] ? 'not working' : 'needs attention', $i['message'] );
			$failed[ $id ] = $i['message'];
		}
		$this->deliver(
			sprintf( '[%s] PurgeKit: a cache needs attention', $this->site_name ),
			sprintf( "Site: %s\n%s", $this->site_url, implode( "\n", $lines ) ),
			array(
				'event'  => 'cache_health',
				'failed' => $failed,
			)
		);
	}

	/** The weekly summary, already written by Digest. @param array<string,mixed> $event */
	public function notifyDigest( string $subject, string $body, array $event ): void {
		if ( $this->channels() ) {
			$this->deliver( $subject, $body, $event );
		}
	}

	/** Sends a test message to every configured channel. @return array<string, string> channel => "ok" or an error. */
	public function sendTest(): array {
		return $this->deliver( 'PurgeKit test alert', "This is a test alert from PurgeKit on {$this->site_name} ({$this->site_url}).\nIf you can read this, alerts work.", array( 'event' => 'test' ) );
	}

	/** @return string[] */
	public function channels(): array {
		$out = array();
		foreach ( array_keys( self::CHANNELS ) as $id ) {
			if ( $this->channelUrl( $id ) ) {
				$out[] = $id;
			}
		}
		if ( '' !== $this->settings->alertEmail() ) {
			$out[] = 'email';
		}
		return $out;
	}

	private function wanted( string $status ): bool {
		return Run::FAILED === $status || ( Run::PARTIAL === $status && 'failed' !== $this->settings->alertOn() );
	}

	/**
	 * @param array<string,mixed> $event Machine readable facts, used by the generic webhook.
	 * @return array<string,string> channel => "ok" or an error.
	 */
	private function deliver( string $subject, string $body, array $event = array() ): array {
		$result = array();
		foreach ( array_keys( self::CHANNELS ) as $id ) {
			$url = $this->channelUrl( $id );
			if ( ! $url ) {
				continue;
			}
			$result[ $id ] = $this->post( $id, $url, $this->payload( $id, $subject, $body, $event ) );
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

	/** @return array<string,mixed> */
	private function payload( string $channel, string $subject, string $body, array $event ): array {
		switch ( $channel ) {
			case 'slack':
				// Slack's own escaping rules for message text.
				return array( 'text' => str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), "*$subject*\n$body" ) );
			case 'discord':
				return array(
					'content'          => mb_substr( "**$subject**\n$body", 0, 1900 ),
					'allowed_mentions' => array( 'parse' => array() ), // A layer message can never ping a role or everyone.
				);
			case 'teams':
				return array(
					'type'        => 'message',
					'attachments' => array(
						array(
							'contentType' => 'application/vnd.microsoft.card.adaptive',
							'content'     => array(
								'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
								'type'    => 'AdaptiveCard',
								'version' => '1.4',
								'body'    => array(
									array(
										'type'   => 'TextBlock',
										'text'   => $subject,
										'weight' => 'Bolder',
										'wrap'   => true,
									),
									array(
										'type' => 'TextBlock',
										'text' => $body,
										'wrap' => true,
									),
								),
							),
						),
					),
				);
			default:
				return array(
					'plugin'  => 'purgekit',
					'site'    => $this->site_name,
					'url'     => $this->site_url,
					'subject' => $subject,
					'message' => $body,
				) + $event;
		}
	}

	private function post( string $channel, string $url, array $payload ): string {
		$label = self::CHANNELS[ $channel ]['label'];
		try {
			$r = $this->http->request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => json_encode( $payload ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					'timeout' => 5,
				)
			);
		} catch ( HttpException $e ) {
			return $label . ' request failed: ' . str_replace( $url, '[webhook]', $e->getMessage() );
		}
		return $r->status >= 200 && $r->status < 300 ? 'ok' : $label . ' answered HTTP ' . $r->status;
	}

	private function channelUrl( string $channel ): ?string {
		$url = (string) $this->secrets->get( self::CHANNELS[ $channel ]['secret'] );
		return '' !== $url && self::isUrl( $channel, $url ) ? $url : null;
	}

	private function slackUrl(): ?string {
		return $this->channelUrl( 'slack' );
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
