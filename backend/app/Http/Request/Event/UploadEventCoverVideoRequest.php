<?php

namespace HiEvents\Http\Request\Event;

use Illuminate\Foundation\Http\FormRequest;

class UploadEventCoverVideoRequest extends FormRequest
{
    public const MAX_SIZE_KB = 15360;

    public function rules(): array
    {
        return [
            'video' => [
                'required',
                'file',
                'max:'.self::MAX_SIZE_KB,
                'mimetypes:video/mp4,video/webm',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'video.max' => __('The video must not be larger than 15 MB.'),
            'video.mimetypes' => __('The video must be an MP4 or WebM file.'),
        ];
    }
}
