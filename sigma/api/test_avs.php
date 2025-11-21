<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

$c = require __DIR__.'/config.php';
$base = rtrim($c['AVS_BASE'], '/');
$key  = $c['AVS_KEY'];

$url = $base.'/flights?access_key='.$key
     .'&arr_iata=TIJ&flight_date='.date('Y-m-d');

echo "URL: $url\n\n";

$ctx = stream_context_create(['http'=>['timeout'=>8]]);
$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) {
    echo "ERROR: file_get_contents() devolvió false\n";
    exit(1);
}

echo "OK, respuesta cruda:\n";
echo substr($raw, 0, 1000), "\n";