<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** carriers or carrier_trunks. */
final class CarrierRepository extends BaseRepository
{
    public static function carriers(PDO $pdo): self
    {
        return new self($pdo, 'carriers', ['code', 'name', 'status', 'contact_email', 'notes', 'is_demo'], 'none',
            ['code', 'name'], ['id', 'code', 'name', 'status', 'created_at']);
    }

    public static function trunks(PDO $pdo): self
    {
        return new self($pdo, 'carrier_trunks',
            ['carrier_id', 'name', 'host', 'port', 'transport', 'auth_username', 'auth_secret_enc', 'max_channels', 'codecs', 'media_policy',
                'initial_increment', 'subsequent_increment', 'default_rate', 'connect_fee', 'priority', 'status'],
            'none', ['name', 'host'], ['id', 'name', 'host', 'status', 'priority', 'carrier_id']);
    }
}
