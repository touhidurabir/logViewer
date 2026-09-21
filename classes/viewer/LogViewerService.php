<?php

/**
 * @file classes/viewer/LogViewerService.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogViewerService
 *
 * @brief The vendor service with the three methods that call storage_path() or public_path()
 *        replaced. PKPContainer implements neither storagePath() nor publicPath() on 3.5.
 */

namespace APP\plugins\generic\logViewer\classes\viewer;

use Illuminate\Support\Str;
use Opcodes\LogViewer\LogViewerService as VendorLogViewerService;

class LogViewerService extends VendorLogViewerService
{
    public function __construct(protected string $logDirectory)
    {
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerService::basePathForLogs()
     */
    public function basePathForLogs(): string
    {
        return Str::finish(realpath($this->logDirectory) ?: $this->logDirectory, DIRECTORY_SEPARATOR);
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerService::assetsArePublished()
     *
     * Never: the plugin's layout always inlines the vendor's self-contained CSS and JS bundle.
     */
    public function assetsArePublished(): bool
    {
        return false;
    }

    /**
     * @copydoc \Opcodes\LogViewer\LogViewerService::assetsAreCurrent()
     */
    public function assetsAreCurrent(): bool
    {
        return false;
    }
}
