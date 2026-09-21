{**
 * templates/upgradeNotice.tpl
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Shown in place of the settings on a release that provides logging in core: the plugin no longer
 * applies its settings, so it hands them over as a [logs] section to paste into config.inc.php.
 *}
<style>
	#logViewerUpgradeNotice textarea {ldelim} width: 100%; font-family: monospace; font-size: 0.85em; white-space: pre; {rdelim}
</style>
<div id="logViewerUpgradeNotice" class="pkp_form">
	<p>{translate key="plugins.generic.logViewer.upgrade.description"}</p>
	<textarea rows="16" readonly="readonly" onclick="this.select();">{$configBlock|escape}</textarea>
</div>
