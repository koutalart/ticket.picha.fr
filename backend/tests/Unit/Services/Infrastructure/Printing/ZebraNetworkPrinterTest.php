<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Printing;

use HiEvents\Exceptions\ZebraPrinterUnreachableException;
use HiEvents\Services\Infrastructure\Printing\ZebraNetworkPrinter;
use Tests\TestCase;

class ZebraNetworkPrinterTest extends TestCase
{
    public function test_sends_full_payload_to_listening_printer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', stream_socket_get_name($server, false));
        $payload = '^XA'.str_repeat('^FO10,10^FDX^FS', 5000).'^XZ';

        (new ZebraNetworkPrinter)->send('127.0.0.1', (int) $port, $payload);

        $connection = stream_socket_accept($server, 1);
        $received = stream_get_contents($connection);
        fclose($connection);
        fclose($server);

        self::assertSame($payload, $received);
    }

    public function test_unreachable_printer_throws_clear_message(): void
    {
        app()->setLocale('fr');
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', stream_socket_get_name($server, false));
        fclose($server);

        try {
            (new ZebraNetworkPrinter)->send('127.0.0.1', (int) $port, '^XA^XZ');
            self::fail('expected unreachable printer');
        } catch (ZebraPrinterUnreachableException $exception) {
            self::assertSame(
                "Imprimante injoignable — vérifiez l'adresse IP (127.0.0.1).",
                $exception->getMessage(),
            );
        }
    }
}
