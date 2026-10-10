<?php

namespace App\Http\Requests;

use App\Icd\Import\ReleaseSource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/codes/{code}.
 */
class CodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'release' => ['nullable', 'string', 'regex:'.ReleaseSource::VERSION_PATTERN],
        ];
    }
}
