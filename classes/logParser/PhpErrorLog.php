<?php

/**
 * @file classes/logParser/PhpErrorLog.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PhpErrorLog
 *
 * @brief PHP error log parser for Log Viewer, replacing the vendor's php-fpm parser.
 *
 * Handles PHP error log formats including exceptions with stack traces:
 * - [datetime] PHP Warning: message in /file on line N
 * - [datetime] PHP Fatal error: message in /file on line N
 * - [datetime] ExceptionClass: message in /file:N
 *   Stack trace:
 *   #0 /file(line): function()
 *
 * Adapted from PKP\logParser\PKPPhpErrorLog on 3.6 (pkp/pkp-lib#12237). The level rules live in
 * MessageLevel, which error_log() capture shares.
 */

namespace APP\plugins\generic\logViewer\classes\logParser;

use APP\plugins\generic\logViewer\classes\MessageLevel;
use Opcodes\LogViewer\Logs\Log;

class PhpErrorLog extends Log
{
    public static string $name = 'PHP Error Log';

    // [datetime] and everything after it; the s flag lets the message span a multi-line stack trace
    public static string $regex = '/^\[(?<datetime>[^\]]+)\]\s*(?<message>.+)/s';

    protected function parseText(array &$matches = []): void
    {
        preg_match(static::$regex, $this->text, $matches);

        if (!empty($matches['message'])) {
            $matches['level'] = strtoupper(MessageLevel::guess($matches['message']));
        }
    }

    /**
     * Check if text matches PHP error log format
     */
    public static function matches(string $text, ?int &$timestamp = null, ?string &$level = null): bool
    {
        // Must start with [datetime] pattern like [23-Jan-2026 06:32:42 UTC]
        if (!preg_match('/^\[[\d]{1,2}-[A-Za-z]{3}-[\d]{4}\s[\d:]+/', $text)) {
            return false;
        }

        return parent::matches($text, $timestamp, $level);
    }
}
