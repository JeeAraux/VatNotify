<?php
/**
 * VatTrackr Notify – Konfiguration
 *
 * Diese Datei nach config.php kopieren und die beiden Pflichtfelder ausfuellen.
 * config.php ist per .htaccess gegen Abruf von aussen gesperrt.
 */
return [

    // PFLICHT: Token deines Bots von @BotFather (sieht aus wie 1234567890:AA...)
    'bot_token' => '',

    // PFLICHT: selbst gewaehltes langes Passwort fuer setup.php (mindestens 16 Zeichen)
    'admin_secret' => '',

    // Adresse dieses Ordners. Leer lassen = wird automatisch erkannt.
    'base_url' => '',

    // Adresse der Anleitungsseite, die der Bot seinen Nutzern nennt.
    'guide_url' => 'https://vattrackr.de/vatnotify/',

    // Nur noetig, wenn die Anleitungsseite auf einer anderen Domain liegt als api.php.
    'allowed_origins' => [],

    // Limits je Nutzer gegen Missbrauch
    'rate_per_minute' => 20,
    'rate_per_day'    => 300,

    // Nur setzen, wenn du eine neuere Plugin-Version verteilst, als dieses Paket mitbringt.
    // Aeltere Plugins bekommen dann per Telegram einen Update-Hinweis.
    // 'plugin_latest' => '1.2.0',

    // Wie viele Flughaefen oder Center ein Nutzer mit /watch beobachten darf.
    'watch_max' => 10,

    // Nutzer ohne Aktivitaet werden nach so vielen Tagen automatisch geloescht.
    'inactive_days' => 365,
];
