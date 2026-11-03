<?php

declare(strict_types=1);

use Ownswitch\Controllers\AuditLogController;
use Ownswitch\Controllers\AuthController;
use Ownswitch\Controllers\BillingController;
use Ownswitch\Controllers\CarrierController;
use Ownswitch\Controllers\CdrController;
use Ownswitch\Controllers\CustomRateController;
use Ownswitch\Controllers\CustomerController;
use Ownswitch\Controllers\DashboardController;
use Ownswitch\Controllers\DestinationPolicyController;
use Ownswitch\Controllers\FraudController;
use Ownswitch\Controllers\InvoiceController;
use Ownswitch\Controllers\IpAuthController;
use Ownswitch\Controllers\LedgerController;
use Ownswitch\Controllers\ModuleScaffoldController;
use Ownswitch\Controllers\PaymentController;
use Ownswitch\Controllers\PrefixController;
use Ownswitch\Controllers\RatePlanController;
use Ownswitch\Controllers\RecordingController;
use Ownswitch\Controllers\ReportController;
use Ownswitch\Controllers\ResellerController;
use Ownswitch\Controllers\RoutingController;
use Ownswitch\Controllers\SettingsController;
use Ownswitch\Controllers\SipUserController;
use Ownswitch\Controllers\StatementController;
use Ownswitch\Controllers\SystemController;
use Ownswitch\Controllers\TariffController;
use Ownswitch\Controllers\CallsOnlineController;
use Ownswitch\Controllers\DidController;
use Ownswitch\Controllers\PrefixImportController;
use Ownswitch\Controllers\TariffImportController;
use Ownswitch\Http\Response;
use Ownswitch\Router;
use Ownswitch\Support\Menu;

/** @var Router $router */

$role = static fn (array $roles): string => 'role:' . implode(',', $roles);
$STAFF = $role(Menu::STAFF);

/** Register list/create/store/edit/update/delete for a CrudController instance. */
$crud = static function (Router $router, string $prefix, object $c, string $roles): void {
    $router->get($prefix, [$c, 'index'], [$roles]);
    $router->get($prefix . '/create', [$c, 'create'], [$roles]);
    $router->post($prefix, [$c, 'store'], [$roles]);
    $router->get($prefix . '/{id}/edit', [$c, 'edit'], [$roles]);
    $router->post($prefix . '/{id}', [$c, 'update'], [$roles]);
    $router->post($prefix . '/{id}/delete', [$c, 'destroy'], [$roles]);
};

// ---- public ----
$router->group('', ['csrf'], function (Router $r): void {
    $r->get('/login', [AuthController::class, 'loginForm']);
    $r->post('/login', [AuthController::class, 'login']);
});

// ---- authenticated (session + tenant scope + CSRF on all unsafe methods) ----
$router->group('', ['auth', 'tenant', 'csrf'], function (Router $r) use ($crud, $role, $STAFF): void {
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/account/password', [AuthController::class, 'passwordForm']);
    $r->post('/account/password', [AuthController::class, 'passwordChange']);

    // Clients — Magnus Users (+ legacy /customers redirects)
    $users = new CustomerController();
    $crud($r, '/users', $users, $role(Menu::RESELLER_UP));
    $r->get('/customers', static fn () => Response::redirect(url('/users')));
    $r->get('/customers/create', static fn () => Response::redirect(url('/users/create')));
    $r->get('/customers/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/users/' . (int) $p['id'] . '/edit'));
    });
    $crud($r, '/resellers', new ResellerController(), $STAFF);
    $crud($r, '/sip-users', new SipUserController(), $role(Menu::SIP_USERS));
    $callsOnline = new CallsOnlineController();
    $r->get('/calls-online', [$callsOnline, 'index'], [$role(Menu::SIP_USERS)]);
    $r->post('/calls-online/hangup', [$callsOnline, 'hangup'], [$role(Menu::SIP_USERS)]);
    $crud($r, '/ip-auth', new IpAuthController(), $role(Menu::SIP_USERS)); // CallerID / IP allowlist

    // DIDs (inbound)
    $crud($r, '/did-providers', new DidController('providers'), $role(Menu::DID_STAFF));
    $crud($r, '/dids', new DidController('dids'), $role(Menu::DID_STAFF));
    $crud($r, '/did-assignments', new DidController('assignments'), $role(Menu::DID_VIEW));

    // Rates
    $crud($r, '/rate-plans', new RatePlanController(), $STAFF);
    $crud($r, '/prefixes', new PrefixController(), $STAFF);
    $crud($r, '/tariffs', new TariffController('customer'), $STAFF);
    $crud($r, '/custom-rates', new CustomRateController(), $STAFF);
    $ti = new TariffImportController();
    $r->get('/tariffs/import', [$ti, 'form'], [$STAFF]);
    $r->get('/tariffs/export', [$ti, 'export'], [$STAFF]);
    $r->post('/tariffs/import/upload', [$ti, 'upload'], [$STAFF]);
    $r->post('/tariffs/import/preview', [$ti, 'preview'], [$STAFF]);
    $r->post('/tariffs/import/commit', [$ti, 'commit'], [$STAFF]);
    $pi = new PrefixImportController();
    $r->get('/prefixes/import', [$pi, 'form'], [$STAFF]);
    $r->get('/prefixes/export', [$pi, 'export'], [$STAFF]);
    $r->post('/prefixes/import/upload', [$pi, 'upload'], [$STAFF]);
    $r->post('/prefixes/import/preview', [$pi, 'preview'], [$STAFF]);
    $r->post('/prefixes/import/commit', [$pi, 'commit'], [$STAFF]);

    // Routes — Providers / Trunk Groups / Provider Rates (legacy paths redirect)
    $providers = new CarrierController('carrier');
    $crud($r, '/providers', $providers, $STAFF);
    $r->get('/carriers', static fn () => Response::redirect(url('/providers')));
    $r->get('/carriers/create', static fn () => Response::redirect(url('/providers/create')));
    $r->get('/carriers/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/providers/' . (int) $p['id'] . '/edit'));
    });
    $crud($r, '/trunks', new CarrierController('trunk'), $STAFF);
    $providerRates = new TariffController('carrier');
    $crud($r, '/provider-rates', $providerRates, $STAFF);
    $r->get('/carrier-tariffs', static fn () => Response::redirect(url('/provider-rates')));
    $r->get('/carrier-tariffs/create', static fn () => Response::redirect(url('/provider-rates/create')));
    $r->get('/carrier-tariffs/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/provider-rates/' . (int) $p['id'] . '/edit'));
    });
    $sim = new RoutingController('rules');
    $r->get('/routing/simulate', [$sim, 'simulateForm'], [$STAFF]);
    $r->post('/routing/simulate', [$sim, 'simulate'], [$STAFF]);
    $trunkGroups = new RoutingController('plans');
    $crud($r, '/trunk-groups', $trunkGroups, $STAFF);
    $crud($r, '/destination-policies', new DestinationPolicyController(), $STAFF);
    $r->get('/routing/plans', static fn () => Response::redirect(url('/trunk-groups')));
    $r->get('/routing/plans/create', static fn () => Response::redirect(url('/trunk-groups/create')));
    $r->get('/routing/plans/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/trunk-groups/' . (int) $p['id'] . '/edit'));
    });
    // Legacy /routing CRUD removed — Trunk Groups cover multi-trunk failover
    $r->get('/routing', static fn () => Response::redirect(url('/trunk-groups')));
    $r->get('/routing/create', static fn () => Response::redirect(url('/trunk-groups/create')));
    $r->get('/routing/{id}/edit', static function ($req, array $p) {
        return Response::redirect(url('/trunk-groups'));
    });

    // Reports
    $cdr = new CdrController();
    $all = $role(Menu::ALL);
    $r->get('/cdrs', [$cdr, 'index'], [$all]);
    $r->get('/cdrs/export', [$cdr, 'export'], [$all]);
    $r->get('/cdrs/{id}', [$cdr, 'show'], [$all]);
    $rep = new ReportController();
    $r->get('/reports/cdr-failed', [$rep, 'cdrFailed'], [$all]);
    $r->get('/reports/asr-acd', static fn () => Response::redirect(url('/reports/summary-day')));
    $r->get('/reports/pdd', [$rep, 'pdd'], [$all]);
    $r->get('/reports/cdr-reconciliation', [$rep, 'cdrReconciliation'], [$role(Menu::FINANCE)]);
    $r->get('/reports/financial', [$rep, 'financial'], [$role(Menu::FINANCE)]);
    // Slugs kept inline so a missing CallSummaryService class cannot 500 the whole app (incl. /login).
    $summaryReports = [
        'summary-day' => false,
        'summary-day-user' => false,
        'summary-day-trunk' => true,
        'summary-month' => false,
        'summary-month-user' => false,
        'summary-month-trunk' => true,
        'summary-user' => false,
        'summary-trunk' => true,
        'summary-destination' => false,
        'summary-month-destination' => false,
    ];
    foreach ($summaryReports as $slug => $staffOnly) {
        $repRoles = $staffOnly ? $STAFF : $all;
        $r->get('/reports/' . $slug, static function ($req, array $p) use ($rep, $slug) {
            return $rep->callSummary($req, ['slug' => $slug]);
        }, [$repRoles]);
    }

    // Billing
    $bill = new BillingController();
    $r->get('/billing/preview', [$bill, 'previewForm'], [$STAFF]);
    $r->post('/billing/preview', [$bill, 'preview'], [$STAFF]);
    $r->get('/billing/refill', [$bill, 'topupForm'], [$STAFF]);
    $r->post('/billing/refill', [$bill, 'topup'], [$STAFF]);
    $r->get('/billing/topup', static fn () => Response::redirect(url('/billing/refill')));
    $pay = new PaymentController();
    $r->get('/payments', [$pay, 'index'], [$role(Menu::FINANCE)]);
    $r->get('/payments/create', [$pay, 'create'], [$role(Menu::RESELLER_UP)]);
    $r->post('/payments', [$pay, 'store'], [$role(Menu::RESELLER_UP)]);
    $led = new LedgerController();
    $r->get('/ledger', [$led, 'index'], [$role(Menu::LEDGER_READ)]);
    $r->post('/ledger/{id}/reverse', [$led, 'reverse'], [$STAFF]);
    $r->get('/statements', [new StatementController(), 'index'], [$role(Menu::LEDGER_READ)]);
    $inv = new InvoiceController();
    $invRoles = $role(['super_admin', 'admin', 'reseller', 'customer_owner']);
    $r->get('/invoices', [$inv, 'index'], [$invRoles]);
    $r->post('/invoices/generate', [$inv, 'generate'], [$role(Menu::RESELLER_UP)]);
    $r->get('/invoices/{id}', [$inv, 'show'], [$invRoles]);

    // Media & fraud (under Reports in menu)
    $rec = new RecordingController();
    $recRoles = $role(['super_admin', 'admin', 'reseller', 'customer_owner']);
    $r->get('/recordings', [$rec, 'index'], [$recRoles]);
    $r->get('/recordings/{id}/play', [$rec, 'play'], [$recRoles]);
    $fraud = new FraudController();
    $r->get('/fraud', [$fraud, 'index'], [$role(Menu::RESELLER_UP)]);
    $r->post('/fraud/scan', [$fraud, 'scan'], [$STAFF]);
    $r->post('/fraud/{id}/ack', [$fraud, 'acknowledge'], [$role(Menu::RESELLER_UP)]);
    $r->post('/fraud/rules/{id}', [$fraud, 'updateRule'], [$STAFF]);
    $r->get('/audit-logs', [new AuditLogController(), 'index'], [$STAFF]);

    // Settings (includes kill switch, CPS/concurrent caps, failover SIP codes, tax)
    $r->get('/system/status', [new SystemController(), 'status'], [$STAFF]);
    $settings = new SettingsController();
    $r->get('/settings', [$settings, 'edit'], [$STAFF]);
    $r->post('/settings', [$settings, 'update'], [$STAFF]);

    // Magnus scaffold modules (every remaining menu item)
    $r->get('/modules/{slug}', [new ModuleScaffoldController(), 'show'], [$STAFF]);
});
