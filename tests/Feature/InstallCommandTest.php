<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use MSahidurr\DeployNotifier\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_publishes_the_config_and_prints_setup_guidance(): void
    {
        $this->artisan('deploy-notifier:install')
            ->expectsOutputToContain('deploy-notifier installed.')
            ->expectsOutputToContain('Next steps:')
            ->assertExitCode(0);

        $this->assertFileExists(config_path('deploy-notifier.php'));
    }

    public function test_force_overwrites_an_existing_published_config(): void
    {
        $this->artisan('deploy-notifier:install')->assertExitCode(0);

        file_put_contents(config_path('deploy-notifier.php'), '<?php return ["tampered" => true];');

        $this->artisan('deploy-notifier:install', ['--force' => true])->assertExitCode(0);

        $published = file_get_contents(config_path('deploy-notifier.php'));
        $this->assertStringNotContainsString('tampered', $published);
    }
}
