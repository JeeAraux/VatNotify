<?php
declare(strict_types=1);

/**
 * VatTrackr Notify – Telegram-Webhook
 *
 * Telegram ruft diese Datei bei jeder Nachricht an den Bot und bei jedem Tippen auf eine
 * Taste auf. Der Aufruf wird nur angenommen, wenn er das bei setup.php hinterlegte
 * Geheimnis mitbringt.
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!vtn_configured()) {
    http_response_code(503);
    exit('{"ok":false}');
}
$given = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals(vtn_webhook_secret(), $given)) {
    http_response_code(403);
    exit('{"ok":false}');
}

// Ab hier immer 200 antworten, damit Telegram denselben Aufruf nicht endlos wiederholt.
try {
    $update = json_decode((string) file_get_contents('php://input'), true);
    $msg    = is_array($update) ? ($update['message'] ?? null) : null;
    $tap    = is_array($update) ? ($update['callback_query'] ?? null) : null;
    if (is_array($msg) && isset($msg['chat']['id'], $msg['text']) && is_string($msg['text'])) {
        vtn_handle($msg);
    } elseif (is_array($tap) && isset($tap['id'], $tap['data'], $tap['message']['chat']['id'], $tap['message']['message_id'])) {
        vtn_tap($tap);
    }
} catch (Throwable $e) {
    error_log('VatTrackr Notify webhook: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}
echo '{"ok":true}';

function vtn_handle(array $msg): void
{
    $chat   = (int) $msg['chat']['id'];
    $cfg    = vtn_config();
    $guide  = vtn_esc((string) $cfg['guide_url']);
    $user   = vtn_read('chats', (string) $chat);
    $lang   = $user['lang'] ?? vtn_lang($msg['from']['language_code'] ?? null);
    $paired = $user !== null && !empty($user['kid']);

    if (($msg['chat']['type'] ?? '') !== 'private') {
        if (str_starts_with($msg['text'], '/start')) {
            vtn_send($chat, vtn_t($lang, 'group'));
        }
        return;
    }

    $parts = preg_split('/\s+/', trim($msg['text']), 3) ?: [];
    $cmd   = strtolower((string) preg_replace('/@.*$/', '', $parts[0] ?? ''));
    $arg   = (string) ($parts[1] ?? '');

    switch ($cmd) {
        case '/start':
            if (preg_match('/^[a-f0-9]{32}$/', $arg) === 1) {
                vtn_pair($chat, $lang, $arg, $guide);
                return;
            }
            if (!$paired) {
                vtn_send($chat, vtn_t($lang, 'welcome', ['guide' => $guide]));
                return;
            }
            vtn_status($chat, $lang, $user);
            return;

        case '/status':
            if (!$paired) {
                $text = vtn_t($lang, 'not_paired', ['guide' => $guide]);
                if ($user !== null && !empty($user['watch'])) {
                    $text .= "\n\n" . vtn_t($lang, 'watch_list', ['list' => vtn_esc(implode(', ', $user['watch']))]);
                }
                vtn_send($chat, $text);
                return;
            }
            vtn_status($chat, $lang, $user);
            return;

        case '/test':
            if (!$paired) {
                vtn_send($chat, vtn_t($lang, 'not_paired', ['guide' => $guide]));
                return;
            }
            [$rec, $allowed] = vtn_count((string) $user['kid']);
            vtn_send($chat, vtn_t($lang, $allowed ? 'test' : 'limit'));
            return;

        case '/settings':
        case '/einstellungen':
            $rec = $paired ? vtn_read('keys', (string) $user['kid']) : null;
            if ($rec === null) {
                vtn_send($chat, vtn_t($lang, 'not_paired', ['guide' => $guide]));
                return;
            }
            vtn_send($chat, vtn_t($lang, 'settings'), ['reply_markup' => vtn_settings_keyboard($lang, $rec)]);
            return;

        case '/atc':
            $prefix = vtn_prefix($arg);
            vtn_send($chat, $prefix === null ? vtn_t($lang, 'atc_usage') : vtn_atc_text($lang, $prefix));
            return;

        case '/watch':
            vtn_watch_command($chat, $lang, $user, $arg);
            return;

        case '/unwatch':
            $prefix = vtn_prefix($arg);
            if ($prefix === null) {
                vtn_send($chat, vtn_t($lang, 'watch_bad'));
                return;
            }
            $removed = vtn_watch_remove($chat, $prefix);
            vtn_send($chat, vtn_t($lang, $removed ? 'watch_removed' : 'watch_notfound', ['p' => $prefix]));
            return;

        case '/newkey':
        case '/key':
        case '/schluessel':
            if (!vtn_chat_limit('newkey', $chat, 5, 3600)) {
                vtn_send($chat, vtn_t($lang, 'limit'));
                return;
            }
            $key = vtn_new_key();
            vtn_bind($chat, $lang, $key);
            vtn_send($chat, vtn_t($lang, 'newkey', ['key' => $key, 'guide' => $guide]));
            return;

        case '/stop':
            vtn_send($chat, vtn_t($lang, vtn_forget($chat) ? 'stopped' : 'stopped_none'));
            return;

        case '/lang':
        case '/sprache':
        case '/language':
            $new = strtolower($arg);
            if ($new !== 'de' && $new !== 'en') {
                vtn_send($chat, vtn_t($lang, 'lang_help'));
                return;
            }
            if ($user !== null) {
                vtn_update('chats', (string) $chat, static function (?array $r) use ($new) {
                    if ($r !== null) {
                        $r['lang'] = $new;
                    }
                    return $r;
                });
                if ($paired && vtn_exists('keys', (string) $user['kid'])) {
                    vtn_update('keys', (string) $user['kid'], static function (?array $r) use ($new) {
                        if ($r !== null) {
                            $r['lang'] = $new;
                        }
                        return $r;
                    });
                }
            }
            vtn_send($chat, vtn_t($new, 'lang_set'));
            return;

        default:
            vtn_send($chat, vtn_t($lang, 'help', ['guide' => $guide]));
    }
}

/** Jemand hat auf eine Taste unter einer Bot-Nachricht getippt (Einstellungen). */
function vtn_tap(array $tap): void
{
    $chat = (int) $tap['message']['chat']['id'];
    $user = vtn_read('chats', (string) $chat);
    $lang = $user['lang'] ?? vtn_lang($tap['from']['language_code'] ?? null);
    $name = str_starts_with((string) $tap['data'], 'opt:') ? substr((string) $tap['data'], 4) : '';
    $kid  = ($user !== null && !empty($user['kid'])) ? (string) $user['kid'] : '';

    if ($kid === '' || !in_array($name, VTN_OPTS, true) || !vtn_exists('keys', $kid)) {
        vtn_tg('answerCallbackQuery', ['callback_query_id' => (string) $tap['id']]);
        return;
    }
    $rec = vtn_update('keys', $kid, static function (?array $r) use ($name) {
        if ($r === null) {
            return null;
        }
        $opts        = (isset($r['opts']) && is_array($r['opts'])) ? $r['opts'] : [];
        $opts[$name] = vtn_opt($r, $name) ? 0 : 1;
        $r['opts']   = $opts;
        return $r;
    });
    if ($rec === null) {
        vtn_tg('answerCallbackQuery', ['callback_query_id' => (string) $tap['id']]);
        return;
    }
    vtn_tg('editMessageReplyMarkup', [
        'chat_id'      => $chat,
        'message_id'   => (int) $tap['message']['message_id'],
        'reply_markup' => vtn_settings_keyboard($lang, $rec),
    ]);
    vtn_tg('answerCallbackQuery', [
        'callback_query_id' => (string) $tap['id'],
        'text'              => vtn_t($lang, 'opt_' . $name) . ': ' . vtn_t($lang, vtn_opt($rec, $name) ? 'opt_on' : 'opt_off'),
    ]);
}

/** /watch ohne Angabe zeigt die Liste, mit Angabe kommt ein Eintrag dazu. */
function vtn_watch_command(int $chat, string $lang, ?array $user, string $arg): void
{
    if (trim($arg) === '') {
        $list = ($user !== null && !empty($user['watch'])) ? (array) $user['watch'] : [];
        vtn_send($chat, $list === []
            ? vtn_t($lang, 'watch_usage')
            : vtn_t($lang, 'watch_list', ['list' => vtn_esc(implode(', ', $list))]));
        return;
    }
    $prefix = vtn_prefix($arg);
    if ($prefix === null) {
        vtn_send($chat, vtn_t($lang, 'watch_bad'));
        return;
    }
    if (!vtn_chat_limit('watch', $chat, 30, 3600)) {
        vtn_send($chat, vtn_t($lang, 'limit'));
        return;
    }
    $result = vtn_watch_add($chat, $lang, $prefix);
    if ($result === 'max') {
        vtn_send($chat, vtn_t($lang, 'watch_max', ['n' => (int) vtn_config()['watch_max']]));
        return;
    }
    $text = vtn_t($lang, $result === 'dup' ? 'watch_dup' : 'watch_added', ['p' => $prefix]);
    if ($result === 'ok') {
        vtn_watch_mark($prefix);                       // wer schon online ist, steht gleich in der Antwort
        $text .= "\n\n" . vtn_atc_text($lang, $prefix);
        $state = vtn_read('meta', 'watch');
        if ($state === null || time() - (int) ($state['t'] ?? 0) > 600) {
            $text .= "\n\n" . vtn_t($lang, 'watch_idle');
        }
    }
    vtn_send($chat, $text);
}

/** Der Nutzer hat den Link oder QR-Code der Einrichtungsseite geoeffnet. */
function vtn_pair(int $chat, string $lang, string $token, string $guide): void
{
    $sid = hash('sha256', $token);
    if (!vtn_exists('pair', $sid)) {
        vtn_send($chat, vtn_t($lang, 'pair_expired', ['guide' => $guide]));
        return;
    }
    $result = 'expired';
    $code   = '';
    vtn_update('pair', $sid, static function (?array $s) use ($chat, $lang, &$result, &$code) {
        if ($s === null || time() - (int) $s['created'] > VTN_PAIR_TTL) {
            $result = 'expired';
            return null;
        }
        if ($s['status'] === 'done') {
            $result = 'done';
            return $s;
        }
        if ($s['status'] === 'failed') {
            // zu viele falsche Codes: ein neuer Anlauf ist erlaubt, aber nicht beliebig oft
            $s['rounds'] = (int) ($s['rounds'] ?? 0) + 1;
            if ($s['rounds'] > 3) {
                $result = 'expired';
                return null;
            }
        }
        $code        = sprintf('%04d', random_int(0, 9999));
        $s['status'] = 'code';
        $s['chat']   = $chat;
        $s['lang']   = $lang;
        $s['code']   = $code;
        $s['tries']  = 0;
        $result      = 'code';
        return $s;
    });
    if ($result === 'code') {
        vtn_send($chat, vtn_t($lang, 'pair_code', ['code' => $code]));
    } elseif ($result === 'done') {
        vtn_send($chat, vtn_t($lang, 'pair_already'));
    } else {
        vtn_send($chat, vtn_t($lang, 'pair_expired', ['guide' => $guide]));
    }
}

function vtn_status(int $chat, string $lang, array $user): void
{
    $rec  = !empty($user['kid']) ? vtn_read('keys', (string) $user['kid']) : null;
    $text = vtn_t($lang, 'status_wait');
    if ($rec !== null && !empty($rec['seen'])) {
        $today = (is_array($rec['d'] ?? null) && $rec['d'][0] === gmdate('Y-m-d')) ? (int) $rec['d'][1] : 0;
        $text  = vtn_t($lang, 'status_ok', [
            'seen'  => gmdate('d.m.Y H:i', (int) $rec['seen']),
            'ver'   => vtn_esc((string) ($rec['ver'] ?? '?')),
            'today' => $today,
        ]);
    }
    if (!empty($user['watch'])) {
        $text .= "\n\n" . vtn_t($lang, 'watch_list', ['list' => vtn_esc(implode(', ', (array) $user['watch']))]);
    }
    vtn_send($chat, $text);
}
