<?php

/**
 * @file classes/viewer/ViewerKernel.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ViewerKernel
 *
 * @brief Serves one request for the viewer or its API through Laravel's router.
 *
 * The page router hands over index/admin/log-viewer[/...] through the LoadHandler hook. This then
 * supplies what the package needs and 3.5's container lacks, registers the package's provider,
 * rewrites the request path to the Laravel route and dispatches it. Everything here happens only
 * for requests to the viewer.
 *
 * The container gaps, each proven against 3.5 before this was written:
 * - no usable view factory: 'view' is unbound and the bound contract has no view paths, while
 *   Illuminate\View\ViewServiceProvider cannot be registered because PKPContainer has no terminating()
 * - no Gate user resolver: PKPAuthManager's constructor does not set one, so every Gate check fatals
 * - no signed-URL request macros: they come from Laravel's FoundationServiceProvider, which 3.5 lacks
 * - no abort(): Laravel's abort helpers call app()->abort(), a Foundation\Application method, so the
 *   package's "not found" answers fatal instead (shims/abortShims.php)
 */

namespace APP\plugins\generic\logViewer\classes\viewer;

use APP\plugins\generic\logViewer\LogViewerPlugin;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewFinderInterface;
use PKP\core\PKPRequest;
use PKP\middleware\SetupContextBasedOnRequestUrl;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ViewerKernel
{
    /**
     * Middleware run ahead of the router, as PKPRoutingProvider::getWebRouteMiddleware() does on 3.6.
     * Session middleware is left out on purpose: Dispatcher::initSession() has already started the
     * session, and a second pass logs the user out. Authorisation is route middleware.
     */
    public const MIDDLEWARE = [
        SetupContextBasedOnRequestUrl::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    public function __construct(protected LogViewerPlugin $plugin)
    {
    }

    /**
     * Dispatch the request to the viewer. When a viewer route matches, its response is sent and the
     * request ends here; false means no route matched and the page router should carry on.
     */
    public function handle(PKPRequest $pkpRequest): bool
    {
        require_once dirname(__DIR__, 2) . '/lib/vendor/autoload.php';
        require_once dirname(__DIR__, 2) . '/shims/abortShims.php';

        $this->installViewFactory();
        $this->installUserResolver();
        $this->installSignatureMacros();
        $this->ensureLogDirectory();

        app()->register(new LogViewerServiceProvider(app(), $this->plugin));

        $request = app()->get(IlluminateRequest::class); /** @var IlluminateRequest $request */

        // Build links against the address this request arrived at, front controller included, so that
        // download URLs route back here and validate their signatures whatever restful_urls is set to
        app()->get('url')->forceRootUrl($request->getSchemeAndHttpHost() . $request->getBaseUrl());

        try {
            $response = (new Pipeline(app()))
                ->send($this->toViewerRequest($request, $pkpRequest))
                ->through(static::MIDDLEWARE)
                ->then(fn (IlluminateRequest $viewerRequest) => app()->get('router')->dispatch($viewerRequest));
        } catch (NotFoundHttpException $exception) {
            // Thrown by route matching; a 404 from a matched route is a response and is sent below
            return false;
        }

        // Log contents (traces, logged emails) must not be kept in the browser cache
        $response->headers->set('Cache-Control', 'no-store');

        $response->send();
        exit;
    }

    /**
     * What Foundation\Application::abort() and the global abort() helper do together.
     *
     * @throws HttpException
     */
    public static function abort(SymfonyResponse|Responsable|int $code, string $message = '', array $headers = []): never
    {
        if ($code instanceof SymfonyResponse) {
            throw new HttpResponseException($code);
        }

        if ($code instanceof Responsable) {
            throw new HttpResponseException($code->toResponse(app()->get(IlluminateRequest::class)));
        }

        if ($code === SymfonyResponse::HTTP_NOT_FOUND) {
            throw new NotFoundHttpException($message, null, 0, $headers);
        }

        throw new HttpException($code, $message, null, $headers);
    }

    /**
     * The request with its path rewritten from {context}[/{locale}]/admin/log-viewer/... to the
     * Laravel route path. Query string, method, headers and body are kept.
     */
    protected function toViewerRequest(IlluminateRequest $request, PKPRequest $pkpRequest): IlluminateRequest
    {
        $path = LogViewerServiceProvider::ROUTE_PATH . $this->subPath($request, $pkpRequest);

        $requestUri = $request->getBaseUrl() . '/' . $path;
        $queryString = (string) $request->server->get('QUERY_STRING', '');
        if ($queryString !== '') {
            $requestUri .= '?' . $queryString;
        }

        return $request->duplicate(server: array_merge($request->server->all(), [
            'REQUEST_URI' => $requestUri,
            'PATH_INFO' => '/' . $path,
        ]));
    }

    /**
     * What follows admin/log-viewer in the path, still URL-encoded so that a signed URL validates
     * against exactly the path it was generated for.
     */
    protected function subPath(IlluminateRequest $request, PKPRequest $pkpRequest): string
    {
        $marker = '/' . LogViewerPlugin::PAGE . '/' . LogViewerPlugin::OP;
        $pathInfo = $request->getPathInfo();
        $position = strpos($pathInfo, $marker);

        if ($position !== false) {
            $rest = substr($pathInfo, $position + strlen($marker));

            if ($rest === '' || $rest[0] === '/') {
                return rtrim($rest, '/');
            }
        }

        // disable_path_info = On: the page router read the arguments from the query string
        $args = $pkpRequest->getRouter()->getRequestedArgs($pkpRequest);

        return $args ? '/' . implode('/', array_map('rawurlencode', $args)) : '';
    }

    /**
     * Bind a view factory that renders the plugin's plain-PHP layout. Bound under all three keys: the
     * view() helper resolves the contract, not 'view'.
     */
    protected function installViewFactory(): void
    {
        $files = app()->get(Filesystem::class);

        $resolver = new EngineResolver();
        $resolver->register('php', fn () => new PhpEngine($files));

        $finder = new FileViewFinder($files, []);
        $finder->addNamespace(LogViewerServiceProvider::VIEW_NAMESPACE, dirname(__DIR__, 2) . '/resources/views');

        $factory = new ViewFactory($resolver, $finder, app()->get('events'));
        $factory->setContainer(app());

        foreach (['view', ViewFactory::class, ViewFactoryContract::class] as $abstract) {
            app()->instance($abstract, $factory);
        }

        app()->instance('view.finder', $finder);
        app()->instance(ViewFinderInterface::class, $finder);
    }

    /**
     * Give the auth manager the user resolver its parent constructor would have set.
     */
    protected function installUserResolver(): void
    {
        $auth = app()->get('auth'); /** @var \Illuminate\Auth\AuthManager $auth */

        if (!$auth->userResolver()) {
            $auth->resolveUsersUsing(fn ($guard = null) => $auth->guard($guard)->user());
        }
    }

    /**
     * The request macros ValidateSignature relies on, as Laravel's FoundationServiceProvider defines them.
     */
    protected function installSignatureMacros(): void
    {
        if (IlluminateRequest::hasMacro('hasValidSignatureWhileIgnoring')) {
            return;
        }

        IlluminateRequest::macro('hasValidSignature', function ($absolute = true) {
            return URL::hasValidSignature($this, $absolute);
        });

        IlluminateRequest::macro('hasValidRelativeSignature', function () {
            return URL::hasValidSignature($this, false);
        });

        IlluminateRequest::macro('hasValidSignatureWhileIgnoring', function ($ignoreQuery = [], $absolute = true) {
            return URL::hasValidSignature($this, $absolute, $ignoreQuery);
        });

        IlluminateRequest::macro('hasValidRelativeSignatureWhileIgnoring', function ($ignoreQuery = []) {
            return URL::hasValidSignature($this, false, $ignoreQuery);
        });
    }

    /**
     * Create the application log directory, which the viewer scans before anything may have been logged.
     */
    protected function ensureLogDirectory(): void
    {
        $directory = $this->plugin->getLoggingSettings()->logDirectory();

        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }
}
