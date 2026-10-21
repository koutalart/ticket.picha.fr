<?php

namespace Tests\Unit\Exports;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exports\AttendeeEntryControlListExport;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeEntryControlListDTO;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileUrlGenerator;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Mockery;
use Tests\TestCase;

class AttendeeEntryControlListExportTest extends TestCase
{
    private AttendeeEntryControlListExport $export;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->export = new AttendeeEntryControlListExport(
            new QuestionAnswerFormatter(Mockery::mock(QuestionAnswerFileUrlGenerator::class)),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_headings_list_identity_questions_and_entry_columns(): void
    {
        $this->export->withData($this->data(collect()));

        $this->assertSame([
            'Last Name',
            'First Name',
            'Email',
            'Phone',
            'Ticket',
            'Order number',
            'Commune',
            'Téléphone',
            'Profil',
            'Status',
            'Entry',
            'Scan time',
        ], $this->export->headings());
    }

    public function test_maps_scanned_active_attendee_with_answers_and_phone(): void
    {
        $attendee = $this->attendee(AttendeeStatus::ACTIVE, OrderStatus::COMPLETED)
            ->setQuestionAndAnswerViews(collect([
                $this->answer(3, QuestionTypeEnum::CHECKBOX, ['Artiste', 'Acteur culturel']),
            ]))
            ->setCheckIns(collect([
                (new AttendeeCheckInDomainObject)->setId(2)->setCreatedAt('2026-10-21 16:30:00'),
                (new AttendeeCheckInDomainObject)->setId(1)->setCreatedAt('2026-10-21 15:05:00'),
            ]));

        $this->export->withData($this->data(collect([$attendee])));

        $this->assertSame([
            'Ali',
            'Fatima',
            'fatima@example.com',
            '+262639000000',
            'Pass soirée',
            'O-ABC123',
            'Mamoudzou',
            '+262639000000',
            'Artiste, Acteur culturel',
            'Active',
            'Scanned',
            '21/10/2026 18:05',
        ], $this->export->map($attendee));
    }

    public function test_maps_cancelled_unscanned_attendee_with_box_office_phone(): void
    {
        $attendee = $this->attendee(AttendeeStatus::ACTIVE, OrderStatus::CANCELLED);
        $attendee->getOrder()->setQuestionAndAnswerViews(collect());
        $attendee->setQuestionAndAnswerViews(collect())->setCheckIns(collect());

        $this->export->withData($this->data(collect([$attendee]), [10 => '0639112233']));

        $row = $this->export->map($attendee);

        $this->assertSame('0639112233', $row[3]);
        $this->assertSame('', $row[6]);
        $this->assertSame('', $row[8]);
        $this->assertSame(['Cancelled', 'Not scanned', ''], array_slice($row, -3));
    }

    private function data($attendees, array $boxOfficePhones = []): AttendeeEntryControlListDTO
    {
        return new AttendeeEntryControlListDTO(
            attendees: $attendees,
            orderQuestions: collect([
                $this->question(1, 'Commune', QuestionTypeEnum::DROPDOWN, QuestionBelongsTo::ORDER),
                $this->question(2, 'Téléphone', QuestionTypeEnum::PHONE, QuestionBelongsTo::ORDER),
            ]),
            productQuestions: collect([
                $this->question(3, 'Profil', QuestionTypeEnum::CHECKBOX, QuestionBelongsTo::PRODUCT),
            ]),
            boxOfficePhonesByOrderId: $boxOfficePhones,
            timezone: 'Indian/Mayotte',
        );
    }

    private function attendee(AttendeeStatus $status, OrderStatus $orderStatus): AttendeeDomainObject
    {
        $order = (new OrderDomainObject)
            ->setId(10)
            ->setPublicId('O-ABC123')
            ->setStatus($orderStatus->name)
            ->setQuestionAndAnswerViews(collect([
                $this->answer(1, QuestionTypeEnum::DROPDOWN, 'Mamoudzou'),
                $this->answer(2, QuestionTypeEnum::PHONE, '+262639000000'),
            ]));

        $attendee = (new AttendeeDomainObject)
            ->setId(100)
            ->setOrderId(10)
            ->setFirstName('Fatima')
            ->setLastName('Ali')
            ->setEmail('fatima@example.com')
            ->setStatus($status->name)
            ->setProduct((new ProductDomainObject)->setId(5)->setTitle('Pass soirée')->setType(ProductPriceType::PAID->name));
        $attendee->setOrder($order);

        return $attendee;
    }

    private function answer(int $questionId, QuestionTypeEnum $type, string|array $answer): QuestionAndAnswerViewDomainObject
    {
        return (new QuestionAndAnswerViewDomainObject)
            ->setQuestionId($questionId)
            ->setQuestionType($type->name)
            ->setAnswer($answer);
    }

    private function question(int $id, string $title, QuestionTypeEnum $type, QuestionBelongsTo $belongsTo): QuestionDomainObject
    {
        return (new QuestionDomainObject)
            ->setId($id)
            ->setTitle($title)
            ->setType($type->name)
            ->setBelongsTo($belongsTo->name);
    }
}
