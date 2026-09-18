<?php

/**
 * @file classes/form/SettingsForm.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SettingsForm
 *
 * @brief Plugin settings: logging and the log sources shown in the viewer. A value set in
 *        config.inc.php [logs] is shown read-only and never saved, because the config file wins
 *        over the plugin.
 *
 * The form also shows the settings as a [logs] section ready to paste into config.inc.php, which is
 * what keeps them after an upgrade to a release that reads [logs] itself.
 */

namespace APP\plugins\generic\logViewer\classes\form;

use APP\plugins\generic\logViewer\classes\ConfigBlock;
use APP\plugins\generic\logViewer\classes\LoggingSettings;
use APP\plugins\generic\logViewer\classes\LogPathDetector;
use APP\plugins\generic\logViewer\classes\LogSources;
use APP\plugins\generic\logViewer\LogViewerPlugin;
use APP\template\TemplateManager;
use PKP\file\FileManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorInSet;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class SettingsForm extends Form
{
    protected LoggingSettings $settings;

    protected LogSources $sources;

    /** @var array<string, bool> Sources whose path field was pre-filled by LogPathDetector. */
    protected array $detected = [];

    public function __construct(protected LogViewerPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->settings = $plugin->getLoggingSettings();
        $this->sources = $plugin->getLogSources();

        if (!$this->settings->isOverridden('logChannel')) {
            $this->addCheck(new FormValidatorInSet(
                $this,
                'logChannel',
                'required',
                'plugins.generic.logViewer.settings.logChannel.invalid',
                LoggingSettings::CHANNELS
            ));
        }

        if (!$this->settings->isOverridden('logStacks')) {
            $this->addCheck(new FormValidatorCustom(
                $this,
                'logStacks',
                'optional',
                'plugins.generic.logViewer.settings.logStacks.invalid',
                fn ($stacks) => is_array($stacks) && !array_diff($stacks, LoggingSettings::STACKABLE_CHANNELS)
            ));
            $this->addCheck(new FormValidatorCustom(
                $this,
                'logChannel',
                'optional',
                'plugins.generic.logViewer.settings.logStacks.required',
                fn ($channel) => $channel !== 'stack' || !empty($this->getData('logStacks'))
            ));
        }

        if (!$this->settings->isOverridden('logDailyDays')) {
            $this->addCheck(new FormValidatorRegExp(
                $this,
                'logDailyDays',
                'required',
                'plugins.generic.logViewer.settings.logDailyDays.invalid',
                '/^\d{1,4}$/'
            ));
        }

        if (!$this->settings->isOverridden('logFormatter')) {
            $this->addCheck(new FormValidatorInSet(
                $this,
                'logFormatter',
                'optional',
                'plugins.generic.logViewer.settings.logFormatter.invalid',
                array_keys($this->formatterOptions())
            ));
        }

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->_data = [
            'logChannel' => $this->settings->channel(),
            'logStacks' => $this->settings->stacks(),
            'logDailyDays' => $this->settings->dailyDays(),
            'logFormatter' => (string) $this->settings->formatter(),
            'logClientErrors' => $this->settings->reportsClientErrors(),
        ];

        foreach (LogSources::names() as $name) {
            $path = $this->sources->path($name);

            // Suggest a path for a source that has none yet; nothing is used until the form is saved
            if ($path === '' && LogSources::hasPath($name) && !$this->sources->isOverridden($name)) {
                $path = (string) LogPathDetector::detect($name);
                $this->detected[$name] = $path !== '';
            }

            $this->_data["{$name}Enabled"] = $this->sources->isEnabled($name);
            $this->_data["{$name}Path"] = $path;
        }

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $sourceVars = [];
        foreach (LogSources::names() as $name) {
            $sourceVars[] = "{$name}Enabled";
            $sourceVars[] = "{$name}Path";
        }

        $this->readUserVars(array_merge(
            ['logChannel', 'logStacks', 'logDailyDays', 'logFormatter', 'logClientErrors'],
            $sourceVars
        ));

        if (!is_array($this->getData('logStacks'))) {
            $this->setData('logStacks', []);
        }
    }

    /**
     * @copydoc Form::validate()
     *
     * A shown source must point at something the web server can read, checked as the viewer will.
     */
    public function validate($callHooks = true)
    {
        $valid = parent::validate($callHooks);

        foreach (LogSources::names() as $name) {
            if (!LogSources::hasPath($name) || $this->sources->isOverridden($name) || !$this->getData("{$name}Enabled")) {
                continue;
            }

            $path = trim((string) $this->getData("{$name}Path"));

            // Left empty, the PHP error log follows PHP's error_log setting
            if ($path === '' && $name === LogSources::PHP_ERROR_LOG) {
                $path = (string) LogPathDetector::phpErrorLog();
            }

            $inspection = LogSources::inspect($path);

            if ($inspection['state'] !== LogSources::STATE_FOUND) {
                $this->addError("{$name}Path", __('plugins.generic.logViewer.sources.invalid', [
                    'source' => __(static::sourceLabelKey($name)),
                    'status' => static::describeInspection($inspection),
                ]));
                $this->addErrorField("{$name}Path");
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $overridden = [];
        foreach (array_keys(LoggingSettings::OVERRIDABLE) as $settingName) {
            $overridden[$settingName] = $this->settings->isOverridden($settingName);
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'channelOptions' => $this->channelOptions(),
            'stackOptions' => array_intersect_key($this->channelOptions(), array_flip(LoggingSettings::STACKABLE_CHANNELS)),
            'formatterOptions' => $this->formatterOptions() + $this->configuredFormatterOption(),
            'overridden' => $overridden,
            'logDirectory' => $this->settings->logDirectory(),
            'logSourceRows' => $this->logSourceRows(),
            'configBlock' => ConfigBlock::build($this->settings, $this->sources),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->plugin->getSettingsContextId();

        if (!$this->settings->isOverridden('logChannel')) {
            $this->plugin->updateSetting($contextId, 'logChannel', $this->getData('logChannel'), 'string');
        }

        if (!$this->settings->isOverridden('logStacks')) {
            $this->plugin->updateSetting($contextId, 'logStacks', implode(',', $this->getData('logStacks')), 'string');
        }

        if (!$this->settings->isOverridden('logDailyDays')) {
            $this->plugin->updateSetting($contextId, 'logDailyDays', (int) $this->getData('logDailyDays'), 'int');
        }

        if (!$this->settings->isOverridden('logFormatter')) {
            $this->plugin->updateSetting($contextId, 'logFormatter', (string) $this->getData('logFormatter'), 'string');
        }

        if (!$this->settings->isOverridden('logClientErrors')) {
            $this->plugin->updateSetting($contextId, 'logClientErrors', (bool) $this->getData('logClientErrors'), 'bool');
        }

        $sources = $this->plugin->getSetting($contextId, LogSources::SETTING_NAME);
        $sources = is_array($sources) ? $sources : [];
        foreach (LogSources::names() as $name) {
            if ($this->sources->isOverridden($name)) {
                continue;
            }

            $sources[$name] = [
                'enabled' => (bool) $this->getData("{$name}Enabled"),
                'path' => LogSources::hasPath($name) ? trim((string) $this->getData("{$name}Path")) : '',
            ];
        }
        $this->plugin->updateSetting($contextId, LogSources::SETTING_NAME, $sources, 'object');

        parent::execute(...$functionArgs);
    }

    /**
     * A human-readable account of what a path or pattern resolves to.
     *
     * @param array{state: string, files: int, bytes: int} $inspection
     */
    public static function describeInspection(array $inspection): string
    {
        return __("plugins.generic.logViewer.sources.status.{$inspection['state']}", [
            'files' => $inspection['files'],
            'size' => (new FileManager())->getNiceFileSize($inspection['bytes']),
        ]);
    }

    /**
     * Locale key of a source's name. The database log is named after the configured database.
     */
    public static function sourceLabelKey(string $name): string
    {
        $key = "plugins.generic.logViewer.sources.{$name}";

        return $name === LogSources::DATABASE_LOG
            ? $key . (LogSources::isPostgres() ? '.postgres' : '.mysql')
            : $key;
    }

    /**
     * One row per source for the template, reflecting the form's current data.
     *
     * @return list<array<string, mixed>>
     */
    protected function logSourceRows(): array
    {
        $rows = [];

        foreach (LogSources::names() as $name) {
            $hasPath = LogSources::hasPath($name);
            $overridden = $this->sources->isOverridden($name);
            // Read-only fields are not submitted, so a redisplayed form takes them from the configuration
            $pattern = match (true) {
                !$hasPath => (string) $this->sources->pattern($name),
                $overridden => $this->sources->path($name),
                default => trim((string) $this->getData("{$name}Path")),
            };
            $inspection = LogSources::inspect($pattern);

            // A directory of logs the application creates on demand is not a problem while it is empty
            $status = !$hasPath && $inspection['state'] !== LogSources::STATE_FOUND
                ? __('plugins.generic.logViewer.sources.status.noFilesYet')
                : static::describeInspection($inspection);

            $rows[] = [
                'name' => $name,
                'enabledField' => "{$name}Enabled",
                'pathField' => "{$name}Path",
                'labelKey' => static::sourceLabelKey($name),
                'descriptionKey' => "plugins.generic.logViewer.sources.{$name}.description",
                'hasPath' => $hasPath,
                'enabled' => $overridden || $this->getData("{$name}Enabled"),
                'path' => $pattern,
                'overridden' => $overridden,
                'configKey' => LogSources::configKey($name),
                'detected' => $this->detected[$name] ?? false,
                'state' => $inspection['state'],
                'status' => $status,
            ];
        }

        return $rows;
    }

    /**
     * Channel choices, keyed by channel name.
     *
     * @return array<string, string>
     */
    protected function channelOptions(): array
    {
        $options = [];
        foreach (LoggingSettings::CHANNELS as $channel) {
            $options[$channel] = __("plugins.generic.logViewer.settings.channel.{$channel}");
        }

        return $options;
    }

    /**
     * Formatter choices, keyed by formatter class ('' for Laravel's default line format).
     *
     * @return array<string, string>
     */
    protected function formatterOptions(): array
    {
        return [
            '' => __('plugins.generic.logViewer.settings.logFormatter.line'),
            LoggingSettings::JSON_FORMATTER => __('plugins.generic.logViewer.settings.logFormatter.json'),
        ];
    }

    /**
     * A choice for a formatter class set in config.inc.php that the form does not offer, so the read-only
     * field can still display it.
     *
     * @return array<string, string>
     */
    protected function configuredFormatterOption(): array
    {
        $formatter = (string) $this->settings->formatter();

        return $formatter !== '' && !array_key_exists($formatter, $this->formatterOptions())
            ? [$formatter => $formatter]
            : [];
    }
}
