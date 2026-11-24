<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\Mailer;

final class FakeMailer implements Mailer {
	public array $sent = array();
	public bool $ok    = true;

	public function send( string $to, string $subject, string $body ): bool {
		$this->sent[] = compact( 'to', 'subject', 'body' );
		return $this->ok;
	}
}
