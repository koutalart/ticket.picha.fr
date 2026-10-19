<?php

namespace Tests\Unit\Console;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use Mockery;
use Tests\TestCase;

class PurgeQuestionAnswerFilesCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_purges_files_unattached_for_more_than24_hours(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');

        $service = Mockery::mock(QuestionAnswerFileService::class);
        $service->shouldReceive('purgeOrphanedFiles')
            ->once()
            ->withArgs(fn (CarbonInterface $cutoff) => $cutoff->equalTo(Carbon::parse('2026-10-18 12:00:00')))
            ->andReturn(3);
        $this->app->instance(QuestionAnswerFileService::class, $service);

        $this->artisan('question-files:purge')
            ->expectsOutput('Deleted 3 question file(s).')
            ->assertSuccessful();
    }
}
