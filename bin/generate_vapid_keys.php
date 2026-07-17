<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "Estensione PHP OpenSSL non disponibile.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);
$target = dirname(__DIR__) . '/app/vapid.local.php';
if (is_file($target) && !$force) {
    echo "Configurazione VAPID già presente e lasciata invariata:\n{$target}\n";
    echo "Per rigenerarla intenzionalmente aggiungere --force.\n";
    exit(0);
}
$emailArgument = '';
foreach (array_slice($argv, 1) as $argument) {
    if (strncmp((string) $argument, '--', 2) !== 0) {
        $emailArgument = trim((string) $argument);
        break;
    }
}
$email = $emailArgument;
if ($email === '') {
    try {
        $appConfig = require dirname(__DIR__) . '/app/config.php';
        $email = trim((string) ($appConfig['mail']['from_email'] ?? ''));
    } catch (Throwable $exception) {
        $email = '';
    }
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php generate_vapid_keys.php email@dominio.it [--force]\n");
    fwrite(STDERR, "Indicare l'email come argomento oppure configurare mail.from_email in config.local.php.\n");
    exit(2);
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if ($key === false || !openssl_pkey_export($key, $privatePem)) {
    fwrite(STDERR, "Generazione chiavi VAPID non riuscita.\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
$x = $details['ec']['x'] ?? null;
$y = $details['ec']['y'] ?? null;
if (!is_string($x) || !is_string($y)) {
    fwrite(STDERR, "Chiave pubblica VAPID non disponibile.\n");
    exit(1);
}
$public = rtrim(strtr(base64_encode("\x04" . $x . $y), '+/', '-_'), '=');

$content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n";
$content .= "    'push' => [\n";
$content .= "        'enabled' => true,\n";
$content .= "        'vapid_subject' => 'mailto:" . str_replace("'", "\\'", $email) . "',\n";
$content .= "        'vapid_public_key' => '" . $public . "',\n";
$content .= "        'vapid_private_key' => " . var_export(trim($privatePem), true) . ",\n";
$content .= "        'ttl' => 86400,\n";
$content .= "        'timeout' => 20,\n";
$content .= "    ],\n];\n";

$temporary = $target . '.tmp.' . bin2hex(random_bytes(5));
if (file_put_contents($temporary, $content, LOCK_EX) === false) {
    fwrite(STDERR, "Impossibile scrivere la configurazione VAPID in app/.\n");
    exit(1);
}
@chmod($temporary, 0640);
if (!rename($temporary, $target)) {
    @unlink($temporary);
    fwrite(STDERR, "Impossibile attivare il file app/vapid.local.php.\n");
    exit(1);
}

echo "Configurazione VAPID creata correttamente.\n";
echo "File: {$target}\n";
echo "Contatto: mailto:{$email}\n";
echo "Non devi copiare alcuna chiave. Verifica ora health.php.\n";
