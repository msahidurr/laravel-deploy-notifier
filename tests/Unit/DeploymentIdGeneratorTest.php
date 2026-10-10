<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\Support\DeploymentIdGenerator;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DeploymentIdGeneratorTest extends TestCase
{
    public function test_generated_ids_follow_the_expected_shape(): void
    {
        $id = DeploymentIdGenerator::generate();

        $this->assertMatchesRegularExpression('/^deploy-\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
    }

    public function test_consecutive_ids_are_unique(): void
    {
        $ids = array_map(fn () => DeploymentIdGenerator::generate(), range(1, 20));

        $this->assertCount(20, array_unique($ids));
    }
}
