<?php

declare(strict_types=1);

require __DIR__ . '/../src/ChecklistRepository.php';
require __DIR__ . '/../src/ChecklistStorage.php';
require __DIR__ . '/../src/I18nRepository.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

// PHP peut recevoir ::ffff:127.x.x.x (IPv4-mapped IPv6) selon la config réseau du serveur.
// Cette forme désigne le même loopback que 127.0.0.1 mais ne serait pas reconnue par in_array seul.
$isLocalhost = in_array($remoteAddress, ['127.0.0.1', '::1', 'localhost'], true)
    || (bool) preg_match('/^::ffff:127\.\d+\.\d+\.\d+$/i', $remoteAddress);
if (! $isLocalhost) {
    http_response_code(403);
    echo 'Access denied: this checklist app is local only.';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$repository = new ChecklistRepository(__DIR__ . '/../data/checklists');
$i18n = new I18nRepository(__DIR__ . '/../data/lang');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $maxBytes = 2 * 1024 * 1024;
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);

    if ($raw !== false && strlen($raw) > $maxBytes) {
        jsonResponse(['ok' => false, 'message' => 'Payload is too large.'], 413);
    }

    $decoded = json_decode($raw ?: '{}', true);

    if (! is_array($decoded)) {
        jsonResponse(['ok' => false, 'message' => 'Invalid JSON.'], 422);
    }

    return $decoded;
}

function requireCsrf(string $csrfToken): void
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (! hash_equals($csrfToken, $headerToken)) {
        jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
    }
}

function requestLang(): string
{
    $lang = (string) ($_GET['lang'] ?? 'en');
    return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $lang) ? $lang : 'en';
}

function requestSlug(): string
{
    $slug = (string) ($_GET['checklist'] ?? '');
    if (! preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug)) {
        jsonResponse(['ok' => false, 'message' => 'Invalid checklist identifier.'], 422);
    }
    return $slug;
}

try {
    if (str_starts_with($uri, '/api/')) {
        if (! extension_loaded('pdo_sqlite')) {
            jsonResponse([
                'ok' => false,
                'message' => 'PHP SQLite extension is missing. Install it with: sudo apt install -y php-sqlite3 sqlite3',
            ], 500);
        }

        $storage = new ChecklistStorage(__DIR__ . '/../database/checklist.sqlite');
    }

    if ($uri === '/api/bootstrap' && $method === 'GET') {
        $lang = requestLang();
        $checklists = $repository->all($lang);
        $currentSlug = (string) ($_GET['checklist'] ?? ($checklists[0]['slug'] ?? ''));
        $current = $currentSlug !== '' ? $repository->find($currentSlug, $lang) : null;

        jsonResponse([
            'ok' => true,
            'csrfToken' => $csrfToken,
            'language' => $lang,
            'languages' => $i18n->languages(),
            'dictionary' => $i18n->dictionary($lang),
            'checklists' => $checklists,
            'current' => $current,
            'states' => $current ? $storage->all((string) $current['slug']) : [],
        ]);
    }

    if ($uri === '/api/checklist' && $method === 'GET') {
        $lang = requestLang();
        $slug = requestSlug();
        $checklist = $repository->find($slug, $lang);

        if (! $checklist) {
            jsonResponse(['ok' => false, 'message' => 'Checklist not found.'], 404);
        }

        jsonResponse([
            'ok' => true,
            'checklist' => $checklist,
            'states' => $storage->all($slug),
            'checklists' => $repository->all($lang),
            'dictionary' => $i18n->dictionary($lang),
        ]);
    }

    if ($uri === '/api/checklists' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $lang = (string) ($payload['lang'] ?? 'en');
        $checklist = $repository->create($lang, (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist, 'checklists' => $repository->all($lang)]);
    }

    if ($uri === '/api/checklists' && $method === 'PATCH') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $slug = (string) ($payload['checklist'] ?? '');
        $lang = (string) ($payload['lang'] ?? 'en');
        $checklist = $repository->updateChecklist($slug, $lang, (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist, 'checklists' => $repository->all($lang)]);
    }

    if ($uri === '/api/checklists' && $method === 'DELETE') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $slug = (string) ($payload['checklist'] ?? '');
        $confirm = (string) ($payload['confirm'] ?? '');

        if ($confirm !== $slug) {
            jsonResponse(['ok' => false, 'message' => 'Type the checklist identifier to confirm deletion.'], 422);
        }

        $repository->delete($slug);
        $storage->deleteChecklistStates($slug);
        jsonResponse(['ok' => true, 'checklists' => $repository->all((string) ($payload['lang'] ?? 'en'))]);
    }

    if ($uri === '/api/sections' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->addSection((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/sections' && $method === 'PATCH') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->updateSection((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['section_id'] ?? ''), (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/sections' && $method === 'DELETE') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->deleteSection((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['section_id'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/sections/move' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->moveSection((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['section_id'] ?? ''), (string) ($payload['direction'] ?? 'down'));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/tasks' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->addTask((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['section_id'] ?? ''), (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''), (string) ($payload['priority'] ?? 'medium'));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/tasks' && $method === 'PATCH') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->updateTask((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['task_id'] ?? ''), (string) ($payload['title'] ?? ''), (string) ($payload['description'] ?? ''), (string) ($payload['priority'] ?? 'medium'));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/tasks' && $method === 'DELETE') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->deleteTask((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['task_id'] ?? ''));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/tasks/move' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $checklist = $repository->moveTask((string) ($payload['checklist'] ?? ''), (string) ($payload['lang'] ?? 'en'), (string) ($payload['task_id'] ?? ''), (string) ($payload['direction'] ?? 'down'));
        jsonResponse(['ok' => true, 'checklist' => $checklist]);
    }

    if ($uri === '/api/state' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $slug = (string) ($payload['checklist'] ?? '');
        if (! $repository->find($slug, (string) ($payload['lang'] ?? 'en'))) {
            jsonResponse(['ok' => false, 'message' => 'Checklist not found.'], 404);
        }
        $storage->save($slug, (string) ($payload['task_id'] ?? ''), (bool) ($payload['done'] ?? false), (bool) ($payload['problem'] ?? false), (string) ($payload['note'] ?? ''));
        jsonResponse(['ok' => true]);
    }

    if ($uri === '/api/reset' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $slug = (string) ($payload['checklist'] ?? '');
        $storage->reset($slug);
        jsonResponse(['ok' => true, 'states' => $storage->all($slug)]);
    }

    if ($uri === '/api/export' && $method === 'GET') {
        $lang = requestLang();
        $slug = (string) ($_GET['checklist'] ?? '');
        $scope = (string) ($_GET['scope'] ?? 'current');
        $type = (string) ($_GET['type'] ?? 'complete');
        $includeAll = $scope === 'all';
        $definitions = [];
        $states = [];

        if ($includeAll) {
            foreach ($repository->all($lang) as $summary) {
                $definitions[(string) $summary['slug']] = $repository->exportDefinition((string) $summary['slug'], $lang);
            }
            $states = $storage->allForExport(null);
        } else {
            if (! preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug)) {
                jsonResponse(['ok' => false, 'message' => 'Invalid checklist identifier.'], 422);
            }
            $definitions[$slug] = $repository->exportDefinition($slug, $lang);
            $states = $storage->allForExport($slug);
        }

        $payload = [
            'version' => 5,
            'exportedAt' => gmdate('c'),
            'language' => $lang,
            'scope' => $scope,
            'type' => $type,
        ];

        if (in_array($type, ['complete', 'definition'], true)) {
            $payload['definitions'] = $definitions;
        }

        if (in_array($type, ['complete', 'progress', 'issues'], true)) {
            if ($type === 'issues') {
                foreach ($states as $checklistSlug => $checklistStates) {
                    $payload['issues'][$checklistSlug] = array_filter($checklistStates, fn (array $state): bool => (bool) ($state['problem'] ?? false));
                }
            } else {
                $payload['states'] = $states;
            }
        }

        jsonResponse($payload);
    }

    if ($uri === '/api/import' && $method === 'POST') {
        requireCsrf($csrfToken);
        $payload = readJsonBody();
        $lang = (string) ($payload['lang'] ?? 'en');
        $mode = (string) ($payload['mode'] ?? 'new');
        $import = $payload['import'] ?? [];

        if (! is_array($import)) {
            jsonResponse(['ok' => false, 'message' => 'Invalid import payload.'], 422);
        }

        $created = [];
        $currentSlug = null;
        $definitions = $import['definitions'] ?? null;

        if (is_array($definitions)) {
            if(count($definitions) > 20){
                jsonResponse(['ok' => false, 'message' => 'Too many definitions: max 20 per import.'], 422);
            }
            foreach ($definitions as $definition) {
                if (is_array($definition)) {
                    $checklist = $repository->importDefinition($definition, $lang);
                    $created[] = $checklist;
                    $currentSlug = (string) $checklist['slug'];
                }
            }
        } elseif (isset($import['definition']) && is_array($import['definition'])) {
            $checklist = $repository->importDefinition($import['definition'], $lang);
            $created[] = $checklist;
            $currentSlug = (string) $checklist['slug'];
        }

        if (isset($import['states']) && is_array($import['states'])) {
            foreach ($import['states'] as $stateSlug => $stateRows) {
                if (! is_string($stateSlug) || ! is_array($stateRows)) {
                    continue;
                }
                if ($mode === 'current' && $currentSlug) {
                    $storage->import($currentSlug, $stateRows, false);
                } elseif ($repository->find($stateSlug, $lang)) {
                    $storage->import($stateSlug, $stateRows, false);
                }
            }
        }

        jsonResponse(['ok' => true, 'checklists' => $repository->all($lang), 'created' => $created]);
    }
} catch (Throwable $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 500);
}

if (! extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SQLite extension missing</title><body style="font-family:system-ui;padding:32px;background:#f8f1e7;color:#1f2937"><main style="max-width:780px;margin:auto;background:white;border-radius:28px;padding:30px;box-shadow:0 24px 70px rgba(15,23,42,.10)"><h1>PHP SQLite extension is missing</h1><p>Install it in WSL, then restart the app:</p><pre style="background:#f3f4f6;border-radius:16px;padding:16px;overflow:auto">sudo apt update
sudo apt install -y php-sqlite3 sqlite3
php -m | grep -i sqlite
./start.sh</pre></main></body></html>';
    exit;
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>Local Checklist Studio</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="topbar">
      <div class="container topbar-inner">
        <div class="brand-area">
          <button id="menuButton" type="button" class="icon-btn" data-action="open-menu" aria-label="Open menu">☰</button>
          <div class="logo" aria-hidden="true">CS</div>
          <div class="brand-copy">
            <p id="appKicker" class="kicker">Local planning tool</p>
            <h1 id="appTitle" class="brand-title">Checklist Studio</h1>
          </div>
        </div>
        <div class="top-actions">
          <select id="languageSelect" class="select" aria-label="Language"></select>
          <button id="exportTopButton" type="button" class="btn desktop-only" data-action="open-export-modal">Export</button>
          <button id="newChecklistTopButton" type="button" class="btn btn-success" data-action="open-checklist-modal">New checklist</button>
        </div>
      </div>
    </header>

    <main class="container">
      <section class="hero">
        <div class="hero-card">
          <span id="heroBadge" class="badge-soft">Premium local workspace</span>
          <h2 id="heroTitle" class="hero-title">Organize any project with clarity.</h2>
          <p id="heroDescription" class="hero-description">Create reusable checklists, track resolved tasks, record detected issues and export exactly what you need.</p>
          <p id="offlineLabel" class="hero-description" style="font-size:12px;margin-top:10px">No CDN. No external dependency. Local SQLite only.</p>
        </div>
        <div class="stats-card" aria-label="Statistics">
          <div class="stat progress"><span id="statProgressLabel">Progress</span><strong id="statProgress">0%</strong></div>
          <div class="stat done"><span id="statDoneLabel">Resolved</span><strong id="statDone">0/0</strong></div>
          <div class="stat issue"><span id="statIssuesLabel">Issues</span><strong id="statIssues">0</strong></div>
          <div class="stat"><span id="statBlocksLabel">Blocks</span><strong id="statBlocks">0</strong></div>
        </div>
      </section>

      <section class="controls" aria-label="Checklist filters">
        <input id="searchInput" class="field" type="search" placeholder="Search tasks, notes or descriptions...">
        <select id="priorityFilter" class="select"></select>
        <select id="statusFilter" class="select"></select>
        <button id="addBlockButton" class="btn btn-primary" type="button" data-action="open-section-modal">Add block</button>
      </section>

      <section id="sections" class="sections" aria-live="polite"></section>
      <section id="emptyState" class="empty-card" hidden></section>
    </main>

    <div id="drawerBackdrop" class="drawer-backdrop" data-action="drawer-backdrop" hidden></div>
    <aside id="drawer" class="drawer" aria-hidden="true" aria-label="Navigation menu">
      <div class="drawer-head">
        <div>
          <p class="kicker">Menu</p>
          <h2 id="drawerTitle" class="drawer-title">Menu</h2>
          <p id="drawerSubtitle" class="drawer-subtitle">Offline-first local checklist manager</p>
        </div>
        <button id="closeDrawerButton" class="icon-btn" type="button" data-action="close-menu" aria-label="Close menu">×</button>
      </div>
      <nav id="drawerMenu"></nav>
    </aside>

    <div id="modalRoot"></div>
    <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
  </div>
  <script src="/assets/js/app.js"></script>
</body>
</html>
