<?php

namespace Tests\Unit\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\GetQuestionAnswerDistributionHandler;
use HiEvents\Services\Domain\Question\DTO\QuestionAnswerCountDTO;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GetQuestionAnswerDistributionHandlerTest extends TestCase
{
    private QuestionRepositoryInterface|MockInterface $questionRepository;

    private QuestionAnswerRepositoryInterface|MockInterface $questionAnswerRepository;

    private GetQuestionAnswerDistributionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $this->questionAnswerRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);

        $this->handler = new GetQuestionAnswerDistributionHandler(
            $this->questionRepository,
            $this->questionAnswerRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_builds_sorted_distribution_for_choice_questions_only(): void
    {
        $commune = $this->question(1, 'Commune', QuestionTypeEnum::DROPDOWN, QuestionBelongsTo::ORDER, ['Mamoudzou', 'Koungou', 'Sada', 'Hors de Mayotte'], 2);
        $profile = $this->question(2, 'Profil', QuestionTypeEnum::CHECKBOX, QuestionBelongsTo::PRODUCT, ['Grand public', 'Artiste'], 1);
        $comment = $this->question(3, 'Commentaire', QuestionTypeEnum::SINGLE_LINE_TEXT, QuestionBelongsTo::ORDER, null, 3);

        $this->questionRepository->shouldReceive('findWhere')
            ->once()
            ->with([QuestionDomainObject::EVENT_ID => 7])
            ->andReturn(collect([$commune, $profile, $comment]));

        $this->questionAnswerRepository->shouldReceive('countActiveAnswerValues')
            ->once()
            ->with(7, [2, 1])
            ->andReturn(collect([
                new QuestionAnswerCountDTO(questionId: 1, value: 'Koungou', count: 3),
                new QuestionAnswerCountDTO(questionId: 1, value: 'Mamoudzou', count: 5),
                new QuestionAnswerCountDTO(questionId: 1, value: 'Ancienne option', count: 2),
                new QuestionAnswerCountDTO(questionId: 2, value: 'Grand public', count: 4),
                new QuestionAnswerCountDTO(questionId: 2, value: 'Artiste', count: 2),
            ]));

        $this->questionAnswerRepository->shouldReceive('countActiveRespondents')
            ->once()
            ->with(7, [2, 1])
            ->andReturn([1 => 10, 2 => 5]);

        $result = $this->handler->handle(7);

        $this->assertCount(2, $result);
        $this->assertSame(2, $result[0]->question_id);
        $this->assertSame('Profil', $result[0]->title);
        $this->assertSame(QuestionBelongsTo::PRODUCT->name, $result[0]->belongs_to);
        $this->assertSame(5, $result[0]->total_responses);
        $this->assertSame(80.0, $result[0]->options[0]->percentage);

        $communeResult = $result[1];
        $this->assertSame(10, $communeResult->total_responses);
        $this->assertSame(
            ['Mamoudzou', 'Koungou', 'Ancienne option', 'Sada', 'Hors de Mayotte'],
            array_map(fn ($option) => $option->label, $communeResult->options),
        );
        $this->assertSame([5, 3, 2, 0, 0], array_map(fn ($option) => $option->count, $communeResult->options));
        $this->assertSame([50.0, 30.0, 20.0, 0.0, 0.0], array_map(fn ($option) => $option->percentage, $communeResult->options));
    }

    public function test_returns_empty_when_no_choice_questions(): void
    {
        $this->questionRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([
                $this->question(3, 'Commentaire', QuestionTypeEnum::MULTI_LINE_TEXT, QuestionBelongsTo::ORDER, null, 1),
            ]));

        $this->questionAnswerRepository->shouldNotReceive('countActiveAnswerValues');
        $this->questionAnswerRepository->shouldNotReceive('countActiveRespondents');

        $this->assertTrue($this->handler->handle(7)->isEmpty());
    }

    public function test_percentages_are_zero_without_responses(): void
    {
        $this->questionRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([
                $this->question(1, 'Profil', QuestionTypeEnum::RADIO, QuestionBelongsTo::ORDER, ['Artiste', 'Grand public'], 1),
            ]));

        $this->questionAnswerRepository->shouldReceive('countActiveAnswerValues')->once()->andReturn(collect());
        $this->questionAnswerRepository->shouldReceive('countActiveRespondents')->once()->andReturn([]);

        $result = $this->handler->handle(7);

        $this->assertSame(0, $result[0]->total_responses);
        $this->assertSame(['Artiste', 'Grand public'], array_map(fn ($option) => $option->label, $result[0]->options));
        $this->assertSame([0.0, 0.0], array_map(fn ($option) => $option->percentage, $result[0]->options));
    }

    private function question(
        int $id,
        string $title,
        QuestionTypeEnum $type,
        QuestionBelongsTo $belongsTo,
        ?array $options,
        int $order,
    ): QuestionDomainObject {
        return (new QuestionDomainObject)
            ->setId($id)
            ->setEventId(7)
            ->setTitle($title)
            ->setType($type->name)
            ->setBelongsTo($belongsTo->name)
            ->setOptions($options)
            ->setOrder($order);
    }
}
