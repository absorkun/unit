<?php

require_once __DIR__ . "/../domain/service.php";

$domainsService = new DomainService();

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
$domains = $domainsService->getAllBrief($limit);

echo "Total domain ditampilkan: " . count($domains) . PHP_EOL;
print_r($domains);