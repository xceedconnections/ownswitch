<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** Customer sell tariffs (rate_plan_tariffs) or carrier buy tariffs (carrier_tariffs). */
final class TariffRepository extends BaseRepository
{
    public static function customer(PDO $pdo): self
    {
        return new self($pdo, 'rate_plan_tariffs',
            ['rate_plan_id', 'prefix', 'destination', 'rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'status', 'effective_from', 'effective_to', 'version_id'],
            'none', ['prefix', 'destination'], ['id', 'prefix', 'destination', 'rate', 'status']);
    }

    public static function carrier(PDO $pdo): self
    {
        return new self($pdo, 'carrier_tariffs',
            ['carrier_id', 'prefix', 'destination', 'rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'status', 'effective_from', 'effective_to'],
            'none', ['prefix', 'destination'], ['id', 'prefix', 'destination', 'rate', 'status']);
    }
}
