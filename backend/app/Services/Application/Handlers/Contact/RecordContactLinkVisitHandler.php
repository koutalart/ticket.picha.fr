<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Contact\ContactLinkVisitRecorder;
use HiEvents\Services\Domain\Contact\ContactPersonalLinkService;
use HiEvents\Services\Domain\Contact\DTO\ContactLinkVisitContextDTO;

class RecordContactLinkVisitHandler
{
    public function __construct(
        private readonly ContactPersonalLinkService $personalLinkService,
        private readonly ContactLinkVisitRecorder $visitRecorder,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(string $code, ContactLinkVisitContextDTO $context): bool
    {
        $contact = $this->personalLinkService->findByCode($code);

        if ($contact === null) {
            throw new ResourceNotFoundException(__('Link not found'));
        }

        return $this->visitRecorder->record($contact, $context);
    }
}
