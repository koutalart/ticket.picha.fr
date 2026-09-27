<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Illuminate\Validation\Rule;

class PrintBoxOfficeZplRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'printer_host' => ['required', 'ipv4'],
            'printer_dpi' => ['sometimes', 'integer', Rule::in(ZplLabelFormatDTO::SUPPORTED_DPI)],
            'label_width_mm' => ['sometimes', 'numeric', 'min:20', 'max:108'],
            'label_length_mm' => ['sometimes', 'numeric', 'min:20', 'max:300'],
        ];
    }

    public function labelFormat(): ZplLabelFormatDTO
    {
        $defaults = new ZplLabelFormatDTO;

        return new ZplLabelFormatDTO(
            dpi: (int) $this->validated('printer_dpi', $defaults->dpi),
            width_mm: (float) $this->validated('label_width_mm', $defaults->width_mm),
            length_mm: (float) $this->validated('label_length_mm', $defaults->length_mm),
        );
    }
}
