<?php

/**
 * @file classes/ExceptionHandler.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ExceptionHandler
 *
 * @brief Replaces the anonymous exception handler PKPContainer binds on 3.5. Reports to the configured
 *        log channel instead of PHP's error_log, and renders a real response, never an empty HTTP 200,
 *        for exceptions raised by Laravel routes outside the API.
 *
 * Adapted from PKP\core\PKPExceptionHandler on 3.6 (pkp/pkp-lib#12841, pkp/pkp-lib#12237), with one
 * difference: failures the caller caused (a 404 for a mistyped or probed URL, a refused action) are
 * only reported when the settings ask for them. 3.6 reports every throwable at ERROR with its stack
 * trace, which on a public site fills the log with bot traffic.
 */

namespace APP\plugins\generic\logViewer\classes;

use APP\core\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PKP\core\APIRouter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExceptionHandler implements ExceptionHandlerContract
{
    /**
     * @param bool $reportClientErrors Whether failures the caller caused are reported too. 3.6 reports
     *                                 everything; on a public site that is mostly bots and typos, each
     *                                 costing an ERROR entry with a full stack trace.
     */
    public function __construct(protected bool $reportClientErrors = false)
    {
    }

    /**
     * @copydoc \Illuminate\Contracts\Debug\ExceptionHandler::shouldReport()
     */
    public function shouldReport(Throwable $exception)
    {
        if ($this->reportClientErrors) {
            return true;
        }

        if ($exception instanceof AuthorizationException) {
            return false;
        }

        return !$exception instanceof HttpExceptionInterface
            || $exception->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    /**
     * @copydoc \Illuminate\Contracts\Debug\ExceptionHandler::report()
     */
    public function report(Throwable $exception)
    {
        if (!$this->shouldReport($exception)) {
            return;
        }

        try {
            Log::error($exception->getMessage(), ['exception' => $exception]);
        } catch (Throwable $loggingException) {
            // Laravel logging itself failed (unwritable log file, broken channel): keep the report
            // where 3.5 always put it.
            error_log($exception->__toString());
            error_log('Logging failed: ' . $loggingException->__toString());
        }
    }

    /**
     * @copydoc \Illuminate\Contracts\Debug\ExceptionHandler::render()
     */
    public function render($request, Throwable $exception)
    {
        $pkpRouter = Application::get()->getRequest()->getRouter();

        // Unchanged from the 3.5 core handler
        if ($pkpRouter instanceof APIRouter && app('router')->getRoutes()->count()) {
            if ($exception instanceof ValidationException) {
                return response()->json($exception->errors(), $exception->status);
            }

            return response()->json(
                ['error' => $exception->getMessage()],
                in_array($exception->getCode(), array_keys(Response::$statusTexts))
                    ? $exception->getCode()
                    : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // The 3.5 handler returns null here, which Laravel's router turns into an empty HTTP 200.
        // Log Viewer routes run off the page router, so a refused delete would read as a success.
        if ($exception instanceof AuthorizationException || $exception instanceof HttpExceptionInterface) {
            $statusCode = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : Response::HTTP_FORBIDDEN;

            // Not a locale key: the translator may be what failed, and abort() carries no message
            $message = $exception->getMessage() ?: (Response::$statusTexts[$statusCode] ?? 'Error');

            return $request->expectsJson() || $request->isJson()
                ? response()->json(['error' => $message], $statusCode)
                : response($message, $statusCode);
        }

        // Anything else would also become an empty HTTP 200. On 3.5 the only Laravel routes dispatched
        // outside the API router are the viewer's; the details are in the log report() just wrote.
        $message = Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR];

        return $request->expectsJson() || $request->isJson()
            ? response()->json(['error' => $message], Response::HTTP_INTERNAL_SERVER_ERROR)
            : response($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @copydoc \Illuminate\Contracts\Debug\ExceptionHandler::renderForConsole()
     */
    public function renderForConsole($output, Throwable $exception)
    {
        echo (string) $exception;
    }
}
