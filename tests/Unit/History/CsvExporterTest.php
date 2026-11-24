<?php
namespace PurgeKit\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use PurgeKit\History\CsvExporter;

final class CsvExporterTest extends TestCase {
	private function row( array $over = array() ): array {
		return $over + array(
			'id'          => 7,
			'created_at'  => '2026-11-24 10:00:00',
			'source'      => 'cron',
			'status'      => 'partial',
			'all'         => false,
			'urls'        => array( 'https://a.test/x', 'https://a.test/y' ),
			'urls_total'  => 500,
			'results'     => array(
				array( 'layer' => 'object-cache', 'status' => 'ok', 'message' => '' ),
				array( 'layer' => 'cloudflare', 'status' => 'failed', 'message' => 'Invalid token' ),
			),
			'duration_ms' => 120,
			'verification' => array( 'status' => 'stale', 'message' => 'Cloudflare still serves a stale copy of https://a.test/x.' ),
		);
	}

	private function csv_line( string $csv ): array {
		return str_getcsv( trim( $csv ), ',', '"', '' );
	}

	public function test_header_and_a_row(): void {
		$e   = new CsvExporter();
		$csv = $e->header() . $e->rows( array( $this->row() ) );
		$out = array_map( static fn( $l ) => str_getcsv( $l, ',', '"', '' ), array_values( array_filter( explode( "\r\n", $csv ) ) ) );
		$this->assertSame( CsvExporter::COLUMNS, $out[0] );
		$this->assertSame( array( '7', '2026-11-24 10:00:00', 'cron', 'partial', 'no', '500', 'cloudflare (Invalid token)', 'object-cache:ok; cloudflare:failed', '120', 'https://a.test/x https://a.test/y', 'stale: Cloudflare still serves a stale copy of https://a.test/x.' ), $out[1] );
		$this->assertSame( 'verification', end( $out[0] ), 'The new column is appended at the end.' );
	}

	public function test_quotes_and_commas_survive_a_round_trip(): void {
		$e   = new CsvExporter();
		$row = $this->row( array( 'results' => array( array( 'layer' => 'a', 'status' => 'failed', 'message' => 'He said "no", then left' ) ) ) );
		$out = $this->csv_line( $e->rows( array( $row ) ) );
		$this->assertSame( 'a (He said "no", then left)', $out[6] );
	}

	public function test_formula_injection_is_defused(): void {
		$e   = new CsvExporter();
		$row = $this->row( array( 'source' => '=HYPERLINK("http://evil")', 'urls' => array( '@SUM(1)' ) ) );
		$out = $this->csv_line( $e->rows( array( $row ) ) );
		$this->assertSame( "'=HYPERLINK(\"http://evil\")", $out[2] );
		$this->assertSame( "'@SUM(1)", $out[9] );
		$this->assertSame( '7', $out[0], 'Plain numbers are untouched.' );
	}

	public function test_old_rows_without_urls_total_use_the_list_length(): void {
		$e   = new CsvExporter();
		$row = $this->row();
		unset( $row['urls_total'] );
		$this->assertSame( '2', $this->csv_line( $e->rows( array( $row ) ) )[5] );
	}

	public function test_old_rows_without_verification_have_an_empty_cell_and_the_text_is_formula_safe(): void {
		$e   = new CsvExporter();
		$row = $this->row();
		unset( $row['verification'] );
		$this->assertSame( '', $this->csv_line( $e->rows( array( $row ) ) )[10] );
		$row['verification'] = array( 'status' => '=cmd', 'message' => '' );
		$this->assertSame( "'=cmd", $this->csv_line( $e->rows( array( $row ) ) )[10] );
	}
}
