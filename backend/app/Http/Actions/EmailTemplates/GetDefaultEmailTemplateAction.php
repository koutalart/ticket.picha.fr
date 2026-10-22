<?php

namespace HiEvents\Http\Actions\EmailTemplates;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\EmailTemplateType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Email\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetDefaultEmailTemplateAction extends BaseAction
{
    public function __construct(
        private readonly EmailTemplateService $emailTemplateService,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $addressForm = $this->addressForm($request);
        $defaults = [];

        foreach (EmailTemplateType::cases() as $type) {
            $template = $this->emailTemplateService->getDefaultTemplate($type, $addressForm);
            $defaults[$type->value] = [
                'type' => $type->value,
                'subject' => $template['subject'],
                'body' => $template['body'],
                'cta' => $template['cta'] ?? null,
            ];
        }

        return $this->jsonResponse($defaults);
    }

    /**
     * The defaults follow the tone (vous / tu) of the organizer the template is created for.
     */
    private function addressForm(Request $request): CustomerAddressForm
    {
        $eventId = filter_var($request->query('event_id'), FILTER_VALIDATE_INT);
        if ($eventId !== false) {
            $this->isActionAuthorized($eventId, EventDomainObject::class);

            return $this->customerLocaleResolver->addressFormForEventId($eventId);
        }

        $organizerId = filter_var($request->query('organizer_id'), FILTER_VALIDATE_INT);
        if ($organizerId !== false) {
            $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

            return $this->customerLocaleResolver->addressFormForOrganizerId($organizerId);
        }

        return CustomerAddressForm::VOUS;
    }
}
