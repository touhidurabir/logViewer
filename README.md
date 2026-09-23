# Log Viewer

Application logging and a log page for OJS, OMP and OPS 3.5 — for site administrators who need to
read the site's logs without a shell on the server.

On 3.5 an uncaught error reaches PHP's error log and nowhere else. This plugin sets up Laravel's log
channels, reports errors into them, and adds a page that reads them back.

## Features

- **Errors are recorded** — uncaught errors, failed queue jobs and API exceptions, in
  `{files_dir}/logs/app-YYYY-MM-DD.log` by default. The destination is a setting: daily files, a
  single file, PHP's error log, syslog, stderr, several of these at once, or nothing.
- **A log page** at Administration → Logs, where logs can be read, searched, filtered by level,
  downloaded and deleted.
- **Other logs too** — PHP's error log, scheduled task logs, usage statistics logs, the Apache or
  Nginx error log, a web server access log, the MySQL/MariaDB or PostgreSQL log, and Supervisor's
  log. Each one is switched on in the settings, and each is read with the parser for that kind of
  log.
- **Emails are previewed** when mail is sent to the log (`[general] sandbox = On` or
  `[email] default = log`). Their HTML is cleaned with `[security] allowed_html` first, so nothing
  in an email runs in the viewer.
- **Site administrators only.** Logged-out visitors are sent to the login page and everyone else
  gets 403. Anything that changes state needs the page's CSRF token, download links are signed and
  expire, and only the application and scheduled task logs can be deleted — the rest belong to the
  server, or hold events not yet counted.
- **Settings that survive the upgrade to 3.6**, which has this in core: the same `[logs]` keys, and
  a ready-made `[logs]` section at the bottom of the settings form to paste into `config.inc.php`.

## Requirements

- OJS, OMP or OPS 3.5.0, any point release
- PHP 8.2 or later
- `{files_dir}` writable by the web server

On 3.6 and later, core does the logging and the plugin refuses to be enabled. Its **Settings**
action still hands over the `[logs]` section there, so nothing is lost if it was not copied before
the upgrade.

## Installation

Download the packaged plugin from the releases and upload it from the Plugins page, or install it
from the plugin gallery once it is listed there. The package carries its dependencies, so the
server needs no Composer step.

To install from a git checkout instead, clone it into `plugins/generic/logViewer` and run:

```bash
composer install --working-dir=plugins/generic/logViewer
php lib/pkp/tools/installPluginVersion.php plugins/generic/logViewer/version.xml
```

The dependencies are not in the repository; `composer install` puts them in the plugin's
`lib/vendor`, where `composer.json` says.

Then enable it:

- **More than one journal, press or server:** Administration → Site Settings → Plugins.
- **Exactly one:** that journal's Settings → Website → Plugins — core hides the site-level tab on
  single-context installations. Only a site administrator can enable it or change its settings.

## Settings

The plugin's **Settings** action holds everything: where log entries go, how long daily files are
kept, the format, whether failed requests are recorded, and which logs the page shows.

A `[logs]` section in `config.inc.php` wins over the form, and those settings are then shown
read-only. The keys are 3.6's, so the section keeps working after the upgrade:

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

; Monolog formatter for the daily, single, stderr and syslog channels. Unset = readable lines.
; log_formatter = Monolog\Formatter\JsonFormatter

; Record failures the caller caused, such as 404s. Plugin only: 3.6 always records them.
; log_client_errors = Off

; Logs the page shows. A key set here shows that log, from this path, whatever the settings say.
; A path may be a glob pattern. php_error_log falls back to PHP's own error_log setting.
; php_error_log = /var/log/php/error.log
; apache_error_log = /var/log/apache2/error.log
; nginx_error_log = /var/log/nginx/error.log
; http_access_log = /var/log/nginx/access.log
; postgres_log = /var/log/postgresql/*.log
; mysql_log = /var/log/mysql/error.log          ; plugin only: 3.6 has no MySQL key
; supervisor_log = /var/log/supervisor/supervisord.log
```

## Logs shown on the page

Each log has a **Show in the viewer** box under **Logs shown in the viewer**. Logs outside the
files directory also take a path, which may be one file or a glob pattern such as
`/var/log/postgresql/*.log`.

- **Paths are checked as they are typed**, and again when saved, as the web server sees them: found
  (with file count and total size), missing, not a file, relative, or unreadable. A log that is
  switched on must resolve to at least one readable file.
- **Paths are suggested** when none is set, from PHP's `error_log` setting, the database server's
  own answer, and the usual places for the web server and Supervisor. A suggestion takes effect
  once the settings are saved. A database log can only be read when the database runs on the same
  machine.
- The web server needs permission to read these files. System logs are often readable only by
  `root` or the `adm` group.

Naming a log file is a site administrator's own business: on 3.5 they can already upload and run
plugins, so this gives them no access they did not have.

## Notes

- **Failed requests** — a request for a page that does not exist, or one that was refused, failed
  because of the caller. On a public site most of those are robots and mistyped addresses, so they
  are left out unless **Failed requests** is ticked. Errors in the application itself are always
  recorded.
- **PHP's error log still matters.** Most of 3.5's own diagnostic messages are written with
  `error_log()`, which no error handler can see, and 3.6 does not route them either. They stay
  there — which is why that log is shown on the page by default.
- **Errors raised before plugins load** — container start-up, configuration loading — cannot be
  reported.
- **Large files** are indexed in 50 MB steps the first time they are opened, so a file of several
  hundred megabytes takes a minute or two before every entry is searchable. The page shows the
  progress.
- **The page remembers which kinds of log were ticked.** A kind that appears later is ticked once,
  so it shows up, but one unticked on purpose stays unticked.

## License

Copyright (c) 2026 Touhidur Rahman

Distributed under the GNU GPL v3. For full terms see the file `LICENSE`. Parts of the plugin are
adapted from pkp-lib, which is distributed under the same license.
