<?php
declare(strict_types=1);

/**
 * VatTrackr Notify – gemeinsame Funktionen (PHP 8.0+, nur cURL noetig)
 *
 * Gespeichert wird je Nutzer nur: Telegram-Chat-ID, Hash des Schluessels, Sprache,
 * Zeitpunkte und Zaehler. Nachrichteninhalte werden nie gespeichert oder protokolliert.
 */

const VTN_VERSION      = '1.1.0';
const VTN_KEY_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // ohne I, L, O, U, 0, 1
const VTN_PAIR_TTL     = 3600;                             // eine Einrichtung gilt 60 Minuten
const VTN_SETUP        = 2;                                // erhoehen, wenn setup.php erneut laufen muss
const VTN_OPTS         = ['private', 'radio', 'selcal', 'disconnect'];
const VTN_GUARD        = "<?php http_response_code(403); exit; ?>\n";

// ---------------------------------------------------------------- Konfiguration

function vtn_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'bot_token'       => '',
        'admin_secret'    => '',
        'base_url'        => '',
        'guide_url'       => 'https://vattrackr.de/vatnotify/',
        'allowed_origins' => [],
        'rate_per_minute' => 20,
        'rate_per_day'    => 300,
        'plugin_latest'   => VTN_VERSION,
        'inactive_days'   => 365,
        'watch_max'       => 10,
        'cron_key'        => '',
        'vatsim_data_url' => 'https://data.vatsim.net/v3/vatsim-data.json',
        'telegram_api'    => 'https://api.telegram.org',
        'data_dir'        => __DIR__ . '/data',
    ];
    $file = __DIR__ . '/config.php';
    $user = is_file($file) ? (require $file) : [];
    $cfg  = array_merge($defaults, is_array($user) ? $user : []);
    $cfg['data_dir'] = rtrim((string) $cfg['data_dir'], '/\\');
    return $cfg;
}

function vtn_configured(): bool
{
    return preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', (string) vtn_config()['bot_token']) === 1;
}

/** Geheimnis, mit dem Telegram seine Webhook-Aufrufe ausweist (aus dem Token abgeleitet). */
function vtn_webhook_secret(): string
{
    return hash_hmac('sha256', 'vtn-webhook', (string) vtn_config()['bot_token']);
}

function vtn_bot_username(): string
{
    $bot = vtn_read('meta', 'bot');
    return is_array($bot) ? (string) ($bot['username'] ?? '') : '';
}

// ---------------------------------------------------------------- Speicher (Dateien)

function vtn_dir(string $kind): string
{
    $dir = vtn_config()['data_dir'] . '/' . $kind;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function vtn_file(string $kind, string $id): string
{
    if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) !== 1) {
        throw new InvalidArgumentException('bad id');
    }
    return vtn_dir($kind) . '/' . $id . '.php';
}

function vtn_decode(string $raw): ?array
{
    $pos = strpos($raw, "\n");
    if ($pos === false) {
        return null;
    }
    $data = json_decode(substr($raw, $pos + 1), true);
    return is_array($data) ? $data : null;
}

function vtn_exists(string $kind, string $id): bool
{
    return is_file(vtn_file($kind, $id));
}

function vtn_read(string $kind, string $id): ?array
{
    $file = vtn_file($kind, $id);
    if (!is_file($file)) {
        return null;
    }
    $h = @fopen($file, 'r');
    if ($h === false) {
        return null;
    }
    flock($h, LOCK_SH);
    $raw = stream_get_contents($h);
    flock($h, LOCK_UN);
    fclose($h);
    return ($raw === false || $raw === '') ? null : vtn_decode($raw);
}

/**
 * Lesen, aendern, schreiben unter exklusiver Sperre.
 * $fn bekommt den aktuellen Datensatz (oder null) und liefert den neuen; null loescht.
 */
function vtn_update(string $kind, string $id, callable $fn): ?array
{
    $file = vtn_file($kind, $id);
    $h = @fopen($file, 'c+');
    if ($h === false) {
        throw new RuntimeException('storage not writable');
    }
    try {
        flock($h, LOCK_EX);
        $raw = stream_get_contents($h);
        $cur = ($raw === false || $raw === '') ? null : vtn_decode($raw);
        $new = $fn($cur);
        if ($new === null) {
            @unlink($file);
            return null;
        }
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, VTN_GUARD . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($h);
        return $new;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function vtn_write(string $kind, string $id, array $data): void
{
    vtn_update($kind, $id, static fn () => $data);
}

function vtn_delete(string $kind, string $id): void
{
    @unlink(vtn_file($kind, $id));
}

// ---------------------------------------------------------------- Schluessel und Nutzer

function vtn_new_key(): string
{
    $n = strlen(VTN_KEY_ALPHABET);
    $core = '';
    for ($i = 0; $i < 16; $i++) {
        $core .= VTN_KEY_ALPHABET[random_int(0, $n - 1)];
    }
    return 'VT-' . implode('-', str_split($core, 4));
}

/** Bringt eine Eingabe in die Form VT-XXXX-XXXX-XXXX-XXXX oder liefert null. */
function vtn_norm_key(string $input): ?string
{
    $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    if (strlen($s) === 18 && str_starts_with($s, 'VT')) {
        $s = substr($s, 2);
    }
    if (strlen($s) !== 16 || strspn($s, VTN_KEY_ALPHABET) !== 16) {
        return null;
    }
    return 'VT-' . implode('-', str_split($s, 4));
}

function vtn_key_id(string $key): string
{
    return hash('sha256', 'vtn-key:' . $key);
}

/** Verbindet einen Chat mit einem neuen Schluessel; ein frueherer Schluessel wird ungueltig. */
function vtn_bind(int $chat, string $lang, string $key): void
{
    $old  = vtn_read('chats', (string) $chat);
    $opts = [];
    if ($old !== null && !empty($old['kid'])) {
        $prev = vtn_read('keys', (string) $old['kid']);
        $opts = (is_array($prev) && isset($prev['opts']) && is_array($prev['opts'])) ? $prev['opts'] : [];
        vtn_delete('keys', (string) $old['kid']);
    }
    $kid = vtn_key_id($key);
    vtn_write('keys', $kid, [
        'chat'    => $chat,
        'lang'    => $lang,
        'created' => time(),
        'seen'    => null,
        'ver'     => null,
        'opts'    => $opts,
        'm'       => [0, 0],
        'd'       => ['', 0],
    ]);
    // Einstellungen und Beobachtungsliste bleiben ueber eine neue Kopplung hinweg erhalten
    vtn_write('chats', (string) $chat, [
        'kid'   => $kid,
        'lang'  => $lang,
        'watch' => array_values((array) ($old['watch'] ?? [])),
    ]);
}

/** Loescht alles, was zu einem Chat gespeichert ist: Schluessel, Einstellungen, Beobachtungsliste. */
function vtn_forget(int $chat): bool
{
    $rec = vtn_read('chats', (string) $chat);
    if ($rec === null) {
        return false;
    }
    if (!empty($rec['kid'])) {
        vtn_delete('keys', (string) $rec['kid']);
    }
    foreach ((array) ($rec['watch'] ?? []) as $prefix) {
        if (is_string($prefix) && vtn_prefix($prefix) === $prefix) {
            vtn_watch_unindex($chat, $prefix);
        }
    }
    vtn_delete('chats', (string) $chat);
    return true;
}

/**
 * Zaehlt eine Nachricht fuer den Schluessel und prueft die Limits.
 * Liefert [Datensatz|null, erlaubt].
 */
function vtn_count(string $kid): array
{
    if (!vtn_exists('keys', $kid)) {
        return [null, false];
    }
    $cfg = vtn_config();
    $allowed = false;
    $rec = vtn_update('keys', $kid, static function (?array $r) use ($cfg, &$allowed) {
        if ($r === null) {
            return null;
        }
        $now   = time();
        $today = gmdate('Y-m-d', $now);
        $m = (isset($r['m']) && is_array($r['m'])) ? $r['m'] : [0, 0];
        $d = (isset($r['d']) && is_array($r['d'])) ? $r['d'] : ['', 0];
        if ($now - (int) $m[0] >= 60) {
            $m = [$now, 0];
        }
        if ($d[0] !== $today) {
            $d = [$today, 0];
        }
        if ($m[1] < (int) $cfg['rate_per_minute'] && $d[1] < (int) $cfg['rate_per_day']) {
            $m[1]++;
            $d[1]++;
            $allowed = true;
        }
        $r['m'] = $m;
        $r['d'] = $d;
        return $r;
    });
    return [$rec, $allowed];
}

/** Einfaches Limit je IP-Adresse (gespeichert wird nur ein gesalzener Hash, kurzzeitig). */
function vtn_ip_limit(string $bucket, int $max, int $window): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $id = hash_hmac('sha256', $bucket . '|' . $ip, vtn_webhook_secret());
    $ok = false;
    vtn_update('rl', $id, static function (?array $r) use ($max, $window, &$ok) {
        $now = time();
        if ($r === null || $now - (int) $r['t'] >= $window) {
            $r = ['t' => $now, 'n' => 0];
        }
        if ($r['n'] < $max) {
            $r['n']++;
            $ok = true;
        }
        return $r;
    });
    return $ok;
}

/** Raeumt abgelaufene Kopplungen, Limit-Dateien und lange inaktive Nutzer weg. */
function vtn_gc(): void
{
    $now = time();
    foreach (['pair' => VTN_PAIR_TTL * 2, 'rl' => 3600] as $kind => $ttl) {
        foreach (glob(vtn_dir($kind) . '/*.php') ?: [] as $f) {
            if ($now - (int) @filemtime($f) > $ttl) {
                @unlink($f);
            }
        }
    }
    $limit = $now - max(30, (int) vtn_config()['inactive_days']) * 86400;
    foreach (glob(vtn_dir('keys') . '/*.php') ?: [] as $f) {
        if ((int) @filemtime($f) < $limit) {
            $rec = vtn_decode((string) @file_get_contents($f));
            if ($rec !== null && isset($rec['chat']) && vtn_exists('chats', (string) $rec['chat'])) {
                vtn_update('chats', (string) $rec['chat'], static function (?array $c) {
                    if ($c === null || empty($c['watch'])) {
                        return null;
                    }
                    $c['kid'] = null;
                    return $c;
                });
            }
            @unlink($f);
        }
    }
}

// ---------------------------------------------------------------- Telegram

function vtn_tg(string $method, array $params = []): array
{
    $cfg = vtn_config();
    $ch  = curl_init(rtrim((string) $cfg['telegram_api'], '/') . '/bot' . $cfg['bot_token'] . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params === [] ? new stdClass() : $params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        return ['ok' => false, 'error_code' => 0, 'description' => 'curl: ' . curl_error($ch)];
    }
    $res = json_decode((string) $body, true);
    return is_array($res) ? $res : ['ok' => false, 'error_code' => 0, 'description' => 'invalid response'];
}

/**
 * Schickt eine Nachricht (HTML). Hat der Nutzer den Bot blockiert oder geloescht,
 * werden seine Daten entfernt und 'gone' gemeldet.
 * Liefert 'ok', 'gone' oder 'error'.
 */
function vtn_send(int $chat, string $html, array $extra = []): string
{
    $res = vtn_tg('sendMessage', $extra + [
        'chat_id'              => $chat,
        'text'                 => $html,
        'parse_mode'           => 'HTML',
        'link_preview_options' => ['is_disabled' => true],
    ]);
    if (!empty($res['ok'])) {
        return 'ok';
    }
    if ((int) ($res['error_code'] ?? 0) === 403) {
        vtn_forget($chat);
        return 'gone';
    }
    return 'error';
}

// ---------------------------------------------------------------- Texte

function vtn_lang(?string $code): string
{
    return ($code !== null && str_starts_with(strtolower($code), 'de')) ? 'de' : 'en';
}

function vtn_esc(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Entfernt Steuerzeichen, sichert gueltiges UTF-8 und kuerzt. */
function vtn_clean($value, int $max): string
{
    $s = is_scalar($value) ? (string) $value : '';
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
    if (function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
    }
    return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s;
}

function vtn_t(string $lang, string $id, array $vars = []): string
{
    static $texts = null;
    if ($texts === null) {
        $texts = [
            'de' => [
                'welcome'       => "<b>VatTrackr Notify</b>\nIch melde mich auf deinem Handy, sobald dich auf VATSIM ein Lotse anschreibt, während du mit vPilot fliegst.\n\nZur Einrichtung öffne am PC:\n{guide}\n\nAuch ohne vPilot: /atc EDDM zeigt, wer dort online ist, und /watch EDDM meldet, wenn jemand online geht.\n\nAlle Befehle: /help",
                'pair_code'     => "Fast fertig. Gib auf der Einrichtungsseite diesen Code ein:\n\n<b>{code}</b>\n\nWenn du die Einrichtung nicht selbst gestartet hast, ignoriere diese Nachricht.",
                'pair_expired'  => "Dieser Einrichtungslink ist abgelaufen. Öffne die Anleitung am PC neu:\n{guide}",
                'pair_done'     => "✅ <b>Telegram ist verbunden.</b>\nDein Plugin holt sich den Schlüssel jetzt selbst. Auf der Einrichtungsseite geht es mit der Testnachricht weiter.",
                'pair_already'  => 'Diese Einrichtung ist schon abgeschlossen. Mit /status siehst du den Stand.',
                'newkey'        => "Dein neuer Schlüssel:\n\n<code>{key}</code>\n\nTrage ihn in der Datei VatTrackrNotify.ini ein (Zeile <code>Key=</code>) und starte vPilot neu.\n\nEin früherer Schlüssel ist ab sofort ungültig.",
                'not_paired'    => "Dein vPilot ist noch nicht verbunden. Öffne am PC die Anleitung:\n{guide}",
                'status_wait'   => "Telegram ist verbunden, aber dein Plugin hat sich noch nicht gemeldet.\nStarte vPilot neu. Hilft das nicht, sieh in die Datei VatTrackrNotify.log im Plugins-Ordner von vPilot.",
                'status_ok'     => "✅ Alles eingerichtet.\nPlugin zuletzt gemeldet: {seen} UTC (Version {ver})\nNachrichten heute: {today}",
                'stopped'       => 'Erledigt. Dein Schlüssel, deine Beobachtungsliste und alle zu dir gespeicherten Daten sind gelöscht. Entferne auch VatTrackrNotify.dll und VatTrackrNotify.ini aus dem Plugins-Ordner von vPilot, sonst öffnet das Plugin beim nächsten Start wieder die Einrichtung.',
                'stopped_none'  => 'Zu dir ist nichts gespeichert.',
                'lang_set'      => 'Sprache: Deutsch.',
                'lang_help'     => 'Sprache wählen mit /lang de oder /lang en.',
                'help'          => "<b>Befehle</b>\n/status – Stand der Einrichtung\n/settings – einstellen, was gemeldet wird\n/test – Testnachricht\n/atc EDDM – wer ist dort online\n/watch EDDM – melden, wenn dort jemand online geht\n/unwatch EDDM – Beobachtung beenden\n/newkey – neuen Schlüssel erstellen\n/lang de|en – Sprache\n/stop – alles löschen und abmelden\n\nAnleitung: {guide}",
                'group'         => 'Bitte schreib mir im privaten Chat.',
                'limit'         => 'Zu viele Anfragen. Bitte warte einen Moment.',
                'hello'         => "✅ <b>Plugin verbunden</b>\nvPilot meldet sich (Version {ver}). Ab jetzt bekommst du Nachrichten aufs Handy.\n\nMit /settings legst du fest, was gemeldet wird.",
                'update'        => "⬆️ <b>Neue Plugin-Version {ver}</b>\nDu nutzt {old}. So aktualisierst du: vPilot beenden, das Paket laden und entpacken, <code>Installieren.cmd</code> starten. Deine Verbindung bleibt bestehen.\n{pkg}",
                'test'          => "🧪 <b>Testnachricht</b>\nSo sieht es aus, wenn dich ein Lotse anschreibt:\n\n📡 <b>Contact me: XXXX_CTR</b>\nBitte melde dich auf <b>123.450</b>.",
                'contact'       => "📡 <b>Contact me: {from}</b>\nBitte melde dich auf <b>{freq}</b>.\n\n<i>{text}</i>",
                'sup'           => "⚠️ <b>Supervisor {from} schreibt dir</b>\n\n{text}\n\nBitte antworte zügig in vPilot.",
                'private'       => "✉️ <b>Private Nachricht von {from}</b>\n\n{text}",
                'radio'         => "📻 <b>Funk-Text von {from}</b>{on}\n\n{text}",
                'selcal'        => "🔔 <b>SELCAL von {from}</b>{on}",
                'disconnect'    => "🔌 <b>Verbindung zu VATSIM getrennt</b>\nvPilot ist nicht mehr mit dem Netzwerk verbunden.",
                'on'            => ' auf {freq}',
                'settings'      => "<b>Was soll aufs Handy?</b>\nTippe auf einen Eintrag, um ihn ein- oder auszuschalten.",
                'opt_private'   => 'Private Nachrichten',
                'opt_radio'     => 'Funk-Text an dich',
                'opt_selcal'    => 'SELCAL',
                'opt_disconnect' => 'Verbindungsabbruch',
                'opt_on'        => 'an',
                'opt_off'       => 'aus',
                'atc_usage'     => "Schick mir das Kürzel eines Flughafens oder Centers, zum Beispiel <code>/atc EDDM</code> oder <code>/atc EDMM</code>.",
                'atc_head'      => '<b>{p}</b> ist besetzt:',
                'atc_none'      => 'Bei <b>{p}</b> ist gerade niemand online.',
                'atc_atis'      => 'ATIS: {cs}  {freq}{code}',
                'atc_info'      => ' (Info {code})',
                'atc_foot'      => 'Stand {t} UTC',
                'atc_down'      => 'Die VATSIM-Daten sind gerade nicht erreichbar. Versuch es in einer Minute noch einmal.',
                'watch_usage'   => "Ich melde mich, sobald an einem Flughafen oder in einem Center jemand online geht. Beispiel: <code>/watch EDDM</code>",
                'watch_list'    => "Du beobachtest: <b>{list}</b>\nHinzufügen mit <code>/watch EDDF</code>, entfernen mit <code>/unwatch EDDM</code>.",
                'watch_added'   => 'Alles klar. Ich melde mich, sobald bei <b>{p}</b> jemand online geht.',
                'watch_dup'     => '<b>{p}</b> beobachtest du schon.',
                'watch_max'     => 'Mehr als {n} Einträge gehen nicht. Entferne zuerst einen mit /unwatch.',
                'watch_bad'     => 'Das habe ich nicht verstanden. Nimm das Kürzel vor dem Unterstrich, zum Beispiel EDDM, EDMM oder LON.',
                'watch_removed' => '<b>{p}</b> beobachte ich nicht mehr.',
                'watch_notfound' => '<b>{p}</b> steht nicht auf deiner Liste.',
                'watch_idle'    => 'Hinweis: Die automatische Prüfung läuft auf diesem Server gerade nicht. Meldungen kommen erst, wenn sie wieder läuft.',
                'watch_hit'     => "🟢 <b>{cs}</b> ist jetzt online{on}.\nAbbestellen: /unwatch {p}",
                'cmd_status'    => 'Stand der Einrichtung',
                'cmd_settings'  => 'Einstellen, was gemeldet wird',
                'cmd_test'      => 'Testnachricht senden',
                'cmd_atc'       => 'Wer ist online? Beispiel: /atc EDDM',
                'cmd_watch'     => 'Melden, wenn jemand online geht',
                'cmd_unwatch'   => 'Beobachtung beenden',
                'cmd_newkey'    => 'Neuen Schlüssel erstellen',
                'cmd_lang'      => 'Sprache wählen (de/en)',
                'cmd_stop'      => 'Alles löschen und abmelden',
                'cmd_help'      => 'Hilfe',
            ],
            'en' => [
                'welcome'       => "<b>VatTrackr Notify</b>\nI ping your phone as soon as a controller messages you on VATSIM while you fly with vPilot.\n\nTo set it up, open this on your PC:\n{guide}\n\nWorks without vPilot too: /atc EDDM shows who is online there, and /watch EDDM tells you when someone comes online.\n\nAll commands: /help",
                'pair_code'     => "Almost done. Enter this code on the setup page:\n\n<b>{code}</b>\n\nIf you did not start the setup yourself, ignore this message.",
                'pair_expired'  => "This setup link has expired. Open the guide on your PC again:\n{guide}",
                'pair_done'     => "✅ <b>Telegram is connected.</b>\nYour plugin now fetches the key by itself. Continue with the test message on the setup page.",
                'pair_already'  => 'This setup is already complete. Use /status to check.',
                'newkey'        => "Your new key:\n\n<code>{key}</code>\n\nPut it into VatTrackrNotify.ini (line <code>Key=</code>) and restart vPilot.\n\nAny earlier key is no longer valid.",
                'not_paired'    => "Your vPilot is not connected yet. Open the guide on your PC:\n{guide}",
                'status_wait'   => "Telegram is connected, but your plugin has not checked in yet.\nRestart vPilot. If that does not help, look at the file VatTrackrNotify.log in vPilot's Plugins folder.",
                'status_ok'     => "✅ All set.\nPlugin last checked in: {seen} UTC (version {ver})\nMessages today: {today}",
                'stopped'       => 'Done. Your key, your watch list and everything stored about you has been deleted. Also remove VatTrackrNotify.dll and VatTrackrNotify.ini from vPilot\'s Plugins folder, otherwise the plugin opens the setup again the next time vPilot starts.',
                'stopped_none'  => 'Nothing is stored about you.',
                'lang_set'      => 'Language: English.',
                'lang_help'     => 'Choose a language with /lang de or /lang en.',
                'help'          => "<b>Commands</b>\n/status – setup status\n/settings – choose what is reported\n/test – test message\n/atc EDDM – who is online there\n/watch EDDM – tell me when someone comes online there\n/unwatch EDDM – stop watching\n/newkey – create a new key\n/lang de|en – language\n/stop – delete everything and sign off\n\nGuide: {guide}",
                'group'         => 'Please message me in a private chat.',
                'limit'         => 'Too many requests. Please wait a moment.',
                'hello'         => "✅ <b>Plugin connected</b>\nvPilot checked in (version {ver}). From now on messages reach your phone.\n\nUse /settings to choose what is reported.",
                'update'        => "⬆️ <b>New plugin version {ver}</b>\nYou are using {old}. To update: close vPilot, download and unzip the package, run <code>Installieren.cmd</code>. Your connection stays in place.\n{pkg}",
                'test'          => "🧪 <b>Test message</b>\nThis is what it looks like when a controller messages you:\n\n📡 <b>Contact me: XXXX_CTR</b>\nPlease call on <b>123.450</b>.",
                'contact'       => "📡 <b>Contact me: {from}</b>\nPlease call on <b>{freq}</b>.\n\n<i>{text}</i>",
                'sup'           => "⚠️ <b>Supervisor {from} is messaging you</b>\n\n{text}\n\nPlease reply promptly in vPilot.",
                'private'       => "✉️ <b>Private message from {from}</b>\n\n{text}",
                'radio'         => "📻 <b>Radio text from {from}</b>{on}\n\n{text}",
                'selcal'        => "🔔 <b>SELCAL from {from}</b>{on}",
                'disconnect'    => "🔌 <b>Disconnected from VATSIM</b>\nvPilot is no longer connected to the network.",
                'on'            => ' on {freq}',
                'settings'      => "<b>What should reach your phone?</b>\nTap an entry to switch it on or off.",
                'opt_private'   => 'Private messages',
                'opt_radio'     => 'Radio text for you',
                'opt_selcal'    => 'SELCAL',
                'opt_disconnect' => 'Lost connection',
                'opt_on'        => 'on',
                'opt_off'       => 'off',
                'atc_usage'     => "Send me the code of an airport or centre, for example <code>/atc EDDM</code> or <code>/atc EDMM</code>.",
                'atc_head'      => '<b>{p}</b> is staffed:',
                'atc_none'      => 'Nobody is online at <b>{p}</b> right now.',
                'atc_atis'      => 'ATIS: {cs}  {freq}{code}',
                'atc_info'      => ' (info {code})',
                'atc_foot'      => 'As of {t} UTC',
                'atc_down'      => 'VATSIM data cannot be reached right now. Try again in a minute.',
                'watch_usage'   => "I tell you as soon as someone comes online at an airport or centre. Example: <code>/watch EDDM</code>",
                'watch_list'    => "You are watching: <b>{list}</b>\nAdd with <code>/watch EDDF</code>, remove with <code>/unwatch EDDM</code>.",
                'watch_added'   => 'Got it. I will tell you as soon as someone comes online at <b>{p}</b>.',
                'watch_dup'     => 'You are already watching <b>{p}</b>.',
                'watch_max'     => 'No more than {n} entries. Remove one with /unwatch first.',
                'watch_bad'     => 'I did not understand that. Use the code before the underscore, for example EDDM, EDMM or LON.',
                'watch_removed' => 'I am no longer watching <b>{p}</b>.',
                'watch_notfound' => '<b>{p}</b> is not on your list.',
                'watch_idle'    => 'Note: the automatic check is not running on this server right now. Messages will only arrive once it runs again.',
                'watch_hit'     => "🟢 <b>{cs}</b> is now online{on}.\nStop watching: /unwatch {p}",
                'cmd_status'    => 'Setup status',
                'cmd_settings'  => 'Choose what is reported',
                'cmd_test'      => 'Send a test message',
                'cmd_atc'       => 'Who is online? Example: /atc EDDM',
                'cmd_watch'     => 'Tell me when someone comes online',
                'cmd_unwatch'   => 'Stop watching',
                'cmd_newkey'    => 'Create a new key',
                'cmd_lang'      => 'Choose language (de/en)',
                'cmd_stop'      => 'Delete everything and sign off',
                'cmd_help'      => 'Help',
            ],
        ];
    }
    $text = $texts[$lang][$id] ?? $texts['en'][$id] ?? $id;
    $map  = [];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = (string) $v;
    }
    // strtr ersetzt in einem Durchgang: Platzhalter in Nutzertexten werden nicht nachtraeglich aufgeloest.
    return $map === [] ? $text : strtr($text, $map);
}

/** Sucht eine Funkfrequenz im Text und liefert sie als 123.450 (oder ''). */
function vtn_find_freq(string $text): string
{
    if (preg_match('/(?<!\d)(1[1-3]\d)[.,](\d{1,3})(?!\d)/', $text, $m) !== 1) {
        return '';
    }
    return $m[1] . '.' . str_pad($m[2], 3, '0');
}

/** Baut den Telegram-Text fuer eine Meldung des Plugins. Inhalte sind bereits bereinigt. */
function vtn_format(string $lang, string $type, string $from, string $text, string $freq): string
{
    $from = vtn_esc($from !== '' ? $from : '?');
    $on   = $freq !== '' ? vtn_t($lang, 'on', ['freq' => vtn_esc($freq)]) : '';
    switch ($type) {
        case 'private':
            if (preg_match('/_SUP$/i', $from) === 1) {
                return vtn_t($lang, 'sup', ['from' => $from, 'text' => vtn_esc($text)]);
            }
            $found = vtn_find_freq($text);
            if ($found !== '' && preg_match('/\bcontact\b/i', $text) === 1) {
                return vtn_t($lang, 'contact', ['from' => $from, 'freq' => $found, 'text' => vtn_esc($text)]);
            }
            return vtn_t($lang, 'private', ['from' => $from, 'text' => vtn_esc($text)]);
        case 'radio':
            return vtn_t($lang, 'radio', ['from' => $from, 'on' => $on, 'text' => vtn_esc($text)]);
        case 'selcal':
            return vtn_t($lang, 'selcal', ['from' => $from, 'on' => $on]);
        case 'disconnect':
            return vtn_t($lang, 'disconnect');
    }
    return '';
}

// ---------------------------------------------------------------- Einrichtung und Einstellungen

/** Ist setup.php seit dem letzten Update gelaufen? (Neue Fassungen brauchen neue Telegram-Einstellungen.) */
function vtn_setup_ok(): bool
{
    $bot = vtn_read('meta', 'bot');
    return is_array($bot) && (int) ($bot['v'] ?? 1) >= VTN_SETUP;
}

/** Adresse des Pakets fuer alle (ohne Token). Liegt im Ordner download neben der Anleitung. */
function vtn_package_url(): string
{
    $guide = (string) preg_replace('~[?#].*$~', '', (string) vtn_config()['guide_url']);
    return preg_replace('~[^/]*$~', '', $guide) . 'download/VatTrackrNotify.zip';
}

/** Aktuelle Plugin-Version: mindestens die dieses Pakets, hoeher nur per Eintrag in der Konfiguration. */
function vtn_latest(): string
{
    $set = (string) vtn_config()['plugin_latest'];
    return (preg_match('/^\d+(\.\d+){1,3}$/', $set) === 1 && version_compare($set, VTN_VERSION, '>')) ? $set : VTN_VERSION;
}

/** Schluessel fuer den Aufruf von cron.php; ohne Eintrag in der Konfiguration aus dem Token abgeleitet. */
function vtn_cron_key(): string
{
    $key = (string) vtn_config()['cron_key'];
    return $key !== '' ? $key : substr(hash_hmac('sha256', 'vtn-cron', (string) vtn_config()['bot_token']), 0, 32);
}

/** Einstellung eines Nutzers; ohne Eintrag ist alles eingeschaltet. */
function vtn_opt(array $rec, string $name): bool
{
    return !isset($rec['opts'][$name]) || (bool) $rec['opts'][$name];
}

function vtn_settings_keyboard(string $lang, array $rec): array
{
    $rows = [];
    foreach (VTN_OPTS as $name) {
        $rows[] = [[
            'text'          => (vtn_opt($rec, $name) ? '✅ ' : '⬜ ') . vtn_t($lang, 'opt_' . $name),
            'callback_data' => 'opt:' . $name,
        ]];
    }
    return ['inline_keyboard' => $rows];
}

/** Einfaches Limit je Chat, zum Beispiel fuer neue Schluessel. */
function vtn_chat_limit(string $bucket, int $chat, int $max, int $window): bool
{
    $ok = false;
    vtn_update('rl', hash('sha256', $bucket . ':' . $chat), static function (?array $r) use ($max, $window, &$ok) {
        $now = time();
        if ($r === null || $now - (int) $r['t'] >= $window) {
            $r = ['t' => $now, 'n' => 0];
        }
        if ($r['n'] < $max) {
            $r['n']++;
            $ok = true;
        }
        return $r;
    });
    return $ok;
}

// ---------------------------------------------------------------- Wer ist online? (VATSIM-Datenfeed)

function vtn_http_get(string $url, int $timeout): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'VatTrackrNotify/' . VTN_VERSION,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ($body === false || $code !== 200) ? null : (string) $body;
}

/**
 * Besetzte Stationen aus dem VATSIM-Datenfeed, hoechstens einmal je Minute abgerufen.
 * Gespeichert werden nur Rufzeichen, Frequenz und Anmeldezeit, keine Namen oder Mitgliedsnummern.
 * Liefert ['t' => Zeitpunkt, 'c' => [[Rufzeichen, Frequenz, Anmeldezeit], ...], 'a' => [[ATIS, Frequenz, Buchstabe], ...]]
 * oder null, wenn es noch nie Daten gab. Aufrufer pruefen das Alter ueber 't'.
 */
function vtn_atc(): ?array
{
    $cache = vtn_read('meta', 'atc');
    $now   = time();
    if ($cache !== null && ($now - (int) $cache['t'] < 60 || $now - (int) ($cache['try'] ?? 0) < 20)) {
        return $cache;
    }
    @ini_set('memory_limit', '256M');
    $raw  = vtn_http_get((string) vtn_config()['vatsim_data_url'], 12);
    $data = $raw !== null ? json_decode($raw, true) : null;
    unset($raw);
    if (!is_array($data) || !isset($data['controllers']) || !is_array($data['controllers'])) {
        if ($cache !== null) {
            $cache['try'] = $now;                      // nicht bei jedem Aufruf neu versuchen
            vtn_write('meta', 'atc', $cache);
        }
        return $cache;
    }
    $freq = static fn ($v): string => preg_match('/^\d{3}\.\d{1,3}$/', (string) $v) === 1 ? str_pad((string) $v, 7, '0') : '';
    $list = [];
    foreach ($data['controllers'] as $c) {
        $cs = strtoupper((string) ($c['callsign'] ?? ''));
        if ((int) ($c['facility'] ?? 0) === 0 || preg_match('/^[A-Z0-9][A-Z0-9_-]{2,15}$/', $cs) !== 1) {
            continue;                                  // Beobachter und Unlesbares
        }
        $logon  = (int) strtotime((string) preg_replace('/\.\d+/', '', (string) ($c['logon_time'] ?? '')));
        $list[] = [$cs, $freq($c['frequency'] ?? ''), $logon];
    }
    $atis = [];
    foreach ((array) ($data['atis'] ?? []) as $a) {
        $cs = strtoupper((string) ($a['callsign'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{2,15}$/', $cs) === 1) {
            $code   = strtoupper((string) ($a['atis_code'] ?? ''));
            $atis[] = [$cs, $freq($a['frequency'] ?? ''), preg_match('/^[A-Z]$/', $code) === 1 ? $code : ''];
        }
    }
    unset($data);
    usort($list, static fn ($x, $y) => strcmp($x[0], $y[0]));
    $cache = ['t' => $now, 'c' => $list, 'a' => $atis];
    vtn_write('meta', 'atc', $cache);
    return $cache;
}

/** Kuerzel vor dem Unterstrich: EDDM_TWR -> EDDM. Liefert null, wenn die Eingabe nicht passt. */
function vtn_prefix(string $input): ?string
{
    $s = explode('_', strtoupper(trim($input)), 2)[0];
    return preg_match('/^[A-Z0-9]{2,6}$/', $s) === 1 ? $s : null;
}

/** Antwort auf /atc: wer ist bei diesem Kuerzel gerade online. */
function vtn_atc_text(string $lang, string $prefix): string
{
    $atc = vtn_atc();
    if ($atc === null || time() - (int) $atc['t'] > 300) {
        return vtn_t($lang, 'atc_down');
    }
    $lines = [];
    foreach ($atc['c'] as [$cs, $freq]) {
        if (explode('_', $cs, 2)[0] === $prefix && count($lines) < 25) {
            $lines[] = '• ' . vtn_esc($cs) . ($freq !== '' ? '  ' . $freq : '');
        }
    }
    $text = $lines === []
        ? vtn_t($lang, 'atc_none', ['p' => $prefix])
        : vtn_t($lang, 'atc_head', ['p' => $prefix]) . "\n" . implode("\n", $lines);
    foreach ($atc['a'] as [$cs, $freq, $code]) {
        if (explode('_', $cs, 2)[0] === $prefix) {
            $text .= "\n" . vtn_t($lang, 'atc_atis', [
                'cs'   => vtn_esc($cs),
                'freq' => $freq,
                'code' => $code !== '' ? vtn_t($lang, 'atc_info', ['code' => $code]) : '',
            ]);
        }
    }
    return $text . "\n\n" . vtn_t($lang, 'atc_foot', ['t' => gmdate('H:i', (int) $atc['t'])]);
}

// ---------------------------------------------------------------- Beobachtungsliste (/watch)

/** Liefert 'ok', 'dup' oder 'max'. */
function vtn_watch_add(int $chat, string $lang, string $prefix): string
{
    $max    = max(1, (int) vtn_config()['watch_max']);
    $result = 'ok';
    vtn_update('chats', (string) $chat, static function (?array $r) use ($lang, $prefix, $max, &$result) {
        $r    = $r ?? ['kid' => null, 'lang' => $lang];
        $list = (isset($r['watch']) && is_array($r['watch'])) ? $r['watch'] : [];
        if (in_array($prefix, $list, true)) {
            $result = 'dup';
        } elseif (count($list) >= $max) {
            $result = 'max';
        } else {
            $list[] = $prefix;
            sort($list);
        }
        $r['watch'] = $list;
        return $r;
    });
    if ($result === 'ok') {
        vtn_update('watch', $prefix, static function (?array $w) use ($chat) {
            $w = $w ?? ['chats' => []];
            if (!in_array($chat, $w['chats'], true)) {
                $w['chats'][] = $chat;
            }
            return $w;
        });
    }
    return $result;
}

function vtn_watch_remove(int $chat, string $prefix): bool
{
    $found = false;
    if (vtn_exists('chats', (string) $chat)) {
        vtn_update('chats', (string) $chat, static function (?array $r) use ($prefix, &$found) {
            if ($r === null) {
                return null;
            }
            $list  = (array) ($r['watch'] ?? []);
            $found = in_array($prefix, $list, true);
            $r['watch'] = array_values(array_diff($list, [$prefix]));
            // ohne Schluessel und ohne Beobachtung wird der Datensatz nicht mehr gebraucht
            return (empty($r['kid']) && $r['watch'] === []) ? null : $r;
        });
    }
    vtn_watch_unindex($chat, $prefix);
    return $found;
}

function vtn_watch_unindex(int $chat, string $prefix): void
{
    if (!vtn_exists('watch', $prefix)) {
        return;
    }
    vtn_update('watch', $prefix, static function (?array $w) use ($chat) {
        if ($w === null) {
            return null;
        }
        $w['chats'] = array_values(array_filter((array) $w['chats'], static fn ($c) => (int) $c !== $chat));
        return $w['chats'] === [] ? null : $w;
    });
}

/** Wer bei diesem Kuerzel schon online ist, gilt als gemeldet (steht in der Antwort auf /watch). */
function vtn_watch_mark(string $prefix): void
{
    $atc = vtn_atc();
    if ($atc === null) {
        return;
    }
    vtn_update('meta', 'watch', static function (?array $s) use ($atc, $prefix) {
        $s = $s ?? ['t' => 0, 'n' => []];
        foreach ($atc['c'] as [$cs]) {
            if (explode('_', $cs, 2)[0] === $prefix) {
                $s['n'][$cs] = time();
            }
        }
        return $s;
    });
}

/**
 * Prueft, wer neu online ist, und benachrichtigt die Beobachter. Wird von cron.php aufgerufen.
 * Gemeldet wird eine Station, wenn sie sich in den letzten 30 Minuten angemeldet hat und
 * fuer diese Anmeldung noch keine Meldung hinausging; nach einer Meldung ist 15 Minuten Ruhe.
 */
function vtn_watch_run(): array
{
    $now   = time();
    $state = vtn_read('meta', 'watch') ?? ['t' => 0, 'n' => []];
    $files = glob(vtn_dir('watch') . '/*.php') ?: [];
    $atc   = $files !== [] ? vtn_atc() : null;
    $fresh = $atc !== null && $now - (int) $atc['t'] <= 300;
    $hits  = 0;
    $sent  = 0;

    if ($fresh) {
        $watch = [];
        foreach ($files as $f) {
            $w = vtn_decode((string) @file_get_contents($f));
            if ($w !== null && !empty($w['chats'])) {
                $watch[basename($f, '.php')] = (array) $w['chats'];
            }
        }
        foreach ($atc['c'] as [$cs, $freq, $logon]) {
            $prefix = explode('_', $cs, 2)[0];
            $last   = (int) ($state['n'][$cs] ?? 0);
            if (!isset($watch[$prefix]) || $logon < $now - 1800 || $last >= $logon || $now - $last < 900) {
                continue;
            }
            $state['n'][$cs] = $now;
            $hits++;
            foreach ($watch[$prefix] as $chat) {
                if ($sent >= 150) {
                    break 2;                               // Rest beim naechsten Lauf
                }
                $rec = vtn_read('chats', (string) $chat);
                if ($rec === null) {
                    vtn_watch_unindex((int) $chat, $prefix);
                    continue;
                }
                $lang = (string) ($rec['lang'] ?? 'en');
                vtn_send((int) $chat, vtn_t($lang, 'watch_hit', [
                    'cs' => vtn_esc($cs),
                    'on' => $freq !== '' ? vtn_t($lang, 'on', ['freq' => $freq]) : '',
                    'p'  => $prefix,
                ]));
                $sent++;
            }
        }
    }
    foreach ($state['n'] as $cs => $t) {
        if ($now - (int) $t > 21600) {
            unset($state['n'][$cs]);
        }
    }
    $state['t'] = $now;
    vtn_write('meta', 'watch', $state);
    return ['watched' => count($files), 'data' => $fresh, 'new' => $hits, 'sent' => $sent];
}
