<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\HistoryWriter;
use PurgeKit\Core\Run;

final class FakeHistory implements HistoryWriter {
	public array $rows = array();
	public function record( string $source, Run $run, array $changes ): void {
		$this->rows[] = array( $source, $run, $changes );
	}
}
