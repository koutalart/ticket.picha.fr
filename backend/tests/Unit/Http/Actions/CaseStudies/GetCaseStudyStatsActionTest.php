<?php

namespace Tests\Unit\Http\Actions\CaseStudies;

use HiEvents\Services\Application\Handlers\CaseStudy\GetCaseStudyStatsHandler;
use Mockery as m;
use Tests\TestCase;

class GetCaseStudyStatsActionTest extends TestCase
{
    private const URL = '/public/case-study-stats?event_ids=4,6';

    private GetCaseStudyStatsHandler&m\MockInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = m::mock(GetCaseStudyStatsHandler::class);
        $this->app->instance(GetCaseStudyStatsHandler::class, $this->handler);
    }

    public function test_returns_not_found_when_token_is_not_configured(): void
    {
        config(['app.case_study_stats_token' => null]);
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL, ['Authorization' => 'Bearer anything'])->assertNotFound();
    }

    public function test_returns_not_found_with_wrong_token(): void
    {
        config(['app.case_study_stats_token' => 'secret-token']);
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL, ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $this->getJson(self::URL)->assertNotFound();
    }

    public function test_returns_stats_with_valid_token(): void
    {
        config(['app.case_study_stats_token' => 'secret-token']);
        $this->handler->shouldReceive('handle')->once()->with([4, 6])->andReturn([]);

        $this->getJson(self::URL, ['Authorization' => 'Bearer secret-token'])
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_rejects_invalid_event_ids(): void
    {
        config(['app.case_study_stats_token' => 'secret-token']);
        $this->handler->shouldNotReceive('handle');

        $this->getJson('/public/case-study-stats?event_ids=4;DROP', ['Authorization' => 'Bearer secret-token'])
            ->assertUnprocessable();
    }
}
