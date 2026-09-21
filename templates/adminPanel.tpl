{**
 * templates/adminPanel.tpl
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Log viewer entry point, appended to the site administration index page through the
 * Templates::Admin::Index::AdminFunctions hook.
 *}
<action-panel>
	<h2>{translate key="plugins.generic.logViewer.admin.title"}</h2>
	<p>
		{translate key="plugins.generic.logViewer.admin.description"}
	</p>
	<template #actions>
		<pkp-button
			element="a"
			href="{$logViewerUrl|escape}"
			target="_blank"
		>
			{translate key="plugins.generic.logViewer.admin.view"}
		</pkp-button>
	</template>
</action-panel>
