<?php
/**
 * Mailer — minimal SMTP client + HTML email templates.
 * Falls back to mail() if SMTP credentials are not configured.
 */

function send_notification_email(string $to, string $subject, string $html, string $replyTo = '', string $fromName = ''): bool {
    if (empty($to)) return false;
    try {
        return send_mail($to, $subject, $html, '', $replyTo, $fromName);
    } catch (Throwable $e) {
        error_log('[FACT Mailer] ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate email address for use in SMTP headers.
 * Prevents CRLF injection attacks that could add arbitrary headers.
 */
function validate_email_for_headers(string $email): string {
    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Invalid email format');
    }
    if (strpos($email, "\n") !== false || strpos($email, "\r") !== false) {
        throw new Exception('Email contains invalid characters (CRLF)');
    }
    return $email;
}

function send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = '', string $fromName = ''): bool {
    static $cfg = null;
    if ($cfg === null) {
        $cfgPath = __DIR__ . '/../../config/mail.php';
        $cfg = file_exists($cfgPath) ? (require $cfgPath) : [];
    }

    try {
        $fromEmail = validate_email_for_headers($cfg['from_email'] ?? 'noreply@localhost');
        $to = validate_email_for_headers($to);
        if ($replyTo && $replyTo !== '') {
            $replyTo = validate_email_for_headers($replyTo);
        } else {
            $replyTo = $fromEmail;
        }
    } catch (Exception $e) {
        error_log('[FACT Mailer] Invalid email: ' . $e->getMessage());
        return false;
    }
    if (empty($fromName)) {
        $fromName = $cfg['from_name'] ?? 'FACT Alliance Hub';
    }
    $text = $text ?: strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>'],
        "\n", $html
    ));

    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        return _smtp_send($cfg, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo);
    }

    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
}

function _smtp_send(array $cfg, string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName, string $replyTo = ''): bool {
    $host = $cfg['smtp_host'];
    $port = (int)($cfg['smtp_port'] ?? 587);
    $enc  = strtolower($cfg['smtp_enc'] ?? 'tls');
    $user = $cfg['smtp_user'] ?? '';
    $pass = $cfg['smtp_pass'] ?? '';

    if (!$replyTo || $replyTo === '') {
        $replyTo = $fromEmail;
    }

    $boundary = 'b_' . md5(microtime(true) . random_int(0, 99999));
    $msgBody  = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . "--{$boundary}--\r\n";

    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fullHeaders    = "Date: " . date('r') . "\r\n"
        . "From: {$encodedFrom} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$encodedSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
        . "Reply-To: {$replyTo}\r\n"
        . "X-Mailer: FACT-Alliance-Hub\r\n";

    $prefix = ($enc === 'ssl') ? 'ssl://' : '';
    $sock   = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("[FACT Mailer] Cannot connect to {$host}:{$port} — {$errstr}");
        return false;
    }

    stream_set_timeout($sock, 15);

    $read = function () use ($sock): string {
        $buf = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $buf .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $buf;
    };
    $write = fn($s) => fwrite($sock, $s . "\r\n");

    $read(); // server greeting
    $write('EHLO localhost');
    $read();

    if ($enc === 'tls') {
        $write('STARTTLS');
        $read();
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : 0);
        if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
            fclose($sock);
            error_log('[FACT Mailer] TLS handshake failed');
            return false;
        }
        $write('EHLO localhost');
        $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (strpos($resp, '235') === false) {
            fclose($sock);
            error_log('[FACT Mailer] AUTH failed');
            return false;
        }
    }

    $write("MAIL FROM:<{$fromEmail}>");
    $read();
    $write("RCPT TO:<{$to}>");
    $resp = $read();
    if (strpos($resp, '250') === false && strpos($resp, '251') === false) {
        fclose($sock);
        error_log("[FACT Mailer] RCPT rejected for {$to}");
        return false;
    }

    $write('DATA');
    $read();
    $write($fullHeaders . "\r\n" . $msgBody . "\r\n.");
    $read();
    $write('QUIT');
    fclose($sock);
    return true;
}

/* ── Bulk sender — one SMTP session for many personalised messages ─── */

function generate_unsubscribe_token(string $email, string $secret): string {
    return bin2hex(hash_hmac('sha256', strtolower(trim($email)), $secret, true));
}

function send_bulk_notifications(array $messages): void {
    if (empty($messages)) return;
    static $cfg = null;
    if ($cfg === null) {
        $cfgPath = __DIR__ . '/../../config/mail.php';
        $cfg = file_exists($cfgPath) ? (require $cfgPath) : [];
    }
    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        _smtp_send_bulk($cfg, $messages);
    } else {
        foreach ($messages as $msg) {
            send_mail($msg['to'], $msg['subject'], $msg['html']);
        }
    }
}

function _smtp_send_bulk(array $cfg, array $messages): void {
    $host      = $cfg['smtp_host'];
    $port      = (int)($cfg['smtp_port'] ?? 587);
    $enc       = strtolower($cfg['smtp_enc'] ?? 'tls');
    $user      = $cfg['smtp_user'] ?? '';
    $pass      = $cfg['smtp_pass'] ?? '';
    try {
        $fromEmail = validate_email_for_headers($cfg['from_email'] ?? 'noreply@localhost');
    } catch (Exception $e) {
        error_log('[FACT Mailer] Bulk: Invalid from_email — ' . $e->getMessage());
        return;
    }
    $fromName  = $cfg['from_name']  ?? 'FACT Alliance Hub';

    $prefix = ($enc === 'ssl') ? 'ssl://' : '';
    $sock   = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("[FACT Mailer] Bulk: cannot connect — {$errstr}");
        return;
    }
    stream_set_timeout($sock, 15);

    $read  = function () use ($sock): string {
        $buf = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $buf .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $buf;
    };
    $write = fn($s) => fwrite($sock, $s . "\r\n");

    $read();
    $write('EHLO localhost'); $read();

    if ($enc === 'tls') {
        $write('STARTTLS'); $read();
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : 0);
        if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
            fclose($sock);
            error_log('[FACT Mailer] Bulk: TLS handshake failed');
            return;
        }
        $write('EHLO localhost'); $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN'); $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (strpos($resp, '235') === false) {
            fclose($sock);
            error_log('[FACT Mailer] Bulk: AUTH failed');
            return;
        }
    }

    $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    foreach ($messages as $msg) {
        // Validate recipient email to prevent header injection
        try {
            $to = validate_email_for_headers($msg['to']);
        } catch (Exception $e) {
            error_log('[FACT Mailer] Bulk: Invalid recipient email — ' . $e->getMessage());
            continue;
        }

        $subject = $msg['subject'];
        $html    = $msg['html'];
        $text    = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>'],
            "\n", $html
        ));

        $boundary       = 'b_' . md5(microtime(true) . $to . random_int(0, 99999));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $msgBody        = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--{$boundary}--\r\n";
        $fullHeaders    = "Date: " . date('r') . "\r\n"
            . "From: {$encodedFrom} <{$fromEmail}>\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: {$encodedSubject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . "X-Mailer: FACT-Alliance-Hub\r\n";

        $write("MAIL FROM:<{$fromEmail}>"); $read();
        $write("RCPT TO:<{$to}>");
        $resp = $read();
        if (strpos($resp, '250') === false && strpos($resp, '251') === false) {
            error_log("[FACT Mailer] Bulk: RCPT rejected for {$to}");
            $write('RSET'); $read();
            continue;
        }
        $write('DATA'); $read();
        $write($fullHeaders . "\r\n" . $msgBody . "\r\n."); $read();
    }

    $write('QUIT');
    fclose($sock);
}

/* ── HTML email templates ──────────────────────────────────────────── */

function _email_layout(string $content): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:0;background:#eef3ef;font-family:'Helvetica Neue',Arial,sans-serif}</style>
</head>
<body style="margin:0;padding:0;background:#eef3ef">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3ef;padding:32px 16px">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:10px;border:1px solid #dde6dd;overflow:hidden">
  <tr><td style="background:#1a6b5a;padding:22px 32px">
    <span style="color:#fff;font-size:17px;font-weight:700;letter-spacing:-.3px">FACT Alliance Hub</span>
    <span style="color:rgba(255,255,255,.5);font-size:12px;margin-left:10px">MIT J-WAFS</span>
  </td></tr>
  <tr><td style="padding:32px 32px 28px;font-size:15px;color:#1c2a24;line-height:1.6">
    {$content}
  </td></tr>
  <tr><td style="padding:18px 32px;background:#f7faf8;border-top:1px solid #e8ede8">
    <p style="margin:0;font-size:12px;color:#60706a;line-height:1.5">
      Abdul Latif Jameel Water &amp; Food Systems Lab &middot; MIT<br>
      77 Massachusetts Avenue, E38-325 &middot; Cambridge, MA 02139
    </p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function mail_tpl_verify_email(string $verifyUrl, string $firstName): string {
    $n    = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safe = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

    $content = <<<HTML
<h2 style="margin:0 0 18px;font-size:20px;color:#1a3d2a">Verify your email address</h2>
<p style="margin:0 0 14px;color:#60706a">Hi {$n},</p>
<p style="margin:0 0 20px;color:#60706a">Welcome to <strong style="color:#1c2a24">FACT Alliance Hub</strong>. Click the button below to verify your email address and activate your account. This link expires in <strong>24 hours</strong>.</p>
<a href="{$safe}" style="display:inline-block;background:#1a6b5a;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:-.01em">Verify my account →</a>
<p style="margin:24px 0 0;font-size:13px;color:#9aaba4">If you did not create an account on FACT Alliance Hub, you can safely ignore this email.</p>
<p style="margin:10px 0 0;font-size:12px;color:#b0bfba;word-break:break-all">Or copy this link into your browser: {$safe}</p>
HTML;
    return _email_layout($content);
}

function mail_tpl_new_message(string $senderName, string $subject, string $bodyPreview, string $appUrl, string $threadUrl = ''): string {
    $s    = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
    $su   = htmlspecialchars($subject,    ENT_QUOTES, 'UTF-8');
    $pr   = htmlspecialchars(mb_substr($bodyPreview, 0, 200), ENT_QUOTES, 'UTF-8');
    $link = $threadUrl ?: rtrim($appUrl, '/') . '/index.php?page=messages&tab=inbox';
    $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $content = <<<HTML
<h2 style="margin:0 0 18px;font-size:20px;color:#1a3d2a">You have a new message</h2>
<p style="margin:0 0 14px;color:#60706a"><strong style="color:#1c2a24">{$s}</strong> sent you a message on FACT Alliance Hub:</p>
<div style="background:#f7faf8;border:1px solid #dde6dd;border-radius:8px;padding:16px 20px;margin:0 0 24px">
  <p style="margin:0 0 8px;font-weight:700;color:#1c2a24">{$su}</p>
  <p style="margin:0;color:#60706a;font-size:14px;line-height:1.6">{$pr}</p>
</div>
<a href="{$safe}" style="display:inline-block;background:#1a6b5a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px">View &amp; Reply →</a>
<p style="margin:20px 0 0;font-size:13px;color:#9aaba4">You are receiving this because you are a member of FACT Alliance Hub. If you are not signed in, you will be asked to log in first.</p>
HTML;
    return _email_layout($content);
}

function mail_tpl_broadcast_message(string $senderName, string $subject, string $bodyPreview, string $threadUrl): string {
    $s    = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
    $su   = htmlspecialchars($subject,    ENT_QUOTES, 'UTF-8');
    $pr   = htmlspecialchars(mb_substr($bodyPreview, 0, 300), ENT_QUOTES, 'UTF-8');
    $safe = htmlspecialchars($threadUrl, ENT_QUOTES, 'UTF-8');

    $content = <<<HTML
<div style="display:inline-block;background:#f0f7f3;border:1px solid #c3dfd0;border-radius:6px;padding:4px 12px;font-size:12px;font-weight:700;color:#1a6b5a;letter-spacing:.06em;text-transform:uppercase;margin-bottom:18px">Network Announcement</div>
<h2 style="margin:0 0 14px;font-size:20px;color:#1a3d2a">{$su}</h2>
<p style="margin:0 0 20px;color:#60706a;font-size:14px">Message from <strong style="color:#1c2a24">{$s}</strong> to the FACT Alliance Network:</p>
<div style="background:#f7faf8;border-left:4px solid #1a6b5a;border-radius:0 8px 8px 0;padding:16px 20px;margin:0 0 24px">
  <p style="margin:0;color:#2a3a32;font-size:14px;line-height:1.7">{$pr}</p>
</div>
<a href="{$safe}" style="display:inline-block;background:#1a6b5a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px">Read &amp; Reply in FACT Hub →</a>
<p style="margin:20px 0 0;font-size:13px;color:#9aaba4">This message was sent to all members of FACT Alliance Hub. Log in to reply or view the full conversation.</p>
HTML;
    return _email_layout($content);
}

function mail_tpl_match_notify(
    string $firstName,
    string $fcTitle,
    string $fcFunder,
    string $fcDeadline,
    string $fcStatus,
    string $fcAmount,
    array  $matchedTopics,
    array  $matchedGeos,
    string $fundingUrl,
    string $unsubUrl
): string {
    $n        = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $t        = htmlspecialchars($fcTitle,   ENT_QUOTES, 'UTF-8');
    $f        = htmlspecialchars($fcFunder,  ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($fundingUrl, ENT_QUOTES, 'UTF-8');
    $safeUnsub = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');

    $deadlineStr = $fcDeadline ? date('F j, Y', strtotime($fcDeadline)) : '';
    $amountStr   = $fcAmount ? htmlspecialchars($fcAmount, ENT_QUOTES, 'UTF-8') : '';

    // Build meta rows
    $metaRows = '';
    if ($f)           $metaRows .= "<tr><td style='padding:5px 0;color:#9aaba4;font-size:13px;width:90px'>Funder</td><td style='padding:5px 0;font-size:13px;color:#1c2a24;font-weight:600'>{$f}</td></tr>";
    if ($deadlineStr) $metaRows .= "<tr><td style='padding:5px 0;color:#9aaba4;font-size:13px'>Deadline</td><td style='padding:5px 0;font-size:13px;color:#b54646;font-weight:700'>{$deadlineStr}</td></tr>";
    if ($amountStr)   $metaRows .= "<tr><td style='padding:5px 0;color:#9aaba4;font-size:13px'>Amount</td><td style='padding:5px 0;font-size:13px;color:#1c2a24'>{$amountStr}</td></tr>";

    // Build matched tag pills
    $pillStyle = 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;margin:2px 3px 2px 0';
    $tagPills  = '';
    foreach ($matchedTopics as $tag) {
        $e = htmlspecialchars(ucfirst($tag), ENT_QUOTES, 'UTF-8');
        $tagPills .= "<span style='{$pillStyle};background:#eaf6f0;color:#1a6b5a;border:1px solid #c3dfd0'>{$e}</span>";
    }
    foreach ($matchedGeos as $tag) {
        $e = htmlspecialchars(ucfirst($tag), ENT_QUOTES, 'UTF-8');
        $tagPills .= "<span style='{$pillStyle};background:#e8f0fd;color:#2563eb;border:1px solid #c3d5f8'>{$e}</span>";
    }

    $content = <<<HTML
<h2 style="margin:0 0 6px;font-size:19px;color:#1a3d2a;font-weight:800">A funding call matches your research profile</h2>
<p style="margin:0 0 22px;font-size:14px;color:#9aaba4">FACT Alliance Hub · Match Notification</p>
<p style="margin:0 0 20px;color:#60706a;font-size:15px">Hi {$n},</p>
<p style="margin:0 0 20px;color:#60706a;font-size:14px;line-height:1.65">
  A new funding opportunity has been posted on FACT Alliance Hub that aligns with your research interests.
</p>

<div style="background:#f7faf8;border:1.5px solid #dde6dd;border-radius:10px;padding:20px 22px;margin:0 0 22px">
  <p style="margin:0 0 12px;font-size:17px;font-weight:800;color:#1a3d2a;line-height:1.3">{$t}</p>
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%">{$metaRows}</table>
</div>

<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em">Matched because of your work in</p>
<p style="margin:0 0 24px">{$tagPills}</p>

<a href="{$safeUrl}" style="display:inline-block;background:#1a6b5a;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:-.01em">View Funding Opportunity →</a>

<p style="margin:28px 0 0;font-size:12px;color:#b0bfba;line-height:1.6">
  You are receiving this because your research profile on FACT Alliance Hub matches this funding call.<br>
  <a href="{$safeUnsub}" style="color:#9aaba4">Unsubscribe from match notifications</a>
</p>
HTML;
    return _email_layout($content);
}

function mail_tpl_password_reset(string $resetUrl, string $userName): string {
    $n    = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safe = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

    $content = <<<HTML
<h2 style="margin:0 0 18px;font-size:20px;color:#1a3d2a">Reset your password</h2>
<p style="margin:0 0 14px;color:#60706a">Hello {$n},</p>
<p style="margin:0 0 20px;color:#60706a">We received a request to reset your FACT Alliance Hub password. Click the button below — this link expires in <strong>1 hour</strong>.</p>
<a href="{$safe}" style="display:inline-block;background:#1a6b5a;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Reset Password</a>
<p style="margin:20px 0 0;font-size:13px;color:#9aaba4">If you did not request this, you can safely ignore this email — your password will not change.</p>
<p style="margin:10px 0 0;font-size:12px;color:#b0bfba;word-break:break-all">Link: {$safe}</p>
HTML;
    return _email_layout($content);
}
