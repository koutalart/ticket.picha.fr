<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Printing;

use HiEvents\Exceptions\ZebraPrinterUnreachableException;

interface ZebraPrinterClientInterface
{
    /**
     * @throws ZebraPrinterUnreachableException
     */
    public function send(string $host, int $port, string $payload): void;
}
