<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\Destination;
use PDO;

final class AreaCodeRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'area_codes',
            ['code', 'name', 'country_code', 'notes'],
            'none',
            ['code', 'name', 'country_code'],
            ['id', 'code', 'name', 'country_code']
        );
    }

    /**
     * Longest-prefix match for a dialled number → area name (e.g. 1213800… → LA).
     */
    public function nameForNumber(string $dst): ?string
    {
        $dst = Destination::normalize($dst);
        if ($dst === '') {
            return null;
        }
        $cands = Destination::candidates($dst, 15);
        if ($cands === []) {
            return null;
        }
        $in = Destination::placeholders($cands);
        $st = $this->pdo->prepare(
            "SELECT code, name FROM area_codes WHERE code IN ({$in}) ORDER BY LENGTH(code) DESC LIMIT 1"
        );
        $st->execute($cands);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['name'] : null;
    }

    /**
     * Batch lookup for many destinations (CDR list).
     *
     * @param list<string> $destinations
     * @return array<string,string> normalized dst => name
     */
    public function namesForNumbers(array $destinations): array
    {
        $out = [];
        $uniq = [];
        foreach ($destinations as $d) {
            $n = Destination::normalize((string) $d);
            if ($n !== '') {
                $uniq[$n] = true;
            }
        }
        if ($uniq === []) {
            return [];
        }
        // Load all area codes once (typically small); match longest in PHP
        $rows = $this->pdo->query('SELECT code, name FROM area_codes')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn ($a, $b) => strlen((string) $b['code']) <=> strlen((string) $a['code']));
        foreach (array_keys($uniq) as $dst) {
            foreach ($rows as $r) {
                $code = (string) $r['code'];
                if ($code !== '' && str_starts_with($dst, $code)) {
                    $out[$dst] = (string) $r['name'];
                    break;
                }
            }
        }
        return $out;
    }
}
