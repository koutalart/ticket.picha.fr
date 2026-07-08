<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Services;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-generates DigitBracelet rows. Designed for the Somaroho scale
 * (~93,020 units) - always chunked, always via query builder inserts (not
 * per-row Eloquent saves), so this can run to completion as a long-lived
 * CLI process (see DigitBraceletsGenerateCommand) without an HTTP timeout
 * ever being a concern.
 *
 * Code format/checksum logic lives in BraceletCodeGenerator (extracted so
 * it is unit-testable without a database).
 *
 * Each individual insert attempt runs inside its own DB::transaction()
 * call, nested within the chunk's outer transaction. On Postgres (and any
 * driver supporting savepoints), a nested DB::transaction() becomes a
 * SAVEPOINT: if the attempt fails (e.g. a unique-constraint collision),
 * Laravel rolls back to that savepoint and rethrows, leaving the *outer*
 * chunk transaction perfectly healthy for the next attempt. Without this,
 * a single failed statement would instead abort the whole outer Postgres
 * transaction (error 25P02, "current transaction is aborted") and make
 * every subsequent retry in the same chunk fail too - exactly defeating
 * the point of the retry logic this method exists to provide.
 */
class BraceletGenerationService
{
    private const CHUNK_SIZE = 1000;

    private const MAX_COLLISION_RETRIES = 5;

    public function __construct(
        private readonly BraceletCodeGenerator $codeGenerator = new BraceletCodeGenerator(),
    ) {
    }

    /**
     * @return int the number of bracelets actually created
     */
    public function generate(int $eventId, int $accountId, int $quantity, ?string $batchLabel = null): int
    {
        $created = 0;
        $remaining = $quantity;

        while ($remaining > 0) {
            $chunkSize = min(self::CHUNK_SIZE, $remaining);
            $created += $this->generateChunk($eventId, $accountId, $chunkSize, $batchLabel);
            $remaining -= $chunkSize;
        }

        return $created;
    }

    private function generateChunk(int $eventId, int $accountId, int $chunkSize, ?string $batchLabel): int
    {
        $now = now();

        $rows = [];
        $codesInChunk = [];

        for ($i = 0; $i < $chunkSize; $i++) {
            $code = $this->generateUniqueCodeWithinBatch($codesInChunk);
            $codesInChunk[$code] = true;

            $rows[] = [
                'code' => $code,
                'event_id' => $eventId,
                'account_id' => $accountId,
                'attendee_id' => null,
                'batch_label' => $batchLabel,
                'status' => BraceletStatus::GENERATED->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return DB::transaction(function () use ($rows) {
            return $this->insertWithCollisionHandling($rows);
        });
    }

    /**
     * Attempts the whole chunk as a single bulk statement, itself inside a
     * savepoint (see class docblock). On a unique-constraint collision,
     * falls back to inserting the chunk row by row so only the colliding
     * row needs a fresh code, instead of discarding the whole chunk.
     */
    private function insertWithCollisionHandling(array $rows): int
    {
        try {
            DB::transaction(function () use ($rows) {
                DB::table('digit_bracelets')->insert($rows);
            });

            return count($rows);
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->insertRowByRowWithRetry($rows);
        }
    }

    private function insertRowByRowWithRetry(array $rows): int
    {
        $created = 0;

        foreach ($rows as $row) {
            $attempts = 0;

            while (true) {
                try {
                    DB::transaction(function () use ($row) {
                        DB::table('digit_bracelets')->insert($row);
                    });
                    $created++;
                    break;
                } catch (QueryException $e) {
                    if (!$this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    $attempts++;

                    if ($attempts > self::MAX_COLLISION_RETRIES) {
                        throw $e;
                    }

                    $row['code'] = $this->codeGenerator->generate();
                }
            }
        }

        return $created;
    }

    private function generateUniqueCodeWithinBatch(array $codesInChunk): string
    {
        do {
            $code = $this->codeGenerator->generate();
        } while (isset($codesInChunk[$code]));

        return $code;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'digit_bracelets_code_unique')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || (int) ($e->errorInfo[1] ?? 0) === 1062
            || $e->getCode() === '23505';
    }
}
