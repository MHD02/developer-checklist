# Local Checklist Studio

Local Checklist Studio is a local-first checklist and roadmap manager built with PHP, SQLite, HTML, CSS and JavaScript.

It helps organize projects into checklists, sections, tasks and detected issues. The app runs on your machine and stores progress in a local SQLite database.

It does not require MySQL, Composer, Node.js, a PHP framework, or an external service.

## Features

- Create and manage multiple checklists
- Organize checklists into sections and tasks
- Add, edit, delete and reorder sections
- Add, edit, delete and reorder tasks
- Mark tasks as resolved
- Mark tasks as having a detected issue
- Add notes for detected issues
- Navigate directly to detected issues
- Track progress per checklist and per section
- Import and export checklist data
- Store progress locally with SQLite
- Use separate files for each checklist language
- Use separate files for the application interface translations

## Requirements

- PHP 8.2 or higher
- PHP SQLite extension
- SQLite CLI, optional but useful

On Ubuntu or WSL:

```bash
sudo apt update
sudo apt install -y php-sqlite3 sqlite3
```

Verify SQLite support:

```bash
php -m | grep -i sqlite
```

Expected output:

```txt
pdo_sqlite
sqlite3
```

## Installation

Clone or download the project, then enter the project folder:

```bash
cd checklist-locale-sqlite
```

Make the start script executable:

```bash
chmod +x start.sh
```

Start the local server:

```bash
./start.sh
```

Open the app:

```txt
http://127.0.0.1:8088
```

Stop the server with:

```txt
CTRL + C
```

## Project Structure

```txt
public/
  index.php
  assets/
    css/
      app.css
    js/
      app.js

src/
  ChecklistRepository.php
  ChecklistStorage.php
  I18nRepository.php

data/
  checklists/
    example-project/
      en.json
      fr.json
  lang/
    en.json
    fr.json

database/
  .gitkeep

storage/
  backups/
    .gitkeep
  exports/
    .gitkeep

README.md
start.sh
.gitignore
```

## Data Storage

The project uses two storage types.

### Checklist definitions

Checklist definitions are stored as JSON files:

```txt
data/checklists/<checklist-slug>/<language>.json
```

Example:

```txt
data/checklists/example-project/en.json
data/checklists/example-project/fr.json
```

These files contain:

- checklist title
- checklist description
- sections
- section descriptions
- tasks
- task descriptions
- task priorities
- stable task IDs

### Progress data

Progress is stored in SQLite:

```txt
database/checklist.sqlite
```

This database stores:

- resolved task status
- detected issue status
- issue notes
- saved task state

Do not delete `database/checklist.sqlite` if you want to keep your progress.

## Language System

The app separates two types of translations.

### Interface language

Interface translations are stored in:

```txt
data/lang/
```

Example:

```txt
data/lang/en.json
data/lang/fr.json
```

These files translate the app interface, such as buttons, menus, filters, labels and messages.

### Checklist content language

Checklist content is stored per checklist and per language:

```txt
data/checklists/<checklist-slug>/<language>.json
```

Example:

```txt
data/checklists/example-project/en.json
data/checklists/example-project/fr.json
```

This allows the English interface to display English checklist content, and the French interface to display French checklist content.

## Adding a New Language

Example with Spanish.

Create the interface language file:

```txt
data/lang/es.json
```

Then create the checklist content file:

```txt
data/checklists/example-project/es.json
```

If a checklist does not exist in the selected language, the app can fall back to English.

## Checklist JSON Format

Example:

```json
{
  "id": "example-project",
  "title": "Example Project Checklist",
  "description": "A simple checklist for organizing a project.",
  "sections": [
    {
      "id": "section-setup",
      "title": "Setup",
      "description": "Prepare the project environment.",
      "tasks": [
        {
          "id": "task-install-dependencies",
          "title": "Install dependencies",
          "description": "Install everything required to run the project.",
          "priority": "high"
        }
      ]
    }
  ]
}
```

## Stable IDs

Each checklist, section and task should have a stable ID.

Good examples:

```txt
example-project
section-security
task-enable-csrf
task-create-backup
```

Avoid position-based IDs such as:

```txt
task-1
task-2
s0-i0
```

Stable IDs prevent progress from being attached to the wrong task when a checklist is edited or reordered.

## Task Priorities

Supported priority values:

```txt
critical
high
medium
low
```

These values are stored internally in English and can be displayed differently depending on the selected language.

Example:

```txt
critical -> Critical / Critique
high     -> High / Haute
medium   -> Medium / Moyenne
low      -> Low / Basse
```

## Detected Issues

Each task can be marked as having a detected issue.

When an issue is detected, the app displays a note field where you can document:

- the issue title
- the problem description
- the expected behavior
- the current behavior
- affected files or pages
- priority
- additional notes

Issue notes are stored in SQLite.

## Import and Export

The app supports JSON import and export.

Depending on the selected options, you can export:

- the current checklist
- all checklists
- checklist definitions only
- progress only
- detected issues only
- complete data

You can also choose the export file name.

If the browser supports the File System Access API, the app may allow you to choose where the file is saved. Otherwise, the browser will use the default download behavior.

## Local Backups

Backup files may be created in:

```txt
storage/backups/
```

Exported files may be created in:

```txt
storage/exports/
```

These files are local runtime files and should not be committed to Git.

## Security

This app is intended for local use only.

Included security measures:

- local access only
- CSRF protection for write operations
- JSON payload size limits
- validation of checklist slugs and IDs
- SQLite access through PDO
- prepared SQL statements
- escaped output in the interface
- no required remote scripts

Do not expose this app publicly without adding authentication, authorization, rate limiting, HTTPS, secure sessions and deployment hardening.

## Recommended `.gitignore`

```gitignore
# Local database
/database/*.sqlite
/database/*.sqlite-*
/database/*.db
/database/*.db-*

# Runtime storage
/storage/backups/*
/storage/exports/*

!/storage/backups/.gitkeep
!/storage/exports/.gitkeep

# Personal or private checklists
/data/checklists/mine/
/data/checklists/private/
/data/checklists/local/

# Environment files
.env
.env.*
!.env.example

# Logs
*.log

# Archives
*.zip
*.tar
*.tar.gz
*.rar
*.7z

# Editors
.vscode/
.idea/
.history/

# OS files
.DS_Store
Thumbs.db
```

## Troubleshooting

### SQLite driver missing

Error example:

```txt
PDOException: could not find driver
```

Install SQLite support:

```bash
sudo apt update
sudo apt install -y php-sqlite3 sqlite3
```

Then verify:

```bash
php -m | grep -i sqlite
```

### Port already in use

The default port is:

```txt
8088
```

Find running PHP servers:

```bash
ps aux | grep "php -S"
```

Stop them:

```bash
pkill -f "php -S"
```

### Progress disappeared

Progress is stored in:

```txt
database/checklist.sqlite
```

If this file is deleted, progress is lost unless you have an export or backup.

### Interface language changed but checklist content did not

Make sure the checklist has a file for the selected language:

```txt
data/checklists/<checklist-slug>/<language>.json
```

Example:

```txt
data/checklists/example-project/fr.json
```

## Recommended Workflow

1. Create or open a checklist.
2. Organize it into sections.
3. Add tasks with clear titles and priorities.
4. Mark tasks as resolved when completed.
5. Mark tasks as having a detected issue when something needs attention.
6. Add issue notes when needed.
7. Export data regularly as a backup.
8. Keep the SQLite database if you want to preserve local progress.

## Limitations

- Local use only
- No authentication
- No multi-user collaboration
- SQLite database stored locally
- Not intended for public hosting without additional security

## License

Add your license here.

Example:

```txt
MIT License
```
