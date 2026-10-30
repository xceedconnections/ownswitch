<?php

declare(strict_types=1);

namespace Ownswitch\Database;

use PDO;

/** Forward-only SQL migration runner. Files: database/migrations/NNN_name.sql */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return list<string> file names sorted */
    public function files(): array
    {
        $files = glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,string> migration => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        return $this->pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return list<array{migration:string,status:string}> */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $f) {
            $sum = hash_file('sha256', $this->dir . '/' . $f);
            $st = !isset($applied[$f]) ? 'pending' : ($applied[$f] === $sum ? 'applied' : 'applied (file changed!)');
            $out[] = ['migration' => $f, 'status' => $st];
        }
        return $out;
    }

    /**
     * Apply pending migrations. MySQL DDL auto-commits, so migrations are written to be
     * re-runnable (IF NOT EXISTS / INSERT IGNORE); a file is recorded only after full success.
     *
     * @return list<string> applied now
     */
    public function migrate(?callable $log = null): array
    {
        $applied = $this->applied();
        $done = [];
        foreach ($this->files() as $f) {
            if (isset($applied[$f])) {
                continue;
            }
            $path = $this->dir . '/' . $f;
            $sql = (string) file_get_contents($path);
            foreach (self::splitStatements($sql) as $stmt) {
                $this->pdo->exec($stmt);
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
            $stmt->execute([$f, hash('sha256', $sql)]);
            $done[] = $f;
            if ($log) {
                $log($f);
            }
        }
        return $done;
    }

    /**
     * Split SQL on ';' outside quotes and comments. No DELIMITER support (triggers must be
     * single-statement bodies).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if ($quote === null) {
                if ($c === '-' && $n === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n")) {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    $buf .= "\n";
                    continue;
                }
                if ($c === '#') {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($c === '/' && $n === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i = $end === false ? $len : $end + 1;
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                    $buf .= $c;
                    continue;
                }
                if ($c === ';') {
                    if (trim($buf) !== '') {
                        $out[] = trim($buf);
                    }
                    $buf = '';
                    continue;
                }
                $buf .= $c;
            } else {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $n;
                    $i++;
                } elseif ($c === $quote) {
                    if ($n === $quote) {
                        $buf .= $n;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
