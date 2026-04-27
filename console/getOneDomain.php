<?php

require_once __DIR__ . "/../domain/service.php";

$domainsService = new DomainService();

if (!isset($argv[1]) || !is_numeric($argv[1])) {
	echo "Gunakan: php console/getOneDomain.php <domain_id>" . PHP_EOL;
	exit(1);
}

$id = (int) $argv[1];
$domain = $domainsService->getOne($id);

if (!$domain) {
	echo "Domain dengan ID {$id} tidak ditemukan" . PHP_EOL;
	exit(0);
}

print_r($domain);