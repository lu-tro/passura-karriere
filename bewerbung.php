<?php
/* ═══════════════════════════════════════════════════════════════════
   PASSURA Automobile – Bewerbungsformular-Handler
   Nimmt das Formular per POST entgegen und schickt die Bewerbung
   inkl. Anhänge (Lebenslauf + weitere Dokumente) per E-Mail.
   ─────────────────────────────────────────────────────────────────
   DEPLOYMENT: Diese Datei muss zusammen mit passura-landing.html auf
   einen Webspace mit PHP + Mailversand (z.B. passura.de) hochgeladen
   werden. Danach im Formular als action verwendet (ist bereits per
   JavaScript/fetch verdrahtet).
═══════════════════════════════════════════════════════════════════ */

/* ─── Konfiguration ─────────────────────────────────────────────── */
const RECIPIENT   = 'passura@t-online.de';          // Empfänger der Bewerbungen
const FROM_EMAIL  = 'bewerbung@passura.de';         // WICHTIG: Adresse auf EURER Domain (für Zustellbarkeit/SPF/DKIM)
const FROM_NAME   = 'PASSURA Bewerbungsformular';
const SUBJECT_PRE = 'Neue Bewerbung';

const SEND_CONFIRMATION = true;                     // Eingangsbestätigung an den Bewerber senden?
const COMPANY_NAME = 'PASSURA Automobile';
const COMPANY_PHONE = '02831-6344';

const MAX_CV_BYTES    = 10 * 1024 * 1024;           // 10 MB (Lebenslauf)
const MAX_DOCS_BYTES  = 20 * 1024 * 1024;           // 20 MB (weitere Dokumente gesamt)
const MAX_DOCS_COUNT  = 10;                          // max. Anzahl weiterer Dateien

const ALLOWED_CV   = ['pdf','doc','docx'];
const ALLOWED_DOCS = ['pdf','doc','docx','jpg','jpeg','png'];

/* Menschenlesbare Stellenbezeichnungen */
$STELLEN = [
  'kfz-mechatroniker'   => 'KFZ Mechatroniker (m/w/d)',
  'kfz-meister'         => 'KFZ Meister (m/w/d)',
  'karosseriebauer'     => 'Karosseriebauer (m/w/d)',
  'karosseriebaumeister'=> 'Karosseriebaumeister (m/w/d)',
  'initiativ'           => 'Initiativbewerbung',
];

/* ─── Hilfsfunktionen ───────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Entfernt Zeilenumbrüche → verhindert E-Mail-Header-Injection */
function h(string $v): string {
  return trim(str_replace(["\r", "\n", "%0a", "%0d"], '', $v));
}

function mimeFromExt(string $ext): string {
  return [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
  ][$ext] ?? 'application/octet-stream';
}

/* ─── Nur POST erlauben ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  fail('Methode nicht erlaubt.', 405);
}

/* Upload größer als Server-Limit (post_max_size)? Dann sind $_POST/$_FILES leer. */
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
  fail('Die Dateien sind zu groß für den Server. Bitte verkleinere die Anhänge '
     . '(oder das Server-Limit post_max_size/upload_max_filesize erhöhen).', 413);
}

/* ─── Spam-Schutz: Honeypot (unsichtbares Feld, muss leer sein) ── */
if (!empty($_POST['website'] ?? '')) {
  // Bot erkannt – wir tun so, als wäre alles ok, versenden aber nichts.
  echo json_encode(['ok' => true]);
  exit;
}

/* ─── Pflichtfelder einlesen & prüfen ───────────────────────────── */
$vorname  = h($_POST['vorname']  ?? '');
$nachname = h($_POST['nachname'] ?? '');
$email    = h($_POST['email']    ?? '');
$telefon  = h($_POST['telefon']  ?? '');
$betreff  = h($_POST['betreff']  ?? '');
$nachricht = trim($_POST['nachricht'] ?? '');

if ($vorname === '' || $nachname === '' || $email === '' || $telefon === '' || $betreff === '') {
  fail('Bitte fülle alle Pflichtfelder aus.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  fail('Bitte gib eine gültige E-Mail-Adresse an.');
}
$stelle = $STELLEN[$betreff] ?? $betreff;

/* ─── Lebenslauf (optional) prüfen & einlesen ───────────────────── */
$attachments = [];

$cvUploaded = !empty($_FILES['cv']) && ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($cvUploaded) {
  $cv = $_FILES['cv'];
  if ($cv['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($cv['tmp_name'])) {
    fail('Der Lebenslauf konnte nicht verarbeitet werden. Bitte versuche es erneut.');
  }
  if ($cv['size'] > MAX_CV_BYTES) {
    fail('Der Lebenslauf ist zu groß (max. 10 MB).');
  }
  $cvExt = strtolower(pathinfo($cv['name'], PATHINFO_EXTENSION));
  if (!in_array($cvExt, ALLOWED_CV, true)) {
    fail('Lebenslauf: Nur PDF, DOC oder DOCX erlaubt.');
  }
  $attachments[] = [
    'name' => 'Lebenslauf_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $vorname . '_' . $nachname) . '.' . $cvExt,
    'mime' => mimeFromExt($cvExt),
    'data' => file_get_contents($cv['tmp_name']),
  ];
}

/* ─── Weitere Dokumente (optional, mehrere) ─────────────────────── */
if (!empty($_FILES['docs']) && is_array($_FILES['docs']['name'])) {
  $docs = $_FILES['docs'];
  $count = count($docs['name']);
  if ($count > MAX_DOCS_COUNT) {
    fail('Zu viele weitere Dokumente (max. ' . MAX_DOCS_COUNT . ').');
  }
  $docsTotal = 0;
  for ($i = 0; $i < $count; $i++) {
    if (($docs['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if ($docs['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($docs['tmp_name'][$i])) {
      fail('Ein weiteres Dokument konnte nicht verarbeitet werden.');
    }
    $ext = strtolower(pathinfo($docs['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_DOCS, true)) {
      fail('Weitere Dokumente: Erlaubt sind PDF, DOC, DOCX, JPG, PNG.');
    }
    $docsTotal += $docs['size'][$i];
    if ($docsTotal > MAX_DOCS_BYTES) {
      fail('Die weiteren Dokumente sind zusammen zu groß (max. 20 MB).');
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $docs['name'][$i]) ?: ('Dokument_' . ($i + 1) . '.' . $ext);
    $attachments[] = [
      'name' => $safe,
      'mime' => mimeFromExt($ext),
      'data' => file_get_contents($docs['tmp_name'][$i]),
    ];
  }
}

/* ─── E-Mail-Text zusammenbauen ─────────────────────────────────── */
$textLines = [
  'Neue Bewerbung über die Karriere-Landingpage',
  '════════════════════════════════════════════',
  '',
  'Gewünschte Stelle : ' . $stelle,
  'Name              : ' . $vorname . ' ' . $nachname,
  'E-Mail            : ' . $email,
  'Telefon           : ' . ($telefon !== '' ? $telefon : '—'),
  'Eingegangen       : ' . date('d.m.Y H:i') . ' Uhr',
  '',
  'Nachricht / Anschreiben:',
  '────────────────────────',
  ($nachricht !== '' ? $nachricht : '— (keine Angabe, Schnellbewerbung)'),
  '',
  '────────────────────────',
  'Anhänge: ' . count($attachments) . ' Datei(en)',
];
$text = implode("\r\n", $textLines);

/* ─── MIME-Multipart mit Anhängen aufbauen ──────────────────────── */
$boundary = '=_PASSURA_' . bin2hex(random_bytes(12));
$fromHeader = h(FROM_NAME) . ' <' . h(FROM_EMAIL) . '>';
$replyTo    = h($vorname . ' ' . $nachname) . ' <' . $email . '>';

$subject = SUBJECT_PRE . ': ' . $stelle . ' – ' . $vorname . ' ' . $nachname;
$subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$headers  = 'From: ' . $fromHeader . "\r\n";
$headers .= 'Reply-To: ' . $replyTo . "\r\n";
$headers .= 'MIME-Version: 1.0' . "\r\n";
$headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
$headers .= 'X-Mailer: PHP/' . phpversion();

$body  = '--' . $boundary . "\r\n";
$body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
$body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
$body .= chunk_split(base64_encode($text)) . "\r\n";

foreach ($attachments as $att) {
  $body .= '--' . $boundary . "\r\n";
  $body .= 'Content-Type: ' . $att['mime'] . '; name="' . $att['name'] . '"' . "\r\n";
  $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
  $body .= 'Content-Disposition: attachment; filename="' . $att['name'] . '"' . "\r\n\r\n";
  $body .= chunk_split(base64_encode($att['data'])) . "\r\n";
}
$body .= '--' . $boundary . '--';

/* ─── Versenden ─────────────────────────────────────────────────── */
// -f setzt den Envelope-Sender (verbessert SPF/Zustellbarkeit). Auf
// manchen Hostern deaktiviert – dann fällt PHP auf den Standard zurück.
$sent = @mail(RECIPIENT, $subjectEnc, $body, $headers, '-f ' . FROM_EMAIL);

if (!$sent) {
  fail('Die Bewerbung konnte gerade nicht versendet werden. '
     . 'Bitte versuche es später erneut oder melde dich telefonisch unter ' . COMPANY_PHONE . '.', 500);
}

/* ─── Eingangsbestätigung an den Bewerber (best effort) ──────────── */
if (SEND_CONFIRMATION) {
  $confSubject = '=?UTF-8?B?' . base64_encode('Deine Bewerbung bei ' . COMPANY_NAME) . '?=';
  $confText =
    'Hallo ' . $vorname . ' ' . $nachname . ",\r\n\r\n" .
    'vielen Dank für deine Bewerbung als ' . $stelle . ' bei ' . COMPANY_NAME . ".\r\n" .
    "Wir haben deine Unterlagen erhalten und melden uns schnellstmöglich bei dir.\r\n\r\n" .
    'Bei Rückfragen erreichst du uns telefonisch unter ' . COMPANY_PHONE . ".\r\n\r\n" .
    "Herzliche Grüße\r\n" .
    'dein Team von ' . COMPANY_NAME . "\r\n\r\n" .
    "──────────────────────────────\r\n" .
    COMPANY_NAME . " · Gottlieb-Daimler-Str. 17 · 47608 Geldern\r\n" .
    "Diese E-Mail wurde automatisch erzeugt – bitte nicht direkt darauf antworten.\r\n";

  $confHeaders  = 'From: ' . h(COMPANY_NAME) . ' <' . h(FROM_EMAIL) . '>' . "\r\n";
  $confHeaders .= 'Reply-To: ' . RECIPIENT . "\r\n";
  $confHeaders .= 'MIME-Version: 1.0' . "\r\n";
  $confHeaders .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
  $confHeaders .= 'Content-Transfer-Encoding: base64';

  @mail($email, $confSubject, chunk_split(base64_encode($confText)), $confHeaders, '-f ' . FROM_EMAIL);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
