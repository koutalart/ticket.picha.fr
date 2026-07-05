<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DigitScanDeviceCreateCommand extends Command
{
    protected $signature = 'digit:scan:device:create
        {--name= : Human-readable device name, e.g. "Entrance A - iPad 1"}
        {--account-id= : Account ID this device belongs to}
        {--event-id= : Optional event ID scope}
        {--check-in-list-id= : Optional check-in list ID scope (recommended - locks the token to one door)}';

    protected $description = 'Create a new DIGIT scan device token. The plaintext token is shown once and never stored.';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Device name (e.g. "Entrance A - iPad 1")');
        $accountId = $this->option('account-id') ?: $this->ask('Account ID');
        $eventId = $this->option('event-id');
        $checkInListId = $this->option('check-in-list-id');

        if (empty($name) || empty($accountId)) {
            $this->error('Name and account ID are required.');
            return self::FAILURE;
        }

        $token = Str::random(48);
        $tokenHash = hash('sha256', $token);

        $id = DB::table('digit_scan_devices')->insertGetId([
            'name' => $name,
            'account_id' => (int) $accountId,
            'event_id' => $eventId !== null ? (int) $eventId : null,
            'check_in_list_id' => $checkInListId !== null ? (int) $checkInListId : null,
            'token_hash' => $tokenHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->newLine();
        $this->line('<fg=green;options=bold>Device created.</>');
        $this->table(['Champ', 'Valeur'], [
            ['Device ID', $id],
            ['Name', $name],
            ['Account ID', $accountId],
            ['Event ID', $eventId ?? '(any)'],
            ['Check-in list ID', $checkInListId ?? '(any within account/event)'],
        ]);
        $this->newLine();
        $this->warn('Token (copiez-le maintenant, il ne sera plus jamais affiché) :');
        $this->line($token);
        $this->newLine();
        $this->line('À utiliser comme : Authorization: Bearer ' . $token);
        $this->line('ou header : X-Scan-Token: ' . $token);
        $this->newLine();

        return self::SUCCESS;
    }
}
