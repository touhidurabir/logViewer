<?php

/**
 * @file classes/logParser/MysqlErrorLog.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class MysqlErrorLog
 *
 * @brief MySQL and MariaDB error log parser for Log Viewer, which has none of its own.
 *
 * Handles:
 * - 2026-09-08T03:19:44.852671Z 3047 [Warning] [MY-013360] [Server] message   (MySQL 8 and later)
 * - 2026-09-08T03:19:44.852671Z 0 [Note] message                               (MySQL 5.7)
 * - 2026-09-08  3:19:44 0 [Warning] message                                    (MariaDB 10.1 and later)
 * - 2026-09-17T04:15:50.6NZ mysqld_safe Logging to '...'.                       (the mysqld_safe wrapper)
 *
 * Lines that match neither, such as InnoDB status dumps, continue the entry above them.
 */

namespace APP\plugins\generic\logViewer\classes\logParser;

use Opcodes\LogViewer\Logs\Log;

class MysqlErrorLog extends Log
{
    public static string $name = 'MySQL';

    public static string $regex = '/^(?<datetime>\d{4}-\d{2}-\d{2}[T ]\s?\d{1,2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\s+(?<thread>\d+)\s+\[(?<level>[A-Za-z]+)\]\s*(?:\[(?<code>MY-\d+)\]\s*)?(?:\[(?<subsystem>[A-Za-z]+)\]\s*)?(?<message>.*)/s';

    /** mysqld_safe writes a malformed fraction ("50.6NZ"), so only the seconds are kept. */
    protected static string $wrapperRegex = '/^(?<datetime>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\S*\s+(?<message>mysqld_safe\b.*)/s';

    /** MySQL's severities, mapped to the viewer's. */
    protected const LEVELS = [
        'system' => 'INFO',
        'note' => 'NOTICE',
        'warning' => 'WARNING',
        'error' => 'ERROR',
    ];

    public static function matches(string $text, ?int &$timestamp = null, ?string &$level = null): bool
    {
        $matches = static::match($text);

        if ($matches === null) {
            return false;
        }

        try {
            $timestamp = static::parseDatetime($matches['datetime'])?->timestamp;
        } catch (\Exception $exception) {
            return false;
        }

        $level = $matches['level'];

        return true;
    }

    protected function parseText(array &$matches = []): void
    {
        $matches = static::match($this->text) ?? [];
    }

    protected function fillMatches(array $matches = []): void
    {
        parent::fillMatches($matches);

        $this->context = array_filter([
            'thread' => $matches['thread'] ?? null,
            'code' => $matches['code'] ?? null,
            'subsystem' => $matches['subsystem'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The named groups of a server or wrapper line, with the level normalised, or null for neither.
     */
    protected static function match(string $text): ?array
    {
        if (preg_match(static::$regex, $text, $matches)) {
            $matches['level'] = static::LEVELS[strtolower($matches['level'])] ?? 'INFO';

            return $matches;
        }

        if (preg_match(static::$wrapperRegex, $text, $matches)) {
            $matches['level'] = 'INFO';

            return $matches;
        }

        return null;
    }
}
