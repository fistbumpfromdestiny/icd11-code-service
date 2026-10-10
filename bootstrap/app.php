<?php

use App\Icd\Search\NoReleaseImportedException;
use App\Icd\Search\UnknownReleaseException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (UnknownReleaseException $e) => response()->json(['message' => $e->getMessage()], 404));
        $exceptions->render(fn (NoReleaseImportedException $e) => response()->json(['message' => $e->getMessage()], 503));
    })->create();
