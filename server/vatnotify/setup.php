<?php
declare(strict_types=1);

/**
 * VatTrackr Notify – einmalige Einrichtung und kleine Verwaltung
 *
 * Aufruf im Browser:
 *   setup.php?secret=DEIN_ADMIN_SECRET                 Bot pruefen, Webhook und Befehle setzen
 *   setup.php?secret=DEIN_ADMIN_SECRET&action=stats    Nutzerzahlen
 *   setup.php?secret=DEIN_ADMIN_SECRET&action=remove   Webhook wieder abmelden
 */

require __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$cfg    = vtn_config();
$secret = (string) $cfg['admin_secret'];
if (strlen($secret) < 16) {
    http_response_code(503);
    exit("Bitte zuerst config.php anlegen und 'admin_secret' mit mindestens 16 Zeichen setzen.\n");
}
if (!hash_equals($secret, (string) ($_GET['secret'] ?? ''))) {
    http_response_code(403);
    exit("Kein Zugriff.\n");
}

$line = static function (bool $ok, string $text): void {
    echo ($ok ? '[ OK ] ' : '[FEHLER] ') . $text . "\n";
};
$action = (string) ($_GET['action'] ?? 'install');

echo "VatTrackr Notify " . VTN_VERSION . " – Einrichtung\n";
echo str_repeat('=', 48) . "\n\n";

// ---------------------------------------------------------------- Grundpruefungen
$fine = true;
$check = static function (bool $ok, string $text) use ($line, &$fine): void {
    $line($ok, $text);
    $fine = $fine && $ok;
};
$check(PHP_VERSION_ID >= 80000, 'PHP ' . PHP_VERSION . ' (mindestens 8.0)');
$check(function_exists('curl_init'), 'cURL verfuegbar');
$check(vtn_configured(), "'bot_token' in config.php eingetragen");
$writable = true;
foreach (['meta', 'keys', 'chats', 'pair', 'rl'] as $kind) {
    $dir = vtn_dir($kind);
    $writable = $writable && is_dir($dir) && is_writable($dir);
}
$check($writable, 'Datenordner beschreibbar: ' . $cfg['data_dir']);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base  = rtrim((string) $cfg['base_url'], '/');
if ($base === '') {
    $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
          . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
}
$check(str_starts_with($base, 'https://'), 'Adresse mit HTTPS: ' . $base);
if (!$fine) {
    echo "\nBitte die Punkte mit [FEHLER] beheben und die Seite neu laden.\n";
    exit;
}

// ---------------------------------------------------------------- Statistik
if ($action === 'stats') {
    $total = 0;
    $seen  = 0;
    $month = 0;
    $limit = time() - 30 * 86400;
    foreach (glob(vtn_dir('keys') . '/*.php') ?: [] as $f) {
        $rec = vtn_decode((string) @file_get_contents($f));
        if ($rec === null) {
            continue;
        }
        $total++;
        if (!empty($rec['seen'])) {
            $seen++;
            if ((int) $rec['seen'] >= $limit) {
                $month++;
            }
        }
    }
    $watchers = 0;
    foreach (glob(vtn_dir('chats') . '/*.php') ?: [] as $f) {
        $rec = vtn_decode((string) @file_get_contents($f));
        if ($rec !== null && !empty($rec['watch'])) {
            $watchers++;
        }
    }
    $run  = vtn_read('meta', 'watch');
    $last = ($run !== null && !empty($run['t'])) ? gmdate('d.m.Y H:i', (int) $run['t']) . ' UTC' : 'noch nie';
    echo "\nNutzer mit Schluessel:            $total\n";
    echo "davon Plugin eingerichtet:        $seen\n";
    echo "davon in den letzten 30 Tagen:    $month\n";
    echo "Nutzer mit Beobachtungsliste:     $watchers\n";
    echo "Letzter Lauf von cron.php:        $last\n";
    exit;
}

// ---------------------------------------------------------------- Webhook abmelden
if ($action === 'remove') {
    $res = vtn_tg('deleteWebhook', ['drop_pending_updates' => true]);
    $line(!empty($res['ok']), 'Webhook abgemeldet' . (empty($res['ok']) ? ': ' . ($res['description'] ?? '') : ''));
    exit;
}

// ---------------------------------------------------------------- Einrichtung
echo "\n";
$me = vtn_tg('getMe');
if (empty($me['ok'])) {
    $line(false, 'Bot-Token wird von Telegram nicht angenommen: ' . ($me['description'] ?? 'keine Antwort'));
    exit;
}
$username = (string) ($me['result']['username'] ?? '');
vtn_write('meta', 'bot', ['username' => $username, 'id' => (int) ($me['result']['id'] ?? 0), 'set' => time(), 'v' => 1]);
$line(true, 'Bot gefunden: @' . $username);

$hook = vtn_tg('setWebhook', [
    'url'                  => $base . '/webhook.php',
    'secret_token'         => vtn_webhook_secret(),
    'allowed_updates'      => ['message', 'callback_query'],
    'drop_pending_updates' => true,
    'max_connections'      => 10,
]);
$line(!empty($hook['ok']), 'Webhook gesetzt: ' . $base . '/webhook.php' . (empty($hook['ok']) ? ' – ' . ($hook['description'] ?? '') : ''));
if (!empty($hook['ok'])) {
    // erst jetzt gilt die Einrichtung als aktuell; die Anleitungsseite prueft das
    vtn_update('meta', 'bot', static function (?array $b) {
        $b      = $b ?? [];
        $b['v'] = VTN_SETUP;
        return $b;
    });
}

foreach (['en' => null, 'de' => 'de'] as $lang => $code) {
    $commands = [];
    foreach (['status', 'settings', 'test', 'atc', 'watch', 'unwatch', 'newkey', 'lang', 'stop', 'help'] as $c) {
        $commands[] = ['command' => $c, 'description' => vtn_t($lang, 'cmd_' . $c)];
    }
    $params = ['commands' => $commands];
    if ($code !== null) {
        $params['language_code'] = $code;
    }
    $res = vtn_tg('setMyCommands', $params);
    $line(!empty($res['ok']), 'Befehlsliste (' . $lang . ') gesetzt');
}

$info = vtn_tg('getWebhookInfo');
if (!empty($info['ok'])) {
    $r = $info['result'];
    $line(($r['url'] ?? '') === $base . '/webhook.php', 'Telegram meldet Webhook: ' . ($r['url'] ?? '-'));
    if (!empty($r['last_error_message'])) {
        $line(false, 'Letzter Fehler laut Telegram: ' . $r['last_error_message']);
    }
}
vtn_gc();

$run = vtn_read('meta', 'watch');
$cronOk = $run !== null && time() - (int) ($run['t'] ?? 0) < 600;
echo ($cronOk ? '[ OK ] ' : '[OFFEN] ') . 'cron.php laeuft regelmaessig (nur fuer /watch noetig)'
    . (($run !== null && !empty($run['t'])) ? ', zuletzt ' . gmdate('d.m.Y H:i', (int) $run['t']) . ' UTC' : ', bisher noch nie') . "\n";
echo "\nFuer /watch muss cron.php jede Minute laufen. Im Cronjob-Manager des Hosters anlegen, eine der beiden Arten:\n";
echo "   Interpreter cURL oder Wget, Befehl:  " . $base . '/cron.php?key=' . vtn_cron_key() . "\n";
echo "   Interpreter PHP, Befehl:             " . __DIR__ . "/cron.php\n";

echo "\nFertig. Naechste Schritte:\n";
echo " 1. In Telegram @" . $username . " oeffnen und /start senden – der Bot muss antworten.\n";
echo " 2. Anleitung aufrufen: " . $cfg['guide_url'] . "\n";
echo " 3. Diese Adresse nicht weitergeben; sie enthaelt dein Admin-Passwort.\n";
