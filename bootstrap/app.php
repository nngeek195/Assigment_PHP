<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );

    $exceptions->render(function (
        \Illuminate\Validation\ValidationException $e,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    });

    $exceptions->render(function (
        \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        $status = $e->getStatusCode();

        $messages = [
            401 => 'Unauthenticated.',
            403 => 'This action is forbidden.',
            404 => 'Resource not found.',
            422 => 'The request could not be processed.',
        ];

        return response()->json([
            'success' => false,
            'message' => $messages[$status] ?? 'An error occurred.',
        ], $status);
    });
    })->create();
