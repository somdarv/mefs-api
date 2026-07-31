<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureStaffAbility;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Versioned from day one. `/api/v1/health` is the Phase 0 gate.
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Staff routes use BOTH: `auth:sanctum` proves who you are, `staff` proves the
            // token was minted by the staff login path (brief Law 3). A customer OTP token
            // satisfies the first and must never satisfy the second.
            'staff' => EnsureStaffAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every API failure is enveloped.
         *
         * The frontend's error handler reads `message` and `errors` off the response body
         * and has nowhere else to look — a bare Laravel error page, or a `{"message": …}`
         * without the envelope, reaches it as an unexplained failure. So the shape going out
         * matches the shape going in (brief §2.1).
         *
         * `errors` carries the raw validation bag rather than being flattened to a sentence:
         * the brief is explicit that detail must survive the boundary.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // Let the web stack render its own pages.
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'The given data was invalid.',
                    422,
                    $e->errors(),
                ),

                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.',
                    401,
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    $e->getMessage() ?: 'This action is unauthorized.',
                    403,
                ),

                // Route-model binding misses surface as ModelNotFound, a bad URL as
                // NotFoundHttp. Both are "no such thing" to the client.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'Not found.',
                    404,
                ),

                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    'Too many requests. Slow down.',
                    429,
                ),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode(),
                ),

                // Anything unhandled. The real message is exposed only with APP_DEBUG on —
                // in production it can carry a DSN, a file path or a query.
                default => ApiResponse::error(
                    config('app.debug') ? $e->getMessage() : 'Server error.',
                    500,
                ),
            };
        });
    })->create();
