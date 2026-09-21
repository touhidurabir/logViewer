<?php

/**
 * @file classes/viewer/SourceLogFile.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SourceLogFile
 *
 * @brief A log file read with the parser of the source it was configured for.
 *
 * The package otherwise guesses a file's type from its name, then its first line, and caches the guess.
 * Here the administrator has already said what each file is, so that decides, and a file scanned earlier
 * under another type has its index discarded, since entries were split by the wrong parser.
 */

namespace APP\plugins\generic\logViewer\classes\viewer;

use APP\plugins\generic\logViewer\classes\LogSources;
use Opcodes\LogViewer\LogFile as VendorLogFile;

class SourceLogFile extends VendorLogFile
{
    public function __construct(string $path, ?string $type = null, ?string $pathAlias = null)
    {
        $type ??= app()->get(LogSources::class)->typeFor($path);

        parent::__construct($path, $type, $pathAlias);

        if ($type === null || $this->getMetadata('type') === $type) {
            return;
        }

        if ($this->getMetadata('type') !== null) {
            $this->clearCache();
            $this->metadata = [];
        }

        $this->setMetadata('type', $type);
        $this->saveMetadata();
    }
}
