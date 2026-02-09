<?php

// use Illuminate\Foundation\Application;
// use Illuminate\Foundation\Configuration\Exceptions;
// use Illuminate\Foundation\Configuration\Middleware;

// return Application::configure(basePath: dirname(__DIR__))
//     ->withRouting(
//         web: __DIR__.'/../routes/web.php',
//         api:__DIR__.'/../routes/api.php',
//         commands: __DIR__.'/../routes/console.php',
//         health: '/up',
//     )
//     ->withMiddleware(function (Middleware $middleware): void {
        
//            // 🔥 Add global CORS middleware
//         $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

//         // If using Sanctum:
//         $middleware->append(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);
//     })
//     ->withExceptions(function (Exceptions $exceptions): void {
//         //
//     })->create();

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ✅ Enable CORS
        $middleware->append(HandleCors::class);

        // ❌ DO NOT add EnsureFrontendRequestsAreStateful
        // ❌ This middleware causes CSRF enforcement
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
