<?php

/**
 * @file classes/logParser/LaravelLog.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LaravelLog
 *
 * @brief Laravel log parser for Log Viewer whose email previews are safe to display.
 *
 * When mail is sent to the log ([general] sandbox, or [email] default = log), the vendor parser
 * extracts each email and the viewer renders its HTML part in an iframe without a sandbox
 * attribute, so any script in that HTML would run in the site's origin with the administrator's
 * session. The HTML part is therefore passed through the site's HTML purifier first. If a later
 * version of the package sandboxes that frame — check `public/app.js` after an update — this class
 * is no longer needed.
 *
 * Adapted from PKP\logParser\PKPLaravelLog on 3.6 (pkp/pkp-lib#12237).
 */

namespace APP\plugins\generic\logViewer\classes\logParser;

use Opcodes\LogViewer\Logs\LaravelLog as VendorLaravelLog;
use PKP\core\PKPString;

class LaravelLog extends VendorLaravelLog
{
    /**
     * @copydoc \Opcodes\LogViewer\Logs\LaravelLog::extractMailPreview()
     */
    protected function extractMailPreview(string $originalText): void
    {
        parent::extractMailPreview($originalText);

        if (isset($this->extra['mail_preview']['html'])) {
            $this->extra['mail_preview']['html'] = PKPString::stripUnsafeHtml($this->extra['mail_preview']['html']);
        }
    }
}
