<?php

/**
 * @file classes/RequestContext.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class RequestContext
 *
 * @brief What was being asked for when a log entry was written: method, path, journal and user.
 *
 * An entry that says only what went wrong leaves the administrator nothing to go on, and a 404
 * carries no message at all, so on its own it reads as an empty ERROR line.
 *
 * Deliberately left out:
 * - the query string. 3.5 routes on PATH_INFO alone, so it adds nothing to the address, while
 *   reviewer access keys, password reset confirmations and API tokens travel in it.
 * - the caller's IP address, which is personal data the web server's own log already keeps.
 *
 * Nothing here may throw: it runs while an error is being reported, and whatever caused that error
 * — an unreachable database, a half-built container — may be what these lookups need. Anything that
 * cannot be answered is left out of the entry instead.
 */

namespace APP\plugins\generic\logViewer\classes;

use APP\core\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PKP\core\Registry;
use PKP\user\User;
use Throwable;

class RequestContext
{
    /** Longest path recorded. A probe can ask for a very long one, and it would be logged in full. */
    public const MAX_PATH_LENGTH = 300;

    /**
     * The request behind a log entry, as log context. Empty on the command line, where a scheduled
     * task or a tool is running and there is no request to describe.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            return [];
        }

        $description = ['method' => (string) $_SERVER['REQUEST_METHOD']];

        if (($path = static::path()) !== '') {
            $description['path'] = $path;
        }

        if (($contextPath = static::contextPath()) !== '') {
            $description['contextPath'] = $contextPath;
        }

        if (($userId = static::userId()) !== null) {
            $description['userId'] = $userId;
        }

        return $description;
    }

    /**
     * The path asked for, without the query string.
     */
    protected static function path(): string
    {
        $path = '';

        try {
            $path = Application::get()->getRequest()->getRequestPath();
        } catch (Throwable) {
            // Falls through to the server's own answer below
        }

        if ($path === '') {
            $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        }

        return Str::limit($path, static::MAX_PATH_LENGTH);
    }

    /**
     * The path of the journal, press or server the request was addressed to, or '' for the site.
     */
    protected static function contextPath(): string
    {
        try {
            $request = Application::get()->getRequest();
            $contextPath = (string) $request->getRouter()?->getRequestedContextPath($request);
        } catch (Throwable) {
            return '';
        }

        /** @deprecated 3.5 The usage of "_" as a site context has been deprecated */
        return in_array($contextPath, [Application::SITE_CONTEXT_PATH, '_'], true) ? '' : $contextPath;
    }

    /**
     * The user the request was made as, if one has already been identified. Never identifies one
     * here: that would mean a session and a query, on a request that is already failing.
     */
    protected static function userId(): ?int
    {
        try {
            $user = Registry::get('user');

            if (!$user instanceof User) {
                $user = Auth::hasUser() ? Auth::user() : null;
            }

            return $user instanceof User ? $user->getId() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
