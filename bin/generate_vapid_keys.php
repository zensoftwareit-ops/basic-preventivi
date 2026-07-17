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

echo "Chiavi VAPID generate. Conservare la chiave privata solo in app/config.local.php.\n\n";
echo "'push' => [\n";
echo "    'enabled' => true,\n";
echo "    'vapid_subject' => 'mailto:preventivi@example.it',\n";
echo "    'vapid_public_key' => '" . $public . "',\n";
echo "    'vapid_private_key' => <<<'PEM'\n" . trim($privatePem) . "\nPEM,\n";
echo "    'ttl' => 86400,\n";
echo "    'timeout' => 20,\n";
echo "],\n";
