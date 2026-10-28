<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\Tests\Support\WidgetHighlightCases;

echo json_encode((new WidgetHighlightCases())->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
