<?php
namespace PurgeKit\Health;

use PurgeKit\Core\Health;

/**
 * Turns what is known about the saved credentials and the setup into health checks.
 * Pure functions: the REST controller gathers the facts (some need network calls), this decides what they mean.
 */
final class Audit {
	private const DAY = 86400;

	public const OLD_KEY_DAYS    = 365;
	public const WEAK_KEY_LENGTH = 32;
	public const TOKEN_WARN_DAYS = 14;

	/** @return array{id:string,status:string,label:string,description:string,tab?:string} */
	public static function check( string $id, string $status, string $label, string $description, string $tab = '' ): array {
		$out = array(
			'id'          => $id,
			'status'      => $status,
			'label'       => $label,
			'description' => $description,
		);
		if ( '' !== $tab ) {
			$out['tab'] = $tab;
		}
		return $out;
	}

	/**
	 * @param int|null $rotated_at Unix time the key was created or last rotated; null when never recorded.
	 * @return array<string,mixed>|null Null when no key exists (the webhook is simply off).
	 */
	public static function webhookKey( bool $set, int $length, ?int $rotated_at, int $now ): ?array {
		if ( ! $set ) {
			return null;
		}
		if ( $length < self::WEAK_KEY_LENGTH ) {
			return self::check( 'webhook_key', Health::CRITICAL, 'The deploy webhook key is weak', "It has only $length characters. Rotate it to get a long random one.", 'settings' );
		}
		if ( null === $rotated_at ) {
			return self::check( 'webhook_key', Health::RECOMMENDED, 'The deploy webhook key has never been rotated', 'There is no record of when it was made. Rotate it once and PurgeKit will remind you when it gets old.', 'settings' );
		}
		$days = (int) floor( ( $now - $rotated_at ) / self::DAY );
		if ( $days > self::OLD_KEY_DAYS ) {
			return self::check( 'webhook_key', Health::RECOMMENDED, 'The deploy webhook key is over a year old', "It was made $days days ago. Rotate it and update your deploy script.", 'settings' );
		}
		return self::check( 'webhook_key', Health::GOOD, 'The deploy webhook key is fine', "Rotated $days days ago." );
	}

	/**
	 * @param array{status:string,expires_on:?string}|null $token Cloudflare's view of the token, if it could be read.
	 * @param array{status:string,message:string}|null     $zone  The result of the zone read test.
	 * @return array<string,mixed>|null
	 */
	public static function cloudflare( bool $token_set, bool $zone_set, ?array $token, ?array $zone, int $now ): ?array {
		if ( ! $token_set && ! $zone_set ) {
			return null;
		}
		if ( $token_set && ! $zone_set ) {
			return self::check( 'cloudflare', Health::RECOMMENDED, 'Cloudflare has a token but no zone ID', 'Use “Find zone ID for this site” in Settings.', 'settings' );
		}
		if ( ! $token_set ) {
			return self::check( 'cloudflare', Health::RECOMMENDED, 'Cloudflare has a zone ID but no token', 'Add an API token with the Cache Purge permission.', 'settings' );
		}
		if ( $token && 'active' !== $token['status'] && '' !== $token['status'] ) {
			return self::check( 'cloudflare', Health::CRITICAL, 'The Cloudflare token is not active', 'Cloudflare reports it as “' . $token['status'] . '”. Create a new token.', 'settings' );
		}
		if ( $zone && 'failed' === $zone['status'] ) {
			return self::check( 'cloudflare', Health::CRITICAL, 'The Cloudflare token cannot read the zone', $zone['message'] . '. Check the zone ID and that the token covers this zone.', 'settings' );
		}
		if ( $token && null !== $token['expires_on'] ) {
			$at = strtotime( $token['expires_on'] );
			if ( false !== $at ) {
				$left = (int) floor( ( $at - $now ) / self::DAY );
				if ( $left < 0 ) {
					return self::check( 'cloudflare', Health::CRITICAL, 'The Cloudflare token has expired', 'Create a new token and save it in Settings.', 'settings' );
				}
				if ( $left <= self::TOKEN_WARN_DAYS ) {
					return self::check( 'cloudflare', Health::RECOMMENDED, 'The Cloudflare token expires soon', "It stops working in $left days.", 'settings' );
				}
			}
		}
		return self::check( 'cloudflare', Health::GOOD, 'Cloudflare credentials work', 'The token is active and can read the zone.' );
	}

	/** @param array{status:string,message:string}|null $probe Null when no Slack address is saved. */
	public static function slack( ?array $probe ): ?array {
		if ( null === $probe ) {
			return null;
		}
		return match ( $probe['status'] ) {
			'ok'   => self::check( 'slack', Health::GOOD, 'The Slack webhook answers', 'Alerts can reach Slack.' ),
			'gone' => self::check( 'slack', Health::CRITICAL, 'The Slack webhook was removed', $probe['message'] . ' Create a new one.', 'settings:alerts' ),
			default => self::check( 'slack', Health::RECOMMENDED, 'The Slack webhook is unreachable', $probe['message'], 'settings:alerts' ),
		};
	}

	/** @param string[] $channels */
	public static function alerts( array $channels ): array {
		if ( array() === $channels ) {
			return self::check( 'alerts', Health::RECOMMENDED, 'Nobody is told when a purge fails', 'Add an alert email or a Slack webhook, so a failing CDN does not go unnoticed.', 'settings:alerts' );
		}
		return self::check( 'alerts', Health::GOOD, 'Failed purges are reported', 'Alerts go to: ' . implode( ', ', $channels ) . '.' );
	}

	public static function cron( bool $cron_disabled, bool $needs_cron ): ?array {
		if ( $cron_disabled && $needs_cron ) {
			return self::check( 'cron', Health::RECOMMENDED, 'WP-Cron is switched off', 'Scheduled flushes, delayed rule jobs and cache warming need it. Make sure a server cron job calls wp-cron.php.' );
		}
		return null;
	}

	/** @param array{count:int,window:int}|null $storm */
	public static function storm( ?array $storm, bool $paused ): ?array {
		if ( null === $storm ) {
			return null;
		}
		$minutes = max( 1, (int) round( $storm['window'] / 60 ) );
		return self::check(
			'storm',
			Health::RECOMMENDED,
			'Flush storm detected',
			sprintf( '%d full flushes in %d minutes. A cron job or a plugin may be flushing in a loop. %s', $storm['count'], $minutes, $paused ? 'Automatic flushing is paused.' : 'Automatic flushing is still on.' ),
			'dashboard'
		);
	}

	/**
	 * @param array<string,array{status:string,message:string}> $last  The latest purge result per layer id.
	 * @param array<string,string>                              $names Layer id => label.
	 * @return array<int,array<string,mixed>>
	 */
	public static function failingLayers( array $last, array $names ): array {
		$out = array();
		foreach ( $last as $id => $r ) {
			if ( 'failed' === ( $r['status'] ?? '' ) && isset( $names[ $id ] ) ) {
				$out[] = self::check( 'layer_' . $id, Health::RECOMMENDED, $names[ $id ] . ' failed its last purge', (string) ( $r['message'] ?? '' ), 'dashboard' );
			}
		}
		return $out;
	}
}
