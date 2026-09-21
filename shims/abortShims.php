<?php

/**
 * @file shims/abortShims.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * abort(), abort_if() and abort_unless() for the log-viewer package's controllers and middleware.
 *
 * Laravel's global helpers end in app()->abort(), a Foundation\Application method PKPContainer does not
 * implement on 3.5, so every "file not found" in the package fatals instead of answering 404. PHP resolves
 * an unqualified function call in the calling namespace first, so these take precedence over the global
 * helpers for the package's code only, and throw what Application::abort() would have thrown.
 * Loaded by ViewerKernel, for viewer requests only.
 */

namespace Opcodes\LogViewer\Http\Controllers {
    if (!\function_exists(__NAMESPACE__ . '\\abort')) {
        function abort($code, $message = '', array $headers = [])
        {
            \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
        }
    }

    if (!\function_exists(__NAMESPACE__ . '\\abort_if')) {
        function abort_if($boolean, $code, $message = '', array $headers = []): void
        {
            if ($boolean) {
                \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
            }
        }
    }

    if (!\function_exists(__NAMESPACE__ . '\\abort_unless')) {
        function abort_unless($boolean, $code, $message = '', array $headers = []): void
        {
            if (!$boolean) {
                \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
            }
        }
    }
}

namespace Opcodes\LogViewer\Http\Middleware {
    if (!\function_exists(__NAMESPACE__ . '\\abort')) {
        function abort($code, $message = '', array $headers = [])
        {
            \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
        }
    }

    if (!\function_exists(__NAMESPACE__ . '\\abort_if')) {
        function abort_if($boolean, $code, $message = '', array $headers = []): void
        {
            if ($boolean) {
                \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
            }
        }
    }

    if (!\function_exists(__NAMESPACE__ . '\\abort_unless')) {
        function abort_unless($boolean, $code, $message = '', array $headers = []): void
        {
            if (!$boolean) {
                \APP\plugins\generic\logViewer\classes\viewer\ViewerKernel::abort($code, $message, $headers);
            }
        }
    }
}
