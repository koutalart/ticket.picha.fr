<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class SaveOrganizerTeamContactRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:150'],
            'role' => ['required', 'string', Rule::in(OrganizerTeamContactRole::valuesArray())],
        ];
    }
}
