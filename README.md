# logViewer

Application logging and a web log viewer for OJS, OMP and OPS **3.5**, as a generic plugin with no
changes to the application. Every 3.5.0 point release is supported: the plugin uses nothing that was
added to `lib/pkp` after 3.5.0-0.

It backports two pieces of 3.6 work:

- **Logging** ([pkp/pkp-lib#12841](https://github.com/pkp/pkp-lib/issues/12841)): Laravel log channels
  writing to `{files_dir}/logs/`, with uncaught errors reported into them.
- **Log viewer** ([pkp/pkp-lib#12237](https://github.com/pkp/pkp-lib/issues/12237)): the
  [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer) UI for site administrators at
  `index.php/index/admin/log-viewer`.

The plugin refuses to be enabled on releases that ship logging in core (3.6 and later). Configure
`[logs]` in `config.inc.php` there instead — and before upgrading, copy the ready-made `[logs]` section
the settings form shows at the bottom, which is these settings written for that file. After the upgrade
the plugin's **Settings** action still hands it over, so nothing is lost if the copy was forgotten.

## What it does

- Sets up the `daily`, `single`, `stack`, `errorlog`, `syslog`, `stderr` and `null` channels, with the
  same names and meaning as 3.6. The default is daily files, `{files_dir}/logs/app-YYYY-MM-DD.log`.
- Reports uncaught errors, queue job failures and API exceptions to the configured channel. Without the
  plugin, 3.5 sends these only to PHP's error log. Failures the caller caused, such as 404s, are left
  out unless the settings ask for them (see below).
- Adds a **Logs** panel to Administration that opens the viewer, where logs can be searched, downloaded
  and deleted. Besides the application log it can show PHP's error log, scheduled task logs, usage
  statistics logs, the Apache and Nginx error logs, a web server access log, the MySQL/MariaDB or
  PostgreSQL log, and Supervisor's log. Which ones is chosen in the settings.

## Installation

Copy the plugin to `plugins/generic/logViewer` and enable it:

- **Several journals/presses/servers:** Administration › Site Settings › Plugins.
- **A single journal/press/server:** Settings › Website › Plugins, in that journal. It is listed there
  because 3.5 hides the site plugin list on single-context installs, but only a site administrator can
  enable it or change its settings.

`{files_dir}` must be writable by the web server.

The release package contains the plugin's dependencies in `lib/vendor`, so installing from it needs no
Composer step. They are **not** in the git repository: after cloning, run `composer install` in the plugin
directory, which puts them in `lib/vendor` as `composer.json` says.

### Building a release

With [pkp-plugin-cli](https://www.npmjs.com/package/pkp-plugin-cli), the tool PKP's own plugins are released
with:

```bash
npm install -g pkp-plugin-cli
pkp-plugin release logViewer --newversion 1.0.0.0
```

It clones the repository fresh, runs `composer install` because there is a `composer.json`, removes `.git`
and everything listed in `exclusions.txt`, and builds `logViewer-vX.tar.gz` with its MD5 — which is what the
plugin gallery entry needs. Installing that file through Administration › Plugins › Upload works on a host
with no shell access, because nothing runs Composer at install time.

## Configuration

Settings are available from the plugin's **Settings** action. Anything set in a `[logs]` section of
`config.inc.php` overrides the matching setting and appears read-only in the form. The keys are those of
3.6, so the same section keeps working after an upgrade:

```ini
[logs]
; daily, single, stack, errorlog, syslog, stderr or null
log_channel = daily

; Minimum level: debug, info, notice, warning, error, critical, alert, emergency (config only)
log_level = debug

; Destinations for the stack channel, comma-separated
log_stacks = daily

; Daily files to keep; 0 keeps them all
log_daily_days = 30

; Monolog formatter class for daily, single, stderr and syslog. Unset = readable lines.
; log_formatter = Monolog\Formatter\JsonFormatter

; Report failures the caller caused, such as 404s. Plugin only: 3.6 always reports them.
; log_client_errors = Off

; Log files to show in the viewer. Setting one here shows it, from this path, whatever the settings say.
; A path may be a glob pattern. php_error_log falls back to PHP's error_log setting.
; php_error_log = /var/log/php/error.log
; apache_error_log = /var/log/apache2/error.log
; nginx_error_log = /var/log/nginx/error.log
; http_access_log = /var/log/nginx/access.log
; postgres_log = /var/log/postgresql/*.log
; mysql_log = /var/log/mysql/error.log          ; plugin only: 3.6 has no MySQL key
; supervisor_log = /var/log/supervisor/supervisord.log
```

## Choosing the logs the viewer shows

Under **Logs shown in the viewer** in the settings, each log has a **Show in the viewer** box. Logs that
live outside the files directory also take a path, which may be a single file or a glob pattern such as
`/var/log/postgresql/*.log`.

- **Paths are checked as you type** and again when saving, as the web server sees them. The check
  reports whether the path was found (with file count and total size), is missing, is not a file, is
  relative, or exists but cannot be read. A log that is switched on must resolve to at least one readable
  file.
- **Paths are suggested** when none is set: PHP's `error_log` setting, the database server's own answer
  (`@@log_error` on MySQL/MariaDB, `pg_current_logfile()` on PostgreSQL), and the usual locations for the
  web server and Supervisor. A suggestion is only used once the settings are saved. A database log can only
  be read when the database server runs on the same machine.
- **Each log is read with the parser for the kind of log it was configured as**, not guessed from its
  first line. MySQL and MariaDB error logs use a parser included with this plugin, since the log-viewer
  package has none.
- The web server needs permission to read these files. System logs are often readable only by `root` or
  the `adm` group.

Only site administrators can change these settings. On 3.5 a site administrator can already upload and
run plugins, so letting them name a log file does not give them any access they do not already have.

## Failed requests

A request for a page that does not exist, or one that was refused, failed because of the caller. 3.6
reports every such failure at ERROR level with a full stack trace, which on a public site means a log
mostly made of robots and mistyped addresses. The plugin leaves them out unless **Failed requests** is
ticked, or `log_client_errors = On` is set. Errors in the application itself are always reported.

## What PHP's own error log still holds

Most of 3.5's diagnostic messages are written with PHP's `error_log()`, which no error handler can see,
and 3.6 does not route them to the log channels either. They stay in PHP's error log — which the viewer
shows as a log source, on by default, so they are still one click away.

## The viewer

- Site administrators only. Logged-out visitors are sent to the login page, and other users get 403.
- Deleting, and other state-changing actions, require the page's CSRF token.
- Only the application logs (`{files_dir}/logs`) and the scheduled task logs can be **deleted**. Every other
  log can be viewed and downloaded but not deleted: usage statistics logs are events the statistics task has
  not counted yet, and external logs (PHP, web server, database, Supervisor) belong to the server.
- When mail is sent to the log (`[general] sandbox = On` or `[email] default = log`), the viewer previews each
  email. The HTML part is cleaned with `[security] allowed_html` first, so scripts and event handlers in an
  email never run in the viewer.
- Download links are signed and expire. They work with or without `restful_urls`. Single files download;
  downloading a whole folder is refused, because the package builds that ZIP at a predictable path in the
  system temporary directory and never removes it.
- The viewer's pages and API responses tell the browser not to store them, so log contents stay out of the
  browser cache.
- The viewer inlines its CSS and JavaScript, so nothing is published to `public/`.
- Large files are indexed in 50 MB steps the first time they are opened, so a file of several hundred
  megabytes takes a minute or two before every entry is searchable. The viewer shows the progress.

## Updating the log-viewer package

The package's configuration is set in full in `classes/viewer/LogViewerServiceProvider.php`, not merged
from its defaults. When updating `opcodesio/log-viewer` (`composer update` in this directory), check that
every config key the new version reads is still set there. The `replace` of `illuminate/contracts` in
`composer.json` keeps Composer from installing a second copy of Laravel's contracts next to the
application's.

Two more things to re-check after an update, both in the package's built `public/app.js`:

- the browser storage key `selectedFileTypes` and its JSON array format, which `resources/views/layout.php`
  adds newly appearing log types to;
- whether the email preview frame has gained a `sandbox` attribute, which would make the plugin's own
  Laravel parser redundant.

## Known limitations

- Errors raised before plugins load (container start-up, configuration loading) cannot be reported.
- The viewer remembers which log types were selected. A type appearing later is ticked once, so it shows
  up, but a type unticked on purpose stays unticked.

## License

GNU GPL v3. See [LICENSE](LICENSE). Parts of the plugin are adapted from pkp-lib, which is distributed
under the same license.
