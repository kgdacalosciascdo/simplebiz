<?php

use App\Exceptions\RegistryConflictException;
use App\Http\Middleware\AttachCorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveCompanyContext;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(HandleCors::class);
        $middleware->append(AttachCorrelationId::class);
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'company.context' => ResolveCompanyContext::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Authentication is required.', 401);
            }
        });
        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('The requested resource was not found.', 404);
            }
        });
        $exceptions->render(function (RegistryConflictException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
            }
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('The given data was invalid.', 422, $exception->errors());
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/*')) {
                if ($exception instanceof HttpExceptionInterface) {
                    $status = $exception->getStatusCode();

                    return ApiResponse::error($status === 404 ? 'The requested resource was not found.' : 'The request could not be completed.', $status);
                }

                report($exception);

                return ApiResponse::error('An unexpected error occurred. Check the correlation ID and try again.', 500);
            }
        });
    })->create();
