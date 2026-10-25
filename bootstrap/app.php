<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->stopIgnoring([
            AuthenticationException::class,
            AuthorizationException::class,
            BackedEnumCaseNotFoundException::class,
            HttpException::class,
            ModelNotFoundException::class,
            OriginMismatchException::class,
            RecordNotFoundException::class,
            RecordsNotFoundException::class,
            TokenMismatchException::class,
            ValidationException::class,
        ]);

        $exceptions->report(function (Throwable $exception): bool {
            $status = match (true) {
                $exception instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
                $exception instanceof AuthorizationException,
                $exception instanceof OriginMismatchException => Response::HTTP_FORBIDDEN,
                $exception instanceof ModelNotFoundException,
                $exception instanceof BackedEnumCaseNotFoundException,
                $exception instanceof RecordNotFoundException,
                $exception instanceof RecordsNotFoundException => Response::HTTP_NOT_FOUND,
                $exception instanceof TokenMismatchException => 419,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => Response::HTTP_INTERNAL_SERVER_ERROR,
            };

            $level = match (true) {
                $exception instanceof ValidationException => LogLevel::DEBUG,
                $status < Response::HTTP_INTERNAL_SERVER_ERROR => LogLevel::INFO,
                default => LogLevel::ERROR,
            };

            Log::log($level, 'Request exception', [
                'exception' => $exception,
                'method' => request()->method(),
                'route' => request()->route()?->getName(),
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            if (! $request->is('api/*')) {
                return $response;
            }

            $status = $response->getStatusCode();
            $message = match ($status) {
                Response::HTTP_BAD_REQUEST => 'Bad request.',
                Response::HTTP_UNAUTHORIZED => 'Unauthenticated.',
                Response::HTTP_FORBIDDEN => 'Forbidden.',
                Response::HTTP_NOT_FOUND => 'Not found.',
                Response::HTTP_METHOD_NOT_ALLOWED => 'Method not allowed.',
                419 => 'Page expired.',
                Response::HTTP_UNPROCESSABLE_ENTITY => 'The given data was invalid.',
                Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests.',
                Response::HTTP_SERVICE_UNAVAILABLE => 'Service unavailable.',
                default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                    ? 'An unexpected error occurred.'
                    : 'The request could not be completed.',
            };

            $payload = [
                'status' => $status,
                'message' => $message,
            ];

            if ($status === Response::HTTP_UNPROCESSABLE_ENTITY && $response instanceof JsonResponse) {
                $errors = $response->getData(true)['errors'] ?? null;

                if (is_array($errors)) {
                    $payload['errors'] = $errors;
                }
            }

            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length']);

            return response()->json($payload, $status, $headers);
        });
    })->create();
