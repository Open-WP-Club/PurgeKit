<?php
namespace PurgeKit\Core;

interface Mailer {
	public function send( string $to, string $subject, string $body ): bool;
}
