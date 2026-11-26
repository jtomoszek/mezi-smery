<?php
/**
 * MEZI SMĚRY — registrační systém
 * Sdílená knihovna: konfigurace, DB (SQLite), auth, pomocné funkce.
 */

declare(strict_types=1);

// ---------- Konfigurace ----------
const AKTUALNI_ROCNIK   = '2027';
const NAZEV_AKCE        = 'MEZI SMĚRY 3';
const TERMIN_AKCE       = '19.–20. 3. 2027';
const MISTO_AKCE        = 'Ibis Styles Relax, Rožnov pod Radhoštěm';
const MAIL_ORGANIZATOR  = 'vanek@4educa.cz';
const MAIL_FROM         = 'registrace@mezismery.cz';
const WEB_URL           = 'https://mezismery.cz';
const CENA_VCAS         = '3 800 Kč (do 11. 3. 2027)';
const CENA_POZDE        = '4 500 Kč (od 12. 3. 2027)';

// ---------- DB ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        // ochrana dat: .htaccess
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $pdo = new PDO('sqlite:' . $dir . '/registrace.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
    }
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ucastnici (
        id INTEGER PRIMARY KEY,
        email TEXT UNIQUE NOT NULL COLLATE NOCASE,
        titul TEXT DEFAULT '',
        jmeno TEXT NOT NULL,
        prijmeni TEXT NOT NULL,
        telefon TEXT DEFAULT '',
        specializace TEXT DEFAULT '',
        poznamka TEXT DEFAULT '',
        vytvoreno TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS registrace (
        id INTEGER PRIMARY KEY,
        ucastnik_id INTEGER NOT NULL REFERENCES ucastnici(id) ON DELETE CASCADE,
        rocnik TEXT NOT NULL,
        stav TEXT DEFAULT 'nová',          -- nová / potvrzená / zaplacená / zrušená
        zdroj TEXT DEFAULT 'web',          -- web / manuálně / import
        fakt_nazev TEXT DEFAULT '',
        fakt_ico TEXT DEFAULT '',
        fakt_dic TEXT DEFAULT '',
        fakt_adresa TEXT DEFAULT '',
        cislo_faktury TEXT DEFAULT '',     -- připraveno pro napojení fakturace
        poznamka TEXT DEFAULT '',
        vytvoreno TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE(ucastnik_id, rocnik)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admini (
        id INTEGER PRIMARY KEY,
        email TEXT UNIQUE NOT NULL COLLATE NOCASE,
        jmeno TEXT DEFAULT '',
        heslo TEXT NOT NULL,
        vytvoreno TEXT DEFAULT (datetime('now','localtime'))
    )");
}

// ---------- Pomocné ----------
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function clean(string $s, int $max = 300): string {
    $s = trim(preg_replace('/[\r\n\t]+/', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function valid_email(string $s): bool {
    return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(400);
        exit('Neplatný požadavek (CSRF).');
    }
}

// ---------- Auth ----------
function session_boot(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
}
function admin_logged(): ?array {
    session_boot();
    return $_SESSION['admin'] ?? null;
}
function require_admin(): array {
    $a = admin_logged();
    if (!$a) { header('Location: ?a=login'); exit; }
    return $a;
}

// ---------- E-maily ----------
function posli_mail(string $komu, string $predmet, string $telo, string $replyTo = ''): bool {
    $hlavicky = [
        'From: ' . NAZEV_AKCE . ' <' . MAIL_FROM . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo && valid_email($replyTo)) { $hlavicky[] = 'Reply-To: ' . $replyTo; }
    $predmet_enc = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
    return @mail($komu, $predmet_enc, $telo, implode("\r\n", $hlavicky));
}

// ---------- Registrace (sdílená logika: web / manuál / import) ----------
/**
 * Založí/aktualizuje účastníka podle e-mailu a přidá registraci na ročník.
 * Vrací ['ok'=>bool, 'duplicitni'=>bool, 'ucastnik_id'=>int]
 */
function zaregistruj(array $u, string $rocnik, string $zdroj, array $fakt = []): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM ucastnici WHERE email = ?');
    $st->execute([$u['email']]);
    $id = $st->fetchColumn();

    if ($id) {
        // doplnit chybějící údaje, nepřepisovat vyplněné novými prázdnými
        $pdo->prepare("UPDATE ucastnici SET
            titul = CASE WHEN :titul != '' THEN :titul ELSE titul END,
            jmeno = CASE WHEN :jmeno != '' THEN :jmeno ELSE jmeno END,
            prijmeni = CASE WHEN :prijmeni != '' THEN :prijmeni ELSE prijmeni END,
            telefon = CASE WHEN :telefon != '' THEN :telefon ELSE telefon END,
            specializace = CASE WHEN :spec != '' THEN :spec ELSE specializace END
            WHERE id = :id")->execute([
                ':titul' => $u['titul'], ':jmeno' => $u['jmeno'], ':prijmeni' => $u['prijmeni'],
                ':telefon' => $u['telefon'], ':spec' => $u['specializace'], ':id' => $id]);
    } else {
        $pdo->prepare('INSERT INTO ucastnici (email, titul, jmeno, prijmeni, telefon, specializace)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$u['email'], $u['titul'], $u['jmeno'], $u['prijmeni'], $u['telefon'], $u['specializace']]);
        $id = (int)$pdo->lastInsertId();
    }

    try {
        $pdo->prepare('INSERT INTO registrace (ucastnik_id, rocnik, zdroj, fakt_nazev, fakt_ico, fakt_dic, fakt_adresa, stav)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $rocnik, $zdroj,
                       $fakt['nazev'] ?? '', $fakt['ico'] ?? '', $fakt['dic'] ?? '', $fakt['adresa'] ?? '',
                       $zdroj === 'import' ? 'potvrzená' : 'nová']);
    } catch (PDOException $ex) {
        return ['ok' => true, 'duplicitni' => true, 'ucastnik_id' => (int)$id];
    }
    return ['ok' => true, 'duplicitni' => false, 'ucastnik_id' => (int)$id];
}
