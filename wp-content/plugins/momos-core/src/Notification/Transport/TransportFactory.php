<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

/**
 * Transport selection: `MOMOS_MAIL_TRANSPORT=log` → LogTransport (dev/test), otherwise wp_mail over
 * SMTP. Filter `momos/notification/transport` lets tests inject a provider stub.
 */
final class TransportFactory {
	public static function make(): TransportInterface {
		$mode      = defined( 'MOMOS_MAIL_TRANSPORT' ) ? strtolower( (string) MOMOS_MAIL_TRANSPORT ) : 'smtp';
		$transport = 'log' === $mode ? new LogTransport() : new WpMailTransport();
		$filtered  = apply_filters( 'momos/notification/transport', $transport );
		return $filtered instanceof TransportInterface ? $filtered : $transport;
	}
}
