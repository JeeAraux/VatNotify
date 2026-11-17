<?php
declare(strict_types=1);

/**
 * VatTrackr Notify – regelmaessige Pruefung fuer /watch
 *
 * Sieht nach, wer neu online ist, und benachrichtigt die Beobachter. Soll jede Minute laufen.
 * Aufruf als Adresse:   cron.php?key=SCHLUESSEL   (den Schluessel nennt setup.php)
 * oder als Befehl:      php /voller/pfad/cron.php  (vom Server selbst aus, dann ohne Schluessel)
 * Ohne laufenden Aufruf funktioniert alles ausser /watch.
 */

require __DIR__ . '/lib.php';

// Ein Aufruf ueber den Webserver hat immer eine Anfrage-Methode; ohne sie kommt der Aufruf
// von der Kommandozeile oder aus dem Cronjob-Manager des Servers.
$fromWeb = isset($_SERVER['REQUEST_METHOD']);
if ($fromWeb) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}
if (!vtn_configured()) {
    http_response_code(503);
    exit("nicht eingerichtet\n");
}
if ($fromWeb && !hash_equals(vtn_cron_key(), (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("kein Zugriff\n");
}

try {
    // Laeuft der vorige Aufruf noch, wird dieser uebersprungen.
    $lock = @fopen(vtn_dir('meta') . '/cron.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit("laeuft schon\n");
    }
    $r = vtn_watch_run();
    if (random_int(1, 60) === 1) {
        vtn_gc();
    }
    flock($lock, LOCK_UN);
    echo 'ok beobachtet=' . $r['watched'] . ' daten=' . ($r['data'] ? 'ja' : 'nein') . ' neu=' . $r['new'] . ' gesendet=' . $r['sent'] . "\n";
} catch (Throwable $e) {
    error_log('VatTrackr Notify cron: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo "fehler\n";
}
