<?php
/** MEZI SMĚRY — veřejný registrační formulář */
require __DIR__ . '/inc/lib.php';
session_boot();

$odeslano = false; $chyby = []; $duplicitni = false;
$f = ['titul'=>'','jmeno'=>'','prijmeni'=>'','email'=>'','telefon'=>'','specializace'=>'',
      'fakt_nazev'=>'','fakt_ico'=>'','fakt_dic'=>'','fakt_adresa'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // honeypot – skryté pole, které vyplní jen robot
    if (!empty($_POST['web'])) { exit('OK'); }

    foreach ($f as $k => $v) { $f[$k] = clean((string)($_POST[$k] ?? '')); }

    if ($f['jmeno'] === '')            $chyby[] = 'Vyplňte prosím jméno.';
    if ($f['prijmeni'] === '')         $chyby[] = 'Vyplňte prosím příjmení.';
    if (!valid_email($f['email']))     $chyby[] = 'Vyplňte prosím platný e-mail.';
    if (empty($_POST['souhlas']))      $chyby[] = 'Pro registraci je nutný souhlas se zpracováním osobních údajů.';

    if (!$chyby) {
        $vysledek = zaregistruj(
            ['email'=>$f['email'], 'titul'=>$f['titul'], 'jmeno'=>$f['jmeno'], 'prijmeni'=>$f['prijmeni'],
             'telefon'=>$f['telefon'], 'specializace'=>$f['specializace']],
            AKTUALNI_ROCNIK, 'web',
            ['nazev'=>$f['fakt_nazev'], 'ico'=>$f['fakt_ico'], 'dic'=>$f['fakt_dic'], 'adresa'=>$f['fakt_adresa']]
        );
        $odeslano = true;
        $duplicitni = $vysledek['duplicitni'];

        if (!$duplicitni) {
            $cele_jmeno = trim($f['titul'] . ' ' . $f['jmeno'] . ' ' . $f['prijmeni']);
            // e-mail organizátorovi
            posli_mail(MAIL_ORGANIZATOR,
                'Nová registrace — ' . NAZEV_AKCE . ': ' . $cele_jmeno,
                "Nová registrace na " . NAZEV_AKCE . " (" . TERMIN_AKCE . ")\n\n"
                . "Jméno: $cele_jmeno\nE-mail: {$f['email']}\nTelefon: {$f['telefon']}\n"
                . "Specializace: {$f['specializace']}\n\n"
                . "Fakturační údaje:\n"
                . "Název: {$f['fakt_nazev']}\nIČO: {$f['fakt_ico']}\nDIČ: {$f['fakt_dic']}\nAdresa: {$f['fakt_adresa']}\n\n"
                . "Administrace: https://registrace.mezismery.cz/admin/",
                $f['email']);
            // potvrzení účastníkovi
            posli_mail($f['email'],
                'Potvrzení registrace — ' . NAZEV_AKCE,
                "Dobrý den,\n\nděkujeme za Vaši registraci na " . NAZEV_AKCE
                . " (" . TERMIN_AKCE . ", " . MISTO_AKCE . ").\n\n"
                . "Registrační poplatek: " . CENA_VCAS . " / " . CENA_POZDE . "\n"
                . "Pokyny k platbě Vám zašleme v samostatném e-mailu.\n\n"
                . "V případě dotazů se obraťte na " . MAIL_ORGANIZATOR . " nebo +420 604 289 352.\n\n"
                . "Těšíme se na Vás!\nTým " . NAZEV_AKCE . "\n" . WEB_URL,
                MAIL_ORGANIZATOR);
        }
    }
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrace — <?=e(NAZEV_AKCE)?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#F4F4F4;--pink:#FFB3C1;--blue:#0027BA;--blue-dark:#002099;--radius:20px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Space Grotesk",sans-serif;background:var(--bg);color:var(--blue);padding:10px}
.wrap{max-width:860px;margin:0 auto}
.card{background:var(--pink);border-radius:var(--radius);padding:40px 36px;margin-bottom:10px}
h1{font-weight:400;text-transform:uppercase;letter-spacing:-.05em;line-height:.92;font-size:clamp(38px,7vw,64px)}
.tag{font-size:14px;font-weight:500;letter-spacing:.04em;margin-bottom:18px;text-transform:uppercase}
.sub{margin-top:14px;font-size:15px;color:rgba(0,39,186,.75)}
.form-card{background:var(--blue);border-radius:var(--radius);padding:36px}
.form-card h2{color:var(--pink);font-weight:400;text-transform:uppercase;letter-spacing:-.03em;font-size:clamp(22px,3vw,30px);margin-bottom:22px}
label{display:flex;flex-direction:column;gap:8px;font-size:12px;font-weight:500;letter-spacing:.05em;text-transform:uppercase;color:rgba(255,179,193,.75);margin-bottom:16px}
input[type=text],input[type=email],input[type=tel],textarea{font-family:inherit;font-size:16px;color:var(--pink);background:transparent;border:1px solid rgba(255,179,193,.35);border-radius:10px;padding:14px 16px;outline:none}
input:focus,textarea:focus{border-color:var(--pink)}
input::placeholder{color:rgba(255,179,193,.45)}
.radek{display:grid;grid-template-columns:1fr 2fr 2fr;gap:12px}
.radek2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.oddel{margin:26px 0 14px;color:var(--pink);font-size:15px;letter-spacing:.03em;text-transform:uppercase;border-top:1px solid rgba(255,179,193,.3);padding-top:22px}
.souhlas{flex-direction:row;align-items:flex-start;gap:10px;text-transform:none;letter-spacing:0;font-size:13px;line-height:1.5}
.souhlas input{margin-top:2px}
button{width:100%;font-family:inherit;font-size:15px;font-weight:500;letter-spacing:.02em;text-transform:uppercase;color:var(--pink);background:transparent;border:1px solid var(--pink);border-radius:999px;padding:18px;cursor:pointer;margin-top:10px;transition:.25s}
button:hover{background:var(--pink);color:var(--blue)}
.ok,.err{border-radius:12px;padding:16px 18px;margin-bottom:18px;font-size:15px}
.ok{background:#d9f2e4;color:#0a5c35}
.err{background:#ffe1e1;color:#8f1d1d}
.zpet{display:inline-block;margin-top:16px;color:var(--blue);font-size:14px}
.hp{position:absolute;left:-9999px}
.info{color:rgba(255,179,193,.7);font-size:12px;margin:-8px 0 16px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <p class="tag">[ REGISTRACE / <?=e(TERMIN_AKCE)?> / ROŽNOV P. R. ]</p>
    <h1>Registrace<br><?=e(NAZEV_AKCE)?></h1>
    <p class="sub">Multioborové setkání pro ambulantní specialisty — <?=e(MISTO_AKCE)?>.
    Poplatek: <?=e(CENA_VCAS)?>, poté <?=e(CENA_POZDE)?>. <a class="zpet" href="<?=e(WEB_URL)?>">← zpět na web</a></p>
  </div>

  <div class="form-card">
    <?php if ($odeslano && !$chyby): ?>
      <?php if ($duplicitni): ?>
        <div class="ok">Tento e-mail je na ročník <?=e(AKTUALNI_ROCNIK)?> už zaregistrovaný. Pokud potřebujete registraci upravit, napište na <?=e(MAIL_ORGANIZATOR)?>.</div>
      <?php else: ?>
        <div class="ok">Děkujeme! Vaše registrace byla přijata. Potvrzení jsme poslali na váš e-mail; pokyny k platbě přijdou v samostatné zprávě.</div>
      <?php endif; ?>
      <a class="zpet" style="color:var(--pink)" href="<?=e(WEB_URL)?>">← zpět na mezismery.cz</a>
    <?php else: ?>
      <h2>Přihláška na ročník <?=e(AKTUALNI_ROCNIK)?></h2>
      <?php foreach ($chyby as $ch): ?><div class="err"><?=e($ch)?></div><?php endforeach; ?>
      <form method="post" action="">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <p class="hp"><label>Web <input type="text" name="web" tabindex="-1" autocomplete="off"></label></p>
        <div class="radek">
          <label>Titul<input type="text" name="titul" value="<?=e($f['titul'])?>" placeholder="MUDr."></label>
          <label>Jméno *<input type="text" name="jmeno" value="<?=e($f['jmeno'])?>" required></label>
          <label>Příjmení *<input type="text" name="prijmeni" value="<?=e($f['prijmeni'])?>" required></label>
        </div>
        <div class="radek2">
          <label>E-mail *<input type="email" name="email" value="<?=e($f['email'])?>" required placeholder="vas@email.cz"></label>
          <label>Telefon<input type="tel" name="telefon" value="<?=e($f['telefon'])?>" placeholder="+420"></label>
        </div>
        <label>Specializace / odbornost<input type="text" name="specializace" value="<?=e($f['specializace'])?>" placeholder="např. gastroenterologie"></label>

        <div class="oddel">Fakturační údaje (nepovinné)</div>
        <p class="info">Vyplňte, pokud chcete fakturu na ordinaci / s.r.o. — využijeme je při vystavení dokladu.</p>
        <div class="radek2">
          <label>Název / jméno pro fakturaci<input type="text" name="fakt_nazev" value="<?=e($f['fakt_nazev'])?>"></label>
          <label>IČO<input type="text" name="fakt_ico" value="<?=e($f['fakt_ico'])?>"></label>
        </div>
        <div class="radek2">
          <label>DIČ<input type="text" name="fakt_dic" value="<?=e($f['fakt_dic'])?>"></label>
          <label>Fakturační adresa<input type="text" name="fakt_adresa" value="<?=e($f['fakt_adresa'])?>"></label>
        </div>

        <label class="souhlas"><input type="checkbox" name="souhlas" value="1" required>
          Souhlasím se zpracováním osobních údajů pro účely organizace akce <?=e(NAZEV_AKCE)?> a zasílání informací o dalších ročnících. Pořadatel: 4Education s.r.o.</label>
        <button type="submit">Odeslat registraci</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
