<?php /** @var array $cdr @var bool $showCost */
$rows = [
    'UUID' => $cdr['uuid'], 'External key' => $cdr['external_cdr_key'], 'SIP Call-ID' => $cdr['sip_call_id'],
    'Customer' => $cdr['customer_name'] ?? '-', 'Source' => $cdr['src'], 'Destination (raw)' => $cdr['dst_raw'], 'Destination (normalised)' => $cdr['dst_normalized'],
    'Matched prefix' => $cdr['dst_prefix'], 'Setup (UTC)' => $cdr['setup_at'], 'Answer (UTC)' => $cdr['answer_at'], 'End (UTC)' => $cdr['end_at'],
    'Disposition' => $cdr['disposition'], 'SIP response' => $cdr['sip_response'], 'Failure reason' => $cdr['failure_reason'],
    'Actual duration (s)' => $cdr['duration_actual'], 'Billable customer (s)' => $cdr['duration_billable_customer'],
    'Customer rate / min' => $cdr['customer_rate'], 'Customer I/S' => ($cdr['customer_initial'] ?? '-') . '/' . ($cdr['customer_subsequent'] ?? '-'),
    'Connection fee' => $cdr['customer_connect_fee'], 'Charge (total)' => $cdr['customer_charge'] . ' ' . $cdr['currency'],
    'Rate plan version' => $cdr['customer_rate_plan_version_id'], 'Rating source' => $cdr['rating_source'], 'Settlement' => $cdr['settlement_status'],
    'Media policy (requested / actual)' => ($cdr['media_policy_requested'] ?? '-') . ' / ' . ($cdr['media_policy_actual'] ?? '-'), 'Recording' => $cdr['recording_status'],
];
if ($showCost) {
    $rows += [
        'Carrier' => $cdr['carrier_name'] ?? '-', 'Carrier rate / min' => $cdr['carrier_rate'], 'Carrier I/S' => ($cdr['carrier_initial'] ?? '-') . '/' . ($cdr['carrier_subsequent'] ?? '-'),
        'Billable carrier (s)' => $cdr['duration_billable_carrier'], 'Carrier cost (total)' => $cdr['carrier_cost'], 'Gross margin' => $cdr['gross_margin'],
        'CGRateS cost' => $cdr['cgrates_cost'], 'Rating discrepancy' => !empty($cdr['rating_discrepancy']) ? 'YES - investigate' : 'no',
    ];
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">CDR #<?= e($cdr['id']) ?><?= !empty($cdr['is_demo']) ? ' <span class="badge text-bg-warning">DEMO</span>' : '' ?></h1>
        <p class="os-page-sub">Full call detail record</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<div class="os-panel col-xl-8 overflow-hidden"><table class="table table-sm mb-0 os-table">
    <?php foreach ($rows as $k => $v): ?>
        <tr><th class="w-50 fw-normal text-muted"><?= e($k) ?></th><td class="os-mono"><?= e($v === null || $v === '' ? '-' : $v) ?></td></tr>
    <?php endforeach; ?>
</table></div>
