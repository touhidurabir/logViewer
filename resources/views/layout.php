<?php

/**
 * @file resources/views/layout.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The viewer page. Replaces the package's index.blade.php, which needs Blade plus container methods
 * 3.5 lacks (getLocale()) and helpers it cannot serve (asset(), mix()). The package's CSS, JS and
 * favicon are inlined, as its own layout does when no assets are published.
 *
 * @var array $logViewerScriptVariables Supplied by Opcodes\LogViewer\Http\Controllers\IndexController
 */

use APP\core\Application;
use APP\plugins\generic\logViewer\classes\LogSources;
use Opcodes\LogViewer\Facades\LogViewer;
use PKP\facades\Locale;

$csrfToken = Application::get()->getRequest()->getSession()->token();
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$fileTypes = app()->get(LogSources::class)->shownTypes();

?>
<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', Locale::getLocale())); ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e($csrfToken); ?>">
    <?php echo LogViewer::favicon(); ?>
    <title><?php echo e(__('plugins.generic.logViewer.pageTitle')); ?></title>
    <?php echo LogViewer::css(); ?>
</head>
<body class="h-full px-3 lg:px-5 bg-gray-100 dark:bg-gray-900">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full">
    <router-view></router-view>
</div>
<script>
    window.LogViewer = <?php echo json_encode($logViewerScriptVariables, $jsonFlags); ?>;

    // The package keeps the ticked "Selected file types" in the browser and only fills that list when
    // it is empty, so a kind of log that appears later - the application log after the first error, or
    // one just switched on in the settings - would stay hidden with no sign of it. Tick a type this
    // browser has not seen before, and leave alone any the administrator unticked on purpose.
    // The storage key and its JSON format belong to the package: re-check them in its public/app.js
    // after an update.
    (function (types) {
        var selectedKey = 'selectedFileTypes';
        var seenKey = 'logViewer.seenFileTypes';

        try {
            var selected = JSON.parse(localStorage.getItem(selectedKey) || '[]');
            var seen = JSON.parse(localStorage.getItem(seenKey) || '[]');

            if (!Array.isArray(selected) || !Array.isArray(seen)) {
                return;
            }

            var unseen = types.filter(function (type) {
                return seen.indexOf(type) === -1;
            });

            if (unseen.length === 0) {
                return;
            }

            // An empty selection is the package's own "show everything" state; leave it to fill itself
            if (selected.length > 0) {
                localStorage.setItem(selectedKey, JSON.stringify(selected.concat(unseen)));
            }

            localStorage.setItem(seenKey, JSON.stringify(seen.concat(unseen)));
        } catch (error) {
            // No storage, or something unexpected in it: the package's own behaviour applies
        }
    })(<?php echo json_encode($fileTypes, $jsonFlags); ?>);
</script>
<?php echo LogViewer::js(); ?>
</body>
</html>
