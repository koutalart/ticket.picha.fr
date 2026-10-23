<?php

namespace HiEvents\Exports;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeEntryControlListDTO;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendeeEntryControlListExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private AttendeeEntryControlListDTO $data;

    public function __construct(private readonly QuestionAnswerFormatter $questionAnswerFormatter) {}

    public function withData(AttendeeEntryControlListDTO $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function collection(): Collection
    {
        return $this->data->attendees;
    }

    public function title(): string
    {
        return __('Entry control list');
    }

    public function headings(): array
    {
        return array_merge(
            [
                __('Last Name'),
                __('First Name'),
                __('Email'),
                __('Phone'),
                __('Ticket'),
                __('Order number'),
            ],
            $this->data->orderQuestions->map(fn (QuestionDomainObject $question) => $question->getTitle())->all(),
            $this->data->productQuestions->map(fn (QuestionDomainObject $question) => $question->getTitle())->all(),
            [
                __('Status'),
                __('Entry'),
                __('Scan time'),
            ],
        );
    }

    /**
     * @param  AttendeeDomainObject  $attendee
     */
    public function map($attendee): array
    {
        $attendeeAnswers = $attendee->getQuestionAndAnswerViews() ?? collect();
        $orderAnswers = $attendee->getOrder()?->getQuestionAndAnswerViews() ?? collect();
        $firstCheckIn = $this->findFirstCheckIn($attendee);

        return array_merge(
            [
                $attendee->getLastName(),
                $attendee->getFirstName(),
                $attendee->getEmail(),
                $this->findPhone($attendee, $attendeeAnswers, $orderAnswers),
                $this->getTicketName($attendee),
                $attendee->getOrder()?->getPublicId() ?? '',
            ],
            $this->data->orderQuestions->map(fn (QuestionDomainObject $question) => $this->answerFor($question, $orderAnswers))->all(),
            $this->data->productQuestions->map(fn (QuestionDomainObject $question) => $this->answerFor($question, $attendeeAnswers))->all(),
            [
                $this->isCancelled($attendee) ? __('Cancelled') : $this->getActiveStatusLabel($attendee),
                $firstCheckIn ? __('Scanned') : __('Not scanned'),
                $firstCheckIn
                    ? Carbon::parse($firstCheckIn->getCreatedAt(), 'UTC')->setTimezone($this->data->timezone)->format('d/m/Y H:i')
                    : '',
            ],
        );
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function answerFor(QuestionDomainObject $question, Collection $answers): string
    {
        /** @var QuestionAndAnswerViewDomainObject|null $answer */
        $answer = $answers->first(fn (QuestionAndAnswerViewDomainObject $view) => $view->getQuestionId() === $question->getId());

        if ($answer === null || $answer->getAnswer() === null) {
            return '';
        }

        return $this->questionAnswerFormatter->getAnswerAsText(
            $answer->getAnswer(),
            QuestionTypeEnum::fromName($question->getType()),
            false,
        );
    }

    private function findPhone(AttendeeDomainObject $attendee, Collection $attendeeAnswers, Collection $orderAnswers): string
    {
        foreach ([$attendeeAnswers, $orderAnswers] as $answers) {
            /** @var QuestionAndAnswerViewDomainObject|null $phoneAnswer */
            $phoneAnswer = $answers->first(fn (QuestionAndAnswerViewDomainObject $view) => $view->getQuestionType() === QuestionTypeEnum::PHONE->name
                && ! empty($view->getAnswer()));

            if ($phoneAnswer !== null) {
                return $this->questionAnswerFormatter->getAnswerAsText($phoneAnswer->getAnswer(), QuestionTypeEnum::PHONE);
            }
        }

        return $this->data->boxOfficePhonesByOrderId[$attendee->getOrderId()] ?? '';
    }

    private function getTicketName(AttendeeDomainObject $attendee): string
    {
        $product = $attendee->getProduct();

        if ($product === null) {
            return __('Unknown');
        }

        $name = (string) $product->getTitle();

        if ($product->getType() === ProductPriceType::TIERED->name) {
            $label = $product->getProductPrices()
                ?->first(fn (ProductPriceDomainObject $price) => $price->getId() === $attendee->getProductPriceId())
                ?->getLabel();

            if ($label) {
                $name .= ' '.$label;
            }
        }

        return $name !== '' ? $name : __('Unknown');
    }

    private function findFirstCheckIn(AttendeeDomainObject $attendee): ?AttendeeCheckInDomainObject
    {
        return ($attendee->getCheckIns() ?? collect())
            ->sortBy(fn (AttendeeCheckInDomainObject $checkIn) => $checkIn->getCreatedAt())
            ->first();
    }

    private function isCancelled(AttendeeDomainObject $attendee): bool
    {
        return $attendee->getStatus() === AttendeeStatus::CANCELLED->name
            || $attendee->getOrder()?->getStatus() === OrderStatus::CANCELLED->name;
    }

    private function getActiveStatusLabel(AttendeeDomainObject $attendee): string
    {
        return $attendee->getStatus() === AttendeeStatus::AWAITING_PAYMENT->name
            ? __('Awaiting payment')
            : __('Active');
    }
}
