<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Support\Destination;
use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Dry-run of what the router would do for (customer, destination): blocks, customer rate,
 * matching rules (longest prefix), trunk ordering by plan strategy (priority | lcr) and per-trunk cost.
 * Read-only; never places calls.
 */
final class RouteSimulator
{
    public function __construct(private PDO $pdo, private RateResolver $resolver, private RatingService $rating)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function simulate(TenantScope $scope, int $customerId, string $rawDst, int $sampleSeconds = 60): array
    {
        $dst = Destination::normalize($rawDst);
        $out = ['destination' => $dst, 'blocked' => false, 'notes' => [], 'customer_rate' => null, 'routes' => [], 'strategy' => null];
        if ($dst === '') {
            $out['notes'][] = 'Destination has no digits.';
            return $out;
        }
        $c = $this->pdo->prepare('SELECT id, name, reseller_id, status, routing_plan_id FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        $cust = $c->fetch(PDO::FETCH_ASSOC);
        if (!$cust || !$scope->canAccessCustomer((int) $cust['id'], $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null)) {
            $out['notes'][] = 'Customer not found.';
            return $out;
        }
        $out['customer'] = $cust['name'];
        if ($cust['status'] !== 'active') {
            $out['blocked'] = true;
            $out['notes'][] = "Customer status is '{$cust['status']}': calls rejected.";
            return $out;
        }

        $cands = Destination::candidates($dst);
        $in = Destination::placeholders($cands);
        $pol = $this->pdo->prepare("SELECT prefix, action, note FROM destination_policies WHERE (customer_id IS NULL OR customer_id = ?) AND prefix IN ({$in}) ORDER BY LENGTH(prefix) DESC LIMIT 1");
        $pol->execute([$customerId, ...$cands]);
        if (($p = $pol->fetch(PDO::FETCH_ASSOC)) && $p['action'] === 'block') {
            $out['blocked'] = true;
            $out['notes'][] = "Blocked by destination policy (prefix {$p['prefix']})" . ($p['note'] ? ': ' . $p['note'] : '');
            return $out;
        }

        $rate = $this->resolver->customer($customerId, $dst);
        if ($rate === null) {
            $out['notes'][] = 'No customer rate matches this destination: call would be rejected as unrated.';
        } else {
            $r = $this->rating->rate($rate['rate'], $rate['initial'], $rate['subsequent'], $sampleSeconds, $rate['connect_fee']);
            $out['customer_rate'] = $rate + ['sample_seconds' => $sampleSeconds, 'sample_total' => $r['total'], 'sample_billable' => $r['billable_seconds']];
        }

        // Magnus: tariff trunk group wins when set; else customer's trunk group.
        $tgId = null;
        if ($rate !== null && !empty($rate['routing_plan_id'])) {
            $tgId = (int) $rate['routing_plan_id'];
            $out['notes'][] = 'Using Trunk Group from matched Tariff (Magnus tariff→trunk group).';
        } elseif (!empty($cust['routing_plan_id'])) {
            $tgId = (int) $cust['routing_plan_id'];
        }
        if (!$tgId) {
            $out['notes'][] = 'No Trunk Group on tariff or user — cannot route.';
            return $out;
        }
        $plan = $this->pdo->prepare("SELECT id, name, strategy FROM routing_plans WHERE id = ? AND status = 'active'");
        $plan->execute([$tgId]);
        $pl = $plan->fetch(PDO::FETCH_ASSOC);
        if (!$pl) {
            $out['notes'][] = 'Trunk Group is inactive or missing.';
            return $out;
        }
        $out['strategy'] = $pl['strategy'];
        $out['plan'] = $pl['name'];

        $st = $this->pdo->prepare(
            "SELECT r.id AS rule_id, r.prefix, r.priority, t.id AS trunk_id, t.name AS trunk_name, t.host, t.port, t.max_channels, ca.name AS carrier_name
             FROM routing_rules r JOIN carrier_trunks t ON t.id = r.carrier_trunk_id AND t.status = 'active'
             JOIN carriers ca ON ca.id = t.carrier_id AND ca.status = 'active'
             WHERE r.routing_plan_id = ? AND r.status = 'active' AND r.prefix IN ({$in})"
        );
        $st->execute([(int) $pl['id'], ...$cands]);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rules) {
            $out['notes'][] = 'No active routing rule matches: no route available.';
            return $out;
        }
        $longest = max(array_map(static fn ($r) => strlen((string) $r['prefix']), $rules));
        $rules = array_values(array_filter($rules, static fn ($r) => strlen((string) $r['prefix']) === $longest));

        foreach ($rules as &$r) {
            $k = $this->resolver->carrier((int) $r['trunk_id'], $dst);
            $r['carrier_rate'] = $k['rate'] ?? null;
            $r['carrier_increment'] = $k ? $k['initial'] . '/' . $k['subsequent'] : null;
            $r['carrier_sample_cost'] = $k ? $this->rating->rate($k['rate'], $k['initial'], $k['subsequent'], $sampleSeconds, $k['connect_fee'])['total'] : null;
            $r['sample_margin'] = ($k && $rate && isset($out['customer_rate']['sample_total']))
                ? $this->rating->margin($out['customer_rate']['sample_total'], $r['carrier_sample_cost']) : null;
        }
        unset($r);
        $out['routes'] = self::order($rules, (string) $pl['strategy']);
        return $out;
    }

    /**
     * Order candidate rules. priority: ascending priority. lcr: ascending carrier_sample_cost (unrated last), priority as tie-break.
     *
     * @param list<array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    public static function order(array $rules, string $strategy): array
    {
        usort($rules, static function (array $a, array $b) use ($strategy): int {
            if ($strategy === 'lcr') {
                $ca = $a['carrier_sample_cost'] ?? null;
                $cb = $b['carrier_sample_cost'] ?? null;
                if ($ca === null xor $cb === null) {
                    return $ca === null ? 1 : -1;
                }
                if ($ca !== null && ($c = bccomp((string) $ca, (string) $cb, 6)) !== 0) {
                    return $c;
                }
            }
            return ((int) $a['priority'] <=> (int) $b['priority']) ?: ((int) $a['rule_id'] <=> (int) $b['rule_id']);
        });
        foreach ($rules as $i => &$r) {
            $r['attempt_order'] = $i + 1;
        }
        return $rules;
    }
}
