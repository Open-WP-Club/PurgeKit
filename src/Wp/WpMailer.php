<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Mailer;

final class WpMailer implements Mailer {
	public function send( string $to, string $subject, string $body ): bool {
		return wp_mail( $to, $subject, $body );
	}
}
