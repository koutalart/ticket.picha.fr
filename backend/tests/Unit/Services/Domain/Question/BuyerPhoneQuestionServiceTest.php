<?php

namespace Tests\Unit\Services\Domain\Question;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Question\BuyerPhoneQuestionService;
use Mockery;
use Tests\TestCase;

class BuyerPhoneQuestionServiceTest extends TestCase
{
    public function test_creates_a_required_order_phone_question(): void
    {
        $questions = Mockery::mock(QuestionRepositoryInterface::class);
        $questions->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $questions->shouldReceive('create')->once()->with(Mockery::on(fn($a) => $a['event_id'] === 5
            && $a['type'] === 'PHONE' && $a['belongs_to'] === 'ORDER' && $a['required'] === true));

        (new BuyerPhoneQuestionService($questions))->ensureRequired(5);
        $this->assertTrue(true);
    }

    public function test_makes_an_existing_optional_phone_question_required(): void
    {
        $existing = (new QuestionDomainObject())->setId(9)->setRequired(false);
        $questions = Mockery::mock(QuestionRepositoryInterface::class);
        $questions->shouldReceive('findFirstWhere')->once()->andReturn($existing);
        $questions->shouldReceive('updateWhere')->once()->with(['required' => true], ['id' => 9]);
        $questions->shouldNotReceive('create');

        (new BuyerPhoneQuestionService($questions))->ensureRequired(5);
        $this->assertTrue(true);
    }

    public function test_leaves_a_required_phone_question_alone(): void
    {
        $existing = (new QuestionDomainObject())->setId(9)->setRequired(true);
        $questions = Mockery::mock(QuestionRepositoryInterface::class);
        $questions->shouldReceive('findFirstWhere')->once()->andReturn($existing);
        $questions->shouldNotReceive('updateWhere');
        $questions->shouldNotReceive('create');

        (new BuyerPhoneQuestionService($questions))->ensureRequired(5);
        $this->assertTrue(true);
    }
}
