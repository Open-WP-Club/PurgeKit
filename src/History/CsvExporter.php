<?php
namespace PurgeKit\History;

/** Turns history rows (as returned by Repository::recent) into CSV text. No WordPress calls. */
final class CsvExporter {
	public const COLUMNS = array( 'id', 'created_at_utc', 'source', 'status', 'purged_everything', 'url_count', 'failed_layers', 'layer_results', 'duration_ms', 'urls', 'verification' );

	public function header(): string {
		return $this->line( self::COLUMNS );
	}

	/** @param array<int, array<string, mixed>> $rows */
	public function rows( array $rows ): string {
		$out = '';
		foreach ( $rows as $row ) {
			$results = (array) ( $row['results'] ?? array() );
			$failed  = array();
			$all     = array();
			foreach ( $results as $r ) {
				$all[] = ( $r['layer'] ?? '' ) . ':' . ( $r['status'] ?? '' );
				if ( 'failed' === ( $r['status'] ?? '' ) ) {
					$failed[] = ( $r['layer'] ?? '' ) . ( '' !== (string) ( $r['message'] ?? '' ) ? ' (' . $r['message'] . ')' : '' );
				}
			}
			$out .= $this->line(
				array(
					(int) ( $row['id'] ?? 0 ),
					(string) ( $row['created_at'] ?? '' ),
					(string) ( $row['source'] ?? '' ),
					(string) ( $row['status'] ?? '' ),
					! empty( $row['all'] ) ? 'yes' : 'no',
					(int) ( $row['urls_total'] ?? count( (array) ( $row['urls'] ?? array() ) ) ),
					implode( '; ', $failed ),
					implode( '; ', $all ),
					(int) ( $row['duration_ms'] ?? 0 ),
					implode( ' ', array_map( 'strval', (array) ( $row['urls'] ?? array() ) ) ),
					$this->verification( $row['verification'] ?? null ),
				)
			);
		}
		return $out;
	}

	private function verification( mixed $v ): string {
		if ( ! is_array( $v ) || ! isset( $v['status'] ) ) {
			return '';
		}
		$message = (string) ( $v['message'] ?? '' );
		return (string) $v['status'] . ( '' !== $message ? ': ' . $message : '' );
	}

	/** @param array<int, scalar> $cells */
	private function line( array $cells ): string {
		return implode( ',', array_map( array( $this, 'cell' ), $cells ) ) . "\r\n";
	}

	private function cell( mixed $value ): string {
		$text = (string) $value;
		// Spreadsheet formula injection: a cell starting with these characters would be run as a formula.
		if ( '' !== $text && str_contains( "=+-@\t\r", $text[0] ) && ! is_numeric( $text ) ) {
			$text = "'" . $text;
		}
		return '"' . str_replace( '"', '""', $text ) . '"';
	}
}
