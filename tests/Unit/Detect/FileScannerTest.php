<?php
namespace PurgeKit\Tests\Unit\Detect;

use PHPUnit\Framework\TestCase;
use PurgeKit\Detect\FileScanner;
use PurgeKit\Tests\Fakes\FakeStore;

final class FileScannerTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/purgekit-scan-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir . '/sub', 0777, true );
		file_put_contents( $this->dir . '/style.css', 'a' );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/{*,sub/*}', GLOB_BRACE ) ?: array() as $f ) {
			is_file( $f ) && unlink( $f );
		}
		@rmdir( $this->dir . '/sub' );
		@rmdir( $this->dir );
	}

	public function test_scan_first_run_false_then_true_after_file_change(): void {
		$s = new FileScanner( new FakeStore(), array( $this->dir ), array( 'css', 'php' ) );
		$this->assertFalse( $s->scan() );
		$this->assertFalse( $s->scan() );
		file_put_contents( $this->dir . '/sub/new.php', '<?php' );
		$this->assertTrue( $s->scan() );
		$this->assertFalse( $s->scan() );
	}

	public function test_scan_detects_content_size_change(): void {
		$s = new FileScanner( new FakeStore(), array( $this->dir ), array( 'css' ) );
		$s->scan();
		file_put_contents( $this->dir . '/style.css', 'abcdef' );
		$this->assertTrue( $s->scan() );
	}

	public function test_scan_ignores_unlisted_extensions(): void {
		$s = new FileScanner( new FakeStore(), array( $this->dir ), array( 'css' ) );
		$s->scan();
		file_put_contents( $this->dir . '/notes.txt', 'x' );
		$this->assertFalse( $s->scan() );
	}

	public function test_scan_detects_deleted_file(): void {
		$s = new FileScanner( new FakeStore(), array( $this->dir ), array( 'css' ) );
		$s->scan();
		unlink( $this->dir . '/style.css' );
		$this->assertTrue( $s->scan() );
	}

	public function test_missing_directory_does_not_crash(): void {
		$s = new FileScanner( new FakeStore(), array( '/nope/' . bin2hex( random_bytes( 4 ) ) ), array( 'php' ) );
		$this->assertFalse( $s->scan() );
	}
}
