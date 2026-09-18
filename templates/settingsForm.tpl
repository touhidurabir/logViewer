{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Log Viewer plugin settings. Fields set in config.inc.php [logs] are read-only.
 *}
{capture assign="checkLogPathUrl"}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="checkLogPath" escape=false}{/capture}
<script>
	$(function() {ldelim}
		var $form = $('#logViewerSettingsForm'),
			checkUrl = '{$checkLogPathUrl|escape:"javascript"}',
			timers = {ldelim}{rdelim};

		$form.pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		// Report whether a log path exists and is readable while it is being typed
		$form.on('input change', 'input.logViewerSourcePath', function() {ldelim}
			var input = this;
			clearTimeout(timers[input.name]);
			timers[input.name] = setTimeout(function() {ldelim}
				$.getJSON(checkUrl, {ldelim}path: input.value{rdelim}, function(json) {ldelim}
					if (json.status) {ldelim}
						$('#' + input.name + 'Status').text(json.content.message).attr('data-state', json.content.state);
					{rdelim}
				{rdelim});
			{rdelim}, 400);
		{rdelim});
	{rdelim});
</script>
<style>
	#logViewerSettingsForm li.logViewerSource {ldelim} list-style: none; margin: 0.25rem 0 0 1.75rem; {rdelim}
	#logViewerSettingsForm li.logViewerSource p {ldelim} display: block; margin: 0.25rem 0; {rdelim}
	#logViewerSettingsForm .logViewerSourceStatus[data-state="found"] {ldelim} color: #00754a; {rdelim}
	#logViewerSettingsForm .logViewerSourceStatus[data-state="missing"],
	#logViewerSettingsForm .logViewerSourceStatus[data-state="notFile"],
	#logViewerSettingsForm .logViewerSourceStatus[data-state="relative"],
	#logViewerSettingsForm .logViewerSourceStatus[data-state="unreadable"] {ldelim} color: #b3122e; {rdelim}
	#logViewerSettingsForm .logViewerConfigBlock {ldelim} width: 100%; font-family: monospace; font-size: 0.85em; white-space: pre; {rdelim}
</style>

<form class="pkp_form" id="logViewerSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="logViewerSettingsFormNotification"}
	{include file="common/formErrors.tpl"}

	<p>{translate key="plugins.generic.logViewer.settings.description" logDirectory=$logDirectory|escape}</p>

	{fbvFormArea id="logViewerChannelArea"}
		{fbvFormSection title="plugins.generic.logViewer.settings.logChannel" description="plugins.generic.logViewer.settings.logChannel.description"}
			{fbvElement type="select" id="logChannel" from=$channelOptions selected=$logChannel translate=false disabled=$overridden.logChannel size=$fbvStyles.size.MEDIUM}
			{if $overridden.logChannel}<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name="log_channel"}</p>{/if}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.logViewer.settings.logStacks" description="plugins.generic.logViewer.settings.logStacks.description" for="logStacks[]" list="true"}
			{fbvElement type="checkboxgroup" id="logStacks" from=$stackOptions selected=$logStacks translate=false disabled=$overridden.logStacks}
			{if $overridden.logStacks}<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name="log_stacks"}</p>{/if}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.logViewer.settings.logDailyDays" description="plugins.generic.logViewer.settings.logDailyDays.description"}
			{fbvElement type="text" id="logDailyDays" value=$logDailyDays disabled=$overridden.logDailyDays size=$fbvStyles.size.SMALL}
			{if $overridden.logDailyDays}<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name="log_daily_days"}</p>{/if}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.logViewer.settings.logFormatter" description="plugins.generic.logViewer.settings.logFormatter.description"}
			{fbvElement type="select" id="logFormatter" from=$formatterOptions selected=$logFormatter translate=false disabled=$overridden.logFormatter size=$fbvStyles.size.MEDIUM}
			{if $overridden.logFormatter}<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name="log_formatter"}</p>{/if}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="logViewerClientErrorsArea"}
		{fbvFormSection title="plugins.generic.logViewer.settings.logClientErrors" list="true"}
			{fbvElement type="checkbox" id="logClientErrors" value="1" checked=$logClientErrors disabled=$overridden.logClientErrors label="plugins.generic.logViewer.settings.logClientErrors.label"}
			<p class="description">{translate key="plugins.generic.logViewer.settings.logClientErrors.description"}</p>
			{if $overridden.logClientErrors}<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name="log_client_errors"}</p>{/if}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="logViewerSourcesArea" title="plugins.generic.logViewer.sources"}
		<p>{translate key="plugins.generic.logViewer.sources.description"}</p>
		{foreach from=$logSourceRows item=source}
			{fbvFormSection title=$source.labelKey list="true"}
				{fbvElement type="checkbox" id=$source.enabledField value="1" checked=$source.enabled disabled=$source.overridden label="plugins.generic.logViewer.sources.show"}
				<li class="logViewerSource">
					<p class="description">{translate key=$source.descriptionKey}</p>
					{if $source.hasPath}
						{fbvElement type="text" id=$source.pathField value=$source.path disabled=$source.overridden class="logViewerSourcePath" label="plugins.generic.logViewer.sources.path" size=$fbvStyles.size.LARGE}
					{/if}
					<p class="description logViewerSourceStatus" id="{$source.pathField|escape}Status" data-state="{$source.state|escape}">{$source.status|escape}</p>
					{if $source.overridden}
						<p class="description">{translate key="plugins.generic.logViewer.settings.overridden" key_name=$source.configKey}</p>
					{elseif $source.detected}
						<p class="description">{translate key="plugins.generic.logViewer.sources.detected"}</p>
					{/if}
				</li>
			{/fbvFormSection}
		{/foreach}
	{/fbvFormArea}

	{fbvFormArea id="logViewerConfigBlockArea" title="plugins.generic.logViewer.settings.configBlock"}
		{fbvFormSection}
			<p class="description">{translate key="plugins.generic.logViewer.settings.configBlock.description"}</p>
			<textarea class="logViewerConfigBlock" rows="14" readonly="readonly" onclick="this.select();">{$configBlock|escape}</textarea>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
