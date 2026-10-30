<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class CustomerRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'customers',
            ['reseller_id', 'name', 'code', 'contact_email', 'billing_mode', 'currency', 'timezone', 'status', 'credit_limit',
                'max_concurrent_calls', 'rate_plan_id', 'routing_plan_id', 'media_policy', 'recording_enabled', 'is_demo'],
            'customer',
            ['name', 'code', 'contact_email'],
            ['id', 'name', 'code', 'status', 'billing_mode', 'created_at']
        );
    }
}
