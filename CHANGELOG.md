# Changelog

All notable changes to `msahidurr/laravel-deploy-notifier` are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Initial implementation: Telegram transport, Git metadata service, message
  formatting, secret redaction, and cache-backed deduplication/locking.
- Lifecycle Artisan commands: `install`, `test`, `status`, `push`, `pull`,
  `start`, `success`, `fail`, `rollback`.
- Signed GitHub `push` webhook with HMAC verification, a repository
  allowlist, payload size limits, and delivery deduplication.
- Config publishing, environment-based routing, and a `DeploymentEventReceived`
  event for applications that want to hook in their own behaviour.
- Optional database-backed event store (`DEPLOY_NOTIFIER_STORE_DRIVER=database`)
  as an alternative to the cache-only MVP store, with a publishable migration
  and a `deploy-notifier:prune` command to sweep expired rows.
- Optional Slack delivery (`DEPLOY_SLACK_ENABLED=true`), supplementing rather
  than replacing Telegram, via a `SendSlackNotification` listener on
  `DeploymentEventReceived` - also a working template for adding further
  transports.
- Full PHPUnit/Orchestra Testbench test suite covering the above, including
  Telegram retry behaviour, webhook payload-size and malformed-JSON
  handling, and secret redaction in logged exception messages.

### Fixed
- The Telegram transport could leak the bot token into the application log:
  HTTP client exceptions (timeouts, DNS failures) routinely embed the full
  request URL - which contains the token - in their message. Both the
  token and, for Slack, the webhook URL are now scrubbed before logging.
- The database event store's migration overrode `Migration::getConnection()`
  with an incompatible (`private`) visibility, which fataled on every
  database-driver install; fixed by setting the inherited `$connection`
  property instead.
- The database event store clamped a zero/negative TTL up to a minimum of
  one second, so a marker meant to be "already expired" could still block
  a legitimate re-acquisition for that second; removed the clamp.
- `:success`, `:fail`, `:pull` and `:rollback` silently fabricated a new,
  disconnected deployment id when `--deployment-id` was omitted, instead
  of failing - quietly breaking correlation with the matching `:start`
  call while still reporting as if nothing was wrong. They now require
  the option and exit 1 with a clear message if it's missing; `:start`
  still generates one, since it's the one command meant to establish it.
- `MessageFormatter` sanitized (stripped newlines, truncated length)
  `author` and `commitMessage` but not `repository`, `branch`, `server`,
  or `step` - the first two come straight from the GitHub webhook payload
  and could contain embedded newlines, letting a malicious branch/repo
  name inject forged-looking extra lines into a Telegram message. All of
  these fields are now sanitized consistently.
- A GitHub push webhook reporting a branch/tag **deletion** (`deleted:
  true`, `head_commit: null`) was reported with the literal all-zero SHA
  GitHub sends for `after` as if it were a real commit; it's now omitted.
- `SendSlackNotification` resolved a Slack webhook URL via
  `config("...webhooks.{$environment}")`, so an environment name
  containing a literal `.` (e.g. `client.staging`) was misread as a
  nested config path instead of a flat key; switched to a plain array
  lookup, matching how `NotificationRouter` already does it for Telegram.
- `NotificationRouter::destinationFor()` would return a non-string (and
  violate its own `?string` return type) if a chat id was hardcoded as a
  literal int in a published config file rather than via `env()`; it's
  now cast explicitly.
- `TelegramTransport`/`SlackTransport` only scrubbed their own token/URL
  from logged exception messages via a hand-rolled `str_replace`; they
  now build a small `SecretRedactor` internally, so the existing generic
  patterns (bearer tokens, telegram-token-shaped strings, ...) also apply
  to anything they log, not just the one known secret.
- A stale docblock on `BaseCommand::resolveDeploymentId()` claimed it
  falls back to the current Git commit when no id is supplied; it
  actually generates a fresh id. Corrected to describe the real
  behaviour (and why most callers should use `requireDeploymentId()`
  instead - see above).
- CI failed installing dependencies for every Laravel 10 and 11 job
  (PHP ^8.2 combos only "PHP x - Laravel 12" passed): recent Composer
  versions refuse, by default, to install any package version flagged by
  a known security advisory, and a `laravel/framework` advisory
  (PKSA-d5tc-s1qs-h781) covers every release below v12.69.0 - which is
  every Laravel 10.x and 11.x release `orchestra/testbench` could pick.
  `laravel/framework` here is only a transitive dev/test dependency of
  `orchestra/testbench`, never installed by anyone who `composer
  require`s this package, so the advisory can't affect a consumer; set
  `config.audit.block-insecure: false` in `composer.json` to stop it
  from blocking CI. Verified locally (via the actual pinning steps the
  workflow runs) that dependencies now resolve and the full test suite
  passes for Laravel 10 (PHP 8.1), 11 and 12 (PHP 8.3).
