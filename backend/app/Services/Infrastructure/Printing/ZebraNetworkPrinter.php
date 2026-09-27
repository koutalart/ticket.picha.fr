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
                __('Printer unreachable — check the IP address (:host).', ['host' => $host])
            );
        }

        stream_set_timeout($socket, 3);

        $length = strlen($payload);
        $sent = 0;
        while ($sent < $length) {
            $written = @fwrite($socket, substr($payload, $sent));
            if ($written === false || $written === 0) {
                break;
            }
            $sent += $written;
        }
        fclose($socket);

        if ($sent < $length) {
            throw new ZebraPrinterUnreachableException(
                __('The ticket could not be fully sent to the printer at :host. Check the printer and try again.', ['host' => $host])
            );
        }
    }
}
