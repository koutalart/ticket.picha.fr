<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Newsletter;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exports\OrganizerNewsletterSubscribersExport;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\GetOrganizerNewsletterSubscribersHandler;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportOrganizerNewsletterSubscribersAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerNewsletterSubscribersHandler $handler,
        private readonly OrganizerNewsletterSubscribersExport     $export,
    )
    {
    }

    public function __invoke(Request $request, int $organizerId): BinaryFileResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $subscribers = $this->handler->handleExport(
            $organizerId,
            mb_substr((string)$request->input('query', ''), 0, 100),
        );

        return Excel::download(
            $this->export->withData($subscribers),
            'abonnes-lettre-information.xlsx'
        );
    }
}
