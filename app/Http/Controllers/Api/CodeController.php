<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CodeRequest;
use App\Http\Resources\CodeResource;
use App\Icd\Search\CodeLookup;
use App\Icd\Search\ReleaseSelector;

class CodeController extends Controller
{
    public function __invoke(CodeRequest $request, string $code, ReleaseSelector $releases, CodeLookup $lookup): CodeResource
    {
        $release = $releases->select($request->validated('release'));
        $entity = $lookup->find($code, $release)
            ?? abort(404, "Code {$code} is not in release {$release->version}.");

        return (new CodeResource($entity))->additional(['meta' => [
            'release' => $release->version,
            'attribution' => config('icd.attribution'),
        ]]);
    }
}
