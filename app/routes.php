<?php

declare(strict_types=1);

use Ownswitch\Controllers\AuthController;
use Ownswitch\Controllers\BillingController;
use Ownswitch\Controllers\CarrierController;
use Ownswitch\Controllers\CdrController;
use Ownswitch\Controllers\CustomerController;
use Ownswitch\Controllers\DashboardController;
use Ownswitch\Controllers\FraudController;
use Ownswitch\Controllers\InvoiceController;
use Ownswitch\Controllers\IpAuthController;
use Ownswitch\Controllers\LedgerController;
use Ownswitch\Controllers\RatePlanController;
use Ownswitch\Controllers\RecordingController;
use Ownswitch\Controllers\ReportController;
use Ownswitch\Controllers\ResellerController;
use Ownswitch\Controllers\RoutingController;
use Ownswitch\Controllers\SettingsController;
use Ownswitch\Controllers\SipUserController;
use Ownswitch\Controllers\SystemController;
use Ownswitch\Controllers\TariffController;
use Ownswitch\Controllers\TariffImportController;
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

    // Customers & access
    $crud($r, '/customers', new CustomerController(), $role(Menu::RESELLER_UP));
    $crud($r, '/resellers', new ResellerController(), $STAFF);
    $crud($r, '/sip-users', new SipUserController(), $role(Menu::SIP_USERS));
    $crud($r, '/ip-auth', new IpAuthController(), $role(Menu::SIP_USERS));

    // Rates
    $crud($r, '/rate-plans', new RatePlanController(), $STAFF);
    $crud($r, '/tariffs', new TariffController('customer'), $STAFF);
    $crud($r, '/carrier-tariffs', new TariffController('carrier'), $STAFF);
    $ti = new TariffImportController();
    $r->get('/tariffs/import', [$ti, 'form'], [$STAFF]);
    $r->get('/tariffs/export', [$ti, 'export'], [$STAFF]);
    $r->post('/tariffs/import/preview', [$ti, 'preview'], [$STAFF]);
    $r->post('/tariffs/import/commit', [$ti, 'commit'], [$STAFF]);

    // Carriers & routing
    $crud($r, '/carriers', new CarrierController('carrier'), $STAFF);
    $crud($r, '/trunks', new CarrierController('trunk'), $STAFF);
    $sim = new RoutingController('rules');
    $r->get('/routing/simulate', [$sim, 'simulateForm'], [$STAFF]);
    $r->post('/routing/simulate', [$sim, 'simulate'], [$STAFF]);
    $crud($r, '/routing/plans', new RoutingController('plans'), $STAFF);
    $crud($r, '/routing', $sim, $STAFF);

    // CDR & reports
    $cdr = new CdrController();
    $all = $role(Menu::ALL);
    $r->get('/cdrs', [$cdr, 'index'], [$all]);
    $r->get('/cdrs/export', [$cdr, 'export'], [$all]);
    $r->get('/cdrs/{id}', [$cdr, 'show'], [$all]);
    $rep = new ReportController();
    $r->get('/reports/asr-acd', [$rep, 'asrAcd'], [$all]);
    $r->get('/reports/financial', [$rep, 'financial'], [$role(Menu::FINANCE)]);

    // Billing
    $bill = new BillingController();
    $r->get('/billing/preview', [$bill, 'previewForm'], [$STAFF]);
    $r->post('/billing/preview', [$bill, 'preview'], [$STAFF]);
    $r->get('/billing/topup', [$bill, 'topupForm'], [$STAFF]);
    $r->post('/billing/topup', [$bill, 'topup'], [$STAFF]);
    $led = new LedgerController();
    $r->get('/ledger', [$led, 'index'], [$role(Menu::LEDGER_READ)]);
    $r->post('/ledger/{id}/reverse', [$led, 'reverse'], [$STAFF]);
    $inv = new InvoiceController();
    $invRoles = $role(['super_admin', 'admin', 'reseller', 'customer_owner']);
    $r->get('/invoices', [$inv, 'index'], [$invRoles]);
    $r->post('/invoices/generate', [$inv, 'generate'], [$role(Menu::RESELLER_UP)]);
    $r->get('/invoices/{id}', [$inv, 'show'], [$invRoles]);

    // Media & fraud
    $rec = new RecordingController();
    $recRoles = $role(['super_admin', 'admin', 'reseller', 'customer_owner']);
    $r->get('/recordings', [$rec, 'index'], [$recRoles]);
    $r->get('/recordings/{id}/play', [$rec, 'play'], [$recRoles]);
    $fraud = new FraudController();
    $r->get('/fraud', [$fraud, 'index'], [$role(Menu::RESELLER_UP)]);
    $r->post('/fraud/scan', [$fraud, 'scan'], [$STAFF]);
    $r->post('/fraud/{id}/ack', [$fraud, 'acknowledge'], [$role(Menu::RESELLER_UP)]);

    // System
    $r->get('/system/status', [new SystemController(), 'status'], [$STAFF]);
    $settings = new SettingsController();
    $r->get('/settings', [$settings, 'edit'], [$STAFF]);
    $r->post('/settings', [$settings, 'update'], [$STAFF]);
});
