<?php

namespace HiEvents\Exports;

use Carbon\Carbon;
use HiEvents\DomainObjects\OrganizerNewsletterSubscriberDomainObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrganizerNewsletterSubscribersExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    private LengthAwarePaginator $subscribers;

    public function withData(LengthAwarePaginator $subscribers): self
    {
        $this->subscribers = $subscribers;

        return $this;
    }

    public function collection(): Collection
    {
        return collect($this->subscribers->items());
    }

    public function headings(): array
    {
        return [
            __('Email'),
            __('Source event'),
            __('Consent date'),
            __('Consent text'),
        ];
    }

    /**
     * @param OrganizerNewsletterSubscriberDomainObject $subscriber
     */
    public function map($subscriber): array
    {
        return [
            $subscriber->getEmail(),
            $subscriber->getEventTitle() ?? '',
            Carbon::parse($subscriber->getConsentedAt())->format('Y-m-d H:i:s'),
            $subscriber->getConsentText(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
