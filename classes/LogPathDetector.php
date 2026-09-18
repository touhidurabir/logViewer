<?php

/**
 * @file classes/LogPathDetector.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogPathDetector
 *
 * @brief Suggests where a log source's file is, to pre-fill the settings form.
 *
 * Only suggestions: nothing detected here is shown in the viewer until an administrator saves it, except
 * the PHP error log, which falls back to PHP's error_log setting as it does on 3.6. Call it from a web
 * request: PHP's settings differ between the web server and the command line.
 */

namespace APP\plugins\generic\logViewer\classes;

use Illuminate\Support\Facades\DB;
use Throwable;

class LogPathDetector
{
    /** Well-known locations, tried in order; the first readable file wins. */
    protected const CANDIDATES = [
        LogSources::APACHE_ERROR_LOG => [
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            '/usr/local/var/log/httpd/error_log',
            '/opt/homebrew/var/log/httpd/error_log',
        ],
        LogSources::NGINX_ERROR_LOG => [
            '/var/log/nginx/error.log',
            '/usr/local/var/log/nginx/error.log',
            '/opt/homebrew/var/log/nginx/error.log',
        ],
        LogSources::HTTP_ACCESS_LOG => [
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            '/usr/local/var/log/nginx/access.log',
            '/usr/local/var/log/httpd/access_log',
            '/opt/homebrew/var/log/nginx/access.log',
            '/opt/homebrew/var/log/httpd/access_log',
        ],
        LogSources::SUPERVISOR_LOG => [
            '/var/log/supervisor/supervisord.log',
            '/usr/local/var/log/supervisord.log',
            '/opt/homebrew/var/log/supervisord.log',
        ],
    ];

    /**
     * A suggested path for a source, or null when none can be found.
     */
    public static function detect(string $source): ?string
    {
        return match ($source) {
            LogSources::PHP_ERROR_LOG => static::phpErrorLog(),
            LogSources::DATABASE_LOG => static::databaseLog(),
            default => static::firstReadable(static::CANDIDATES[$source] ?? []),
        };
    }

    /**
     * PHP's error_log setting, when it names a file. Empty means PHP writes to the web server's own error
     * log (or php-fpm's), and "syslog" to the system log; neither is a path.
     */
    public static function phpErrorLog(): ?string
    {
        $path = trim((string) ini_get('error_log'));

        return LogSources::isAbsolute($path) ? $path : null;
    }

    /**
     * The database server's error log, as the server reports it. The server may run on another host, in
     * which case the path is only a hint.
     */
    public static function databaseLog(): ?string
    {
        try {
            return LogSources::isPostgres() ? static::postgresLog() : static::mysqlLog();
        } catch (Throwable $exception) {
            // Not permitted, or not supported by this server version
            return null;
        }
    }

    /**
     * MySQL and MariaDB report log_error relative to the data directory; MariaDB leaves it empty for the
     * default {hostname}.err.
     */
    protected static function mysqlLog(): ?string
    {
        $row = DB::selectOne('SELECT @@log_error AS log_error, @@datadir AS datadir, @@hostname AS hostname');
        $logError = trim((string) $row->log_error);

        if ($logError === 'stderr') {
            return null;
        }

        $logError = $logError === '' ? $row->hostname . '.err' : preg_replace('#^\./#', '', $logError);

        return LogSources::isAbsolute($logError)
            ? $logError
            : rtrim((string) $row->datadir, '/') . '/' . $logError;
    }

    /**
     * pg_current_logfile() needs the logging collector and, by default, superuser rights.
     */
    protected static function postgresLog(): ?string
    {
        $row = DB::selectOne("SELECT pg_current_logfile() AS logfile, current_setting('data_directory') AS datadir");
        $logFile = trim((string) $row->logfile);

        if ($logFile === '') {
            return null;
        }

        return LogSources::isAbsolute($logFile)
            ? $logFile
            : rtrim((string) $row->datadir, '/') . '/' . $logFile;
    }

    /**
     * The first readable regular file among the candidates.
     *
     * @param list<string> $candidates
     */
    protected static function firstReadable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
