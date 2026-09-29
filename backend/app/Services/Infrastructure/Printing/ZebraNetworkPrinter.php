<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Printing;

use HiEvents\Exceptions\ZebraPrinterUnreachableException;

class ZebraNetworkPrinter implements ZebraPrinterClientInterface
{
    public function send(string $host, int $port, string $payload): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            3.0,
        );

        if ($socket === false) {
            throw new ZebraPrinterUnreachableException(
                __('Could not reach the Zebra printer at :host.', ['host' => $host])
            );
        }

        stream_set_timeout($socket, 3);

        $written = fwrite($socket, $payload);
        fclose($socket);

        if ($written === false) {
            throw new ZebraPrinterUnreachableException(
                __('Could not send the ticket to the Zebra printer at :host.', ['host' => $host])
            );
        }
    }
}
