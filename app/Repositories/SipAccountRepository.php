<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class SipAccountRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'sip_accounts',
            ['reseller_id', 'customer_id', 'username', 'domain', 'ha1', 'ha1b', 'caller_id', 'max_channels', 'status', 'notes', 'password_changed_at'],
            'owned',
            ['username', 'caller_id'],
            ['id', 'username', 'status', 'created_at']
        );
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username, string $domain): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM sip_accounts WHERE username = ? AND domain = ? LIMIT 1');
        $st->execute([$username, $domain]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
}
