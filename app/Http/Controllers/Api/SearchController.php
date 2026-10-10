<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchHitResource;
use App\Icd\Search\Ancestors;
use App\Icd\Search\CodeSearch;
use App\Icd\Search\ReleaseSelector;
use App\Icd\Search\SearchHit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, ReleaseSelector $releases, CodeSearch $search, Ancestors $ancestors): AnonymousResourceCollection
    {
        $release = $releases->select($request->validated('release'));
        $hits = $search->search($request->validated('q'), $release, $request->limit(), $request->filters());
        $ancestors->load(array_map(fn (SearchHit $hit) => $hit->entity, $hits));

        return SearchHitResource::collection($hits)->additional(['meta' => [
            'release' => $release->version,
            'attribution' => config('icd.attribution'),
        ]]);
    }
}
