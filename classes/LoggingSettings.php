<?php

/**
 * @file classes/LoggingSettings.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LoggingSettings
 *
 * @brief The effective logging configuration, resolved from config.inc.php and the plugin
 *        settings, and its translation into Laravel's `logging` config.
 *
 * Channel names and semantics match the [logs] section introduced on 3.6 by pkp/pkp-lib#12841,
 * so upgrading to a release that ships logging in core changes nothing for operators. A key
 * set in config.inc.php always wins over the plugin setting of the same meaning, which is also
 * what keeps that upgrade path honest: once core reads [logs], the plugin is no longer involved.
 */

namespace APP\plugins\generic\logViewer\classes;

use APP\plugins\generic\logViewer\LogViewerPlugin;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use PKP\config\Config;

class LoggingSettings
{
    /** Directory under files_dir that holds the application log. Same name as core's on 3.6. */
    public const LOG_DIRECTORY = 'logs';

    /** Application log file name. The daily channel derives app-YYYY-MM-DD.log from it. */
    public const LOG_FILE_NAME = 'app.log';

    /** Channels an operator may select as the default channel. */
    public const CHANNELS = ['daily', 'single', 'stack', 'errorlog', 'syslog', 'stderr', 'null'];

    /** Channels the stack channel may fan out to. */
    public const STACKABLE_CHANNELS = ['daily', 'single', 'errorlog', 'syslog', 'stderr'];

    /** PSR-3 levels, least to most severe. */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** The formatter offered in the settings form besides Laravel's default line format. */
    public const JSON_FORMATTER = JsonFormatter::class;

    /**
     * Settings an operator may change from the settings form, keyed by plugin setting name, with
     * the config.inc.php [logs] key that overrides each and the value used when neither is set.
     */
    public const OVERRIDABLE = [
        'logChannel' => ['log_channel', 'daily'],
        'logStacks' => ['log_stacks', 'daily'],
        'logDailyDays' => ['log_daily_days', 30],
        'logFormatter' => ['log_formatter', null],
        'logClientErrors' => ['log_client_errors', false],
    ];

    /**
     * @param array<string, mixed> $values Raw values keyed by plugin setting name
     * @param array<string, bool> $overridden Setting names whose value came from config.inc.php
     */
    public function __construct(
        protected array $values,
        protected array $overridden = []
    ) {
    }

    /**
     * Resolve the effective settings for the plugin.
     */
    public static function load(LogViewerPlugin $plugin): static
    {
        $contextId = $plugin->getSettingsContextId();
        $values = [];
        $overridden = [];

        foreach (static::OVERRIDABLE as $settingName => [$configKey, $default]) {
            $configured = Config::getVar('logs', $configKey);

            if ($configured !== null) {
                $values[$settingName] = $configured;
                $overridden[$settingName] = true;
                continue;
            }

            $values[$settingName] = $plugin->getSetting($contextId, $settingName) ?? $default;
        }

        // Config-only: nothing in 3.5 core logs through Laravel below error, so the form does not
        // offer it, but the [logs] key is honoured for parity with 3.6.
        $values['logLevel'] = Config::getVar('logs', 'log_level', 'debug');

        return new static($values, $overridden);
    }

    /**
     * Whether a setting's value comes from config.inc.php rather than from the plugin.
     */
    public function isOverridden(string $settingName): bool
    {
        return $this->overridden[$settingName] ?? false;
    }

    /**
     * The default channel.
     */
    public function channel(): string
    {
        $channel = strtolower(trim((string) $this->values['logChannel']));

        return in_array($channel, static::CHANNELS, true) ? $channel : 'daily';
    }

    /**
     * The channels the stack channel fans out to.
     *
     * @return list<string>
     */
    public function stacks(): array
    {
        $stacks = is_array($this->values['logStacks'])
            ? $this->values['logStacks']
            : explode(',', (string) $this->values['logStacks']);

        $stacks = array_values(array_unique(array_intersect(
            array_map(fn ($channel) => strtolower(trim((string) $channel)), $stacks),
            static::STACKABLE_CHANNELS
        )));

        return $stacks ?: ['daily'];
    }

    /**
     * Number of rotated files the daily channel keeps.
     */
    public function dailyDays(): int
    {
        return max(0, (int) $this->values['logDailyDays']);
    }

    /**
     * The Monolog formatter class for the formatter-capable channels, or null for Laravel's default.
     */
    public function formatter(): ?string
    {
        $formatter = trim((string) $this->values['logFormatter']);

        return $formatter !== '' && class_exists($formatter) ? $formatter : null;
    }

    /**
     * The minimum level recorded.
     */
    public function level(): string
    {
        $level = strtolower(trim((string) $this->values['logLevel']));

        return in_array($level, static::LEVELS, true) ? $level : 'debug';
    }

    /**
     * Whether failures the caller caused — 404s, refused requests, anything below HTTP 500 — are
     * reported too. Off by default: on a public site most of them are bots and typos.
     */
    public function reportsClientErrors(): bool
    {
        return filter_var($this->values['logClientErrors'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The channels a log entry actually reaches, with the stack channel expanded.
     *
     * @return list<string>
     */
    public function activeChannels(): array
    {
        return $this->channel() === 'stack' ? $this->stacks() : [$this->channel()];
    }

    /**
     * Whether PHP's error_log is one of the active destinations.
     */
    public function usesErrorLogChannel(): bool
    {
        return in_array('errorlog', $this->activeChannels(), true);
    }

    /**
     * Absolute path of the directory holding the application log.
     */
    public function logDirectory(): string
    {
        return rtrim((string) Config::getVar('files', 'files_dir'), '/') . '/' . static::LOG_DIRECTORY;
    }

    /**
     * Absolute path of the application log file.
     */
    public function logFilePath(): string
    {
        return $this->logDirectory() . '/' . static::LOG_FILE_NAME;
    }

    /**
     * Install the configuration into the container.
     */
    public function apply(): void
    {
        $config = app()->get('config'); /** @var \Illuminate\Config\Repository $config */

        $config->set('logging', $this->toLaravelConfig());

        // Core pins the log mailer to the errorlog channel because 3.5 has no other working channel.
        // Clearing the pin makes sandboxed mail follow the default channel, as it does on 3.6.
        $config->set('mail.mailers.log.channel', null);
    }

    /**
     * The `logging` config array. Mirrors PKPContainer::loadConfiguration() on 3.6.
     */
    public function toLaravelConfig(): array
    {
        $level = $this->level();
        $logFile = $this->logFilePath();
        $formatter = [
            'formatter' => $this->formatter(),
            'formatter_with' => ['includeStacktraces' => true],
        ];

        return [
            'default' => $this->channel(),
            'channels' => [
                'stack' => [
                    'driver' => 'stack',
                    'channels' => $this->stacks(),
                    'ignore_exceptions' => false,
                ],
                'single' => [
                    'driver' => 'single',
                    'path' => $logFile,
                    'level' => $level,
                    'replace_placeholders' => true,
                ] + $formatter,
                'daily' => [
                    'driver' => 'daily',
                    'path' => $logFile,
                    'level' => $level,
                    'days' => $this->dailyDays(),
                    'replace_placeholders' => true,
                ] + $formatter,
                'stderr' => [
                    'driver' => 'monolog',
                    'level' => $level,
                    'handler' => StreamHandler::class,
                    'with' => ['stream' => 'php://stderr'],
                    'processors' => [PsrLogMessageProcessor::class],
                ] + $formatter,
                'syslog' => [
                    'driver' => 'syslog',
                    'level' => $level,
                    'facility' => LOG_USER,
                    'replace_placeholders' => true,
                ] + $formatter,
                'errorlog' => [
                    'driver' => 'errorlog',
                    'level' => $level,
                    'replace_placeholders' => true,
                ],
                'null' => [
                    'driver' => 'monolog',
                    'handler' => NullHandler::class,
                ],
                // LogManager falls back to this when a channel cannot be built. Without a path it
                // calls storage_path(), which PKPContainer does not implement on 3.5.
                'emergency' => [
                    'path' => $logFile,
                ],
            ],
        ];
    }
}
