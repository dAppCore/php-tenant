<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Enums;

enum WebhookDeliveryStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
