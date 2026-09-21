<?php

/**
 * @file LogViewerPlugin.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogViewerPlugin
 *
 * @brief Backports the 3.6 logging infrastructure (pkp/pkp-lib#12841) and web log viewer
 *        (pkp/pkp-lib#12237) to 3.5 without modifying core: configurable Laravel log channels
 *        under {files_dir}/logs, uncaught exception reporting into them, and the
 *        opcodesio/log-viewer UI for site administrators.
 */

namespace APP\plugins\generic\logViewer;

use APP\core\Application;
use APP\plugins\generic\logViewer\classes\ConfigBlock;
use APP\plugins\generic\logViewer\classes\ExceptionHandler;
use APP\plugins\generic\logViewer\classes\form\SettingsForm;
use APP\plugins\generic\logViewer\classes\LoggingSettings;
use APP\plugins\generic\logViewer\classes\LogSources;
use APP\plugins\generic\logViewer\classes\viewer\ViewerKernel;
use APP\template\TemplateManager;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\core\PKPContainer;
use PKP\core\PKPRequest;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use Throwable;

class LogViewerPlugin extends GenericPlugin
{
    /** The page the viewer is grafted onto. */
    public const PAGE = 'admin';

    /** The operation that serves the viewer and its API. */
    public const OP = 'log-viewer';

    /**
     * Registration sequence. Registering ahead of other generic plugins lets their registration
     * errors reach the configured channels.
     */
    public const SEQUENCE = -1000;

    /** Whether the logging services have been installed in this request. */
    private static bool $installed = false;

    /** Memoised context count; isSitePlugin() is called repeatedly by the plugin grid. */
    private static ?int $contextCount = null;

    /** Memoised sole context id for single-context installations. */
    private static ?int $soleContextId = null;

    /** Memoised answer to coreProvidesLogging(). */
    private static ?bool $coreProvidesLogging = null;

    private ?LoggingSettings $settings = null;

    private ?LogSources $logSources = null;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!$success
            || !Application::isInstalled()
            || Application::isUpgrading()
            || static::coreProvidesLogging()
            || !$this->getEnabled($mainContextId)
            || self::$installed
        ) {
            return $success;
        }

        self::$installed = true;

        $settings = $this->getLoggingSettings();
        $settings->apply();

        app()->singleton(
            ExceptionHandlerContract::class,
            fn () => new ExceptionHandler($settings->reportsClientErrors())
        );

        // Last, so a hook that takes ownership of the throwable (returns ABORT) suppresses the report,
        // as PKPApplication::execute() does on 3.6
        Hook::add('PKPApplication::execute::catch', $this->reportUncaughtThrowable(...), Hook::SEQUENCE_LAST);

        Hook::add('LoadHandler', $this->handleViewerRequest(...), Hook::SEQUENCE_CORE);
        Hook::add('Templates::Admin::Index::AdminFunctions', $this->addAdminPanel(...));

        return $success;
    }

    /**
     * @copydoc Plugin::getSeq()
     */
    public function getSeq()
    {
        return static::SEQUENCE;
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     *
     * Dynamic: core hides Site Settings > Plugins on single-context installs, so there the plugin
     * presents itself as a context plugin or it could never be enabled at all.
     */
    public function isSitePlugin()
    {
        if (!Application::isInstalled() || Application::isUpgrading()) {
            return true;
        }

        return $this->getContextCount() !== 1;
    }

    /**
     * @copydoc LazyLoadPlugin::getEnabled()
     *
     * On a single-context install the plugin is enabled against the journal, but the viewer and the
     * logging services work at site level, where there is no context. Resolve the sole context.
     *
     * @param null|int $contextId
     */
    public function getEnabled($contextId = null)
    {
        if ($contextId === null && !$this->isSitePlugin()) {
            $contextId = $this->getSoleContextId();
        }

        return parent::getEnabled($contextId);
    }

    /**
     * @copydoc LazyLoadPlugin::getCanEnable()
     *
     * Refused where core already provides Laravel logging: two sources of truth would silently
     * override the [logs] configuration.
     */
    public function getCanEnable()
    {
        return !static::coreProvidesLogging() && $this->currentUserIsSiteAdmin() && parent::getCanEnable();
    }

    /**
     * @copydoc LazyLoadPlugin::getCanDisable()
     */
    public function getCanDisable()
    {
        return $this->currentUserIsSiteAdmin() && parent::getCanDisable();
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.logViewer.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return static::coreProvidesLogging()
            ? __('plugins.generic.logViewer.description.unsupported')
            : __('plugins.generic.logViewer.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();

        // Where core provides logging the settings no longer apply, but they are still worth reading:
        // the action then offers them as a [logs] section to carry into config.inc.php
        $canManage = $this->currentUserIsSiteAdmin() && (static::coreProvidesLogging() || $this->getEnabled());

        return array_merge(
            $canManage ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb' => 'settings',
                            'plugin' => $this->getName(),
                            'category' => 'generic',
                        ]),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $verb = $request->getUserVar('verb');

        if (!in_array($verb, ['settings', 'checkLogPath'], true)) {
            return parent::manage($args, $request);
        }

        if (!$this->currentUserIsSiteAdmin()) {
            return new JSONMessage(false);
        }

        // On a release that reads [logs] itself, hand the settings over rather than pretend to apply them
        if (static::coreProvidesLogging()) {
            return $verb === 'settings'
                ? new JSONMessage(true, $this->getUpgradeNotice($request))
                : new JSONMessage(false);
        }

        // Live feedback for a log path being typed into the settings form
        if ($verb === 'checkLogPath') {
            $inspection = LogSources::inspect((string) $request->getUserVar('path'));

            return new JSONMessage(true, [
                'state' => $inspection['state'],
                'message' => SettingsForm::describeInspection($inspection),
            ]);
        }

        $form = new SettingsForm($this);

        if (!$request->getUserVar('save')) {
            $form->initData();

            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();

        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();

        return new JSONMessage(true);
    }

    /**
     * The settings written as a config.inc.php [logs] section, shown where core reads [logs] itself
     * and the plugin's own settings no longer have any effect.
     */
    protected function getUpgradeNotice(PKPRequest $request): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('configBlock', ConfigBlock::build($this->getLoggingSettings(), $this->getLogSources()));

        return $templateMgr->fetch($this->getTemplateResource('upgradeNotice.tpl'));
    }

    /**
     * The effective logging configuration.
     */
    public function getLoggingSettings(): LoggingSettings
    {
        return $this->settings ??= LoggingSettings::load($this);
    }

    /**
     * The log sources shown in the viewer.
     */
    public function getLogSources(): LogSources
    {
        return $this->logSources ??= LogSources::load($this);
    }

    /**
     * The context the plugin's settings are stored against.
     */
    public function getSettingsContextId(): int
    {
        return $this->isSitePlugin()
            ? Application::SITE_CONTEXT_ID
            : (int) $this->getSoleContextId();
    }

    /**
     * Report a throwable no other hook took ownership of to the configured channels.
     *
     * The parameter name is load-bearing: the hook passes the throwable as a named argument.
     */
    public function reportUncaughtThrowable(string $hookName, Throwable $throwable): bool
    {
        // PHP's native handler already writes the re-thrown throwable to error_log; reporting it through
        // an errorlog channel as well would record it twice
        if (!$this->getLoggingSettings()->usesErrorLogChannel()) {
            app(ExceptionHandlerContract::class)->report($throwable);
        }

        return Hook::CONTINUE;
    }

    /**
     * Serve the viewer and its API at index/admin/log-viewer.
     *
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function handleViewerRequest(string $hookName, array $args): bool
    {
        [$page, $op] = $args;

        if ($page !== static::PAGE || $op !== static::OP) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();

        // Site administration only, like the rest of Administration
        if ($request->getContext() !== null) {
            return Hook::CONTINUE;
        }

        return (new ViewerKernel($this))->handle($request) ? Hook::ABORT : Hook::CONTINUE;
    }

    /**
     * Append the Logs panel to the site administration index page.
     *
     * @param array $args [&$params, $smarty, &$output]
     */
    public function addAdminPanel(string $hookName, array $args): bool
    {
        $templateMgr = $args[1]; /** @var TemplateManager $templateMgr */
        $output = &$args[2];

        $templateMgr->assign('logViewerUrl', $this->getViewerUrl());
        $output .= $templateMgr->fetch($this->getTemplateResource('adminPanel.tpl'));

        return Hook::CONTINUE;
    }

    /**
     * URL of the viewer.
     */
    public function getViewerUrl(): string
    {
        $request = Application::get()->getRequest();

        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            Application::SITE_CONTEXT_PATH,
            static::PAGE,
            static::OP
        );
    }

    /**
     * Whether the running application already ships Laravel logging (3.6 and later). Feature-detected
     * rather than version-compared, so a backport of #12841 into a 3.5 point release is caught too.
     */
    public static function coreProvidesLogging(): bool
    {
        return self::$coreProvidesLogging ??= method_exists(PKPContainer::class, 'logFilePath')
            || class_exists('PKP\core\PKPExceptionHandler');
    }

    /**
     * Whether the current user is a site administrator. On a single-context install the plugin is listed
     * among the journal's plugins, where journal managers could otherwise enable it or change the server's
     * logging. Requests without a user, such as command-line tools, are not restricted.
     */
    protected function currentUserIsSiteAdmin(): bool
    {
        $user = Application::get()->getRequest()->getUser();

        return !$user || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);
    }

    /**
     * Number of contexts installed, enabled or not. Mirrors AdminHandler::siteSettingsAvailability().
     */
    protected function getContextCount(): int
    {
        return self::$contextCount ??= app()->get('context')->getCount();
    }

    /**
     * Id of the only context, when there is exactly one.
     */
    protected function getSoleContextId(): ?int
    {
        if (self::$soleContextId !== null) {
            return self::$soleContextId;
        }

        $ids = app()->get('context')->getIds();

        return count($ids) === 1
            ? self::$soleContextId = (int) reset($ids)
            : null;
    }
}
