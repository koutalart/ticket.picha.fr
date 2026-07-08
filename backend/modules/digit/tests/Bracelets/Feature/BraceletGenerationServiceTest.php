<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Feature;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletGenerationService;
use Digit\Tests\Bracelets\Support\FakeSequentialCodeGenerator;
use Digit\Tests\Bracelets\Support\InMemorySqliteTestCase;

class BraceletGenerationServiceTest extends InMemorySqliteTestCase
{
    private const EVENT_ID = 1;
    private const ACCOUNT_ID = 1;

    public function testGeneratesRequestedQuantityWithUniqueCodesAndGeneratedStatus(): void
    {
        $service = new BraceletGenerationService();

        $created = $service->generate(self::EVENT_ID, self::ACCOUNT_ID, 250, 'Test Batch');

        $this->assertSame(250, $created);

        $rows = DigitBracelet::query()->where('event_id', self::EVENT_ID)->get();
        $this->assertCount(250, $rows);
        $this->assertCount(250, $rows->pluck('code')->unique(), 'All generated codes must be unique.');
        $this->assertTrue($rows->every(fn (DigitBracelet $b) => $b->status === BraceletStatus::GENERATED));
        $this->assertTrue($rows->every(fn (DigitBracelet $b) => $b->batch_label === 'Test Batch'));
    }

    public function testCollisionWithAnExistingCodeIsRetriedNotFatal(): void
    {
        // Pre-existing bracelet occupies 'BR-COLLISION' already.
        DigitBracelet::query()->create([
            'code' => 'BR-COLLISION',
            'event_id' => self::EVENT_ID,
            'account_id' => self::ACCOUNT_ID,
            'status' => BraceletStatus::GENERATED->value,
        ]);

        // Fake generator scripted to hand out the colliding code for both
        // the initial bulk-insert attempt AND the first row-by-row retry
        // attempt, before finally succeeding on the second retry with a
        // fresh, non-colliding code.
        $fakeGenerator = new FakeSequentialCodeGenerator([
            'BR-COLLISION',
            'BR-COLLISION',
            'BR-COLLISION-RESOLVED',
        ]);

        $service = new BraceletGenerationService($fakeGenerator);

        $created = $service->generate(self::EVENT_ID, self::ACCOUNT_ID, 1, null);

        $this->assertSame(1, $created);

        $newRow = DigitBracelet::query()
            ->where('event_id', self::EVENT_ID)
            ->where('code', '!=', 'BR-COLLISION')
            ->first();

        $this->assertNotNull($newRow);
        $this->assertSame('BR-COLLISION-RESOLVED', $newRow->code);

        // The original bracelet must be untouched.
        $this->assertSame(2, DigitBracelet::query()->where('event_id', self::EVENT_ID)->count());
    }
}
