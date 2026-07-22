<?php
session_start();
require __DIR__ . '/config.php';

// ------------------------------------------------------------
// Hilfsfunktionen
// ------------------------------------------------------------
function load_jobs() {
    if (!file_exists(JOBS_FILE)) return [];
    $content = file_get_contents(JOBS_FILE);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function save_jobs($jobs) {
    // Nummerische Indizes sauber weg, JSON hübsch formatiert, Umlaute lesbar
    $json = json_encode(array_values($jobs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents(JOBS_FILE, $json) !== false;
}

function load_content() {
    if (!file_exists(CONTENT_FILE)) return [];
    $content = file_get_contents(CONTENT_FILE);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function save_content($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents(CONTENT_FILE, $json) !== false;
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid($token) {
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------
// Logout
// ------------------------------------------------------------
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ------------------------------------------------------------
// Login-Verarbeitung
// ------------------------------------------------------------
$loginError = '';
if (!empty($_SESSION['logged_in'])) {
    // eingeloggt
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
    } else {
        $loginError = 'Benutzername oder Passwort falsch.';
    }
}

// ------------------------------------------------------------
// Nicht eingeloggt -> Login-Formular anzeigen
// ------------------------------------------------------------
if (empty($_SESSION['logged_in'])) {
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Admin-Login — PS Facility Services</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<style>
  body{font-family:system-ui,Arial,sans-serif; background:#f4f4f2; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0;}
  .box{background:#fff; padding:40px; border-radius:10px; box-shadow:0 4px 24px rgba(0,0,0,.08); width:100%; max-width:340px;}
  h1{font-size:20px; margin:0 0 24px;}
  label{display:block; font-size:13px; margin-bottom:6px; color:#444;}
  input{width:100%; padding:10px 12px; margin-bottom:16px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-size:15px;}
  button{width:100%; padding:12px; background:#111; color:#fff; border:none; border-radius:6px; font-size:15px; cursor:pointer;}
  button:hover{background:#333;}
  .error{color:#b00020; font-size:13px; margin-bottom:16px;}
</style>
</head>
<body>
  <div class="box">
    <h1>Karriere-Admin — Login</h1>
    <?php if ($loginError): ?><div class="error"><?= h($loginError) ?></div><?php endif; ?>
    <form method="post">
      <label>Benutzername</label>
      <input type="text" name="username" required autofocus>
      <label>Passwort</label>
      <input type="password" name="password" required>
      <button type="submit" name="login_submit" value="1">Anmelden</button>
    </form>
  </div>
</body>
</html>
<?php
    exit;
}

// ------------------------------------------------------------
// Ab hier: eingeloggt — Aktionen verarbeiten
// ------------------------------------------------------------
$jobs = load_jobs();
$content = load_content();
$message = '';

// Reihenfolge und Beschriftung der bearbeitbaren Inhalts-Reiter
$contentTabs = [
    'hero'       => 'Startseite: Hero',
    'werte'      => 'Startseite: Warum PS',
    'leistungen' => 'Leistungen',
    'referenzen' => 'Referenzen',
    'kontakt'    => 'Kontakt & Footer',
    'karriere'   => 'Karriere',
    'impressum'  => 'Impressum',
    'datenschutz'=> 'Datenschutz',
];

// Reihenfolge und Fallback-Titel der 12 Leistungsbereiche (Schlüssel müssen zu index.html passen)
$serviceOrder = [
    'buero'       => 'Unterhaltsreinigung',
    'fassade'     => 'Glas- & Fassadenreinigung',
    'industrie'   => 'Industrie- & Gewerbereinigung',
    'winterdienst'=> 'Winterdienst',
    'bauend'      => 'Bauendreinigung',
    'grundboden'  => 'Grund- & Bodenreinigung',
    'parkplatz'   => 'Parkplatz- & Tiefgaragenreinigung',
    'sonder'      => 'Sonderreinigung',
    'gruen'       => 'Grünpflege',
    'stiegenhaus' => 'Stiegenhaus & Hausbetreuung',
    'pv'          => 'PV-Anlagenreinigung',
    'mehr'        => 'Sonderanfrage',
];

// Wandelt die "Name | Beschreibung"-Zeilen aus dem Textfeld in ein Items-Array um
function parse_leistung_items($raw) {
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('|', $line, 2);
        $name = trim($parts[0]);
        $desc = isset($parts[1]) ? trim($parts[1]) : '';
        if ($name !== '') {
            $items[] = ['name' => $name, 'desc' => $desc];
        }
    }
    return $items;
}

// Wandelt ein Items-Array zurück in "Name | Beschreibung"-Zeilen für das Textfeld
function items_to_lines($items) {
    $lines = [];
    foreach ((array)$items as $item) {
        $lines[] = ($item['name'] ?? '') . ' | ' . ($item['desc'] ?? '');
    }
    return implode("\n", $lines);
}

$activeTab = $_GET['tab'] ?? 'jobs';
if (!in_array($activeTab, array_merge(['jobs'], array_keys($contentTabs)), true)) {
    $activeTab = 'jobs';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!csrf_valid($token)) {
        $message = 'Sitzung abgelaufen, bitte erneut versuchen.';
    } else {
        $action = $_POST['action'] ?? '';
        $activeTab = $_POST['tab'] ?? $activeTab;

        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            $metaRaw = trim($_POST['meta'] ?? '');
            if ($title !== '') {
                $meta = $metaRaw !== '' ? array_map('trim', explode(',', $metaRaw)) : [];
                $jobs[] = [
                    'id' => uniqid('job_'),
                    'title' => $title,
                    'meta' => $meta,
                ];
                save_jobs($jobs);
                $message = 'Jobangebot hinzugefügt.';
            }
        }

        if ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($jobs as &$job) {
                if ($job['id'] === $id) {
                    $job['title'] = trim($_POST['title'] ?? $job['title']);
                    $metaRaw = trim($_POST['meta'] ?? '');
                    $job['meta'] = $metaRaw !== '' ? array_map('trim', explode(',', $metaRaw)) : [];
                }
            }
            unset($job);
            save_jobs($jobs);
            $message = 'Jobangebot aktualisiert.';
        }

        if ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $jobs = array_filter($jobs, function($job) use ($id) { return $job['id'] !== $id; });
            save_jobs($jobs);
            $jobs = array_values($jobs);
            $message = 'Jobangebot gelöscht.';
        }

        if ($action === 'move') {
            $id = $_POST['id'] ?? '';
            $dir = $_POST['dir'] ?? '';
            $idx = null;
            foreach ($jobs as $i => $job) {
                if ($job['id'] === $id) { $idx = $i; break; }
            }
            if ($idx !== null) {
                $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
                if ($swap >= 0 && $swap < count($jobs)) {
                    $tmp = $jobs[$idx];
                    $jobs[$idx] = $jobs[$swap];
                    $jobs[$swap] = $tmp;
                    save_jobs($jobs);
                }
            }
        }

        if ($action === 'save_content') {
            $section = $_POST['section'] ?? '';
            $allowedSections = ['hero', 'werte', 'kontakt', 'footer', 'karriere', 'impressum', 'datenschutz'];
            if (in_array($section, $allowedSections, true)) {
                if (!isset($content[$section]) || !is_array($content[$section])) {
                    $content[$section] = [];
                }
                foreach ($_POST as $key => $value) {
                    if (in_array($key, ['csrf', 'action', 'section', 'tab'], true)) continue;
                    $content[$section][$key] = trim((string)$value);
                }
                save_content($content);
                $message = 'Inhalt gespeichert.';
            }
        }

        if ($action === 'save_leistung') {
            $key = $_POST['key'] ?? '';
            if (array_key_exists($key, $serviceOrder)) {
                if (!isset($content['leistungen']) || !is_array($content['leistungen'])) {
                    $content['leistungen'] = [];
                }
                $existingNum = $content['leistungen'][$key]['num'] ?? null;
                $content['leistungen'][$key] = [
                    'num'   => $existingNum ?: ('S' . str_pad((string)(array_search($key, array_keys($serviceOrder)) + 1), 2, '0', STR_PAD_LEFT)),
                    'title' => trim($_POST['title'] ?? ''),
                    'desc'  => trim($_POST['desc'] ?? ''),
                    'items' => parse_leistung_items($_POST['items'] ?? ''),
                ];
                save_content($content);
                $message = 'Leistung gespeichert.';
            }
        }

        if ($action === 'add_ref') {
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            if ($name !== '') {
                if (!isset($content['referenzen']) || !is_array($content['referenzen'])) {
                    $content['referenzen'] = [];
                }
                $content['referenzen'][] = [
                    'id'   => uniqid('ref_'),
                    'name' => $name,
                    'url'  => $url,
                ];
                save_content($content);
                $message = 'Referenz hinzugefügt.';
            }
        }

        if ($action === 'update_ref') {
            $id = $_POST['id'] ?? '';
            $refs = $content['referenzen'] ?? [];
            foreach ($refs as &$ref) {
                if ($ref['id'] === $id) {
                    $ref['name'] = trim($_POST['name'] ?? $ref['name']);
                    $ref['url'] = trim($_POST['url'] ?? '');
                }
            }
            unset($ref);
            $content['referenzen'] = $refs;
            save_content($content);
            $message = 'Referenz aktualisiert.';
        }

        if ($action === 'delete_ref') {
            $id = $_POST['id'] ?? '';
            $refs = $content['referenzen'] ?? [];
            $refs = array_values(array_filter($refs, function($ref) use ($id) { return $ref['id'] !== $id; }));
            $content['referenzen'] = $refs;
            save_content($content);
            $message = 'Referenz gelöscht.';
        }

        if ($action === 'move_ref') {
            $id = $_POST['id'] ?? '';
            $dir = $_POST['dir'] ?? '';
            $refs = $content['referenzen'] ?? [];
            $idx = null;
            foreach ($refs as $i => $ref) {
                if ($ref['id'] === $id) { $idx = $i; break; }
            }
            if ($idx !== null) {
                $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
                if ($swap >= 0 && $swap < count($refs)) {
                    $tmp = $refs[$idx];
                    $refs[$idx] = $refs[$swap];
                    $refs[$swap] = $tmp;
                    $content['referenzen'] = $refs;
                    save_content($content);
                }
            }
        }
    }
    $jobs = load_jobs();
    $content = load_content();
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Admin-Panel — PS Facility Services</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<style>
  body{font-family:system-ui,Arial,sans-serif; background:#f4f4f2; margin:0; padding:32px 16px; color:#111;}
  .wrap{max-width:760px; margin:0 auto;}
  h1{font-size:24px; margin-bottom:4px;}
  .sub{color:#666; font-size:14px; margin-bottom:28px;}
  .topbar{display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;}
  .logout{font-size:13px; color:#b00020; text-decoration:none;}
  .message{background:#e6f4ea; color:#1e7e34; padding:10px 14px; border-radius:6px; margin-bottom:20px; font-size:14px;}
  .card{background:#fff; border-radius:8px; padding:20px; margin-bottom:16px; box-shadow:0 2px 10px rgba(0,0,0,.05);}
  .card h3{margin:0 0 4px; font-size:17px;}
  .card .meta{color:#777; font-size:13px; margin-bottom:14px;}
  label{display:block; font-size:12.5px; color:#555; margin-bottom:4px; margin-top:10px;}
  input[type=text]{width:100%; padding:9px 11px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-size:14px;}
  textarea{width:100%; padding:9px 11px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; font-size:14px; font-family:inherit; resize:vertical;}
  .row{display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;}
  button{padding:9px 16px; border:none; border-radius:6px; font-size:13.5px; cursor:pointer;}
  .btn-save{background:#111; color:#fff;}
  .btn-save:hover{background:#333;}
  .btn-delete{background:#fdeaea; color:#b00020;}
  .btn-delete:hover{background:#fbd6d6;}
  .btn-move{background:#eee; color:#333;}
  .btn-move:hover{background:#ddd;}
  .add-box{background:#fff; border:2px dashed #ccc; border-radius:8px; padding:20px; margin-top:32px;}
  .add-box h3{margin-top:0;}
  .hint{font-size:12px; color:#888; margin-top:4px;}
  .tabs{display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; border-bottom:1px solid #ddd; padding-bottom:0;}
  .tab-link{padding:10px 16px; font-size:13.5px; text-decoration:none; color:#555; border-radius:6px 6px 0 0; border:1px solid transparent;}
  .tab-link.active{color:#111; font-weight:600; background:#fff; border-color:#ddd; border-bottom-color:#fff; margin-bottom:-1px;}
  .field-grid{display:grid; grid-template-columns:1fr 1fr; gap:0 16px;}
  .field-grid .full{grid-column:1 / -1;}
  @media (max-width:560px){ .field-grid{grid-template-columns:1fr;} }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>Admin-Panel</h1>
      <div class="sub">Änderungen erscheinen sofort auf der Website.</div>
    </div>
    <a class="logout" href="?logout=1">Abmelden</a>
  </div>

  <div class="tabs">
    <a class="tab-link <?= $activeTab === 'jobs' ? 'active' : '' ?>" href="?tab=jobs">Jobangebote</a>
    <?php foreach ($contentTabs as $key => $label): ?>
      <a class="tab-link <?= $activeTab === $key ? 'active' : '' ?>" href="?tab=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>

  <?php if ($activeTab === 'jobs'): ?>

  <?php if (empty($jobs)): ?>
    <p style="color:#777;">Aktuell keine Jobangebote eingetragen.</p>
  <?php endif; ?>

  <?php foreach ($jobs as $i => $job): ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= h($job['id']) ?>">
        <label>Jobtitel</label>
        <input type="text" name="title" value="<?= h($job['title']) ?>" required>
        <label>Details (durch Komma getrennt)</label>
        <input type="text" name="meta" value="<?= h(implode(', ', $job['meta'] ?? [])) ?>">
        <div class="hint">Beispiel: Vollzeit, Traun, Ab sofort</div>
        <div class="row">
          <button type="submit" class="btn-save">Speichern</button>
        </div>
      </form>
      <div class="row">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="move">
          <input type="hidden" name="id" value="<?= h($job['id']) ?>">
          <input type="hidden" name="dir" value="up">
          <button type="submit" class="btn-move" <?= $i === 0 ? 'disabled' : '' ?>>↑ Nach oben</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="move">
          <input type="hidden" name="id" value="<?= h($job['id']) ?>">
          <input type="hidden" name="dir" value="down">
          <button type="submit" class="btn-move" <?= $i === count($jobs) - 1 ? 'disabled' : '' ?>>↓ Nach unten</button>
        </form>
        <form method="post" onsubmit="return confirm('Dieses Jobangebot wirklich löschen?');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= h($job['id']) ?>">
          <button type="submit" class="btn-delete">Löschen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="add-box">
    <h3>Neues Jobangebot hinzufügen</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <label>Jobtitel</label>
      <input type="text" name="title" placeholder="z. B. Objektleitung (m/w/d)" required>
      <label>Details (durch Komma getrennt)</label>
      <input type="text" name="meta" placeholder="z. B. Vollzeit, Linz, Ab sofort">
      <div class="row"><button type="submit" class="btn-save">Hinzufügen</button></div>
    </form>
  </div>

  <?php endif; ?>

  <?php if ($activeTab === 'hero'): $h = $content['hero'] ?? []; ?>
  <div class="card">
    <h3>Hero-Bereich (ganz oben auf der Startseite)</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="hero">
      <input type="hidden" name="tab" value="hero">
      <div class="field-grid">
        <div class="full">
          <label>Eyebrow (kleiner Text über der Überschrift)</label>
          <input type="text" name="eyebrow" value="<?= h($h['eyebrow'] ?? '') ?>">
        </div>
        <div>
          <label>Überschrift Zeile 1</label>
          <input type="text" name="headline_line1" value="<?= h($h['headline_line1'] ?? '') ?>">
        </div>
        <div>
          <label>Überschrift Zeile 2</label>
          <input type="text" name="headline_line2" value="<?= h($h['headline_line2'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Untertext</label>
          <textarea name="subtext" rows="3"><?= h($h['subtext'] ?? '') ?></textarea>
        </div>
        <div>
          <label>Button-Text (primär)</label>
          <input type="text" name="cta_primary" value="<?= h($h['cta_primary'] ?? '') ?>">
        </div>
        <div>
          <label>Button-Text (sekundär)</label>
          <input type="text" name="cta_secondary" value="<?= h($h['cta_secondary'] ?? '') ?>">
        </div>
        <div>
          <label>Kennzahl 1</label>
          <input type="text" name="stat1_num" value="<?= h($h['stat1_num'] ?? '') ?>">
        </div>
        <div>
          <label>Beschriftung Kennzahl 1</label>
          <input type="text" name="stat1_label" value="<?= h($h['stat1_label'] ?? '') ?>">
        </div>
        <div>
          <label>Kennzahl 2</label>
          <input type="text" name="stat2_num" value="<?= h($h['stat2_num'] ?? '') ?>">
        </div>
        <div>
          <label>Beschriftung Kennzahl 2</label>
          <input type="text" name="stat2_label" value="<?= h($h['stat2_label'] ?? '') ?>">
        </div>
        <div>
          <label>Kennzahl 3</label>
          <input type="text" name="stat3_num" value="<?= h($h['stat3_num'] ?? '') ?>">
        </div>
        <div>
          <label>Beschriftung Kennzahl 3</label>
          <input type="text" name="stat3_label" value="<?= h($h['stat3_label'] ?? '') ?>">
        </div>
      </div>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'werte'): $w = $content['werte'] ?? []; ?>
  <div class="card">
    <h3>Bereich „Warum PS“</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="werte">
      <input type="hidden" name="tab" value="werte">
      <div class="field-grid">
        <div>
          <label>Tag (kleiner Text über der Überschrift)</label>
          <input type="text" name="tag" value="<?= h($w['tag'] ?? '') ?>">
        </div>
        <div>
          <label>Überschrift</label>
          <input type="text" name="title" value="<?= h($w['title'] ?? '') ?>">
        </div>
      </div>
      <?php for ($n = 1; $n <= 3; $n++): ?>
        <hr style="border:none; border-top:1px solid #eee; margin:18px 0 6px;">
        <div class="field-grid">
          <div>
            <label>Punkt <?= $n ?> — Kennzeichnung (z. B. "01 / PROFESSIONELL")</label>
            <input type="text" name="item<?= $n ?>_label" value="<?= h($w['item' . $n . '_label'] ?? '') ?>">
          </div>
          <div>
            <label>Punkt <?= $n ?> — Titel</label>
            <input type="text" name="item<?= $n ?>_title" value="<?= h($w['item' . $n . '_title'] ?? '') ?>">
          </div>
          <div class="full">
            <label>Punkt <?= $n ?> — Text</label>
            <textarea name="item<?= $n ?>_text" rows="2"><?= h($w['item' . $n . '_text'] ?? '') ?></textarea>
          </div>
        </div>
      <?php endfor; ?>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'leistungen'): ?>
  <p class="hint" style="margin-bottom:16px;">Bei „Unterpunkte“ eine Zeile pro Punkt, im Format <strong>Name | Beschreibung</strong>.</p>
  <?php foreach ($serviceOrder as $key => $fallbackTitle): $svc = $content['leistungen'][$key] ?? []; ?>
    <div class="card">
      <h3><?= h(($svc['num'] ?? '')) ?> — <?= h($svc['title'] ?? $fallbackTitle) ?></h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_leistung">
        <input type="hidden" name="key" value="<?= h($key) ?>">
        <input type="hidden" name="tab" value="leistungen">
        <label>Titel</label>
        <input type="text" name="title" value="<?= h($svc['title'] ?? $fallbackTitle) ?>">
        <label>Beschreibung</label>
        <textarea name="desc" rows="2"><?= h($svc['desc'] ?? '') ?></textarea>
        <label>Unterpunkte (eine Zeile pro Punkt: Name | Beschreibung)</label>
        <textarea name="items" rows="<?= max(3, count($svc['items'] ?? [])) ?>"><?= h(items_to_lines($svc['items'] ?? [])) ?></textarea>
        <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($activeTab === 'referenzen'): $refs = $content['referenzen'] ?? []; ?>
  <p class="hint" style="margin-bottom:16px;">Erscheinen automatisch in der Lauf-Zeile und im Kunden-Raster auf der Startseite — das Layout passt sich der Anzahl an, ohne Pfeile oder Blättern.</p>

  <?php if (empty($refs)): ?>
    <p style="color:#777;">Aktuell keine Referenzen eingetragen.</p>
  <?php endif; ?>

  <?php foreach ($refs as $i => $ref): ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update_ref">
        <input type="hidden" name="id" value="<?= h($ref['id']) ?>">
        <input type="hidden" name="tab" value="referenzen">
        <label>Name</label>
        <input type="text" name="name" value="<?= h($ref['name'] ?? '') ?>" required>
        <label>Link (optional — leer lassen für reinen Text ohne Verlinkung)</label>
        <input type="text" name="url" value="<?= h($ref['url'] ?? '') ?>" placeholder="https://...">
        <div class="row">
          <button type="submit" class="btn-save">Speichern</button>
        </div>
      </form>
      <div class="row">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="move_ref">
          <input type="hidden" name="id" value="<?= h($ref['id']) ?>">
          <input type="hidden" name="dir" value="up">
          <input type="hidden" name="tab" value="referenzen">
          <button type="submit" class="btn-move" <?= $i === 0 ? 'disabled' : '' ?>>↑ Nach oben</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="move_ref">
          <input type="hidden" name="id" value="<?= h($ref['id']) ?>">
          <input type="hidden" name="dir" value="down">
          <input type="hidden" name="tab" value="referenzen">
          <button type="submit" class="btn-move" <?= $i === count($refs) - 1 ? 'disabled' : '' ?>>↓ Nach unten</button>
        </form>
        <form method="post" onsubmit="return confirm('Diese Referenz wirklich löschen?');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="delete_ref">
          <input type="hidden" name="id" value="<?= h($ref['id']) ?>">
          <input type="hidden" name="tab" value="referenzen">
          <button type="submit" class="btn-delete">Löschen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="add-box">
    <h3>Neue Referenz hinzufügen</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add_ref">
      <input type="hidden" name="tab" value="referenzen">
      <label>Name</label>
      <input type="text" name="name" placeholder="z. B. Musterfirma GmbH" required>
      <label>Link (optional)</label>
      <input type="text" name="url" placeholder="https://...">
      <div class="row"><button type="submit" class="btn-save">Hinzufügen</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'kontakt'): $k = $content['kontakt'] ?? []; $f = $content['footer'] ?? []; ?>
  <div class="card">
    <h3>Kontakt-Bereich</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="kontakt">
      <input type="hidden" name="tab" value="kontakt">
      <label>Überschrift</label>
      <input type="text" name="heading" value="<?= h($k['heading'] ?? '') ?>">
      <label>Text</label>
      <textarea name="text" rows="3"><?= h($k['text'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Footer</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="footer">
      <input type="hidden" name="tab" value="kontakt">
      <label>Beschreibungstext (unter dem Logo)</label>
      <textarea name="brand_text" rows="2"><?= h($f['brand_text'] ?? '') ?></textarea>
      <div class="field-grid">
        <div>
          <label>Copyright-Zeile</label>
          <input type="text" name="copyright" value="<?= h($f['copyright'] ?? '') ?>">
        </div>
        <div>
          <label>Standort-Zeile</label>
          <input type="text" name="location" value="<?= h($f['location'] ?? '') ?>">
        </div>
      </div>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'karriere'): $kr = $content['karriere'] ?? []; ?>
  <p class="hint" style="margin-bottom:16px;">Die offenen Stellen selbst verwaltest du weiterhin im Reiter „Jobangebote“ — hier geht's nur um die Texte drumherum auf der Karriere-Seite.</p>
  <div class="card">
    <h3>Kopfbereich</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="karriere">
      <input type="hidden" name="tab" value="karriere">
      <label>Seitentitel (große Überschrift)</label>
      <input type="text" name="page_title" value="<?= h($kr['page_title'] ?? '') ?>">
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Bereich „Warum PS als Arbeitgeber“</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="karriere">
      <input type="hidden" name="tab" value="karriere">
      <div class="field-grid">
        <div>
          <label>Tag (kleiner Text über der Überschrift)</label>
          <input type="text" name="werte_tag" value="<?= h($kr['werte_tag'] ?? '') ?>">
        </div>
        <div>
          <label>Überschrift</label>
          <input type="text" name="werte_title" value="<?= h($kr['werte_title'] ?? '') ?>">
        </div>
      </div>
      <?php for ($n = 1; $n <= 3; $n++): ?>
        <hr style="border:none; border-top:1px solid #eee; margin:18px 0 6px;">
        <div class="field-grid">
          <div>
            <label>Punkt <?= $n ?> — Kennzeichnung (z. B. "01 / SICHERHEIT")</label>
            <input type="text" name="item<?= $n ?>_label" value="<?= h($kr['item' . $n . '_label'] ?? '') ?>">
          </div>
          <div>
            <label>Punkt <?= $n ?> — Titel</label>
            <input type="text" name="item<?= $n ?>_title" value="<?= h($kr['item' . $n . '_title'] ?? '') ?>">
          </div>
          <div class="full">
            <label>Punkt <?= $n ?> — Text</label>
            <textarea name="item<?= $n ?>_text" rows="2"><?= h($kr['item' . $n . '_text'] ?? '') ?></textarea>
          </div>
        </div>
      <?php endfor; ?>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Bereich „Jobs bei PS“</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="karriere">
      <input type="hidden" name="tab" value="karriere">
      <label>Tag (kleiner Text über der Job-Liste)</label>
      <input type="text" name="jobs_tag" value="<?= h($kr['jobs_tag'] ?? '') ?>">
      <label>Einleitungstext</label>
      <textarea name="jobs_intro" rows="2"><?= h($kr['jobs_intro'] ?? '') ?></textarea>
      <label>Text wenn aktuell keine Stellen ausgeschrieben sind</label>
      <textarea name="jobs_empty" rows="2"><?= h($kr['jobs_empty'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Bewerbungs-Bereich (unten auf der Seite)</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="karriere">
      <input type="hidden" name="tab" value="karriere">
      <label>Überschrift</label>
      <input type="text" name="bewerbung_heading" value="<?= h($kr['bewerbung_heading'] ?? '') ?>">
      <label>Text</label>
      <textarea name="bewerbung_text" rows="3"><?= h($kr['bewerbung_text'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'impressum'): $im = $content['impressum'] ?? []; ?>
  <p class="hint" style="margin-bottom:16px;">Telefonnummern, E-Mail und der Gewerbeordnungs-Link sind fix im Template hinterlegt und hier bewusst nicht editierbar.</p>
  <div class="card">
    <h3>Seitentitel &amp; Firmendaten</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="impressum">
      <input type="hidden" name="tab" value="impressum">
      <div class="field-grid">
        <div class="full">
          <label>Seitentitel</label>
          <input type="text" name="page_title" value="<?= h($im['page_title'] ?? '') ?>">
        </div>
        <div>
          <label>Medieninhaber</label>
          <input type="text" name="medieninhaber" value="<?= h($im['medieninhaber'] ?? '') ?>">
        </div>
        <div>
          <label>Firmenwortlaut</label>
          <input type="text" name="firmenwortlaut" value="<?= h($im['firmenwortlaut'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Unternehmensgegenstand</label>
          <input type="text" name="gegenstand" value="<?= h($im['gegenstand'] ?? '') ?>">
        </div>
        <div>
          <label>Adresse (Firmensitz)</label>
          <input type="text" name="adresse" value="<?= h($im['adresse'] ?? '') ?>">
        </div>
        <div>
          <label>Finanzamt-Zeile</label>
          <input type="text" name="finanzamt" value="<?= h($im['finanzamt'] ?? '') ?>">
        </div>
        <div>
          <label>UID-Nummer</label>
          <input type="text" name="uid" value="<?= h($im['uid'] ?? '') ?>">
        </div>
        <div>
          <label>Firmenbuchnummer</label>
          <input type="text" name="firmenbuch_nr" value="<?= h($im['firmenbuch_nr'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Firmenbuchgericht-Zeile</label>
          <input type="text" name="firmenbuch_gericht" value="<?= h($im['firmenbuch_gericht'] ?? '') ?>">
        </div>
        <div>
          <label>Mitgliedschaft</label>
          <input type="text" name="mitgliedschaft" value="<?= h($im['mitgliedschaft'] ?? '') ?>">
        </div>
        <div>
          <label>Meisterbetrieb-Zeile</label>
          <input type="text" name="meisterbetrieb" value="<?= h($im['meisterbetrieb'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Aufsichtsbehörde</label>
          <input type="text" name="aufsichtsbehoerde" value="<?= h($im['aufsichtsbehoerde'] ?? '') ?>">
        </div>
      </div>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Urheberrecht &amp; Streitbeilegung</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="impressum">
      <input type="hidden" name="tab" value="impressum">
      <label>Urheberrecht-Text</label>
      <textarea name="urheberrecht" rows="3"><?= h($im['urheberrecht'] ?? '') ?></textarea>
      <label>Streitbeilegung-Text (ohne den ODR-Link, der bleibt fix)</label>
      <textarea name="streitbeilegung" rows="2"><?= h($im['streitbeilegung'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($activeTab === 'datenschutz'): $ds = $content['datenschutz'] ?? []; ?>
  <p class="hint" style="margin-bottom:16px;">Textabschnitte mit fix verlinkten Pflichtangaben (z. B. der Link zur Datenschutzbehörde in "Ihre Rechte" oder die Telefonnummern in "Firmensitz") bleiben aus Sicherheitsgründen fix, damit kein Link aus Versehen verschwindet — alle anderen Texte sind vollständig editierbar.</p>
  <div class="card">
    <h3>Seitentitel</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <input type="text" name="page_title" value="<?= h($ds['page_title'] ?? '') ?>">
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>01 — Informationspflicht (DSGVO)</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s01_title" value="<?= h($ds['s01_title'] ?? '') ?>">
      <label>Text</label>
      <textarea name="s01_p1" rows="2"><?= h($ds['s01_p1'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>02 — Geltungsbereich</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s02_title" value="<?= h($ds['s02_title'] ?? '') ?>">
      <label>Text</label>
      <textarea name="s02_p1" rows="2"><?= h($ds['s02_p1'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>03 — Automatische Datenspeicherung</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s03_title" value="<?= h($ds['s03_title'] ?? '') ?>">
      <label>Text 1</label>
      <textarea name="s03_p1" rows="2"><?= h($ds['s03_p1'] ?? '') ?></textarea>
      <label>Text 2</label>
      <textarea name="s03_p2" rows="2"><?= h($ds['s03_p2'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>04 — Speicherung persönlicher Daten</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s04_title" value="<?= h($ds['s04_title'] ?? '') ?>">
      <label>Text 1</label>
      <textarea name="s04_p1" rows="2"><?= h($ds['s04_p1'] ?? '') ?></textarea>
      <label>Text 2</label>
      <textarea name="s04_p2" rows="2"><?= h($ds['s04_p2'] ?? '') ?></textarea>
      <label>Text 3</label>
      <textarea name="s04_p3" rows="2"><?= h($ds['s04_p3'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>05 — Ihre Rechte <span style="font-weight:400; color:#888; font-size:13px;">(nur Überschrift)</span></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <input type="text" name="s05_title" value="<?= h($ds['s05_title'] ?? '') ?>">
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>06 — Cookies</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s06_title" value="<?= h($ds['s06_title'] ?? '') ?>">
      <label>Text vor der Liste</label>
      <textarea name="s06_p1" rows="2"><?= h($ds['s06_p1'] ?? '') ?></textarea>
      <label>Stichpunkt 1 (notwendige Cookies)</label>
      <input type="text" name="s06_li1" value="<?= h($ds['s06_li1'] ?? '') ?>">
      <label>Stichpunkt 2 (funktionelle Cookies)</label>
      <input type="text" name="s06_li2" value="<?= h($ds['s06_li2'] ?? '') ?>">
      <label>Stichpunkt 3 (zielorientierte Cookies)</label>
      <input type="text" name="s06_li3" value="<?= h($ds['s06_li3'] ?? '') ?>">
      <label>Text nach der Liste</label>
      <textarea name="s06_p2" rows="2"><?= h($ds['s06_p2'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>07 — Links &amp; soziale Medien</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s07_title" value="<?= h($ds['s07_title'] ?? '') ?>">
      <label>Text 1</label>
      <textarea name="s07_p1" rows="2"><?= h($ds['s07_p1'] ?? '') ?></textarea>
      <label>Text 2 (Einleitung zur Liste)</label>
      <textarea name="s07_p2" rows="2"><?= h($ds['s07_p2'] ?? '') ?></textarea>
      <label>Stichpunkt 1 (Facebook)</label>
      <input type="text" name="s07_li1" value="<?= h($ds['s07_li1'] ?? '') ?>">
      <label>Stichpunkt 2 (Instagram)</label>
      <input type="text" name="s07_li2" value="<?= h($ds['s07_li2'] ?? '') ?>">
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>08 — Facebook-Pixel</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s08_title" value="<?= h($ds['s08_title'] ?? '') ?>">
      <label>Text 1</label>
      <textarea name="s08_p1" rows="2"><?= h($ds['s08_p1'] ?? '') ?></textarea>
      <label>Text 2</label>
      <textarea name="s08_p2" rows="2"><?= h($ds['s08_p2'] ?? '') ?></textarea>
      <label>Text 3</label>
      <textarea name="s08_p3" rows="2"><?= h($ds['s08_p3'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>09 — Änderung und Widerruf</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s09_title" value="<?= h($ds['s09_title'] ?? '') ?>">
      <label>Text 1</label>
      <textarea name="s09_p1" rows="2"><?= h($ds['s09_p1'] ?? '') ?></textarea>
      <label>Text 2</label>
      <textarea name="s09_p2" rows="2"><?= h($ds['s09_p2'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>10 — Auskunftsrecht <span style="font-weight:400; color:#888; font-size:13px;">(2. Absatz mit Verantwortlichen-Namen ist fix)</span></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s10_title" value="<?= h($ds['s10_title'] ?? '') ?>">
      <label>Text</label>
      <textarea name="s10_p1" rows="2"><?= h($ds['s10_p1'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h3>11 — Firmensitz <span style="font-weight:400; color:#888; font-size:13px;">(Adresse &amp; Telefonnummern sind fix)</span></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_content">
      <input type="hidden" name="section" value="datenschutz">
      <input type="hidden" name="tab" value="datenschutz">
      <label>Überschrift</label>
      <input type="text" name="s11_title" value="<?= h($ds['s11_title'] ?? '') ?>">
      <label>Text (AGB-Hinweis)</label>
      <textarea name="s11_p1" rows="2"><?= h($ds['s11_p1'] ?? '') ?></textarea>
      <div class="row"><button type="submit" class="btn-save">Speichern</button></div>
    </form>
  </div>
  <?php endif; ?>

</div>
</body>
</html>