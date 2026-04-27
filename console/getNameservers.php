<?php

require_once __DIR__ . "/../nameserver/service.php";

$ns = new NameserverService();

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
$nameservers = $ns->getAll($limit);

echo "Total nameserver ditampilkan: " . count($nameservers) . PHP_EOL;
print_r($nameservers);
