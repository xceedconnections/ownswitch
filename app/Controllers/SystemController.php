<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\HealthService;

final class SystemController extends BaseController
{
    public function status(Request $r, array $p = []): Response
    {
        $checks = (new HealthService())->run($this->db());
        $counts = [];
        foreach (['users', 'customers', 'cdrs', 'ledger_entries', 'audit_logs'] as $t) {
            $counts[$t] = (int) $this->db()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();   // fixed table names
        }
        return $this->view('system/status', ['title' => 'System Status', 'checks' => $checks, 'counts' => $counts, 'php' => PHP_VERSION, 'time' => gmdate('Y-m-d H:i:s') . ' UTC']);
    }
}
