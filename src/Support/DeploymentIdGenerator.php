<?php

namespace MSahidurr\DeployNotifier\Support;

class DeploymentIdGenerator
{
    /**
     * Generate a short, sortable, unique-enough deployment id.
     * Format: deploy-YYYYMMDD-HHMMSS-xxxxxx
     */
    public static function generate(): string
    {
        return sprintf(
            'deploy-%s-%s',
            date('Ymd-His'),
            substr(bin2hex(random_bytes(4)), 0, 6)
        );
    }
}
