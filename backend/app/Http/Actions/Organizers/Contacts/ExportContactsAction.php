<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportContactsAction extends BaseAction
{
    public function __construct(private readonly ContactRepositoryInterface $contactRepository) {}

    public function __invoke(Request $request, int $organizerId): StreamedResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $contacts = $this->contactRepository->lazyForOrganizer(
            $organizerId,
            ContactFilterDTO::fromQuery($request->query->all()),
        );

        $headers = [
            __('First name'), __('Last name'), __('Email'), __('Phone'),
            __('Accepts emails'), __('Accepts SMS'), __('Events'), __('Events attended'),
            __('Orders'), __('Total spent'), __('First purchase'), __('Last purchase'), __('Tags'), __('Source'),
        ];

        return new StreamedResponse(function () use ($contacts, $headers) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ';');

            /** @var ContactDomainObject $contact */
            foreach ($contacts as $contact) {
                fputcsv($output, array_map(self::escapeCell(...), [
                    $contact->getFirstName(),
                    $contact->getLastName(),
                    $contact->getEmail(),
                    $contact->getPhone(),
                    $contact->hasEmailConsent() ? __('Yes') : __('No'),
                    $contact->hasSmsConsent() ? __('Yes') : __('No'),
                    $contact->getEventsCount(),
                    $contact->getAttendedEventsCount(),
                    $contact->getOrdersCount(),
                    number_format((float) $contact->getTotalSpent(), 2, ',', ''),
                    $contact->getFirstOrderAt(),
                    $contact->getLastOrderAt(),
                    ($contact->getContactTags() ?? collect())
                        ->map(fn (ContactTagDomainObject $tag) => $tag->getName())
                        ->implode(', '),
                    $contact->getSource(),
                ]), ';');
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contacts-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private static function escapeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if (preg_match('/^[=@\t\r]/', $value) || preg_match('/^[+\-](?!\d+$)/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
