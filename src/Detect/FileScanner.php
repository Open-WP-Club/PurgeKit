<?php
namespace PurgeKit\Detect;

use PurgeKit\Core\Store;

/** Detects file changes WordPress never sees (FTP, git deploys, file editor). */
final class FileScanner {
	private const KEY = 'file_fingerprint';

	public function __construct( private Store $store, private array $dirs, private array $extensions ) {}

	/** True when the fingerprint differs from the last scan. The first scan only records one. */
	public function scan(): bool {
		$current  = $this->fingerprint();
		$previous = $this->store->get( self::KEY );
		$this->store->set( self::KEY, $current );
		return null !== $previous && $previous !== $current;
	}

	private function fingerprint(): string {
		$parts = array();
		foreach ( $this->dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), $this->extensions, true ) ) {
					$parts[] = $file->getPathname() . '|' . $file->getSize() . '|' . $file->getMTime();
				}
			}
		}
		sort( $parts );
		return md5( implode( "\n", $parts ) );
	}
}
