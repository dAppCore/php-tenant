<?php

namespace Core\Mod\Tenant\Enums;

enum UserTier: string
{
    case FREE = 'free';
    case APOLLO = 'apollo';      // Standard paid tier
    case HADES = 'hades';        // Premium tier

    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Free',
            self::APOLLO => 'Apollo',
            self::HADES => 'Hades',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FREE => 'gray',
            self::APOLLO => 'blue',
            self::HADES => 'violet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FREE => 'user',
            self::APOLLO => 'sun',
            self::HADES => 'crown',
        };
    }

    public function maxWorkspaces(): int
    {
        return match ($this) {
            self::FREE => 1,
            self::APOLLO => 5,
            self::HADES => -1, // Unlimited
        };
    }

    public function features(): array
    {
        return match ($this) {
            self::FREE => [
                'basic_content_editing',
                'single_workspace',
            ],
            self::APOLLO => [
                'basic_content_editing',
                'advanced_content_editing',
                'multiple_workspaces',
                'analytics_basic',
                'social_scheduling',
            ],
            self::HADES => [
                'basic_content_editing',
                'advanced_content_editing',
                'multiple_workspaces',
                'unlimited_workspaces',
                'analytics_basic',
                'analytics_advanced',
                'social_scheduling',
                'social_automation',
                'api_access',
                'priority_support',
                'white_label',
            ],
        };
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features());
    }
}
