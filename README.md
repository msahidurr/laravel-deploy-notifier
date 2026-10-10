# Laravel Deploy Notifier

Git and deployment lifecycle notifications for Laravel, delivered to Telegram.

This package reports what happened during a deployment - a push, a pull, a
start, a success, a failure, a rollback - to a Telegram chat. It does not
run your deployment. It never executes anything on your behalf, and a
notification problem (a Telegram timeout, a misconfigured chat id) never
changes the exit status of your deployment script.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12 (tested against this matrix in CI; see below)
- A Telegram bot token and at least one chat id
- `git` available on the server, for commit metadata

## Installation

```bash
composer require msahidurr/laravel-deploy-notifier

php artisan deploy-notifier:install
```

The service provider is auto-discovered. `deploy-notifier:install` publishes
`config/deploy-notifier.php` and prints the remaining setup steps.

## Configuration

Set these in your `.env`:

```dotenv
DEPLOY_NOTIFIER_ENABLED=true
DEPLOY_NOTIFIER_PROJECT="My App"
DEPLOY_NOTIFIER_ENV=production
DEPLOY_NOTIFIER_SERVER=web-prod-01
DEPLOY_NOTIFIER_REPOSITORY_PATH=/var/www/my-app

DEPLOY_TELEGRAM_BOT_TOKEN=123456789:your-bot-token
DEPLOY_TELEGRAM_PRODUCTION_CHAT_ID=-100xxxxxxxxxx
DEPLOY_TELEGRAM_STAGING_CHAT_ID=-100xxxxxxxxxx

# Only needed if you want the GitHub push webhook (see below):
DEPLOY_GITHUB_WEBHOOK_SECRET=
DEPLOY_GITHUB_ALLOWED_REPOSITORIES=your-org/your-repo
```

Every Telegram chat id is mapped by **environment name**, so one app can
notify production and staging separately - see the `telegram.channels`
array in `config/deploy-notifier.php` if you need more environments than
the three predefined ones.

Verify everything with:

```bash
php artisan deploy-notifier:test
php artisan deploy-notifier:status
```

## Deployment lifecycle commands

| Command | What it reports |
|---|---|
| `deploy-notifier:start [--deployment-id=<id>]` | A deployment has begun. Also acquires a per-project/environment lock. Generates an id if you don't pass one - see the note below before relying on that. |
| `deploy-notifier:pull --deployment-id=<id> [--previous-commit=<sha>] [--exit-code=0]` | The outcome of `git pull` - before/after commit, new commit count. |
| `deploy-notifier:success --deployment-id=<id>` | The deployment completed. Releases the lock. |
| `deploy-notifier:fail --deployment-id=<id> [--step=] [--exit-code=]` | A deployment step failed. Releases the lock. Never reflects the *deployment's* outcome into its own exit code - your script keeps control of the real failure. |
| `deploy-notifier:rollback --deployment-id=<id> [--commit=]` | A rollback happened, reported independently of the original failure. |
| `deploy-notifier:push [--branch=] [--commit=] [--repository=] [--author=] [--message=]` | Manually reports a push. **For testing only** - see below. |
| `deploy-notifier:prune` | Deletes expired dedup/lock rows. Only relevant with the database event store (see below); a no-op on the cache store. |

`:pull`, `:success`, `:fail` and `:rollback` **require** `--deployment-id`
and fail immediately (exit 1, no notification sent) without it - the id is
their entire reason for existing, so silently fabricating one instead of
failing would quietly break correlation with the matching `:start` call
while still reporting as if everything worked. `:start` is the one place a
fresh id is legitimate, since it's the command that establishes it; even
there, generate it yourself in your deploy script instead (see
`examples/deploy.sh`) so you have something to pass to the other commands -
`:start` has no way to hand a generated id back to the calling script.

### Why `:push` is not proof of a real push

A server cannot know, by inspecting its own Git repository, that someone
pushed to the remote. `deploy-notifier:push` only reports whatever you tell
it - useful to test formatting and routing, but it is not evidence that a
push actually happened. For that, use the signed GitHub webhook below.

## Wiring into a manual SSH deployment

See [`examples/deploy.sh`](examples/deploy.sh) for a complete reference
script. The important points it demonstrates:

- Generate `DEPLOYMENT_ID` once, in the script, and pass it to every call.
- Call `deploy-notifier:start` **without** the failure-swallowing wrapper
  used for the other commands, and check its exit code - that is what
  makes the deployment lock actually stop a second, overlapping deploy.
- Wrap every other `deploy-notifier:*` call so that a notification problem
  can never affect your deployment's real exit code.
- Report `:fail` from an `ERR` trap with the step name and exit code, then
  re-raise the original exit code.

## Receiving GitHub push notifications

Pushes are reported through a signed webhook, not by guessing from the
local repository. To enable it:

1. Set `DEPLOY_GITHUB_WEBHOOK_SECRET` to a random value.
2. Set `DEPLOY_GITHUB_ALLOWED_REPOSITORIES` to a comma-separated allowlist
   (e.g. `your-org/your-repo`). Leaving it empty accepts any repository
   that knows the secret - set it explicitly in production.
3. In your GitHub repository settings, add a webhook:
   - **Payload URL**: `https://your-app.test/webhooks/deploy-notifier/github`
     (the path is configurable via `DEPLOY_GITHUB_WEBHOOK_PATH`)
   - **Content type**: `application/json`
   - **Secret**: the same value as `DEPLOY_GITHUB_WEBHOOK_SECRET`
   - **Events**: just `push`

The endpoint:

- Rejects anything whose `X-Hub-Signature-256` doesn't match (constant-time
  comparison), before parsing the body.
- Rejects payloads over `DEPLOY_GITHUB_MAX_PAYLOAD_BYTES` (default 1 MB).
- Rejects repositories outside the allowlist, when one is configured.
- Deduplicates by GitHub's `X-GitHub-Delivery` id, so retried deliveries
  don't double-notify.
- Acknowledges (202) and ignores any event that isn't `push` - pings, etc.
- Never executes anything derived from the payload. It only reports.

The route is only registered at all when both `deploy-notifier.enabled` and
`deploy-notifier.github.webhook_secret` are set, so leaving the secret empty
disables the endpoint entirely rather than exposing an unsigned one.

## Event store: cache or database

Deduplication markers and the deployment lock live in whichever store
`deploy-notifier.store.driver` selects:

- **`cache`** (default) - zero setup, backed by your app's cache (Redis,
  Memcached, the database cache driver, etc). Good enough for a single app
  server. State is lost if that cache is flushed.
- **`database`** - a dedicated table, so dedup/lock state survives a cache
  flush and you can inspect it directly. Opt in with:

  ```dotenv
  DEPLOY_NOTIFIER_STORE_DRIVER=database
  ```

  ```bash
  php artisan vendor:publish --tag=deploy-notifier-migrations
  php artisan migrate
  ```

  Rows expire lazily (a stale row for a given key is cleared the next time
  that key is written) and `php artisan deploy-notifier:prune` deletes
  every expired row - schedule it in your app's console kernel if you
  want the table swept even for keys that are never reused:

  ```php
  $schedule->command('deploy-notifier:prune')->daily();
  ```

## Slack (optional, in addition to Telegram)

Disabled by default. When enabled, every event already going to Telegram is
*also* posted to Slack - it supplements Telegram, it doesn't replace it.

```dotenv
DEPLOY_SLACK_ENABLED=true
DEPLOY_SLACK_PRODUCTION_WEBHOOK_URL=https://hooks.slack.com/services/...
DEPLOY_SLACK_STAGING_WEBHOOK_URL=https://hooks.slack.com/services/...
```

A Slack [incoming webhook](https://api.slack.com/messaging/webhooks) URL
*is* the secret (Slack has no separate token for it), so treat it like one -
keep it out of version control and rotate it if it leaks. It's covered by
the same redaction and delivery-failure safety guarantees as the bot token
below. Delivery is handled by `SendSlackNotification`, a listener on
`DeploymentEventReceived` - see **Extending** if you want to swap it for
something else (Discord, a second Slack channel, ...).

## Safety guarantees this package relies on

- **Notification failures never become deployment failures.** Both the
  Telegram and Slack transports catch every delivery problem (timeout, API
  error, DNS failure) and log it locally; neither throws, and neither ever
  flips a `success` into a `failure` or vice versa.
- **The `:fail` command never reports its own notification failure as a
  second deployment failure.** That would risk a notification loop. Telegram
  and Slack problems while reporting a failure are only ever logged.
- **Secrets never reach Telegram, Slack, or the log in cleartext.** Every
  outgoing message is passed through redaction for the configured bot
  token, webhook secret, and Slack webhook URLs, plus generic patterns
  (bearer tokens, GitHub signature headers, Telegram-bot-token-shaped
  strings) as a second line of defense. The bot token and Slack webhook URL
  are also specifically scrubbed from exception messages before they reach
  a log - HTTP client errors routinely embed the full request URL (which
  contains both) in their message.
- **Duplicate events are suppressed**, both for webhook deliveries (by
  GitHub's delivery id) and for lifecycle events (by `type:deployment-id`),
  using an atomic cache write with a configurable TTL.
- **Overlapping deployments are blocked** by a cache-based lock per
  project/environment, released by `:success`, `:fail`, or `:rollback`.

## Extending

Every processed event - including ones that were not delivered because no
destination was configured - dispatches
`MSahidurr\DeployNotifier\Events\DeploymentEventReceived`, carrying the
`DeploymentEvent` DTO and whether delivery succeeded. `SendSlackNotification`
(see above) is a working example of a listener built on this: look at it as
a template for your own (Discord, metrics, persistence, ...). A second
transport can also be added by binding your own implementation of
`MSahidurr\DeployNotifier\Contracts\NotificationTransport` over the default
`TelegramTransport`, if you want to replace the primary transport entirely
rather than add a supplementary one.

## Testing

```bash
composer install
composer test
```

The suite runs under [Orchestra Testbench](https://packages.tools/testbench)
and fakes both the Telegram HTTP calls and the `git` process invocations, so
it needs no real bot token, chat, or Git repository to run.

## Scope

This is a notification library, not a deployment engine or a dashboard.
Explicitly out of scope for now: executing deployment commands, a
deployment-history UI, remote server orchestration, automatic rollback
execution, and additional chat transports beyond Telegram. These may be
added later without breaking the current API.

## License

MIT. See [LICENSE](LICENSE).
