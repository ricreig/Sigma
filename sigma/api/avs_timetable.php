<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = cfg();
$iata = strtoupper((string)($_GET['iata'] ?? ($cfg['IATA'] ?? 'TIJ')));
$type = strtolower((string)($_GET['type'] ?? 'arrival')) === 'departure' ? 'departure' : 'arrival';
$date = trim((string)($_GET['date'] ?? ''));
$tzName = $cfg['timezone'] ?? 'America/Tijuana';
$icao = strtoupper((string)($cfg['ICAO'] ?? 'MMTJ'));

try {
    $tzLocal = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tzLocal = new DateTimeZone('America/Tijuana');
}
$tzUtc = new DateTimeZone('UTC');

// Permitir rango dinámico from/to en UTC
$fromParam = trim((string)($_GET['from'] ?? ''));
$toParam   = trim((string)($_GET['to'] ?? ''));
$fromUtc = null;
$toUtc   = null;

if ($fromParam !== '' && $toParam !== '') {
    try {
        $fromUtc = new DateTimeImmutable($fromParam, $tzUtc);
        $toUtc   = new DateTimeImmutable($toParam,   $tzUtc);
    } catch (Throwable $e) {
        $fromUtc = $toUtc = null;
    }
}

// Si no se envía rango, usar por fecha local
if ($fromUtc === null || $toUtc === null) {
    if ($date === '') {
        $date = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['ok' => false, 'error' => 'bad_date_format', 'date' => $date], 400);
    }

    $localStart = new DateTimeImmutable($date . ' 00:00:00', $tzLocal);
    $localEnd   = $localStart->modify('+1 day');
    $fromUtc    = $localStart->setTimezone($tzUtc);
    $toUtc      = $localEnd->setTimezone($tzUtc);
}

$db = db();
$db->set_charset('utf8mb4');

if ($type === 'arrival') {
    $sql = "SELECT id, flight_number, callsign, airline, ac_reg, ac_type, dep_icao, dst_icao,
                   std_utc, sta_utc, delay_min, status, codeshares_json
            FROM flights
            WHERE (dst_icao = ? OR dst_icao = ?)
              AND sta_utc >= ? AND sta_utc < ?
            ORDER BY sta_utc ASC";
    $params = [$iata, $icao, $fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')];
} else {
    $sql = "SELECT id, flight_number, callsign, airline, ac_reg, ac_type, dep_icao, dst_icao,
                   std_utc, sta_utc, delay_min, status, codeshares_json
            FROM flights
            WHERE (dep_icao = ? OR dep_icao = ?)
              AND std_utc >= ? AND std_utc < ?
            ORDER BY std_utc ASC";
    $params = [$iata, $icao, $fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')];
}

$stmt = $db->prepare($sql);
if (!$stmt) {
    json_response(['ok' => false, 'error' => 'db_prepare', 'detail' => $db->error], 500);
}

$stmt->bind_param('ssss', $params[0], $params[1], $params[2], $params[3]);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$seen = [];

while ($r = $res->fetch_assoc()) {
    $callsign   = strtoupper(trim((string)($r['callsign'] ?? '')));
    $flightIata = strtoupper(trim((string)($r['flight_number'] ?? '')));
    // Usamos callsign como identificador ICAO principal cuando existe
    $flightIcao = $callsign ?: $flightIata;

    $staUtc = $r['sta_utc'] ?: null;
    $stdUtc = $r['std_utc'] ?: null;

    $staTs = $staUtc ? strtotime($staUtc . ' UTC') : false;
    $stdTs = $stdUtc ? strtotime($stdUtc . ' UTC') : false;

    $staOut = $staTs ? gmdate('Y-m-d H:i:s', $staTs) : null;
    $stdOut = $stdTs ? gmdate('Y-m-d H:i:s', $stdTs) : null;

    $delayMin = is_numeric($r['delay_min'] ?? null) ? (int)$r['delay_min'] : 0;

    $etaOut = null;
    if ($staTs !== false) {
        $etaOut = gmdate('Y-m-d H:i:s', $staTs + ($delayMin * 60));
    } elseif ($stdTs !== false) {
        $etaOut = gmdate('Y-m-d H:i:s', $stdTs + ($delayMin * 60));
    }

    $status = strtolower((string)($r['status'] ?? 'scheduled'));
    if (in_array($status, ['active', 'enroute', 'en-route'], true) && !$etaOut) {
        $status = 'taxi';
    }

    // Conservamos ICAO completo, sin recortar a 3 letras
    $depIcao = strtoupper((string)($r['dep_icao'] ?? ''));
    $dstIcao = strtoupper((string)($r['dst_icao'] ?? ''));

    $depCode = $depIcao ?: '';
    $arrCode = $dstIcao ?: '';

    // Para la estación consultada, forzamos el ICAO local
    if ($type === 'arrival') {
        $arrCode = $icao;      // p.ej. MMTJ
    } elseif ($type === 'departure') {
        $depCode = $icao;
    }

    $key = $flightIcao . '|' . ($staOut ?? $stdOut ?? '');
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;

    $codeshares = [];
    if (!empty($r['codeshares_json'])) {
        $decoded = json_decode((string)$r['codeshares_json'], true);
        if (is_array($decoded)) {
            $codeshares = array_values(array_filter(array_map('strval', $decoded)));
        }
    }

    $rows[] = [
        'id'           => (int)$r['id'],
        'flight_icao'  => $flightIcao,
        'flight_iata'  => $flightIata,
        'callsign'     => $callsign,
        'airline_icao' => strtoupper((string)($r['airline'] ?? '')),
        // OJO: aquí van ICAO completos, aunque el campo se llame *_iata
        'dep_iata'     => $depCode,
        'arr_iata'     => $arrCode,
        'std_utc'      => $stdOut,
        'sta_utc'      => $staOut,
        'eta_utc'      => $etaOut,
        'delay_min'    => $delayMin,
        'status'       => $status,
        'codeshares'   => $codeshares,
    ];
}

json_response(['ok' => true, 'rows' => $rows]);