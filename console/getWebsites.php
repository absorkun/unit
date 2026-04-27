<?php

require_once __DIR__ . "/../website/service.php";

$web = new WebsiteService();

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
$websites = $web->getAll($limit);

echo "Total website ditampilkan: " . count($websites) . PHP_EOL;
print_r($websites);