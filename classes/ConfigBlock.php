<?php

/**
 * @file classes/ConfigBlock.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ConfigBlock
 *
 * @brief The plugin's settings written as a config.inc.php [logs] section.
 *
 * The keys are those 3.6 reads (pkp/pkp-lib#12841), so pasting the block into config.inc.php keeps
 * the current behaviour after an upgrade, where core reads [logs] and this plugin does nothing. A
 * setting at its default is written commented out: uncommenting it changes nothing, and the line
 * documents what else can be set.
 */

namespace APP\plugins\generic\logViewer\classes;

class ConfigBlock
{
    /**
     * The whole section, ready to paste into config.inc.php.
     */
    public static function build(LoggingSettings $settings, LogSources $sources): string
    {
        return implode("\n", array_merge(
            ['[logs]'],
            static::channelLines($settings),
            [''],
            static::sourceLines($sources)
        ));
    }

    /**
     * Where and how the application logs.
     *
     * @return list<string>
     */
    protected static function channelLines(LoggingSettings $settings): array
    {
        $formatter = (string) $settings->formatter();

        return array_merge(
            static::setting(
                'log_channel',
                $settings->channel(),
                'daily',
                'Where the application logs: daily, single, stack, errorlog, syslog, stderr or null'
            ),
            static::setting(
                'log_level',
                $settings->level(),
                'debug',
                'Least severe level recorded: debug, info, notice, warning, error, critical, alert, emergency'
            ),
            static::setting(
                'log_stacks',
                implode(',', $settings->stacks()),
                'daily',
                'Destinations of the stack channel, comma-separated'
            ),
            static::setting(
                'log_daily_days',
                (string) $settings->dailyDays(),
                '30',
                'Daily files kept before the oldest is deleted; 0 keeps them all'
            ),
            static::setting(
                'log_formatter',
                $formatter !== '' ? $formatter : LoggingSettings::JSON_FORMATTER,
                $formatter !== '' ? null : LoggingSettings::JSON_FORMATTER,
                'Monolog formatter for the daily, single, stderr and syslog channels; unset means readable lines'
            ),
            static::setting(
                'log_client_errors',
                $settings->reportsClientErrors() ? 'On' : 'Off',
                'Off',
                'Report failures the caller caused, such as 404s. This plugin only: 3.6 always reports them'
            )
        );
    }

    /**
     * The logs the viewer shows. A log the settings do not show is written commented out, because on
     * 3.6 a path is what makes a log appear.
     *
     * @return list<string>
     */
    protected static function sourceLines(LogSources $sources): array
    {
        $lines = [
            '; Logs shown in the viewer. A path may be a glob pattern such as /var/log/postgresql/*.log.',
            '; The scheduled task and usage statistics logs need no key: 3.6 finds them itself.',
        ];

        foreach (LogSources::names() as $name) {
            if (($key = LogSources::configKey($name)) === null) {
                continue;
            }

            $path = $sources->path($name);

            // As on 3.6, an unset PHP error log follows PHP's own error_log setting, so the key can
            // stay commented out while the log is still shown
            if ($path === '' && $name === LogSources::PHP_ERROR_LOG) {
                $path = (string) LogPathDetector::phpErrorLog();
            }

            $comment = $key === 'mysql_log'
                ? 'This plugin only: 3.6 has no key for the MySQL or MariaDB log'
                : null;

            $lines = array_merge($lines, static::setting(
                $key,
                $path,
                $sources->isEnabled($name) && $sources->path($name) !== '' ? null : $path,
                $comment
            ));
        }

        return $lines;
    }

    /**
     * One setting: a comment line, then the key, commented out when its value is the default one or
     * when there is no value to write.
     *
     * @return list<string>
     */
    protected static function setting(string $key, string $value, ?string $default, ?string $comment): array
    {
        $lines = $comment !== null ? ['; ' . $comment] : [];
        $inactive = $value === '' || $value === $default;

        $lines[] = rtrim(($inactive ? '; ' : '') . $key . ' = ' . $value);

        return $lines;
    }
}
