<?php

/**
 * @file classes/LogSources.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogSources
 *
 * @brief The log files the viewer shows: which sources are switched on, where each one's files are,
 *        and which parser reads them.
 *
 * The application log is always shown. Every other source can be switched off, and those living outside
 * files_dir take a path, which may be a glob pattern. The [logs] keys of 3.6
 * (pkp/pkp-lib#12237) override the plugin settings: a source whose path is set in config.inc.php is
 * shown, from that path, and cannot be changed from the settings form. `mysql_log` has no 3.6
 * equivalent; 3.6 only knows postgres_log.
 */

namespace APP\plugins\generic\logViewer\classes;

use APP\plugins\generic\logViewer\LogViewerPlugin;
use PKP\config\Config;
use PKP\core\PKPContainer;
use PKP\scheduledTask\ScheduledTaskHelper;
use PKP\statistics\PKPStatisticsHelper;

class LogSources
{
    /** Plugin setting holding the choices, as {source: {enabled: bool, path: string}}. */
    public const SETTING_NAME = 'logSources';

    public const PHP_ERROR_LOG = 'phpErrorLog';
    public const SCHEDULED_TASK_LOGS = 'scheduledTaskLogs';
    public const USAGE_STATS_LOGS = 'usageStatsLogs';
    public const APACHE_ERROR_LOG = 'apacheErrorLog';
    public const NGINX_ERROR_LOG = 'nginxErrorLog';
    public const HTTP_ACCESS_LOG = 'httpAccessLog';
    public const DATABASE_LOG = 'databaseLog';
    public const SUPERVISOR_LOG = 'supervisorLog';

    /** Log types, as registered with the viewer. The PHP error parser replaces the vendor's php_fpm one. */
    public const TYPE_APPLICATION = 'pkp_application';
    public const TYPE_PHP_ERROR = 'php_fpm';
    public const TYPE_SCHEDULED_TASKS = 'scheduled_tasks';
    public const TYPE_USAGE_STATS = 'usage_stats';
    public const TYPE_MYSQL = 'mysql';

    /**
     * Every source, in display order: [shown by default, takes a path].
     */
    protected const DEFINITIONS = [
        self::PHP_ERROR_LOG => [true, true],
        self::SCHEDULED_TASK_LOGS => [true, false],
        self::USAGE_STATS_LOGS => [true, false],
        self::APACHE_ERROR_LOG => [false, true],
        self::NGINX_ERROR_LOG => [false, true],
        self::HTTP_ACCESS_LOG => [false, true],
        self::DATABASE_LOG => [false, true],
        self::SUPERVISOR_LOG => [false, true],
    ];

    /** Result of inspect(): the path resolves to at least one readable file. */
    public const STATE_FOUND = 'found';
    public const STATE_EMPTY = 'empty';
    public const STATE_RELATIVE = 'relative';
    public const STATE_MISSING = 'missing';
    public const STATE_NOT_FILE = 'notFile';
    public const STATE_UNREADABLE = 'unreadable';

    /** @var array<string, string>|null Real path => log type, built on first use. */
    protected ?array $typesByPath = null;

    /**
     * @param array<string, array{enabled: bool, path: string, overridden: bool}> $sources
     */
    public function __construct(
        protected array $sources,
        protected string $logDirectory
    ) {
    }

    /**
     * Resolve the sources for the plugin.
     */
    public static function load(LogViewerPlugin $plugin): static
    {
        $stored = $plugin->getSetting($plugin->getSettingsContextId(), static::SETTING_NAME);
        $stored = is_array($stored) ? $stored : [];
        $sources = [];

        foreach (static::DEFINITIONS as $name => [$enabledByDefault, $hasPath]) {
            $configKey = static::configKey($name);
            $configured = $configKey ? trim((string) Config::getVar('logs', $configKey)) : '';

            $sources[$name] = $configured !== ''
                ? ['enabled' => true, 'path' => $configured, 'overridden' => true]
                : [
                    'enabled' => (bool) ($stored[$name]['enabled'] ?? $enabledByDefault),
                    'path' => $hasPath ? trim((string) ($stored[$name]['path'] ?? '')) : '',
                    'overridden' => false,
                ];
        }

        return new static($sources, $plugin->getLoggingSettings()->logDirectory());
    }

    /**
     * Source names, in display order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(static::DEFINITIONS);
    }

    /**
     * Whether a source is configured by a path.
     */
    public static function hasPath(string $name): bool
    {
        return static::DEFINITIONS[$name][1];
    }

    /**
     * The config.inc.php [logs] key that sets a source's path, if any. The database key follows the
     * configured database driver.
     */
    public static function configKey(string $name): ?string
    {
        return match ($name) {
            self::PHP_ERROR_LOG => 'php_error_log',
            self::APACHE_ERROR_LOG => 'apache_error_log',
            self::NGINX_ERROR_LOG => 'nginx_error_log',
            self::HTTP_ACCESS_LOG => 'http_access_log',
            self::DATABASE_LOG => static::isPostgres() ? 'postgres_log' : 'mysql_log',
            self::SUPERVISOR_LOG => 'supervisor_log',
            default => null,
        };
    }

    /**
     * The parser type a source's files are read with.
     */
    public static function type(string $name): string
    {
        return match ($name) {
            self::PHP_ERROR_LOG => self::TYPE_PHP_ERROR,
            self::SCHEDULED_TASK_LOGS => self::TYPE_SCHEDULED_TASKS,
            self::USAGE_STATS_LOGS => self::TYPE_USAGE_STATS,
            self::APACHE_ERROR_LOG => 'http_error_apache',
            self::NGINX_ERROR_LOG => 'http_error_nginx',
            self::HTTP_ACCESS_LOG => 'http_access',
            self::DATABASE_LOG => static::isPostgres() ? 'postgres' : self::TYPE_MYSQL,
            self::SUPERVISOR_LOG => 'supervisor',
        };
    }

    /**
     * Whether the application runs on PostgreSQL rather than MySQL or MariaDB.
     */
    public static function isPostgres(): bool
    {
        return PKPContainer::getDatabaseDriverName() === 'pgsql';
    }

    /**
     * Whether a source is shown.
     */
    public function isEnabled(string $name): bool
    {
        return $this->sources[$name]['enabled'];
    }

    /**
     * The path stored for a source, as entered; empty when none is set.
     */
    public function path(string $name): string
    {
        return $this->sources[$name]['path'];
    }

    /**
     * Whether a source is set in config.inc.php.
     */
    public function isOverridden(string $name): bool
    {
        return $this->sources[$name]['overridden'];
    }

    /**
     * The file or glob pattern a source's files are found with, or null when it has none.
     */
    public function pattern(string $name): ?string
    {
        $pattern = match ($name) {
            self::SCHEDULED_TASK_LOGS => rtrim((string) Config::getVar('files', 'files_dir'), '/')
                . '/' . ScheduledTaskHelper::SCHEDULED_TASK_EXECUTION_LOG_DIR . '/*.log',
            self::USAGE_STATS_LOGS => PKPStatisticsHelper::getUsageStatsDirPath() . '/usageEventLogs/*.log',
            // As on 3.6, an unset PHP error log falls back to PHP's own error_log setting
            self::PHP_ERROR_LOG => $this->path($name) ?: (string) LogPathDetector::phpErrorLog(),
            default => $this->path($name),
        };

        return static::isAbsolute($pattern) ? $pattern : null;
    }

    /**
     * The viewer's include_files: the application log directory, then every shown source.
     *
     * @return list<string>
     */
    public function includeFiles(): array
    {
        $patterns = [$this->logDirectory . '/*.log'];

        foreach (static::names() as $name) {
            if ($this->isEnabled($name) && ($pattern = $this->pattern($name)) !== null) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Every type of log the viewer can list: the application log, then each shown source's own type.
     *
     * @return list<string>
     */
    public function shownTypes(): array
    {
        $types = [self::TYPE_APPLICATION];

        foreach (static::names() as $name) {
            if ($this->isEnabled($name)) {
                $types[] = static::type($name);
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The parser type for a file the viewer lists, or null to let the viewer guess from its content.
     */
    public function typeFor(string $path): ?string
    {
        $path = realpath($path) ?: $path;

        if ($this->typesByPath === null) {
            $this->typesByPath = [];

            foreach (static::names() as $name) {
                if (!$this->isEnabled($name) || ($pattern = $this->pattern($name)) === null) {
                    continue;
                }

                foreach (static::expand($pattern) as $file) {
                    // The first source to claim a file decides how it is read
                    $this->typesByPath[realpath($file) ?: $file] ??= static::type($name);
                }
            }
        }

        if (isset($this->typesByPath[$path])) {
            return $this->typesByPath[$path];
        }

        $logDirectory = realpath($this->logDirectory) ?: $this->logDirectory;

        return dirname($path) === $logDirectory && static::isApplicationLogFile(basename($path))
            ? self::TYPE_APPLICATION
            : null;
    }

    /**
     * Whether a file name is the application log or one of its daily rotations.
     */
    public static function isApplicationLogFile(string $fileName): bool
    {
        $base = pathinfo(LoggingSettings::LOG_FILE_NAME, PATHINFO_FILENAME);

        return $fileName === LoggingSettings::LOG_FILE_NAME
            || (bool) preg_match('/^' . preg_quote($base, '/') . '-\d{4}-\d{2}-\d{2}\.log$/', $fileName);
    }

    /**
     * Check what a path or glob pattern resolves to, as the web server sees it.
     *
     * @return array{state: string, files: int, bytes: int}
     */
    public static function inspect(string $pattern): array
    {
        $pattern = trim($pattern);
        $result = fn (string $state, array $files = []) => [
            'state' => $state,
            'files' => count($files),
            'bytes' => (int) array_sum(array_map(fn ($file) => (int) @filesize($file), $files)),
        ];

        if ($pattern === '') {
            return $result(self::STATE_EMPTY);
        }

        if (!static::isAbsolute($pattern)) {
            return $result(self::STATE_RELATIVE);
        }

        if (!static::isGlob($pattern)) {
            // @: file_exists() warns instead of answering false for paths outside open_basedir
            if (!@file_exists($pattern)) {
                return $result(self::STATE_MISSING);
            }

            if (!@is_file($pattern)) {
                return $result(self::STATE_NOT_FILE);
            }

            return @is_readable($pattern) ? $result(self::STATE_FOUND, [$pattern]) : $result(self::STATE_UNREADABLE);
        }

        $files = static::expand($pattern, false);
        if ($files === []) {
            return $result(self::STATE_MISSING);
        }

        $readable = array_values(array_filter($files, fn ($file) => @is_readable($file)));

        return $readable === [] ? $result(self::STATE_UNREADABLE) : $result(self::STATE_FOUND, $readable);
    }

    /**
     * Whether a path is absolute. Relative paths are refused: the viewer would resolve them against the
     * application log directory.
     */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /**
     * Whether a path contains glob wildcards.
     */
    public static function isGlob(string $path): bool
    {
        return strpbrk($path, '*?[') !== false;
    }

    /**
     * The regular files a path or pattern matches.
     *
     * @return list<string>
     */
    protected static function expand(string $pattern, bool $readableOnly = true): array
    {
        $files = static::isGlob($pattern) ? (@glob($pattern) ?: []) : [$pattern];

        return array_values(array_filter(
            $files,
            fn ($file) => @is_file($file) && (!$readableOnly || @is_readable($file))
        ));
    }
}
