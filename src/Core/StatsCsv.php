<?php
namespace PurgeKit\Core;

/** The per-layer comparison as CSV text. No WordPress calls. */
final class StatsCsv {
	public const COLUMNS = array( 'layer', 'group', 'state', 'purges', 'failed', 'success_percent', 'full_purges', 'pages_purged', 'avg_ms', 'slowest_ms', 'hit_rate_percent', 'memory_percent', 'last_purge_utc' );

	/** @param array<int,array<string,mixed>> $rows CompareData::rows() */
	public function build( array $rows ): string {
		$out = $this->line( self::COLUMNS );
		foreach ( $rows as $r ) {
			$out .= $this->line(
				array(
					$r['label'] ?? '',
					$r['tier_label'] ?? '',
					$r['state'] ?? '',
					$r['purges'] ?? 0,
					$r['failed'] ?? 0,
					$r['success_rate'] ?? '',
					$r['full'] ?? 0,
					$r['urls'] ?? 0,
					$r['avg_ms'] ?? 0,
					$r['slowest_ms'] ?? 0,
					$r['hit_rate'] ?? '',
					$r['memory'] ?? '',
					$r['last_at'] ?? '',
				)
			);
		}
		return $out;
	}

	/** @param array<int,mixed> $cells */
	private function line( array $cells ): string {
		return implode( ',', array_map( array( $this, 'cell' ), $cells ) ) . "\r\n";
	}

	private function cell( mixed $value ): string {
		$text = (string) $value;
		// A cell that starts like this would be run as a formula by a spreadsheet.
		if ( '' !== $text && str_contains( "=+-@\t\r", $text[0] ) && ! is_numeric( $text ) ) {
			$text = "'" . $text;
		}
		return '"' . str_replace( '"', '""', $text ) . '"';
	}
}
