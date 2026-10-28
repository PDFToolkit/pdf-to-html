<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\HtmlDeriver;
use PDF\Html\Tests\Support\MathMlCases;

$cases = [];
foreach ((new MathMlCases())->all() as $name => $case) {
    $cases[$name] = ['html' => (new HtmlDeriver())->derive($case['document'])->html, 'expectedDisplay' => $case['expectedDisplay'], 'htmlDisplays' => (object) ($case['htmlDisplays'] ?? [])];
}
echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
