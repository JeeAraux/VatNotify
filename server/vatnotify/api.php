<?php
declare(strict_types=1);

/**
 * VatTrackr Notify – Schnittstelle fuer Plugin und Anleitungsseite
 *
 * Aktionen (Parameter "action"):
 *   info          Bot-Name und aktuelle Plugin-Version
 *   pair_start    Einrichtung von der Anleitungsseite aus beginnen
 *   pair_status   Stand einer Einrichtung abfragen (Anleitungsseite)
 *   pair_confirm  Code aus Telegram bestaetigen (liefert den Schluessel)
 *   device_start  Einrichtung vom Plugin aus beginnen (Plugin ohne Schluessel)
 *   device_poll   Stand einer Einrichtung abfragen (Plugin); liefert am Ende den Schluessel
 *   status        hat sich das Plugin schon gemeldet?
 *   test          Testnachricht an den Nutzer schicken
 *   notify        Meldung des vPilot-Plugins weiterreichen
 */

require __DIR__ . '/lib.php';

// ---------------------------------------------------------------- Rahmen

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$cfg    = vtn_config();
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && in_array($origin, (array) $cfg['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function vtn_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vtn_fail(int $code, string $error): void
{
    vtn_out(['ok' => false, 'error' => $error], $code);
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 8192) {
    vtn_fail(413, 'too_large');
}
$in = [];
if ($raw !== '' && $raw[0] === '{') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $in = $json;
    }
}
$in = array_merge($_GET, $_POST, $in);

$p = static function (string $name) use ($in): string {
    return isset($in[$name]) && is_scalar($in[$name]) ? (string) $in[$name] : '';
};
$needPost = static function () use ($method): void {
    if ($method !== 'POST') {
        vtn_fail(405, 'post_required');
    }
};
/** Prueft den Schluessel und liefert [Schluessel-ID, Datensatz]; falsche Versuche werden je IP gebremst. */
$needKey = static function () use ($p): array {
    $key = vtn_norm_key($p('key'));
    $rec = $key !== null ? vtn_read('keys', vtn_key_id($key)) : null;
    if ($rec === null) {
        if (!vtn_ip_limit('badkey', 30, 600)) {
            vtn_fail(429, 'rate');
        }
        vtn_fail(401, 'key');
    }
    return [vtn_key_id($key), $rec];
};

if (!vtn_configured()) {
    vtn_fail(503, 'not_configured');
}

try {
    switch ($p('action')) {

        // ------------------------------------------------------------ info
        case 'info':
            $bot   = vtn_bot_username();
            $error = $bot === '' ? 'setup_missing' : (vtn_setup_ok() ? null : 'setup_outdated');
            vtn_out([
                'ok'      => $error === null,
                'error'   => $error,
                'bot'     => $bot,
                'latest'  => vtn_latest(),
                'pairTtl' => VTN_PAIR_TTL,
            ]);

        // ------------------------------------------------------------ pair_start
        case 'pair_start':
            $needPost();
            $bot = vtn_bot_username();
            if ($bot === '') {
                vtn_fail(503, 'setup_missing');
            }
            if (!vtn_ip_limit('pair', 12, 600)) {
                vtn_fail(429, 'rate');
            }
            if (random_int(1, 40) === 1) {
                vtn_gc();
            }
            $token = bin2hex(random_bytes(16));
            vtn_write('pair', hash('sha256', $token), ['created' => time(), 'status' => 'pending', 'tries' => 0]);
            vtn_out([
                'ok'      => true,
                'token'   => $token,
                'link'    => 'https://t.me/' . $bot . '?start=' . $token,
                'expires' => VTN_PAIR_TTL,
            ]);

        // ------------------------------------------------------------ pair_status
        case 'pair_status':
            $token = $p('token');
            if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
                vtn_fail(400, 'token');
            }
            $sid = hash('sha256', $token);
            $s   = !vtn_exists('pair', $sid) ? null : vtn_update('pair', $sid, static function (?array $s) {
                if ($s === null || time() - (int) $s['created'] > VTN_PAIR_TTL) {
                    return null;
                }
                $s['page'] = time();                   // die Seite ist offen und fragt
                return $s;
            });
            if ($s === null) {
                vtn_out(['ok' => true, 'status' => 'expired']);
            }
            $out = ['ok' => true, 'status' => (string) $s['status'], 'plugin' => !empty($s['plugin'])];
            if ($s['status'] === 'done') {
                $out['key'] = (string) $s['key'];
            }
            vtn_out($out);

        // ------------------------------------------------------------ device_start
        case 'device_start':
            $needPost();
            if (vtn_bot_username() === '') {
                vtn_fail(503, 'setup_missing');
            }
            if (!vtn_ip_limit('pair', 12, 600)) {
                vtn_fail(429, 'rate');
            }
            $token = bin2hex(random_bytes(16));
            vtn_write('pair', hash('sha256', $token), [
                'created' => time(), 'status' => 'pending', 'tries' => 0,
                'plugin'  => time(), 'ver' => vtn_clean($p('v'), 16),
            ]);
            vtn_out([
                'ok'     => true,
                'status' => 'pending',
                'page'   => false,
                'token'  => $token,
                'url'    => rtrim((string) $cfg['guide_url'], '?&') . '?pair=' . $token,
            ]);

        // ------------------------------------------------------------ device_poll
        case 'device_poll':
            $needPost();
            $token = $p('token');
            if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
                vtn_fail(400, 'token');
            }
            $ver = vtn_clean($p('v'), 16);
            $sid = hash('sha256', $token);
            $s   = !vtn_exists('pair', $sid) ? null : vtn_update('pair', $sid, static function (?array $s) use ($ver) {
                if ($s === null || time() - (int) $s['created'] > VTN_PAIR_TTL) {
                    return null;
                }
                $s['plugin'] = time();                 // das Plugin laeuft und fragt
                $s['ver']    = $ver;
                return $s;
            });
            if ($s === null) {
                vtn_out(['ok' => true, 'status' => 'expired']);
            }
            $out = [
                'ok'     => true,
                'status' => (string) $s['status'],
                'page'   => time() - (int) ($s['page'] ?? 0) < 90,
                'url'    => rtrim((string) $cfg['guide_url'], '?&') . '?pair=' . $token,
            ];
            if ($s['status'] === 'done') {
                $out['key'] = (string) $s['key'];
            }
            vtn_out($out);

        // ------------------------------------------------------------ pair_confirm
        case 'pair_confirm':
            $needPost();
            $token = $p('token');
            $code  = (string) preg_replace('/\D/', '', $p('code'));
            if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
                vtn_fail(400, 'token');
            }
            $sid = hash('sha256', $token);
            if (!vtn_exists('pair', $sid)) {
                vtn_out(['ok' => false, 'status' => 'expired', 'error' => 'expired'], 404);
            }
            $result = 'expired';
            $bind   = null;
            $s = vtn_update('pair', $sid, static function (?array $s) use ($code, &$result, &$bind) {
                if ($s === null || time() - (int) $s['created'] > VTN_PAIR_TTL) {
                    $result = 'expired';
                    return null;
                }
                if ($s['status'] === 'done') {
                    $result = 'done';
                    return $s;
                }
                if ($s['status'] !== 'code') {
                    $result = (string) $s['status'];
                    return $s;
                }
                if ($code === '' || !hash_equals((string) $s['code'], $code)) {
                    $s['tries'] = (int) $s['tries'] + 1;
                    if ($s['tries'] >= 5) {
                        $s['status'] = 'failed';
                        $result = 'failed';
                    } else {
                        $result = 'wrong';
                    }
                    return $s;
                }
                $s['status'] = 'done';
                $s['key']    = vtn_new_key();
                unset($s['code']);
                $bind   = [(int) $s['chat'], (string) $s['lang'], $s['key']];
                $result = 'done';
                return $s;
            });
            if ($bind !== null) {
                vtn_bind($bind[0], $bind[1], $bind[2]);
                if (vtn_send($bind[0], vtn_t($bind[1], 'pair_done')) === 'gone') {
                    // Bot wurde inzwischen blockiert: kein Schluessel ohne erreichbaren Chat
                    vtn_delete('pair', $sid);
                    vtn_fail(410, 'gone');
                }
            }
            if ($result === 'done') {
                vtn_out(['ok' => true, 'status' => 'done', 'key' => (string) $s['key']]);
            }
            if ($result === 'wrong') {
                vtn_out(['ok' => false, 'status' => 'code', 'error' => 'wrong_code', 'left' => 5 - (int) $s['tries']], 400);
            }
            vtn_out(['ok' => false, 'status' => $result, 'error' => $result], $result === 'expired' ? 404 : 409);

        // ------------------------------------------------------------ status
        case 'status':
            [$kid, $rec] = $needKey();
            vtn_out([
                'ok'      => true,
                'seen'    => $rec['seen'] !== null ? gmdate('c', (int) $rec['seen']) : null,
                'version' => $rec['ver'],
                'latest'  => vtn_latest(),
            ]);

        // ------------------------------------------------------------ test
        case 'test':
            $needPost();
            [$kid, $rec] = $needKey();
            [$rec, $allowed] = vtn_count($kid);
            if ($rec === null) {
                vtn_fail(401, 'key');
            }
            if (!$allowed) {
                vtn_fail(429, 'rate');
            }
            $sent = vtn_send((int) $rec['chat'], vtn_t((string) $rec['lang'], 'test'));
            if ($sent !== 'ok') {
                vtn_fail($sent === 'gone' ? 410 : 502, $sent === 'gone' ? 'gone' : 'telegram');
            }
            vtn_out(['ok' => true]);

        // ------------------------------------------------------------ notify
        case 'notify':
            $needPost();
            [$kid, $rec] = $needKey();
            $type = $p('type');
            $ver  = vtn_clean($p('v'), 16);

            if ($type === 'hello') {
                $first  = false;
                $update = false;
                $latest = vtn_latest();
                $rec = vtn_update('keys', $kid, static function (?array $r) use ($ver, $latest, &$first, &$update) {
                    if ($r === null) {
                        return null;
                    }
                    $first     = empty($r['seen']);
                    $r['seen'] = time();
                    $r['ver']  = $ver !== '' ? $ver : null;
                    // auf eine neue Version nur einmal hinweisen
                    if (preg_match('/^\d+(\.\d+){1,3}$/', $ver) === 1 && version_compare($ver, $latest, '<') && ($r['upd'] ?? '') !== $latest) {
                        $r['upd'] = $latest;
                        $update   = true;
                    }
                    return $r;
                });
                if ($rec === null) {
                    vtn_fail(401, 'key');
                }
                $lang = (string) $rec['lang'];
                if ($first) {
                    vtn_send((int) $rec['chat'], vtn_t($lang, 'hello', ['ver' => vtn_esc($ver !== '' ? $ver : '?')]));
                }
                if ($update) {
                    vtn_send((int) $rec['chat'], vtn_t($lang, 'update', [
                        'ver' => vtn_esc($latest), 'old' => vtn_esc($ver), 'pkg' => vtn_esc(vtn_package_url()),
                    ]));
                }
                vtn_out(['ok' => true, 'latest' => $latest, 'url' => (string) $cfg['guide_url']]);
            }

            if (!in_array($type, VTN_OPTS, true)) {
                vtn_fail(400, 'type');
            }
            if (!vtn_opt($rec, $type)) {
                vtn_out(['ok' => true, 'muted' => true]);      // im Bot unter /settings abgeschaltet
            }
            $from = strtoupper(vtn_clean($p('from'), 20));
            $text = vtn_clean($p('text'), 600);
            $freq = vtn_find_freq($p('freq'));
            if (($type === 'private' || $type === 'radio') && $text === '') {
                vtn_fail(400, 'text');
            }
            [$rec, $allowed] = vtn_count($kid);
            if ($rec === null) {
                vtn_fail(401, 'key');
            }
            if (!$allowed) {
                vtn_fail(429, 'rate');
            }
            $sent = vtn_send((int) $rec['chat'], vtn_format((string) $rec['lang'], $type, $from, $text, $freq));
            if ($sent !== 'ok') {
                vtn_fail($sent === 'gone' ? 410 : 502, $sent === 'gone' ? 'gone' : 'telegram');
            }
            vtn_out(['ok' => true]);

        default:
            vtn_fail(404, 'action');
    }
} catch (Throwable $e) {
    error_log('VatTrackr Notify api: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    vtn_fail(500, 'server');
}
