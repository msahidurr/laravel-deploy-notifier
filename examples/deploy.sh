#!/usr/bin/env bash
#
# Reference manual-SSH deployment script for msahidurr/laravel-deploy-notifier.
# Copy this into your application (or your separate deployment repository),
# adjust the path, branch and build steps, and wire it into your own SSH
# deployment workflow. This script is NOT installed by the package - it is
# a starting point.

set -Eeuo pipefail

APP_PATH="/var/www/example-app"
BRANCH="main"

cd "$APP_PATH"

# Generated here, once, and passed explicitly to every notifier call below
# so they all correlate to the same deployment attempt.
DEPLOYMENT_ID="deploy-$(date +%Y%m%d-%H%M%S)-$$"
CURRENT_STEP="initialization"

# Never let a notification problem affect the deployment's own exit status.
notify() {
    php artisan "$@" || {
        echo "Warning: deploy-notifier command failed (ignored)" >&2
        return 0
    }
}

on_error() {
    local exit_code=$?
    trap - ERR

    notify deploy-notifier:fail \
        --deployment-id="$DEPLOYMENT_ID" \
        --step="$CURRENT_STEP" \
        --exit-code="$exit_code"

    exit "$exit_code"
}

trap on_error ERR

# :start is called WITHOUT the `notify` wrapper and its exit code IS
# checked, on purpose: this is what makes the deployment lock actually
# stop a second, overlapping deployment rather than merely report one.
if ! php artisan deploy-notifier:start --deployment-id="$DEPLOYMENT_ID"; then
    echo "Another deployment is already in progress. Aborting." >&2
    exit 1
fi

CURRENT_STEP="capture previous commit"
PREVIOUS_COMMIT="$(git rev-parse HEAD)"

CURRENT_STEP="git pull"
git pull --ff-only origin "$BRANCH"

notify deploy-notifier:pull \
    --deployment-id="$DEPLOYMENT_ID" \
    --previous-commit="$PREVIOUS_COMMIT"

CURRENT_STEP="composer install"
composer install --no-interaction --prefer-dist --optimize-autoloader

CURRENT_STEP="database migration"
php artisan migrate --force

CURRENT_STEP="application optimization"
php artisan optimize

CURRENT_STEP="deployment completion"
notify deploy-notifier:success --deployment-id="$DEPLOYMENT_ID"

trap - ERR

echo "Deployment completed: $DEPLOYMENT_ID"
