<?php

declare(strict_types=1);

final class ChecklistStorage
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS task_states (
                checklist_slug TEXT NOT NULL,
                task_id TEXT NOT NULL,
                done INTEGER NOT NULL DEFAULT 0,
                problem INTEGER NOT NULL DEFAULT 0,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (checklist_slug, task_id)
            )
        SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_states_problem ON task_states(checklist_slug, problem)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_states_done ON task_states(checklist_slug, done)');
    }

    /**
     * @return array<string, array{done: bool, problem: bool, note: string, updated_at: string}>
     */
    public function all(string $checklistSlug): array
    {
        $this->assertValidSlug($checklistSlug);

        $statement = $this->pdo->prepare('SELECT task_id, done, problem, note, updated_at FROM task_states WHERE checklist_slug = :slug ORDER BY task_id ASC');
        $statement->execute([':slug' => $checklistSlug]);

        $states = [];

        foreach ($statement->fetchAll() as $row) {
            $states[(string) $row['task_id']] = [
                'done' => (bool) $row['done'],
                'problem' => (bool) $row['problem'],
                'note' => (string) $row['note'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $states;
    }

    public function save(string $checklistSlug, string $taskId, bool $done, bool $problem, string $note): void
    {
        $this->assertValidSlug($checklistSlug);
        $this->assertValidId($taskId, 'task');

        $note = mb_substr($note, 0, 15000);
        $now = gmdate('c');

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO task_states (checklist_slug, task_id, done, problem, note, created_at, updated_at)
            VALUES (:checklist_slug, :task_id, :done, :problem, :note, :created_at, :updated_at)
            ON CONFLICT(checklist_slug, task_id) DO UPDATE SET
                done = excluded.done,
                problem = excluded.problem,
                note = excluded.note,
                updated_at = excluded.updated_at
        SQL);

        $statement->execute([
            ':checklist_slug' => $checklistSlug,
            ':task_id' => $taskId,
            ':done' => $done ? 1 : 0,
            ':problem' => $problem ? 1 : 0,
            ':note' => $note,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function import(string $checklistSlug, array $states, bool $replace = false): void
    {
        $this->assertValidSlug($checklistSlug);
        $this->pdo->beginTransaction();

        try {
            if ($replace) {
                $this->reset($checklistSlug);
            }

            foreach ($states as $taskId => $state) {
                if (! is_string($taskId) || ! is_array($state)) {
                    continue;
                }

                if (! preg_match('/^task-[a-z0-9][a-z0-9-]{1,120}$/', $taskId)) {
                    continue;
                }

                $this->save(
                    $checklistSlug,
                    $taskId,
                    (bool) ($state['done'] ?? false),
                    (bool) ($state['problem'] ?? false),
                    (string) ($state['note'] ?? '')
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function reset(string $checklistSlug): void
    {
        $this->assertValidSlug($checklistSlug);
        $statement = $this->pdo->prepare('DELETE FROM task_states WHERE checklist_slug = :slug');
        $statement->execute([':slug' => $checklistSlug]);
    }

    public function deleteChecklistStates(string $checklistSlug): void
    {
        $this->reset($checklistSlug);
    }

    /**
     * @return array<string, array<string, array{done: bool, problem: bool, note: string, updated_at: string}>>
     */
    public function allForExport(?string $checklistSlug = null): array
    {
        if ($checklistSlug !== null) {
            return [$checklistSlug => $this->all($checklistSlug)];
        }

        $rows = $this->pdo->query('SELECT checklist_slug, task_id, done, problem, note, updated_at FROM task_states ORDER BY checklist_slug, task_id')->fetchAll();
        $result = [];

        foreach ($rows as $row) {
            $slug = (string) $row['checklist_slug'];
            $result[$slug][(string) $row['task_id']] = [
                'done' => (bool) $row['done'],
                'problem' => (bool) $row['problem'],
                'note' => (string) $row['note'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $result;
    }

    private function assertValidSlug(string $slug): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug)) {
            throw new InvalidArgumentException('Invalid checklist identifier.');
        }
    }

    private function assertValidId(string $id, string $prefix): void
    {
        if (! preg_match('/^' . preg_quote($prefix, '/') . '-[a-z0-9][a-z0-9-]{1,120}$/', $id)) {
            throw new InvalidArgumentException('Invalid task identifier.');
        }
    }
}
