<?php

declare(strict_types=1);

final class ChecklistRepository
{
    private const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public function __construct(private readonly string $directory)
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $language = 'en'): array
    {
        $language = $this->normaliseLanguage($language);
        $folders = glob($this->directory . '/*', GLOB_ONLYDIR) ?: [];
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        $checklists = [];

        foreach ($folders as $folder) {
            $slug = basename($folder);
            if (! $this->isValidSlug($slug)) {
                continue;
            }

            $definition = $this->find($slug, $language);
            if ($definition) {
                $checklists[] = [
                    'slug' => $definition['slug'],
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'language' => $definition['language'],
                    'available_languages' => $this->availableLanguages($slug),
                    'sections_count' => $definition['sections_count'],
                    'tasks_count' => $definition['tasks_count'],
                    'updated_at' => $definition['updated_at'],
                ];
            }
        }

        usort($checklists, fn (array $a, array $b): int => strcmp((string) $a['title'], (string) $b['title']));
        return $checklists;
    }

    public function find(string $slug, string $language = 'en'): ?array
    {
        $this->assertValidSlug($slug);
        $language = $this->normaliseLanguage($language);
        $file = $this->fileFor($slug, $language);

        if (! is_file($file)) {
            $file = $this->fileFor($slug, 'en');
        }

        if (! is_file($file)) {
            $files = glob($this->folderFor($slug) . '/*.json') ?: [];
            $file = $files[0] ?? '';
        }

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($file) ?: '{}', true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->normaliseChecklist($decoded, $slug, basename($file, '.json'));
    }

    /** @return array<string, mixed> */
    public function create(string $language, string $title, string $description = ''): array
    {
        $language = $this->normaliseLanguage($language);
        $title = $this->cleanText($title, 140);
        $description = $this->cleanText($description, 600);

        if ($title === '') {
            throw new InvalidArgumentException('Checklist title is required.');
        }

        $slug = $this->uniqueSlug($this->slugify($title));
        $definition = [
            'meta' => [
                'slug' => $slug,
                'title' => $title,
                'description' => $description !== '' ? $description : 'Local checklist.',
                'language' => $language,
                'version' => 5,
                'updated_at' => gmdate('c'),
            ],
            'sections' => [],
        ];

        $this->write($slug, $language, $definition);

        if ($language !== 'en') {
            $english = $definition;
            $english['meta']['language'] = 'en';
            $this->write($slug, 'en', $english);
        }

        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be created.');
    }

    public function updateChecklist(string $slug, string $language, string $title, string $description): array
    {
        $this->assertValidSlug($slug);
        $language = $this->normaliseLanguage($language);
        $definition = $this->raw($slug, $language);
        $definition['meta']['title'] = $this->cleanText($title, 140) ?: (string) ($definition['meta']['title'] ?? 'Checklist');
        $definition['meta']['description'] = $this->cleanText($description, 600);
        $this->write($slug, $language, $definition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be updated.');
    }

    public function delete(string $slug): void
    {
        $this->assertValidSlug($slug);
        $folder = $this->folderFor($slug);
        if (! is_dir($folder)) {
            return;
        }

        $this->backupDefinition($slug);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($folder);
    }

    public function addSection(string $slug, string $language, string $title, string $description = ''): array
    {
        $definition = $this->raw($slug, $language);
        $title = $this->cleanText($title, 140);
        $description = $this->cleanText($description, 600);
        if ($title === '') {
            throw new InvalidArgumentException('Block title is required.');
        }
        $definition['sections'][] = [
            'id' => $this->uniqueSectionId($definition, $title),
            'title' => $title,
            'description' => $description,
            'tasks' => [],
        ];
        $this->write($slug, $this->normaliseLanguage($language), $definition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
    }

    public function updateSection(string $slug, string $language, string $sectionId, string $title, string $description = ''): array
    {
        $this->assertValidId($sectionId, 'section');
        $definition = $this->raw($slug, $language);
        foreach ($definition['sections'] as &$section) {
            if (($section['id'] ?? '') === $sectionId) {
                $section['title'] = $this->cleanText($title, 140) ?: (string) ($section['title'] ?? 'Block');
                $section['description'] = $this->cleanText($description, 600);
                $this->write($slug, $this->normaliseLanguage($language), $definition);
                return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
            }
        }
        throw new InvalidArgumentException('Block not found.');
    }

    public function deleteSection(string $slug, string $language, string $sectionId): array
    {
        $this->assertValidId($sectionId, 'section');
        $definition = $this->raw($slug, $language);
        $definition['sections'] = array_values(array_filter($definition['sections'], fn (array $section): bool => ($section['id'] ?? '') !== $sectionId));
        $this->write($slug, $this->normaliseLanguage($language), $definition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
    }

    public function moveSection(string $slug, string $language, string $sectionId, string $direction): array
    {
        $this->assertValidId($sectionId, 'section');
        $definition = $this->raw($slug, $language);
        $sections = $definition['sections'];
        $index = array_search($sectionId, array_column($sections, 'id'), true);
        if ($index === false) {
            throw new InvalidArgumentException('Block not found.');
        }
        $newIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($newIndex >= 0 && $newIndex < count($sections)) {
            [$sections[$index], $sections[$newIndex]] = [$sections[$newIndex], $sections[$index]];
        }
        $definition['sections'] = array_values($sections);
        $this->write($slug, $this->normaliseLanguage($language), $definition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
    }

    public function addTask(string $slug, string $language, string $sectionId, string $title, string $description, string $priority): array
    {
        $this->assertValidId($sectionId, 'section');
        $definition = $this->raw($slug, $language);
        $title = $this->cleanText($title, 240);
        $description = $this->cleanText($description, 1200);
        $priority = $this->normalisePriority($priority);

        if ($title === '') {
            throw new InvalidArgumentException('Objective title is required.');
        }

        foreach ($definition['sections'] as &$section) {
            if (($section['id'] ?? '') === $sectionId) {
                $section['tasks'][] = [
                    'id' => $this->uniqueTaskId($definition, $title),
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority,
                ];
                $this->write($slug, $this->normaliseLanguage($language), $definition);
                return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
            }
        }
        throw new InvalidArgumentException('Target block is invalid.');
    }

    public function updateTask(string $slug, string $language, string $taskId, string $title, string $description, string $priority): array
    {
        $this->assertValidId($taskId, 'task');
        $definition = $this->raw($slug, $language);
        foreach ($definition['sections'] as &$section) {
            foreach ($section['tasks'] as &$task) {
                if (($task['id'] ?? '') === $taskId) {
                    $task['title'] = $this->cleanText($title, 240) ?: (string) ($task['title'] ?? 'Objective');
                    $task['description'] = $this->cleanText($description, 1200);
                    $task['priority'] = $this->normalisePriority($priority);
                    $this->write($slug, $this->normaliseLanguage($language), $definition);
                    return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
                }
            }
        }
        throw new InvalidArgumentException('Task not found.');
    }

    public function deleteTask(string $slug, string $language, string $taskId): array
    {
        $this->assertValidId($taskId, 'task');
        $definition = $this->raw($slug, $language);
        foreach ($definition['sections'] as &$section) {
            $section['tasks'] = array_values(array_filter($section['tasks'], fn (array $task): bool => ($task['id'] ?? '') !== $taskId));
        }
        $this->write($slug, $this->normaliseLanguage($language), $definition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
    }

    public function moveTask(string $slug, string $language, string $taskId, string $direction): array
    {
        $this->assertValidId($taskId, 'task');
        $definition = $this->raw($slug, $language);
        foreach ($definition['sections'] as &$section) {
            $tasks = $section['tasks'];
            $index = array_search($taskId, array_column($tasks, 'id'), true);
            if ($index === false) {
                continue;
            }
            $newIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($newIndex >= 0 && $newIndex < count($tasks)) {
                [$tasks[$index], $tasks[$newIndex]] = [$tasks[$newIndex], $tasks[$index]];
            }
            $section['tasks'] = array_values($tasks);
            $this->write($slug, $this->normaliseLanguage($language), $definition);
            return $this->find($slug, $language) ?? throw new RuntimeException('Checklist could not be reloaded.');
        }
        throw new InvalidArgumentException('Task not found.');
    }

    public function importDefinition(array $payload, string $language = 'en'): array
    {
        $language = $this->normaliseLanguage($language);
        $definition = $payload['definition'] ?? $payload['checklist'] ?? $payload;
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Imported checklist definition is invalid.');
        }
        $normalised = $this->normaliseChecklist($definition, 'imported-checklist', $language);
        $slug = $this->uniqueSlug($this->slugify((string) $normalised['title']));
        $normalised['slug'] = $slug;
        $safeDefinition = $this->definitionFromNormalised($normalised, $language);
        $this->write($slug, $language, $safeDefinition);
        return $this->find($slug, $language) ?? throw new RuntimeException('Imported checklist could not be loaded.');
    }

    public function exportDefinition(string $slug, string $language): array
    {
        return $this->raw($slug, $language);
    }

    private function raw(string $slug, string $language): array
    {
        $this->assertValidSlug($slug);
        $language = $this->normaliseLanguage($language);
        $file = $this->fileFor($slug, $language);

        if (! is_file($file)) {
            $fallback = $this->fileFor($slug, 'en');
            if (! is_file($fallback)) {
                throw new InvalidArgumentException('Checklist not found.');
            }
            $source = json_decode(file_get_contents($fallback) ?: '{}', true);
            if (! is_array($source)) {
                throw new RuntimeException('Checklist JSON is invalid.');
            }
            $source['meta']['language'] = $language;
            $this->write($slug, $language, $source);
        }

        $decoded = json_decode(file_get_contents($file) ?: '{}', true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Checklist JSON is invalid.');
        }
        return $this->definitionFromNormalised($this->normaliseChecklist($decoded, $slug, $language), $language);
    }

    private function write(string $slug, string $language, array $definition): void
    {
        $this->assertValidSlug($slug);
        $language = $this->normaliseLanguage($language);
        if (! is_dir($this->folderFor($slug))) {
            mkdir($this->folderFor($slug), 0775, true);
        }
        $definition['meta']['slug'] = $slug;
        $definition['meta']['language'] = $language;
        $definition['meta']['version'] = (int) ($definition['meta']['version'] ?? 5);
        $definition['meta']['updated_at'] = gmdate('c');
        $encoded = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new RuntimeException('Checklist could not be encoded.');
        }
        file_put_contents($this->fileFor($slug, $language), $encoded . PHP_EOL, LOCK_EX);
    }

    private function normaliseChecklist(array $definition, string $fallbackSlug, string $language): array
    {
        $meta = is_array($definition['meta'] ?? null) ? $definition['meta'] : [];
        $slug = (string) ($meta['slug'] ?? $fallbackSlug);
        if (! $this->isValidSlug($slug)) {
            $slug = $fallbackSlug;
        }
        $sections = [];
        foreach (($definition['sections'] ?? []) as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            $sectionId = (string) ($section['id'] ?? $this->uniqueSectionId(['sections' => $sections], (string) ($section['title'] ?? 'section')));
            if (! preg_match('/^section-[a-z0-9][a-z0-9-]{1,120}$/', $sectionId)) {
                $sectionId = 'section-' . ($sectionIndex + 1) . '-' . $this->slugify((string) ($section['title'] ?? 'section'));
            }
            $tasks = [];
            $rawTasks = $section['tasks'] ?? $section['items'] ?? [];
            foreach ($rawTasks as $taskIndex => $task) {
                if (is_array($task) && array_is_list($task)) {
                    $task = ['title' => (string) ($task[0] ?? ''), 'priority' => (string) ($task[1] ?? 'medium')];
                }
                if (! is_array($task)) {
                    continue;
                }
                $title = $this->cleanText((string) ($task['title'] ?? $task['label'] ?? ''), 240);
                if ($title === '') {
                    continue;
                }
                $taskId = (string) ($task['id'] ?? ('task-' . ($sectionIndex + 1) . '-' . ($taskIndex + 1) . '-' . $this->slugify($title)));
                if (! preg_match('/^task-[a-z0-9][a-z0-9-]{1,120}$/', $taskId)) {
                    $taskId = 'task-' . ($sectionIndex + 1) . '-' . ($taskIndex + 1) . '-' . $this->slugify($title);
                }
                $tasks[] = [
                    'id' => mb_substr($taskId, 0, 125),
                    'title' => $title,
                    'description' => $this->cleanText((string) ($task['description'] ?? ''), 1200),
                    'priority' => $this->normalisePriority((string) ($task['priority'] ?? 'medium')),
                ];
            }
            $title = $this->cleanText((string) ($section['title'] ?? 'Block'), 160);
            $sections[] = [
                'id' => mb_substr($sectionId, 0, 128),
                'title' => $title !== '' ? $title : 'Block',
                'description' => $this->cleanText((string) ($section['description'] ?? ''), 700),
                'tasks' => $tasks,
            ];
        }
        return [
            'slug' => $slug,
            'title' => $this->cleanText((string) ($meta['title'] ?? $this->titleFromSlug($slug)), 160),
            'description' => $this->cleanText((string) ($meta['description'] ?? ''), 700),
            'language' => $language,
            'version' => (int) ($meta['version'] ?? 5),
            'updated_at' => (string) ($meta['updated_at'] ?? ''),
            'available_languages' => $this->availableLanguages($slug),
            'sections' => $sections,
            'sections_count' => count($sections),
            'tasks_count' => array_sum(array_map(fn (array $s): int => count($s['tasks']), $sections)),
        ];
    }

    private function definitionFromNormalised(array $normalised, string $language): array
    {
        return [
            'meta' => [
                'slug' => $normalised['slug'],
                'title' => $normalised['title'],
                'description' => $normalised['description'],
                'language' => $language,
                'version' => $normalised['version'] ?? 5,
                'updated_at' => gmdate('c'),
            ],
            'sections' => $normalised['sections'],
        ];
    }

    /** @return array<int, string> */
    private function availableLanguages(string $slug): array
    {
        if (! $this->isValidSlug($slug) || ! is_dir($this->folderFor($slug))) {
            return [];
        }
        $languages = [];
        foreach (glob($this->folderFor($slug) . '/*.json') ?: [] as $file) {
            $language = basename($file, '.json');
            if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language)) {
                $languages[] = $language;
            }
        }
        sort($languages);
        return $languages;
    }

    private function backupDefinition(string $slug): void
    {
        $backupDir = dirname($this->directory) . '/../storage/backups';
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        if (! class_exists('ZipArchive')) {
            return;
        }

        $backupFile = rtrim($backupDir, '/') . '/' . $slug . '-' . gmdate('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }
        foreach (glob($this->folderFor($slug) . '/*.json') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();
    }

    private function uniqueSectionId(array $definition, string $title): string
    {
        return $this->uniqueId($definition, 'section', $title);
    }

    private function uniqueTaskId(array $definition, string $title): string
    {
        return $this->uniqueId($definition, 'task', $title);
    }

    private function uniqueId(array $definition, string $prefix, string $title): string
    {
        $base = $prefix . '-' . $this->slugify($title);
        $ids = [];
        foreach (($definition['sections'] ?? []) as $section) {
            if (is_array($section)) {
                if (isset($section['id'])) {
                    $ids[] = $section['id'];
                }
                foreach (($section['tasks'] ?? []) as $task) {
                    if (is_array($task) && isset($task['id'])) {
                        $ids[] = $task['id'];
                    }
                }
            }
        }
        $candidate = $base;
        $i = 2;
        while (in_array($candidate, $ids, true)) {
            $candidate = $base . '-' . $i++;
        }
        return mb_substr($candidate, 0, 125);
    }

    private function uniqueSlug(string $baseSlug): string
    {
        $candidate = $baseSlug ?: 'checklist';
        $slug = $candidate;
        $i = 2;
        while (is_dir($this->folderFor($slug))) {
            $slug = $candidate . '-' . $i++;
        }
        return $slug;
    }

    private function folderFor(string $slug): string
    {
        $this->assertValidSlug($slug);
        return rtrim($this->directory, '/') . '/' . $slug;
    }

    private function fileFor(string $slug, string $language): string
    {
        $this->assertValidSlug($slug);
        $language = $this->normaliseLanguage($language);
        return $this->folderFor($slug) . '/' . $language . '.json';
    }

    private function normalisePriority(string $priority): string
    {
        $map = ['critique' => 'critical', 'haute' => 'high', 'moyenne' => 'medium', 'basse' => 'low'];
        $priority = strtolower(trim($priority));
        $priority = $map[$priority] ?? $priority;
        return in_array($priority, self::PRIORITIES, true) ? $priority : 'medium';
    }

    private function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return mb_substr($value !== '' ? $value : 'item', 0, 70);
    }

    private function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_substr($value, 0, $maxLength);
    }

    private function normaliseLanguage(string $language): string
    {
        return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) ? $language : 'en';
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug);
    }

    private function assertValidSlug(string $slug): void
    {
        if (! $this->isValidSlug($slug)) {
            throw new InvalidArgumentException('Invalid checklist identifier.');
        }
    }

    private function assertValidId(string $id, string $prefix): void
    {
        if (! preg_match('/^' . preg_quote($prefix, '/') . '-[a-z0-9][a-z0-9-]{1,120}$/', $id)) {
            throw new InvalidArgumentException('Invalid identifier.');
        }
    }
}
