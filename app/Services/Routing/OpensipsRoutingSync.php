<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use PDO;

/** Full sync of Ownswitch trunks + routes → OpenSIPS drouting / registrant / address. */
final class OpensipsRoutingSync
{
    public function __construct(private PDO $own, private OpensipsProvisioner $prov)
    {
    }

    /** @return array{trunks:int,rules:int,warnings:list<string>} */
    public function syncAll(): array
    {
        $warnings = [];
        if (!$this->prov->enabled()) {
            return ['trunks' => 0, 'rules' => 0, 'warnings' => ['OpenSIPS provisioning disabled (OPENSIPS_ENABLED=false)']];
        }

        $trunks = $this->own->query('SELECT * FROM carrier_trunks')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($trunks as $t) {
            try {
                $this->prov->syncTrunk($t);
                $n++;
            } catch (\Throwable $e) {
                $warnings[] = 'Trunk #' . $t['id'] . ' (' . $t['name'] . '): ' . $e->getMessage();
            }
        }

        $rules = $this->own->query(
            "SELECT prefix, carrier_trunk_id, priority, status FROM routing_rules WHERE status = 'active'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        try {
            $this->prov->syncRoutingRules($rules);
        } catch (\Throwable $e) {
            $warnings[] = 'Route sync: ' . $e->getMessage();
        }

        $this->prov->reloadRouting();
        $this->prov->reloadAddresses();
        $this->prov->reloadRegistrant();

        return ['trunks' => $n, 'rules' => count($rules), 'warnings' => $warnings];
    }
}
