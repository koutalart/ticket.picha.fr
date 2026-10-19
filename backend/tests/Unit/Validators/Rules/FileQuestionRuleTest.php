<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Validators\Rules\OrderQuestionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FileQuestionRuleTest extends TestCase
{
    public function test_accepts_a_file_uploaded_for_the_same_question_and_order(): void
    {
        $validator = $this->validate(['answer' => 42], required: true);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_rejects_a_file_uploaded_for_another_question(): void
    {
        $validator = $this->validate(['answer' => 43], required: true);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.questions.0.response.answer', $validator->errors()->toArray());
    }

    public function test_rejects_an_unknown_file_id(): void
    {
        $validator = $this->validate(['answer' => 999], required: false);

        $this->assertTrue($validator->fails());
    }

    public function test_required_file_question_needs_a_file(): void
    {
        $validator = $this->validate(['answer' => ''], required: true);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.questions.0.response.answer', $validator->errors()->toArray());
    }

    public function test_optional_file_question_can_be_left_empty(): void
    {
        $validator = $this->validate(['answer' => ''], required: false);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_optional_file_question_can_be_sent_without_answer(): void
    {
        $validator = $this->validate([], required: false);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    private function validate(array $response, bool $required): \Illuminate\Validation\Validator
    {
        $question = (new QuestionDomainObject)
            ->setId(7)
            ->setEventId(1)
            ->setTitle('CV')
            ->setType(QuestionTypeEnum::FILE->name)
            ->setBelongsTo(QuestionBelongsTo::ORDER->name)
            ->setRequired($required)
            ->setIsHidden(false);

        $uploadedFiles = new Collection([
            (new QuestionAnswerFileDomainObject)->setId(42)->setOrderId(1)->setQuestionId(7),
            (new QuestionAnswerFileDomainObject)->setId(43)->setOrderId(1)->setQuestionId(8),
        ]);

        $rule = (new OrderQuestionRule(new Collection([$question]), new Collection))
            ->setUploadedFiles($uploadedFiles);

        $validator = Validator::make(
            ['order' => ['questions' => [['question_id' => 7, 'response' => $response]]]],
            ['order.questions' => $rule],
        );

        $validator->passes();

        return $validator;
    }
}
