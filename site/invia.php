<?php
/* House of Dogs - gestore form prenotazione.
   Invia la richiesta via email a houseofdogdigiuseppe@outlook.it.
   Nota per l'hosting: mail() deve essere abilitata sul server.
   Se le email non arrivano, configurare un SMTP nel pannello hosting. */

declare(strict_types=1);

const DESTINATARIO = 'houseofdogdigiuseppe@outlook.it';

/* Lunghezza e taglio sicuri anche senza estensione mbstring. */
function hod_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
function hod_cut(string $s, int $max): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function rispondi(bool $ok, string $msg): void {
    $wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        $flag = $ok ? 'inviato=1' : 'errore=1';
        header('Location: ./?' . $flag . '#contatti');
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./');
    exit;
}

/* Honeypot: il campo "sito_web" e' invisibile agli umani. Se e' pieno, e' un bot. */
if (!empty($_POST['sito_web'] ?? '')) {
    rispondi(true, 'Grazie! Ti ricontattiamo al piu presto.');
}

$nome     = trim((string)($_POST['nome'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$razza    = trim((string)($_POST['razza'] ?? ''));
$messaggio = trim((string)($_POST['messaggio'] ?? ''));
$privacy  = isset($_POST['privacy']);

if ($nome === '' || hod_len($nome) > 120) {
    rispondi(false, 'Inserisci il tuo nome.');
}
if ($telefono === '' && $email === '') {
    rispondi(false, 'Lascia un telefono o una email per ricontattarti.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    rispondi(false, 'L\'email non sembra valida, ricontrollala.');
}
if (!$privacy) {
    rispondi(false, 'Serve il consenso privacy per inviare la richiesta.');
}
if (hod_len($messaggio) > 3000) {
    $messaggio = hod_cut($messaggio, 3000);
}

$razzeValide = ['Barboncino Toy', 'Maltipoo Toy', 'Maltese Toy', 'Bassotto Kaninchen', 'Non so ancora'];
if (!in_array($razza, $razzeValide, true)) {
    $razza = 'Non specificata';
}

$oggetto = 'Nuova richiesta cucciolo dal sito: ' . $razza;
$corpo = "Nuova richiesta dal sito thehouseofdogs.it\n\n"
       . "Nome: {$nome}\n"
       . "Telefono: " . ($telefono !== '' ? $telefono : 'non indicato') . "\n"
       . "Email: " . ($email !== '' ? $email : 'non indicata') . "\n"
       . "Razza di interesse: {$razza}\n\n"
       . "Messaggio:\n" . ($messaggio !== '' ? $messaggio : '(nessun messaggio)') . "\n\n"
       . "Inviato il: " . date('d/m/Y H:i') . "\n"
       . "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'sconosciuto') . "\n";

$dominio = $_SERVER['SERVER_NAME'] ?? 'thehouseofdogs.it';
$headers = 'From: House of Dogs <noreply@' . $dominio . '>' . "\r\n"
         . ($email !== '' ? 'Reply-To: ' . $email . "\r\n" : '')
         . 'X-Mailer: PHP/' . phpversion() . "\r\n"
         . 'Content-Type: text/plain; charset=UTF-8';

$inviata = @mail(DESTINATARIO, '=?UTF-8?B?' . base64_encode($oggetto) . '?=', $corpo, $headers);

if ($inviata) {
    rispondi(true, 'Richiesta inviata! Ti ricontattiamo entro poche ore.');
}

/* mail() non disponibile: salva la richiesta su file cosi non si perde nulla. */
$riga = json_encode([
    'quando' => date('c'), 'nome' => $nome, 'telefono' => $telefono,
    'email' => $email, 'razza' => $razza, 'messaggio' => $messaggio,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
/* Prefisso .ht: Apache nega l'accesso web ai file .ht* per default. */
@file_put_contents(__DIR__ . '/.ht_richieste.log', $riga, FILE_APPEND | LOCK_EX);

rispondi(true, 'Richiesta registrata! Ti ricontattiamo entro poche ore.');
