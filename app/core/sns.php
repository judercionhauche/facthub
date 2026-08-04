<?php
/**
 * Amazon SNS message helpers — signature verification & URL guards.
 * Kept separate from the webhook endpoint so they can be unit-tested directly.
 */

/** Only allow cert/confirm URLs on the official SNS hosts (anti-SSRF). */
function sns_url_is_aws(string $url): bool {
    $host   = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === 'https' && is_string($host)
        && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) === 1;
}

/**
 * Verify an SNS message signature (SignatureVersion 1 = SHA1, 2 = SHA256).
 * Rebuilds the canonical string in the exact field order SNS specifies,
 * fetches the signing certificate (host-validated), and checks the signature.
 * Returns true only on a cryptographically valid signature.
 */
function sns_signature_valid(array $m): bool {
    $certUrl = $m['SigningCertURL'] ?? $m['SigningCertUrl'] ?? '';
    if (!sns_url_is_aws($certUrl)) return false;
    if (empty($m['Signature'])) return false;

    $type = $m['Type'] ?? '';
    if ($type === 'Notification') {
        $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
    } elseif ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
        $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    } else {
        return false;
    }

    $canonical = '';
    foreach ($fields as $f) {
        if (!array_key_exists($f, $m)) continue; // Subject is optional
        $canonical .= $f . "\n" . $m[$f] . "\n";
    }

    $cert = sns_https_get($certUrl);
    if ($cert === '') return false;
    $pubKey = openssl_pkey_get_public($cert);
    if ($pubKey === false) return false;

    $algo = ((string)($m['SignatureVersion'] ?? '1') === '2') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
    $ok = openssl_verify($canonical, base64_decode($m['Signature']), $pubKey, $algo);
    return $ok === 1;
}

/** HTTPS GET with peer verification (used for the SNS signing certificate). */
function sns_https_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    return is_string($out) ? $out : '';
}
