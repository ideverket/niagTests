<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';

function mail_settings(array $settings): array {
    return $settings + ['mailFromName'=>'', 'replyTo'=>'', 'replyToName'=>'', 'returnPath'=>'',
        'listUnsubscribe'=>'', 'smtpHost'=>'', 'smtpPort'=>587, 'smtpUser'=>'', 'smtpPassword'=>'',
        'smtpEncryption'=>'tls', 'smtpAuth'=>true, 'smtpTimeout'=>15];
}
function mail_configured(array $settings): bool {
    $s = mail_settings($settings); $transport = config()['mail_transport'];
    return $transport === 'file' || (!empty($s['mailFrom']) && ($transport !== 'smtp' ||
        ($s['smtpHost'] !== '' && (!$s['smtpAuth'] || ($s['smtpUser'] !== '' && $s['smtpPassword'] !== '')))));
}
function mail_header_value(mixed $value, int $max = 254): string {
    $value = text_value($value, $max);
    if (preg_match('/[\r\n]/', $value)) fail('E-postfält får inte innehålla radbrytningar.');
    return $value;
}
function validate_mail_settings(array $input, array $settings): array {
    $s = mail_settings($settings);
    foreach (['mailFromName','replyToName','smtpUser'] as $key) $s[$key] = mail_header_value($input[$key] ?? $s[$key]);
    foreach (['replyTo','returnPath'] as $key) {
        $value = mail_header_value($input[$key] ?? $s[$key]);
        $s[$key] = $value === '' ? '' : email_value($value);
    }
    $host = mail_header_value($input['smtpHost'] ?? $s['smtpHost']);
    if ($host !== '' && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP)) fail('Ange SMTP-serverns värdnamn utan protokoll eller port.');
    $s['smtpHost'] = $host;
    $s['smtpPort'] = integer($input['smtpPort'] ?? $s['smtpPort'], 1, 65535);
    $s['smtpTimeout'] = integer($input['smtpTimeout'] ?? $s['smtpTimeout'], 5, 60);
    $s['smtpEncryption'] = $input['smtpEncryption'] ?? $s['smtpEncryption'];
    if (!in_array($s['smtpEncryption'], ['tls','ssl','none'], true)) fail('Ogiltig SMTP-kryptering.');
    $s['smtpAuth'] = $input['smtpAuth'] ?? $s['smtpAuth'];
    if (!is_bool($s['smtpAuth'])) fail('Ogiltig SMTP-autentisering.');
    // Keep the exact password, including intentional spaces. Never return it to the browser.
    $password = $input['smtpPassword'] ?? '';
    if (!is_string($password) || strlen($password)>1000 || str_contains($password, "\0")) fail('Ogiltigt SMTP-lösenord.');
    if ($password === 'REPLACE_ME') fail('Ange kontots riktiga lösenord, inte REPLACE_ME.');
    if ($password !== '') $s['smtpPassword'] = $password;
    if (($input['clearSmtpPassword'] ?? false) === true) $s['smtpPassword'] = '';
    $s['listUnsubscribe'] = mail_header_value($input['listUnsubscribe'] ?? $s['listUnsubscribe'], 2000);
    if ($s['listUnsubscribe'] !== '') {
        foreach (explode(',', $s['listUnsubscribe']) as $entry) {
            $entry = trim($entry);
            if (!preg_match('/^<(mailto:[^<>]+|https:\/\/[^<>]+)>$/D', $entry, $match)) fail('Avregistrering anges som <mailto:adress> eller <https://adress>, separerade med komma.');
            $url = $match[1];
            if (str_starts_with($url,'mailto:')) email_value(substr($url,7));
            elseif (!filter_var($url,FILTER_VALIDATE_URL) || strpbrk($url,'{}') !== false) fail('Ange en fullständig fungerande avregistreringslänk utan platshållare.');
        }
    }
    if ($s['mailTransport'] === 'smtp' && ($s['smtpHost'] === '' || ($s['smtpAuth'] && $s['smtpUser'] === ''))) fail('Ange SMTP-server och användarnamn.');
    return $s;
}
function send_email(array &$db, string $to, string $subject, string $body, ?string $pdf = null): bool {
    $s = mail_settings($db['settings']); $transport = config()['mail_transport'];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $ok = false; $error = null;
    try {
        if (!mail_configured($s)) throw new RuntimeException('missing-config');
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($s['mailFrom'] ?: 'local@example.test', $s['mailFromName'], false);
        if ($s['replyTo'] !== '') $mail->addReplyTo($s['replyTo'], $s['replyToName']);
        $mail->Sender = $s['returnPath'] ?: $s['mailFrom'];
        if ($s['listUnsubscribe'] !== '') $mail->addCustomHeader('List-Unsubscribe', $s['listUnsubscribe']);
        $mail->addAddress($to); $mail->Subject = $subject; $mail->Body = $body;
        if ($pdf !== null) $mail->addStringAttachment($pdf, 'niag-diplom.pdf', 'base64', 'application/pdf');
        if ($transport === 'smtp') {
            $mail->isSMTP(); $mail->Host = $s['smtpHost']; $mail->Port = $s['smtpPort'];
            $mail->SMTPAuth = $s['smtpAuth']; $mail->Username = $s['smtpUser']; $mail->Password = $s['smtpPassword'];
            $mail->SMTPSecure = $s['smtpEncryption'] === 'none' ? '' : $s['smtpEncryption'];
            $mail->SMTPAutoTLS = false; // Explicit STARTTLS/SMTPS must succeed; never silently downgrade.
            $mail->Timeout = $s['smtpTimeout']; $mail->getSMTPInstance()->Timelimit = $s['smtpTimeout'];
            $ok = $mail->send();
        } elseif ($transport === 'file') {
            // Use identical MIME encoding and attachments for local verification.
            $mail->preSend(); storage_mkdir(data_path('mail'));
            $message = $mail->getSentMIMEMessage();
            $ok = file_put_contents(data_path('mail/'.uid().'.eml'), $message) === strlen($message);
        } else { $mail->isMail(); $ok = $mail->send(); }
    } catch (Throwable $e) {
        // Never persist SMTP transcripts, credentials or raw server error strings.
        $error = $e->getMessage() === 'missing-config' ? 'E-postinställningarna är ofullständiga. Ange avsändare och SMTP-lösenord.' : 'Utskicket misslyckades. Kontrollera server, kryptering, inloggning och avsändare.';
        if ($transport === 'smtp') {
            $code = (string)($mail->getSMTPInstance()->getError()['smtp_code'] ?? '');
            if (preg_match('/^[45][0-9]{2}$/D', $code)) $error .= ' SMTP-status: '.$code.'.';
        }
    }
    $row = ['id'=>uid(), 'at'=>time(), 'to'=>$to, 'subject'=>$subject,
        'status'=>$ok ? ($transport === 'file' ? 'local' : 'accepted') : 'failed'];
    if ($error !== null) $row['error'] = $error;
    $db['mailLog'][] = $row; $db['mailLog'] = array_slice($db['mailLog'], -200);
    return $ok;
}
