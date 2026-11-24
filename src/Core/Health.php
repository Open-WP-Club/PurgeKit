<?php
namespace PurgeKit\Core;

/** Turns what the plugin knows (last run, paused layers) into health checks for Site Health and the admin notice. */
final class Health {
	public const GOOD        = 'good';
	public const RECOMMENDED = 'recommended';
	public const CRITICAL    = 'critical';

	/**
	 * What is remembered about the latest flush; kept small because it is read on every admin page.
	 *
	 * @return array{status:string,at:int,source:string,problems:array<int,array{layer:string,message:string}>}
	 */
	public static function summarise( Run $run, string $source, int $now ): array {
		$problems = array();
		foreach ( $run->results as $r ) {
			if ( LayerResult::FAILED === $r->status && count( $problems ) < 5 ) {
				$problems[] = array(
					'layer'   => $r->layer,
					'message' => mb_substr( $r->message, 0, 200 ),
				);
			}
		}
		return array(
			'status'   => $run->status,
			'at'       => $now,
			'source'   => $source,
			'problems' => $problems,
		);
	}

	/**
	 * @param array<string,mixed>|null $last_run   A value from summarise(), or null when nothing ran yet.
	 * @param string|null              $paused     Why external purges are paused, if they are.
	 * @param int                      $detected   How many layers were detected on this site.
	 * @return array<int,array{id:string,status:string,label:string,description:string}>
	 */
	public static function checks( ?array $last_run, ?string $paused, int $detected ): array {
		$out = array();
		if ( 0 === $detected ) {
			$out[] = self::check( 'layers', self::RECOMMENDED, 'No cache layer was detected', 'PurgeKit did not find a cache it can purge. Configure one in Settings, or ignore this if the site is not cached.' );
		}
		if ( null !== $paused ) {
			$out[] = self::check( 'paused', self::RECOMMENDED, 'Purges to CDNs and firewalls are paused', 'Paused because ' . $paused . '. This protects the live site from a copy of it.' );
		}
		$status = (string) ( $last_run['status'] ?? '' );
		if ( Run::FAILED === $status || Run::PARTIAL === $status ) {
			$names = implode( ', ', array_map( static fn( $p ) => $p['layer'] . ( '' !== $p['message'] ? ' (' . $p['message'] . ')' : '' ), (array) ( $last_run['problems'] ?? array() ) ) );
			$out[] = self::check(
				'last_run',
				Run::FAILED === $status ? self::CRITICAL : self::RECOMMENDED,
				Run::FAILED === $status ? 'The last cache purge failed' : 'The last cache purge only partly worked',
				'' !== $names ? 'Problems: ' . $names . '. Visitors may still see old pages.' : 'Visitors may still see old pages.'
			);
		}
		if ( array() === $out ) {
			$out[] = self::check( 'ok', self::GOOD, 'Cache purging is working', 'No problems with the last purge.' );
		}
		return $out;
	}

	/** The worst status among the checks. */
	public static function worst( array $checks ): string {
		$statuses = array_column( $checks, 'status' );
		if ( in_array( self::CRITICAL, $statuses, true ) ) {
			return self::CRITICAL;
		}
		return in_array( self::RECOMMENDED, $statuses, true ) ? self::RECOMMENDED : self::GOOD;
	}

	private static function check( string $id, string $status, string $label, string $description ): array {
		return array(
			'id'          => $id,
			'status'      => $status,
			'label'       => $label,
			'description' => $description,
		);
	}
}
