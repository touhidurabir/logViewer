<?php

/**
 * @file classes/viewer/LogViewerServiceProvider.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogViewerServiceProvider
 *
 * @brief The opcodesio/log-viewer service provider, configured for OJS/OMP/OPS 3.5.
 *
 * Adapted from PKP\core\PKPLogViewerServiceProvider on 3.6 (pkp/pkp-lib#12237). It is registered
 * only for requests to the viewer, so the rest of the application never loads the package.
 * Differences from the parent provider:
 * - register() skips mergeConfigFrom(); the whole config is set explicitly in configureLogViewer(),
 *   so after updating the package check that every key its new version reads is set there
 * - the vendor's views, asset publishing, HTTP kernel middleware and Octane hooks are not used
 * - routes are registered on the router directly, then the URL generator is synced with them
 * - gates allow deletion of the application and scheduled task logs only, and refuse folder downloads
 * - the files shown, and the parser for each, come from the plugin's log sources (LogSources)
 * - PKP parsers are registered for the application, PHP error, scheduled task, usage event and MySQL logs
 * - the Laravel parser is replaced by one that sanitises logged email previews
 */

namespace APP\plugins\generic\logViewer\classes\viewer;

use APP\core\Application;
use APP\plugins\generic\logViewer\classes\logParser\ApplicationLog;
use APP\plugins\generic\logViewer\classes\logParser\LaravelLog;
use APP\plugins\generic\logViewer\classes\logParser\MysqlErrorLog;
use APP\plugins\generic\logViewer\classes\logParser\PhpErrorLog;
use APP\plugins\generic\logViewer\classes\logParser\ScheduledTaskLog;
use APP\plugins\generic\logViewer\classes\logParser\UsageEventLog;
use APP\plugins\generic\logViewer\classes\LogSources;
use APP\plugins\generic\logViewer\LogViewerPlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Enums\SortingMethod;
use Opcodes\LogViewer\Enums\SortingOrder;
use Opcodes\LogViewer\Enums\Theme;
use Opcodes\LogViewer\Events\LogFileDeleted;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\LogFolder;
use Opcodes\LogViewer\Logs\LogType;
use Opcodes\LogViewer\LogTypeRegistrar;
use Opcodes\LogViewer\LogViewerServiceProvider as VendorLogViewerServiceProvider;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\plugins\Hook;
use PKP\scheduledTask\ScheduledTaskHelper;

class LogViewerServiceProvider extends VendorLogViewerServiceProvider
{
    /** Laravel route prefix. Starts with the site context path, as SetupContextBasedOnRequestUrl expects. */
    public const ROUTE_PATH = Application::SITE_CONTEXT_PATH . '/' . LogViewerPlugin::PAGE . '/' . LogViewerPlugin::OP;

    /** View namespace of the plugin's own layout. */
    public const VIEW_NAMESPACE = 'logViewer';

    public function __construct($app, protected LogViewerPlugin $plugin)
    {
        parent::__construct($app);
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerServiceProvider::register()
     */
    public function register()
    {
        $logDirectory = $this->plugin->getLoggingSettings()->logDirectory();

        $this->app->singleton('log-viewer', fn () => new LogViewerService($logDirectory));
        $this->app->singleton('log-viewer-cache', fn () => Cache::driver(config('log-viewer.cache_driver')));
        $this->app->instance(LogSources::class, $this->plugin->getLogSources());

        // One registrar, or the parsers added with LogViewer::extend() are lost on the next lookup
        $this->app->singleton(LogTypeRegistrar::class);
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerServiceProvider::boot()
     */
    public function boot()
    {
        $this->configureLogViewer();
        $this->defineDefaultGates();
        $this->registerLogParsers();
        $this->remapLaravelLocaleKeys();

        Event::listen(LogFileDeleted::class, fn () => LogViewer::clearFileCache());

        LogViewer::setViewLayout(static::VIEW_NAMESPACE . '::layout');

        // Reads each file with the parser of the source it was configured for
        LogViewer::useLogFileClass(SourceLogFile::class);

        $this->registerRoutes();
    }

    /**
     * Set the complete log-viewer config. Every key the package reads must be present, because nothing
     * is merged from its defaults: re-audit this list whenever the package is upgraded.
     */
    protected function configureLogViewer(): void
    {
        $request = Application::get()->getRequest();

        $this->app->get('config')->set('log-viewer', [
            'enabled' => true,
            'api_only' => false,
            'require_auth_in_production' => true,

            'route_path' => static::ROUTE_PATH,
            'route_domain' => null,
            // Probed on every render but never linked: the layout inlines the assets
            'assets_path' => 'vendor/log-viewer',

            'show_support_link' => false,
            'back_to_system_url' => $request->getDispatcher()->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                Application::SITE_CONTEXT_PATH,
                LogViewerPlugin::PAGE
            ),
            'back_to_system_label' => __('navigation.admin'),
            'timezone' => null,
            'datetime_format' => 'Y-m-d H:i:s',

            'api_stateful_domains' => [],
            'api_middleware' => [SiteAdminAuthorizer::class],
            'middleware' => [SiteAdminAuthorizer::class],

            'hosts' => [
                'local' => ['name' => 'Local'],
            ],

            'include_files' => $this->plugin->getLogSources()->includeFiles(),
            'exclude_files' => [],
            'hide_unknown_files' => false,

            'shorter_stack_trace_excludes' => [
                'vendor/symfony',
                'vendor/laravel',
            ],
            'strip_extracted_context' => true,

            'cache_driver' => null,
            'cache_key_prefix' => 'lv',

            'lazy_scan_chunk_size_in_mb' => 50,

            'per_page_options' => [10, 25, 50, 100, 250, 500],

            'defaults' => [
                'use_local_storage' => true,
                'folder_sorting_method' => SortingMethod::ModifiedTime,
                'folder_sorting_order' => SortingOrder::Descending,
                'file_sorting_method' => SortingMethod::ModifiedTime,
                'log_sorting_order' => SortingOrder::Descending,
                'per_page' => 25,
                'theme' => Theme::System,
                'shorter_stack_traces' => false,
            ],

            'exclude_ip_from_identifiers' => false,
            'root_folder_prefix' => 'root',
        ]);
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerServiceProvider::defineDefaultGates()
     *
     * The parent only defines a gate that is not defined yet, so the delete gates are defined first and
     * the parent supplies the permissive download gates.
     */
    protected function defineDefaultGates()
    {
        Gate::define(
            'deleteLogFile',
            fn (mixed $user, LogFile $file): bool => $this->isDeletable($file->path)
        );

        // FoldersController::delete() authorises the folder and then each file, so both gates are needed
        Gate::define(
            'deleteLogFolder',
            fn (mixed $user, LogFolder $folder): bool => $this->isDeletable($folder->path)
        );

        // The package builds a folder's ZIP at a predictable path in the system temporary directory and
        // never removes it, leaving a copy of the logs where other accounts on the host can read it.
        // Refusing the gate also removes the button: the folder resource reports can_download from it.
        Gate::define('downloadLogFolder', fn (mixed $user, LogFolder $folder): bool => false);

        parent::defineDefaultGates();
    }

    /**
     * Whether a log file or folder may be deleted from the viewer. Only the logs the application writes for
     * its own diagnostics can be: usage statistics logs are source data the statistics task has not processed
     * yet, and external logs (PHP, web server, database, supervisor) belong to the server.
     *
     * A path that cannot be resolved is refused, and resolving it means a symlink placed in a deletable
     * directory is judged by its target.
     */
    protected function isDeletable(string $path): bool
    {
        if (($path = realpath($path)) === false) {
            return false;
        }

        foreach ($this->deletableLogDirectories() as $directory) {
            if (($directory = realpath($directory)) === false) {
                continue;
            }

            if ($path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The directories holding the logs that may be deleted from the viewer: the application log and the
     * scheduled task logs.
     *
     * @return string[]
     */
    protected function deletableLogDirectories(): array
    {
        return [
            $this->plugin->getLoggingSettings()->logDirectory(),
            rtrim((string) Config::getVar('files', 'files_dir'), '/') . '/' . ScheduledTaskHelper::SCHEDULED_TASK_EXECUTION_LOG_DIR,
        ];
    }

    /**
     * Register the PKP log parsers. The registrar unshifts, so the last one is tried first when a type is
     * guessed from a file's first line.
     */
    protected function registerLogParsers(): void
    {
        // Replaces the vendor's php-fpm parser, which does not read PHP's own error log format
        LogViewer::extend(LogSources::TYPE_PHP_ERROR, PhpErrorLog::class);

        // Replaces the vendor's Laravel parser, so logged email previews are sanitised
        LogViewer::extend(LogType::LARAVEL, LaravelLog::class);

        LogViewer::extend(LogSources::TYPE_APPLICATION, ApplicationLog::class);
        LogViewer::extend(LogSources::TYPE_SCHEDULED_TASKS, ScheduledTaskLog::class);
        LogViewer::extend(LogSources::TYPE_USAGE_STATS, UsageEventLog::class);
        LogViewer::extend(LogSources::TYPE_MYSQL, MysqlErrorLog::class);
    }

    /**
     * Laravel's paginator asks for keys like pagination.next, which PKP keeps as common.pagination.next.
     */
    protected function remapLaravelLocaleKeys(): void
    {
        $remaps = [
            'pagination.previous' => 'common.pagination.previous',
            'pagination.next' => 'common.pagination.next',
        ];

        Hook::add('Locale::translate', function (string $hookName, array $args) use ($remaps): bool {
            $value = &$args[0];
            $key = $args[1];

            if (!isset($remaps[$key])) {
                return Hook::CONTINUE;
            }

            $value = __($remaps[$key]);

            return Hook::ABORT;
        });
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerServiceProvider::registerRoutes()
     */
    protected function registerRoutes()
    {
        $router = $this->app->get('router'); /** @var \Illuminate\Routing\Router $router */
        $routePath = config('log-viewer.route_path');

        $router->group([
            'prefix' => Str::finish($routePath, '/') . 'api',
            'namespace' => 'Opcodes\LogViewer\Http\Controllers',
            'middleware' => config('log-viewer.api_middleware', []),
        ], fn () => require static::basePath('/routes/api.php'));

        $router->group([
            'prefix' => $routePath,
            'namespace' => 'Opcodes\LogViewer\Http\Controllers',
            'middleware' => config('log-viewer.middleware', []),
        ], fn () => require static::basePath('/routes/web.php'));

        // Without these, route() and signed download URLs cannot see the routes just added
        $router->getRoutes()->refreshNameLookups();
        $this->app->get('url')->setRoutes($router->getRoutes());
    }
}
