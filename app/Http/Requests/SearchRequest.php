<?php

namespace App\Http\Requests;

use App\Icd\Import\ReleaseSource;
use App\Icd\Search\SearchFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/search. q is trimmed by the TrimStrings middleware before the
 * length check.
 */
class SearchRequest extends FormRequest
{
    private const DEFAULT_LIMIT = 10;

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'release' => ['nullable', 'string', 'regex:'.ReleaseSource::VERSION_PATTERN],
            'chapter' => ['nullable', 'string', Rule::in(config('icd.chapters'))],
            'leaf_only' => ['nullable', 'boolean'],
            'primary_only' => ['nullable', 'boolean'],
            'include_blocks' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    public function filters(): SearchFilters
    {
        return new SearchFilters(
            chapter: $this->validated('chapter'),
            leafOnly: $this->boolean('leaf_only'),
            primaryOnly: $this->boolean('primary_only'),
            includeBlocks: $this->boolean('include_blocks'),
        );
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
