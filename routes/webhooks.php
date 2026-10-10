<?php

use Illuminate\Support\Facades\Route;
use MSahidurr\DeployNotifier\Http\Controllers\GitHubWebhookController;
use MSahidurr\DeployNotifier\Http\Middleware\VerifyGitHubSignature;

Route::post(
    '/'.trim(config('deploy-notifier.github.webhook_path', 'webhooks/deploy-notifier/github'), '/'),
    [GitHubWebhookController::class, 'handle']
)
    ->middleware(VerifyGitHubSignature::class)
    ->name('deploy-notifier.github-webhook');
