<?php

/**
 * @file classes/MessageLevel.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MessageLevel
 *
 * @brief Infers a PSR-3 level from free-text messages that carry none, such as PHP error log lines
 *        and error_log() calls.
 *
 * Shared by the PHP error log parser and error_log() capture, so the same line is given the same
 * level whichever of the two logs it is read from. The rules are those of PKP\logParser\PKPPhpErrorLog
 * on 3.6 (pkp/pkp-lib#12237). Deliberately free of vendor dependencies: capture runs on every request.
 */

namespace APP\plugins\generic\logViewer\classes;

class MessageLevel
{
    /** PHP's own error-type prefixes, in the order they are tested. */
    protected const PHP_PREFIXES = [
        'PHP Fatal error' => 'critical',
        'PHP Parse error' => 'critical',
        'PHP Compile' => 'critical',
        'PHP Core' => 'critical',
        'PHP Warning' => 'warning',
        'PHP Notice' => 'notice',
        'PHP Deprecated' => 'notice',
        'PHP Strict' => 'notice',
    ];

    /**
     * The PSR-3 level, in lower case, that best describes the message.
     */
    public static function guess(string $message): string
    {
        foreach (static::PHP_PREFIXES as $prefix => $level) {
            if (str_starts_with($message, $prefix)) {
                return $level;
            }
        }

        // A stringified throwable: "Some\Namespace\SomethingException: message"
        if (preg_match('/^[\w\\\\]+(Exception|Error):/', $message)) {
            return 'error';
        }

        return match (true) {
            (bool) preg_match('/\b(fatal|crash|segfault)\b/i', $message) => 'critical',
            (bool) preg_match('/\b(error)\b/i', $message) => 'error',
            (bool) preg_match('/\b(warn|warning)\b/i', $message) => 'warning',
            default => 'notice',
        };
    }
}
