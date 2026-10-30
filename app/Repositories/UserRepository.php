<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class UserRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'users',
            ['email', 'name', 'password_hash', 'role_id', 'reseller_id', 'customer_id', 'status', 'failed_logins', 'locked_until', 'last_login_at', 'last_login_ip'],
            'none',
            ['email', 'name'],
            ['id', 'email', 'name', 'created_at']
        );
    }

    /** @return array<string,mixed>|null user row incl. `role` code */
    public function findWithRole(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1');
        $st->execute([mb_strtolower(trim($email))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function roleId(string $code): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM roles WHERE code = ?');
        $st->execute([$code]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public function recordFailure(int $userId, int $max, int $lockMinutes): void
    {
        $this->pdo->prepare(
            'UPDATE users SET failed_logins = failed_logins + 1,
                locked_until = CASE WHEN failed_logins + 1 >= ? THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) ELSE locked_until END
             WHERE id = ?'
        )->execute([$max, $lockMinutes, $userId]);
    }

    public function recordSuccess(int $userId, string $ip): void
    {
        $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')
            ->execute([$ip, $userId]);
    }

    public function setPasswordHash(int $userId, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    }
}
