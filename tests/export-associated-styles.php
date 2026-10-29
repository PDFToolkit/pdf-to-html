<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

echo json_encode((new \PDF\Html\Tests\Support\AssociatedStylesCases())->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
