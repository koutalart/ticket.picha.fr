<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\EventAssistant;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Http\Request\Concerns\NormalizesUploadedImage;

class ReadEventPosterRequest extends BaseRequest
{
    use NormalizesUploadedImage;

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:8192', 'mimes:jpeg,png,jpg,webp'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    public function messages(): array
    {
        return $this->imageFormatMessages();
    }
}
