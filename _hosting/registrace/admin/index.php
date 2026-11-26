<?php
/** MEZI SMĚRY — administrace registrací */
require __DIR__ . '/../inc/lib.php';
session_boot();
$pdo = db();

$a = $_GET['a'] ?? 'prehled';
$zprava = ''; $chyba = '';

// ---------- První spuštění: založení prvního správce ----------
$pocet_adminu = (int)$pdo->query('SELECT COUNT(*) FROM admini')->fetchColumn();
if ($pocet_adminu === 0) { $a = 'setup'; }

if ($a === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pocet_adminu === 0) {
    csrf_check();
    $em = clean($_POST['email'] ?? ''); $jm = clean($_POST['jmeno'] ?? '');
    $h1 = (string)($_POST['heslo'] ?? ''); $h2 = (string)($_POST['heslo2'] ?? '');
    if (!valid_email($em)) { $chyba = 'Zadejte platný e-mail.'; }
    elseif (strlen($h1) < 10) { $chyba = 'Heslo musí mít alespoň 10 znaků.'; }
    elseif ($h1 !== $h2) { $chyba = 'Hesla se neshodují.'; }
    else {
        $pdo->prepare('INSERT INTO admini (email, jmeno, heslo) VALUES (?,?,?)')
            ->execute([$em, $jm, password_hash($h1, PASSWORD_DEFAULT)]);
        $_SESSION['admin'] = ['id' => (int)$pdo->lastInsertId(), 'email' => $em, 'jmeno' => $jm];
        header('Location: ?a=prehled'); exit;
    }
}

// ---------- Přihlášení / odhlášení ----------
if ($a === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    usleep(400000); // zpomalení hádání hesel
    $st = $pdo->prepare('SELECT * FROM admini WHERE email = ?');
    $st->execute([clean($_POST['email'] ?? '')]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u && password_verify((string)($_POST['heslo'] ?? ''), $u['heslo'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => (int)$u['id'], 'email' => $u['email'], 'jmeno' => $u['jmeno']];
        header('Location: ?a=prehled'); exit;
    }
    $chyba = 'Nesprávný e-mail nebo heslo.';
}
if ($a === 'logout') { session_destroy(); header('Location: ?a=login'); exit; }

// ---------- Chráněné akce ----------
$admin = null;
if (!in_array($a, ['login', 'setup'], true)) { $admin = require_admin(); }

$rocniky = $pdo->query("SELECT DISTINCT rocnik FROM registrace ORDER BY rocnik DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(AKTUALNI_ROCNIK, $rocniky, true)) { array_unshift($rocniky, AKTUALNI_ROCNIK); }

if ($admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($a === 'pridat') {
        $em = clean($_POST['email'] ?? '');
        if (!valid_email($em)) { $chyba = 'Zadejte platný e-mail.'; }
        elseif (clean($_POST['jmeno'] ?? '') === '' || clean($_POST['prijmeni'] ?? '') === '') { $chyba = 'Vyplňte jméno a příjmení.'; }
        else {
            $v = zaregistruj(
                ['email'=>$em, 'titul'=>clean($_POST['titul'] ?? ''), 'jmeno'=>clean($_POST['jmeno'] ?? ''),
                 'prijmeni'=>clean($_POST['prijmeni'] ?? ''), 'telefon'=>clean($_POST['telefon'] ?? ''),
                 'specializace'=>clean($_POST['specializace'] ?? '')],
                clean($_POST['rocnik'] ?? AKTUALNI_ROCNIK) ?: AKTUALNI_ROCNIK, 'manuálně');
            $zprava = $v['duplicitni'] ? 'Účastník už byl na tento ročník registrovaný — údaje byly doplněny.' : 'Účastník byl zaregistrován.';
            $a = 'prehled';
        }
    }

    if ($a === 'import') {
        $rocnik = clean($_POST['rocnik'] ?? '') ?: AKTUALNI_ROCNIK;
        $data = '';
        if (!empty($_FILES['soubor']['tmp_name'])) { $data = (string)file_get_contents($_FILES['soubor']['tmp_name']); }
        if ($data === '') { $data = (string)($_POST['data'] ?? ''); }
        $data = preg_replace('/^\xEF\xBB\xBF/', '', $data); // BOM
        $novych = 0; $duplicit = 0; $spatnych = 0;
        foreach (preg_split('/\r\n|\r|\n/', $data) as $radek) {
            $radek = trim($radek);
            if ($radek === '') continue;
            $odd = (substr_count($radek, ';') >= substr_count($radek, ',')) ? ';' : ',';
            $c = array_map('trim', str_getcsv($radek, $odd, '"', '\\'));
            // formát: email;titul;jmeno;prijmeni;telefon  (hlavičku přeskočit)
            if (!isset($c[0]) || !valid_email($c[0])) { if (stripos($radek, 'mail') === false) $spatnych++; continue; }
            $v = zaregistruj(
                ['email'=>$c[0], 'titul'=>clean($c[1] ?? ''), 'jmeno'=>clean($c[2] ?? ''),
                 'prijmeni'=>clean($c[3] ?? ($c[2] ?? '')), 'telefon'=>clean($c[4] ?? ''), 'specializace'=>clean($c[5] ?? '')],
                $rocnik, 'import');
            $v['duplicitni'] ? $duplicit++ : $novych++;
        }
        $zprava = "Import ročníku $rocnik: $novych nových, $duplicit už existovalo" . ($spatnych ? ", $spatnych řádků přeskočeno (neplatný e-mail)" : '') . '.';
        $a = 'prehled';
    }

    if ($a === 'stav') {
        $povolene = ['nová','potvrzená','zaplacená','zrušená'];
        $novy = $_POST['stav'] ?? '';
        if (in_array($novy, $povolene, true)) {
            $pdo->prepare('UPDATE registrace SET stav = ? WHERE id = ?')->execute([$novy, (int)($_POST['id'] ?? 0)]);
        }
        header('Location: ?a=prehled&rocnik=' . urlencode($_POST['zpet_rocnik'] ?? '')); exit;
    }

    if ($a === 'smazat') {
        $pdo->prepare('DELETE FROM registrace WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        // účastníky bez registrací nechávám v databázi kontaktů záměrně
        header('Location: ?a=prehled&rocnik=' . urlencode($_POST['zpet_rocnik'] ?? '')); exit;
    }

    if ($a === 'spravci-pridat') {
        $em = clean($_POST['email'] ?? ''); $h = (string)($_POST['heslo'] ?? '');
        if (!valid_email($em)) { $chyba = 'Zadejte platný e-mail.'; }
        elseif (strlen($h) < 10) { $chyba = 'Heslo musí mít alespoň 10 znaků.'; }
        else {
            try {
                $pdo->prepare('INSERT INTO admini (email, jmeno, heslo) VALUES (?,?,?)')
                    ->execute([$em, clean($_POST['jmeno'] ?? ''), password_hash($h, PASSWORD_DEFAULT)]);
                $zprava = 'Přístup vytvořen. Předejte přihlašovací údaje bezpečnou cestou a požádejte o změnu hesla po prvním přihlášení.';
            } catch (PDOException $ex) { $chyba = 'Tento e-mail už přístup má.'; }
        }
        $a = 'spravci';
    }

    if ($a === 'spravci-smazat') {
        if ((int)($_POST['id'] ?? 0) === (int)$admin['id']) { $chyba = 'Nemůžete smazat sami sebe.'; }
        elseif ((int)$pdo->query('SELECT COUNT(*) FROM admini')->fetchColumn() <= 1) { $chyba = 'Poslední přístup nelze smazat.'; }
        else { $pdo->prepare('DELETE FROM admini WHERE id = ?')->execute([(int)$_POST['id']]); $zprava = 'Přístup smazán.'; }
        $a = 'spravci';
    }

    if ($a === 'heslo') {
        $h1 = (string)($_POST['heslo'] ?? '');
        if (strlen($h1) < 10) { $chyba = 'Heslo musí mít alespoň 10 znaků.'; }
        elseif ($h1 !== (string)($_POST['heslo2'] ?? '')) { $chyba = 'Hesla se neshodují.'; }
        else {
            $pdo->prepare('UPDATE admini SET heslo = ? WHERE id = ?')
                ->execute([password_hash($h1, PASSWORD_DEFAULT), (int)$admin['id']]);
            $zprava = 'Heslo změněno.'; $a = 'prehled';
        }
    }
}

// ---------- Export CSV ----------
if ($admin && $a === 'export') {
    $rocnik = clean($_GET['rocnik'] ?? '');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mezismery-ucastnici' . ($rocnik ? "-$rocnik" : '') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM pro Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email','titul','jmeno','prijmeni','telefon','specializace','rocnik','stav','zdroj','registrovano'], ';', '"', '\\');
    $sql = 'SELECT u.email,u.titul,u.jmeno,u.prijmeni,u.telefon,u.specializace,r.rocnik,r.stav,r.zdroj,r.vytvoreno
            FROM registrace r JOIN ucastnici u ON u.id = r.ucastnik_id';
    $par = [];
    if ($rocnik) { $sql .= ' WHERE r.rocnik = ?'; $par[] = $rocnik; }
    $sql .= ' ORDER BY r.vytvoreno DESC';
    $st = $pdo->prepare($sql); $st->execute($par);
    while ($row = $st->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row, ';', '"', '\\'); }
    exit;
}

// ---------- Data pro zobrazení ----------
$vybrany_rocnik = clean($_GET['rocnik'] ?? '');
$hledat = clean($_GET['q'] ?? '');

function nacti_registrace(PDO $pdo, string $rocnik, string $q): array {
    $sql = 'SELECT r.id, r.rocnik, r.stav, r.zdroj, r.vytvoreno, r.fakt_nazev, r.fakt_ico,
                   u.id AS uid, u.email, u.titul, u.jmeno, u.prijmeni, u.telefon, u.specializace,
                   (SELECT COUNT(*) FROM registrace r2 WHERE r2.ucastnik_id = u.id) AS pocet_rocniku
            FROM registrace r JOIN ucastnici u ON u.id = r.ucastnik_id WHERE 1=1';
    $par = [];
    if ($rocnik) { $sql .= ' AND r.rocnik = ?'; $par[] = $rocnik; }
    if ($q) { $sql .= " AND (u.email LIKE ? OR u.jmeno LIKE ? OR u.prijmeni LIKE ?)"; $w = "%$q%"; array_push($par, $w, $w, $w); }
    $sql .= ' ORDER BY r.vytvoreno DESC LIMIT 1000';
    $st = $pdo->prepare($sql); $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

$stavy_barvy = ['nová'=>'#f6c344','potvrzená'=>'#4f8ef6','zaplacená'=>'#3bb273','zrušená'=>'#d9534f'];
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administrace — MEZI SMĚRY</title>
<meta name="robots" content="noindex,nofollow">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#F4F4F4;--pink:#FFB3C1;--blue:#0027BA;--navy:#000A32;--radius:16px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Space Grotesk",sans-serif;background:var(--bg);color:#1a1a2e;font-size:15px}
a{color:var(--blue)}
.top{background:var(--blue);color:var(--pink);padding:14px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.top .logo{font-size:19px;letter-spacing:-.03em;text-transform:uppercase;color:var(--pink);text-decoration:none;font-weight:500}
.top nav{display:flex;gap:4px;flex-wrap:wrap}
.top nav a{color:var(--pink);text-decoration:none;font-size:13px;text-transform:uppercase;letter-spacing:.03em;padding:8px 14px;border-radius:999px}
.top nav a.akt,.top nav a:hover{background:rgba(255,179,193,.18)}
.top .kdo{margin-left:auto;font-size:12.5px;opacity:.8}
.wrap{max-width:1180px;margin:26px auto;padding:0 16px}
h1{font-weight:400;text-transform:uppercase;letter-spacing:-.04em;font-size:30px;color:var(--blue);margin-bottom:18px}
.karta{background:#fff;border-radius:var(--radius);padding:22px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,10,50,.06)}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{Text-align:left;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#777;padding:8px 10px;border-bottom:2px solid #eee}
td{padding:9px 10px;border-bottom:1px solid #f0f0f3;vertical-align:middle}
tr:hover td{background:#fafafe}
.stitek{display:inline-block;color:#fff;border-radius:999px;padding:2px 10px;font-size:11.5px}
.op{display:inline-block;background:var(--pink);color:var(--blue);border-radius:999px;padding:2px 9px;font-size:11.5px;font-weight:500}
.filtry{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
input,select,textarea{font-family:inherit;font-size:14px;padding:10px 12px;border:1px solid #d8d8e2;border-radius:10px;background:#fff}
.btn{display:inline-block;background:var(--blue);color:var(--pink);border:0;border-radius:999px;padding:11px 20px;font-family:inherit;font-size:13px;text-transform:uppercase;letter-spacing:.03em;cursor:pointer;text-decoration:none}
.btn:hover{background:#002099}
.btn2{background:transparent;color:var(--blue);border:1px solid var(--blue)}
.btn-mini{font-size:11px;padding:5px 10px}
.ok,.err{border-radius:10px;padding:13px 16px;margin-bottom:14px}
.ok{background:#d9f2e4;color:#0a5c35}.err{background:#ffe1e1;color:#8f1d1d}
.mrizka{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-bottom:16px}
.dlazdice{background:var(--blue);color:var(--pink);border-radius:var(--radius);padding:18px 20px}
.dlazdice b{display:block;font-size:34px;font-weight:400;letter-spacing:-.04em}
.dlazdice span{font-size:12px;text-transform:uppercase;letter-spacing:.05em;opacity:.75}
form.inline{display:inline}
label.b{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#666;margin:12px 0 6px}
.radek{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.pozn{font-size:12.5px;color:#888;margin-top:8px}
.login{max-width:420px;margin:60px auto}
code{background:#eef;padding:2px 6px;border-radius:6px;font-size:12.5px}
@media(max-width:760px){.skryt-mobil{display:none}}
</style>
</head>
<body>

<?php if ($a === 'setup'): ?>
<div class="wrap login">
  <div class="karta">
    <h1>První spuštění</h1>
    <p style="margin-bottom:14px">Vytvořte si první správcovský účet administrace registrací MEZI SMĚRY.</p>
    <?php if ($chyba): ?><div class="err"><?=e($chyba)?></div><?php endif; ?>
    <form method="post" action="?a=setup">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <label class="b">Jméno</label><input type="text" name="jmeno" style="width:100%">
      <label class="b">E-mail</label><input type="email" name="email" required style="width:100%">
      <label class="b">Heslo (min. 10 znaků)</label><input type="password" name="heslo" required style="width:100%">
      <label class="b">Heslo znovu</label><input type="password" name="heslo2" required style="width:100%">
      <p style="margin-top:16px"><button class="btn">Vytvořit účet</button></p>
    </form>
  </div>
</div>

<?php elseif (!$admin): ?>
<div class="wrap login">
  <div class="karta">
    <h1>Přihlášení</h1>
    <?php if ($chyba): ?><div class="err"><?=e($chyba)?></div><?php endif; ?>
    <form method="post" action="?a=login">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <label class="b">E-mail</label><input type="email" name="email" required style="width:100%">
      <label class="b">Heslo</label><input type="password" name="heslo" required style="width:100%">
      <p style="margin-top:16px"><button class="btn">Přihlásit se</button></p>
    </form>
  </div>
</div>

<?php else: ?>
<div class="top">
  <a class="logo" href="?a=prehled">MEZI/SMĚRY — administrace</a>
  <nav>
    <a href="?a=prehled" class="<?= $a==='prehled'?'akt':'' ?>">Registrace</a>
    <a href="?a=opakovani" class="<?= $a==='opakovani'?'akt':'' ?>">Opakovaní účastníci</a>
    <a href="?a=pridat" class="<?= $a==='pridat'?'akt':'' ?>">Přidat ručně</a>
    <a href="?a=import" class="<?= $a==='import'?'akt':'' ?>">Import</a>
    <a href="?a=spravci" class="<?= $a==='spravci'?'akt':'' ?>">Přístupy</a>
  </nav>
  <span class="kdo"><?=e($admin['email'])?> · <a style="color:var(--pink)" href="?a=heslo">změna hesla</a> · <a style="color:var(--pink)" href="?a=logout">odhlásit</a></span>
</div>
<div class="wrap">
  <?php if ($zprava): ?><div class="ok"><?=e($zprava)?></div><?php endif; ?>
  <?php if ($chyba): ?><div class="err"><?=e($chyba)?></div><?php endif; ?>

  <?php if ($a === 'prehled'): ?>
    <h1>Registrace</h1>
    <?php
      $celkem = (int)$pdo->query('SELECT COUNT(*) FROM registrace')->fetchColumn();
      $aktualni = $pdo->prepare('SELECT COUNT(*) FROM registrace WHERE rocnik = ?');
      $aktualni->execute([AKTUALNI_ROCNIK]);
      $aktualni = (int)$aktualni->fetchColumn();
      $kontaktu = (int)$pdo->query('SELECT COUNT(*) FROM ucastnici')->fetchColumn();
      $vraceji = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT ucastnik_id FROM registrace GROUP BY ucastnik_id HAVING COUNT(*) > 1)')->fetchColumn();
    ?>
    <div class="mrizka">
      <div class="dlazdice"><b><?=$aktualni?></b><span>ročník <?=e(AKTUALNI_ROCNIK)?></span></div>
      <div class="dlazdice"><b><?=$celkem?></b><span>registrací celkem</span></div>
      <div class="dlazdice"><b><?=$kontaktu?></b><span>kontaktů v databázi</span></div>
      <div class="dlazdice"><b><?=$vraceji?></b><span>vracejících se účastníků</span></div>
    </div>

    <div class="karta">
      <form class="filtry" method="get" action="">
        <input type="hidden" name="a" value="prehled">
        <select name="rocnik" onchange="this.form.submit()">
          <option value="">Všechny ročníky</option>
          <?php foreach ($rocniky as $r): ?><option value="<?=e($r)?>" <?= $vybrany_rocnik===$r?'selected':'' ?>>Ročník <?=e($r)?></option><?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?=e($hledat)?>" placeholder="Hledat jméno / e-mail…">
        <button class="btn btn2 btn-mini" type="submit">Hledat</button>
        <a class="btn btn-mini" style="margin-left:auto" href="?a=export&rocnik=<?=e($vybrany_rocnik)?>">⬇ Export CSV<?= $vybrany_rocnik ? ' ('.e($vybrany_rocnik).')' : '' ?></a>
      </form>
      <table>
        <tr><th>Jméno</th><th>E-mail</th><th class="skryt-mobil">Telefon</th><th>Ročník</th><th>Stav</th><th class="skryt-mobil">Zdroj</th><th class="skryt-mobil">Účasti</th><th class="skryt-mobil">Vytvořeno</th><th></th></tr>
        <?php foreach (nacti_registrace($pdo, $vybrany_rocnik, $hledat) as $r): ?>
        <tr>
          <td><?=e(trim($r['titul'].' '.$r['jmeno'].' '.$r['prijmeni']))?><?= $r['fakt_ico'] ? ' <span title="Má fakturační údaje">🧾</span>' : '' ?></td>
          <td><a href="mailto:<?=e($r['email'])?>"><?=e($r['email'])?></a></td>
          <td class="skryt-mobil"><?=e($r['telefon'])?></td>
          <td><?=e($r['rocnik'])?></td>
          <td>
            <form class="inline" method="post" action="?a=stav">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="zpet_rocnik" value="<?=e($vybrany_rocnik)?>">
              <select name="stav" onchange="this.form.submit()" style="padding:4px 6px;font-size:12px;border-radius:999px;background:<?=$stavy_barvy[$r['stav']]??'#999'?>;color:#fff;border:0">
                <?php foreach (['nová','potvrzená','zaplacená','zrušená'] as $s): ?>
                  <option value="<?=$s?>" <?= $r['stav']===$s?'selected':'' ?>><?=$s?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="skryt-mobil"><?=e($r['zdroj'])?></td>
          <td class="skryt-mobil"><?= $r['pocet_rocniku'] > 1 ? '<span class="op">'.$r['pocet_rocniku'].'×</span>' : '1×' ?></td>
          <td class="skryt-mobil" style="white-space:nowrap"><?=e(substr($r['vytvoreno'],0,16))?></td>
          <td>
            <form class="inline" method="post" action="?a=smazat" onsubmit="return confirm('Smazat registraci <?=e($r['email'])?> (<?=e($r['rocnik'])?>)?')">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="zpet_rocnik" value="<?=e($vybrany_rocnik)?>">
              <button class="btn btn2 btn-mini" style="border-color:#d9534f;color:#d9534f">✕</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <p class="pozn">🧾 = účastník vyplnil fakturační údaje (zobrazí se v exportu a připravují se pro automatickou fakturaci).</p>
    </div>

  <?php elseif ($a === 'opakovani'): ?>
    <h1>Opakovaní účastníci</h1>
    <div class="karta">
      <table>
        <tr><th>Jméno</th><th>E-mail</th><th>Počet účastí</th><th>Ročníky</th></tr>
        <?php
        $st = $pdo->query("SELECT u.titul,u.jmeno,u.prijmeni,u.email,COUNT(*) pocet,GROUP_CONCAT(r.rocnik, ', ') rocniky
                           FROM registrace r JOIN ucastnici u ON u.id=r.ucastnik_id
                           GROUP BY u.id HAVING COUNT(*) > 1 ORDER BY pocet DESC, u.prijmeni");
        foreach ($st as $r): ?>
        <tr>
          <td><?=e(trim($r['titul'].' '.$r['jmeno'].' '.$r['prijmeni']))?></td>
          <td><a href="mailto:<?=e($r['email'])?>"><?=e($r['email'])?></a></td>
          <td><span class="op"><?=$r['pocet']?>×</span></td>
          <td><?=e($r['rocniky'])?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <p class="pozn">Účastníci se dvěma a více ročníky — ideální cílová skupina pro e-mail o novém ročníku.</p>
    </div>

  <?php elseif ($a === 'pridat'): ?>
    <h1>Přidat účastníka ručně</h1>
    <div class="karta">
      <form method="post" action="?a=pridat">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <div class="radek">
          <div><label class="b">Titul</label><input type="text" name="titul" style="width:100%"></div>
          <div><label class="b">Jméno *</label><input type="text" name="jmeno" required style="width:100%"></div>
          <div><label class="b">Příjmení *</label><input type="text" name="prijmeni" required style="width:100%"></div>
        </div>
        <div class="radek">
          <div><label class="b">E-mail *</label><input type="email" name="email" required style="width:100%"></div>
          <div><label class="b">Telefon</label><input type="text" name="telefon" style="width:100%"></div>
          <div><label class="b">Specializace</label><input type="text" name="specializace" style="width:100%"></div>
        </div>
        <label class="b">Ročník</label>
        <select name="rocnik"><?php foreach ($rocniky as $r): ?><option <?= $r===AKTUALNI_ROCNIK?'selected':'' ?>><?=e($r)?></option><?php endforeach; ?><option>2025</option><option>2026</option></select>
        <p style="margin-top:16px"><button class="btn">Uložit registraci</button></p>
        <p class="pozn">Potvrzovací e-mail se při ručním přidání neposílá — hodí se pro telefonické přihlášky.</p>
      </form>
    </div>

  <?php elseif ($a === 'import'): ?>
    <h1>Import účastníků</h1>
    <div class="karta">
      <form method="post" action="?a=import" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label class="b">Ročník, ke kterému se import vztahuje</label>
        <select name="rocnik"><option>2025</option><option>2026</option><option selected><?=e(AKTUALNI_ROCNIK)?></option></select>
        <label class="b">CSV soubor</label>
        <input type="file" name="soubor" accept=".csv,.txt">
        <label class="b">… nebo vložte data sem</label>
        <textarea name="data" rows="8" style="width:100%" placeholder="email;titul;jmeno;prijmeni;telefon
jan.novak@email.cz;MUDr.;Jan;Novák;+420601234567"></textarea>
        <p style="margin-top:16px"><button class="btn">Importovat</button></p>
        <p class="pozn">Formát: <code>email;titul;jmeno;prijmeni;telefon</code> (oddělovač ; nebo ,). Duplicitní e-maily se nespojují dvakrát — u existujících kontaktů se jen přidá ročník. Importované registrace dostanou stav „potvrzená“ a zdroj „import“.</p>
      </form>
    </div>

  <?php elseif ($a === 'spravci'): ?>
    <h1>Přístupy do administrace</h1>
    <div class="karta">
      <table>
        <tr><th>Jméno</th><th>E-mail</th><th>Vytvořeno</th><th></th></tr>
        <?php foreach ($pdo->query('SELECT * FROM admini ORDER BY id') as $u): ?>
        <tr>
          <td><?=e($u['jmeno'])?></td>
          <td><?=e($u['email'])?></td>
          <td><?=e(substr($u['vytvoreno'],0,16))?></td>
          <td>
            <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
            <form class="inline" method="post" action="?a=spravci-smazat" onsubmit="return confirm('Smazat přístup <?=e($u['email'])?>?')">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="id" value="<?=$u['id']?>">
              <button class="btn btn2 btn-mini" style="border-color:#d9534f;color:#d9534f">smazat</button>
            </form>
            <?php else: ?><span class="pozn">(vy)</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="karta">
      <h1 style="font-size:20px">Nový přístup (majitelé, agentura…)</h1>
      <form method="post" action="?a=spravci-pridat">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <div class="radek">
          <div><label class="b">Jméno</label><input type="text" name="jmeno" style="width:100%"></div>
          <div><label class="b">E-mail *</label><input type="email" name="email" required style="width:100%"></div>
          <div><label class="b">Dočasné heslo * (min. 10 znaků)</label><input type="text" name="heslo" required style="width:100%"></div>
        </div>
        <p style="margin-top:16px"><button class="btn">Vytvořit přístup</button></p>
        <p class="pozn">Heslo předejte bezpečnou cestou (ne e-mailem společně s adresou administrace). Nový uživatel si ho může změnit v „změna hesla“.</p>
      </form>
    </div>

  <?php elseif ($a === 'heslo'): ?>
    <h1>Změna hesla</h1>
    <div class="karta" style="max-width:420px">
      <form method="post" action="?a=heslo">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label class="b">Nové heslo (min. 10 znaků)</label><input type="password" name="heslo" required style="width:100%">
        <label class="b">Nové heslo znovu</label><input type="password" name="heslo2" required style="width:100%">
        <p style="margin-top:16px"><button class="btn">Změnit heslo</button></p>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
