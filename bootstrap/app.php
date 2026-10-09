<?php

use App\Exceptions\RouteLoopException;
use App\Exceptions\TooManyRedirectsException;
use App\Http\Middleware\CaptureAuditContext;
use App\Http\Middleware\EnsureWebsiteRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->statefulApi();

        $middleware->alias([
            'website.role' => EnsureWebsiteRole::class,
        ]);

        // Every request, public or authenticated, may end up producing an
        // audit entry — capture IP/User-Agent unconditionally up front.
        $middleware->append(CaptureAuditContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Every API error is rendered through the same JSON envelope as success
        // responses (App\Http\Responses\ApiResponse), regardless of exception type.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('The given data was invalid.', $e->errors(), $e->status);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Unauthenticated.', status: 401);
            }
        });

        // Laravel's base handler converts AuthorizationException (when it has no
        // explicit status) into AccessDeniedHttpException before any render()
        // closure sees it, so that's the type we actually need to intercept.
        // The AuthorizationException closure below is kept for the rare case
        // where a status is explicitly set on it (then it's left as HttpException,
        // but a plain AuthorizationException could still surface from code that
        // catches/rethrows it manually), matching the raw thrown type.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', status: 403);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', status: 403);
            }
        });

        $exceptions->render(function (RouteLoopException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 422);
            }
        });

        $exceptions->render(function (TooManyRedirectsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 422);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Resource not found.', status: 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Resource not found.', status: 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! app()->environment(['local', 'testing'])) {
                return ApiResponse::error('Server error.', status: 500);
            }
        });
    })->create();
