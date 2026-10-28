<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\Tests\Signatures\SignatureFixture;

$fixtures = new SignatureFixture();
$report = [];
foreach ($fixtures->cases() as $case) {
    $result = $fixtures->provider($case['certificate'])->validate($fixtures->request($case['file']));
    $report[] = ['name' => $case['name'], 'status' => $result->status->value, 'message' => $result->message];
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
