<?php

namespace HiEvents\Http\Request\Questions;

use HiEvents\Http\Request\BaseRequest;

class UploadQuestionAnswerFileRequest extends BaseRequest
{
    public const MAX_FILE_SIZE_KB = 5120;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::MAX_FILE_SIZE_KB],
            'question_id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('Please select a PDF file.'),
            'file.mimes' => __('Only PDF files are accepted.'),
            'file.mimetypes' => __('Only PDF files are accepted.'),
            'file.max' => __('The file must not be larger than 5 MB.'),
            'file.uploaded' => __('The file could not be uploaded. It must not be larger than 5 MB.'),
        ];
    }
}
