<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\HistoryWriter;
use PurgeKit\Core\Run;

final class FakeHistory implements HistoryWriter {
	public array $rows        = array();
	public array $annotations = array();
	public function record( string $source, Run $run, array $changes ): int {
		$this->rows[] = array( $source, $run, $changes );
		return count( $this->rows );
	}
	public function annotate( int $id, array $verification ): void {
		$this->annotations[ $id ] = $verification;
	}
}
