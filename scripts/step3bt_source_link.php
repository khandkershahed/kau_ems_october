<?php

// Read-only: source-link snapshot for bill 5850 (Task B).
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$l = DB::table('salary_bill_source_links')->where('salary_generate_id', 5850)->first();
echo 'link id='.$l->id.' ver='.$l->salary_calculation_version_id.' fix='.$l->fixation_id
    .' regular_net='.$l->regular_net.' arrear_pay='.$l->arrear_payable.' arrear_ded='.$l->arrear_deduction
    .' net_payable='.$l->net_payable.' hash='.substr($l->source_hash, 0, 12).PHP_EOL;
$s = json_decode($l->source_snapshot, true);
echo 'snap keys: '.implode(',', array_keys($s)).PHP_EOL;
echo 'regular: '.json_encode($s['regular'] ?? null).PHP_EOL;
echo 'arrear_gpf: '.json_encode($s['arrear_gpf'] ?? null).PHP_EOL;
echo 'snap hash matches: '.var_export($s['source_hash'] === $l->source_hash, true).PHP_EOL;
