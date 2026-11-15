<?php
// Fehler nie an den Client ausgeben - immer als saubere JSON-Antwort behandeln.
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib/phpmailer/Exception.php';
require __DIR__ . '/lib/phpmailer/PHPMailer.php';
require __DIR__ . '/lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$configFile = __DIR__ . '/mail-config.php';
if (!file_exists($configFile)) {
    respond(500, ['ok' => false, 'error' => 'missing_mail_config']);
}
$config = require $configFile;

/**
 * Sehr einfaches, dateibasiertes Rate-Limit pro IP: max. 5 Anfragen / 10 Minuten.
 * Verhindert, dass das Formular zum Spam-Versand oder zur Kontoblockade beim
 * Mail-Provider missbraucht wird.
 */
function rate_limit_ok($ip, $maxRequests = 5, $windowSeconds = 600) {
    $dir = __DIR__ . '/var';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/rate-limit.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Kann die Rate-Limit-Datei nicht anlegen/öffnen -> nicht blockieren, aber auch nicht crashen.
        return true;
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }

    $now = time();
    $key = sha1($ip);
    $timestamps = $data[$key] ?? [];
    $timestamps = array_values(array_filter($timestamps, function ($t) use ($now, $windowSeconds) {
        return ($now - $t) < $windowSeconds;
    }));

    $allowed = count($timestamps) < $maxRequests;
    if ($allowed) {
        $timestamps[] = $now;
    }
    $data[$key] = $timestamps;

    // Alte IPs komplett entfernen, damit die Datei nicht unbegrenzt waechst.
    foreach ($data as $k => $v) {
        $v = array_values(array_filter($v, function ($t) use ($now, $windowSeconds) {
            return ($now - $t) < $windowSeconds;
        }));
        if (empty($v)) {
            unset($data[$k]);
        } else {
            $data[$k] = $v;
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok($ip)) {
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}

function clean_field($value, $maxLength) {
    $value = (string) $value;
    $value = str_replace(["\r", "\n"], ' ', $value);
    $value = trim($value);
    return substr($value, 0, $maxLength);
}

/**
 * Max. eine Bestaetigung pro Besucher-Adresse und Stunde.
 * $record = false: nur pruefen, ob eine Bestaetigung erlaubt ist.
 * $record = true: Versand eintragen (erst nach erfolgreichem Versand aufrufen).
 */
function confirm_limit($email, $record, $windowSeconds = 3600) {
    $dir = __DIR__ . '/var';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fp = @fopen($dir . '/confirm-limit.json', 'c+');
    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) {
        $data = [];
    }

    $now = time();
    foreach ($data as $k => $t) {
        if (!is_int($t) || ($now - $t) >= $windowSeconds) {
            unset($data[$k]);
        }
    }

    $key = sha1(strtolower($email));
    $allowed = !isset($data[$key]);
    if ($record) {
        $data[$key] = $now;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

$name = clean_field($_POST['name'] ?? '', 120);
$email = clean_field($_POST['email'] ?? '', 190);
$phone = clean_field($_POST['phone'] ?? '', 40);
$ort = clean_field($_POST['ort'] ?? '', 80);
$message = trim(substr((string) ($_POST['message'] ?? ''), 0, 1000));
$themen = ['Drucker oder Scanner', 'WLAN oder Router', 'Smartphone oder Tablet', 'Computer oder Laptop', 'E-Mail-Konto', 'Etwas anderes'];
$thema = in_array($_POST['thema'] ?? '', $themen, true) ? $_POST['thema'] : 'Etwas anderes';
$honeypot = trim((string) ($_POST['website'] ?? ''));
$formLoadedAt = (int) ($_POST['ts'] ?? 0);

// Honeypot-Feld ist fuer Menschen unsichtbar; ist es befuellt, war es ein Bot.
// Genauso verdaechtig: das Formular wurde schneller als 2 Sekunden nach dem Laden abgeschickt.
$submittedTooFast = $formLoadedAt > 0 && (time() - intdiv($formLoadedAt, 1000)) < 2;
if ($honeypot !== '' || $submittedTooFast) {
    respond(200, ['ok' => true]);
}

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'error' => 'invalid_input']);
}

$subject = $thema . ' – ' . $name;

$body = "Neue Anfrage über it.bwstudios.de (IT-Hilfe)

";
$body .= "Thema: {$thema}
";
$body .= "Name: {$name}
";
$body .= "E-Mail: {$email}
";
$body .= "Telefon: " . ($phone !== '' ? $phone : '–') . "
";
$body .= "Ort: " . ($ort !== '' ? $ort : '–') . "

";
$body .= "Anmerkungen:
" . ($message !== '' ? $message : '–') . "
";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->Port = $config['smtp_port'];
    $mail->SMTPSecure = $config['smtp_secure']; // 'tls' (Port 587) oder 'ssl' (Port 465)
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_user'];
    $mail->Password = $config['smtp_pass'];
    $mail->CharSet = 'UTF-8';

    // Absender ist das echte, authentifizierte Postfach - wichtig gegen Spam-Einstufung.
    $mail->setFrom($config['mail_from'], $config['mail_from_name']);
    $mail->addAddress($config['mail_to']);
    $mail->addReplyTo($email, $name);

    $mail->Subject = '[IT-Hilfe] ' . $subject;
    $mail->Body = $body;

    $mail->send();

    // Eingangsbestaetigung an den Besucher; Fehler hier duerfen die Antwort nicht veraendern.
    try {
        if (confirm_limit($email, false)) {
            // Name aus dem Formular: Links entfernen und kuerzen, damit die Bestaetigung nicht fuer fremde Inhalte missbraucht werden kann.
            $safeName = trim(preg_replace('~(https?://|www\.)\S+~i', '', $name));
            $safeName = function_exists('mb_substr') ? mb_substr($safeName, 0, 40, 'UTF-8') : substr($safeName, 0, 40);

            $reply = ($safeName !== '' ? "Hallo {$safeName}," : "Hallo,") . "\n\n";
            $reply .= "vielen Dank für deine Anfrage zur IT-Hilfe. Sie ist bei mir angekommen, ich melde mich so schnell wie möglich persönlich bei dir.\n\n";
            $reply .= "Viele Grüße\nBennet Wegener\nBW Studios – Webdesign, Grafikdesign & Webentwicklung\nhttps://bwstudios.de\n\n";
            $reply .= "Diese Bestätigung wurde automatisch versendet.\n";

            $confirm = new PHPMailer(true);
            $confirm->isSMTP();
            $confirm->Host = $config['smtp_host'];
            $confirm->Port = $config['smtp_port'];
            $confirm->SMTPSecure = $config['smtp_secure'];
            $confirm->SMTPAuth = true;
            $confirm->Username = $config['smtp_user'];
            $confirm->Password = $config['smtp_pass'];
            $confirm->CharSet = 'UTF-8';

            $confirm->setFrom('info@bwstudios.de', 'BW Studios');
            $confirm->addReplyTo('info@bwstudios.de', 'BW Studios');
            $confirm->addAddress($email);
            $confirm->addCustomHeader('Auto-Submitted', 'auto-replied');
            $confirm->addCustomHeader('Precedence', 'bulk');

            $confirm->Subject = 'Danke für deine Nachricht – BW Studios';
            $confirm->Body = $reply;

            $confirm->send();
            confirm_limit($email, true);
        }
    } catch (\Throwable $e) {
        // Bestaetigung ist optional - still ignorieren.
    }

    respond(200, ['ok' => true]);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'send_failed']);
}
