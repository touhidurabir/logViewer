<?php

/**
 * @file classes/logParser/UsageEventLog.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class UsageEventLog
 *
 * @brief Custom usage event log parser for Log Viewer
 *
 * Handles the usage event log format (JSON per line):
 * {"time":"2026-03-31 05:47:46","ip":"...","canonicalUrl":"...","assocType":256,...}
 *
 * Adapted from PKP\logParser\PKPUsageEventLog on 3.6 (pkp/pkp-lib#12237).
 */

namespace APP\plugins\generic\logViewer\classes\logParser;

use Opcodes\LogViewer\Logs\Log;

class UsageEventLog extends Log
{
    public static string $name = 'Usage Stats';

    public static string $regex = '/^(?<message>\{.+\})$/s';

    protected function parseText(array &$matches = []): void
    {
        $data = json_decode($this->text, true);

        if ($data && isset($data['time'])) {
            $matches['datetime'] = $data['time'];
            $matches['level'] = 'INFO';

            // Build a human-readable summary message
            $url = $data['canonicalUrl'] ?? 'unknown';
            $assocType = $data['assocType'] ?? '';
            $matches['message'] = $url . ' (assocType: ' . $assocType . ')';
        }
    }

    protected function fillMatches(array $matches = []): void
    {
        $this->datetime = static::parseDatetime($matches['datetime'] ?? null);
        $this->level = $matches['level'] ?? 'INFO';
        $this->message = $matches['message'] ?? $this->text;

        // Store full JSON data in context for expanded view
        $data = json_decode($this->text, true);
        if ($data) {
            $this->context = $data;
        }
    }

    public static function matches(string $text, ?int &$timestamp = null, ?string &$level = null): bool
    {
        // Must be valid JSON starting with { and containing "time" key
        $data = json_decode($text, true);
        if (!$data || !isset($data['time'])) {
            return false;
        }

        try {
            $datetime = static::parseDatetime($data['time']);
            $timestamp = $datetime?->timestamp;
            $level = 'INFO';
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }
}
