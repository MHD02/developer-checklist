# Local Checklist Studio — Premium SQLite v6

Local Checklist Studio is a small local-first checklist manager built for project planning and issue tracking. It was created as a separate helper tool, independent from any e-commerce project.

## What changed in v6

- No CDN dependency anymore. The previous version could render as an empty/skeleton page if Tailwind/Alpine CDNs failed to load. This version is fully local and reliable.
- Burger menu fixed: it opens and closes correctly.
- No blur when the menu is open. The page stays visible.
- Premium UI rebuilt with a softer, professional palette.
- Submenus are folded by default.
- Recommended blocks and detected issues are separated in the menu.
- Section headers show both resolved count and detected issue count.
- A section header switches to a controlled danger/copper tone when it contains issues.
- Stable task IDs are preserved.
- Full front-end CRUD: create/edit/delete/reorder checklists, blocks and tasks.
- Import/export restored and improved.
- Language default is English, with French included.
- Checklist content is language-specific through `data/checklists/<slug>/<lang>.json`.
- Progress, issue status and issue notes are saved in SQLite.

## Requirements

- PHP 8.2+
- PHP SQLite extension
- SQLite CLI optional but useful

Install SQLite support in WSL/Ubuntu:

```bash
sudo apt update
sudo apt install -y php-sqlite3 sqlite3
php -m | grep -i sqlite
```

## Start

```bash
cd checklist-locale-sqlite-premium-v6
chmod +x start.sh
./start.sh
```

Open:

```txt
http://127.0.0.1:8088
```

## Data structure

Checklist definitions are stored here:

```txt
data/checklists/<checklist-slug>/en.json
data/checklists/<checklist-slug>/fr.json
```

Translations for the app UI are stored here:

```txt
data/lang/en.json
data/lang/fr.json
```

SQLite progress is stored here:

```txt
database/checklist.sqlite
```

Do not delete `database/checklist.sqlite` if you want to keep your progress.

## Import / Export

The export modal lets you choose:

- current checklist or all checklists
- complete export
- definition only
- progress only
- detected issues only
- file name
- save location when the browser supports the File System Access API

If the browser does not support choosing a folder, it falls back to a normal JSON download.

## Security

This app is designed for local use only.

Security measures:

- local IP only (`127.0.0.1`, `::1`, `localhost`)
- CSRF token for write operations
- JSON payload size limit
- strict slugs and IDs validation
- SQLite through PDO prepared statements
- defensive output escaping
- no remote scripts required

Do not expose this app publicly without adding real authentication.

## Adding a new language

1. Add a UI language file:

```txt
data/lang/es.json
```

2. Add checklist content for the same language:

```txt
data/checklists/your-checklist/es.json
```

If a checklist does not exist in a selected language, the app falls back to English.
