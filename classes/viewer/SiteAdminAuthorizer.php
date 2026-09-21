<?php

/**
 * @file classes/viewer/SiteAdminAuthorizer.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SiteAdminAuthorizer
 *
 * @brief Route middleware for the viewer and its API: site administrators only, and a valid CSRF
 *        token on every state-changing request.
 *
 * Adapted from PKP\middleware\SiteAdminAuthorizer on 3.6 (pkp/pkp-lib#12237). Two additions:
 * a logged-out browser is sent to the login page instead of a bare 401, and state-changing
 * requests (deleting logs, clearing caches) must carry the session's CSRF token, which the
 * viewer's SPA sends as X-CSRF-TOKEN from the page's csrf-token meta tag.
 */

namespace APP\plugins\generic\logViewer\classes\viewer;

use APP\core\Application;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PKP\core\PKPApplication;
use PKP\security\Role;
use PKP\security\Validation;

class SiteAdminAuthorizer
{
    /** HTTP methods that do not change state and so need no CSRF token. */
    protected const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $pkpRequest = Application::get()->getRequest();
        $user = $pkpRequest->getUser();

        if (!$user) {
            if (!$this->wantsJson($request)) {
                Validation::redirectLogin();
            }

            return $this->deny($request, 'user.authorization.loginRequired', Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID)) {
            return $this->deny($request, 'plugins.generic.logViewer.siteAdminRequired', Response::HTTP_FORBIDDEN);
        }

        // A session always carries a token, but comparing an empty one would let a request with no
        // token through, so the check refuses rather than trusts when there is nothing to compare
        $token = (string) $pkpRequest->getSession()->token();

        if (!in_array($request->getMethod(), static::SAFE_METHODS, true)
            && ($token === '' || !hash_equals($token, (string) $request->header('X-CSRF-TOKEN')))
        ) {
            return $this->deny($request, 'form.csrfInvalid', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Whether the caller expects a JSON response.
     */
    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->isJson();
    }

    /**
     * Refuse the request.
     */
    protected function deny(Request $request, string $messageKey, int $statusCode): Response|JsonResponse
    {
        $message = __($messageKey);

        return $this->wantsJson($request)
            ? response()->json(['error' => $messageKey, 'errorMessage' => $message], $statusCode)
            : response($message, $statusCode);
    }
}
