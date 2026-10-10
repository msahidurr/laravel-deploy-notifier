# Contributing

Thanks for considering a contribution to `msahidurr/laravel-deploy-notifier`.

## Setup

```bash
git clone <your fork>
cd laravel-deploy-notifier
composer install
```

## Running the test suite

```bash
composer test
# or directly:
vendor/bin/phpunit
```

The suite runs under [Orchestra Testbench](https://packages.tools/testbench)
and fakes both the Telegram/Slack/Discord HTTP calls (`Http::fake()`) and
`git` process invocations (`Process::fake()`), so it needs no real bot
token, chat, webhook, or Git repository.

A handful of tests (anything exercising the database event store -
`DatabaseEventStoreTest`, `DatabaseEventStoreIntegrationTest`) need the
`pdo_sqlite` PHP extension and skip themselves gracefully when it isn't
installed locally. They always run in CI, which installs it explicitly.

## Before opening a PR

- Run the full suite (`composer test`) and make sure it's green.
- `composer validate --strict` should pass.
- `find src database config routes -name "*.php" | xargs -n1 php -l` should
  report no syntax errors.
- Add or update tests for any behavioural change - see `tests/Unit` for
  isolated service/class tests and `tests/Feature` for anything that goes
  through the container, an Artisan command, or an HTTP route.
- Update `README.md`/`CHANGELOG.md` if you're adding or changing
  user-facing behaviour (a config key, a command, a safety guarantee).

## Design principles to keep in mind

These are the constraints the existing code is written against - a PR that
violates one of them needs a strong reason:

- **This package reports; it does not execute.** It never runs a
  deployment step, and the GitHub webhook controller never executes
  anything derived from the request payload.
- **A notification problem must never affect a deployment's own outcome.**
  Every transport (`TelegramTransport`, `SlackTransport`, `DiscordTransport`)
  catches delivery failures internally and returns `false`; none of them
  throws, and `deploy-notifier:fail` never changes its own exit code based
  on whether its own Telegram/Slack/Discord call succeeded.
- **Secrets stay out of messages and logs.** `SecretRedactor` scrubs
  configured secrets (and a few generic patterns) from every outgoing
  message, and every transport specifically scrubs its own
  token/webhook-URL from exception messages before logging them - HTTP
  client errors routinely embed the full request URL.
- **Everything that talks to Telegram, Slack, Discord, GitHub, or `git` is
  behind an interface or a facade that can be faked in tests**
  (`NotificationTransport`, `EventStore`, the `Process` facade for Git, the
  `Http` facade for HTTP). Avoid adding a new integration that can only be
  exercised against a real external service.
- **A second channel is a listener on `DeploymentEventReceived`, not a
  second argument on `DeploymentManager`.** `SendSlackNotification` and
  `SendDiscordNotification` are the templates - `DeploymentManager` stays
  wired to exactly one primary transport (Telegram).
- **An environment-keyed config lookup (Telegram chat id, Slack/Discord
  webhook) is always a plain array lookup**, never
  `config("...webhooks.{$environment}")` - the latter misreads an
  environment name containing a literal `.` as a nested config path.

## Reporting a security issue

Please don't open a public issue for a security vulnerability (e.g. a
webhook signature bypass). Email the maintainer instead - see `composer.json`
for contact details.
