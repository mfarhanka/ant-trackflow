<?php
declare(strict_types=1);

function trackflow_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDirectory)) {
        mkdir($dataDirectory, 0777, true);
    }

    $databasePath = $dataDirectory . DIRECTORY_SEPARATOR . 'trackflow.sqlite';
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initialize_trackflow_database($pdo);

    return $pdo;
}

function initialize_trackflow_database(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "Active"
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contributors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            contributor_id INTEGER,
            log_date TEXT NOT NULL,
            task TEXT NOT NULL,
            status TEXT NOT NULL,
            note TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        )'
    );

    ensure_logs_has_contributor_column($pdo);

    $contributorCount = (int) $pdo->query('SELECT COUNT(*) FROM contributors')->fetchColumn();
    if ($contributorCount === 0) {
        $contributorStatement = $pdo->prepare('INSERT INTO contributors (name) VALUES (:name)');
        foreach (['Farhan', 'Alya', 'Rizky'] as $name) {
            $contributorStatement->execute([':name' => $name]);
        }
    }

    $projectCount = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($projectCount > 0) {
        return;
    }

    $projectStatement = $pdo->prepare('INSERT INTO projects (name, status) VALUES (:name, :status)');
    $contributors = $pdo->query('SELECT id, name FROM contributors ORDER BY id')->fetchAll();
    $contributorsByName = [];
    foreach ($contributors as $contributor) {
        $contributorsByName[$contributor['name']] = (int) $contributor['id'];
    }
    $logStatement = $pdo->prepare(
        'INSERT INTO logs (project_id, contributor_id, log_date, task, status, note)
         VALUES (:project_id, :contributor_id, :log_date, :task, :status, :note)'
    );

    $seedProjects = [
        [
            'name' => 'Shop Signage',
            'status' => 'Active',
            'logs' => [
                [
                    'contributor_name' => 'Farhan',
                    'log_date' => '2026-06-02',
                    'task' => 'Site Survey',
                    'status' => 'Done',
                    'note' => 'Measurements finalized.',
                ],
            ],
        ],
        [
            'name' => 'Renovation Phase 2',
            'status' => 'Delayed',
            'logs' => [
                [
                    'contributor_name' => 'Alya',
                    'log_date' => '2026-06-01',
                    'task' => 'Plumbing',
                    'status' => 'Done',
                    'note' => 'Pipe installation complete.',
                ],
            ],
        ],
        [
            'name' => 'Inventory Audit',
            'status' => 'Active',
            'logs' => [],
        ],
    ];

    foreach ($seedProjects as $project) {
        $projectStatement->execute([
            ':name' => $project['name'],
            ':status' => $project['status'],
        ]);

        $projectId = (int) $pdo->lastInsertId();
        foreach ($project['logs'] as $log) {
            $logStatement->execute([
                ':project_id' => $projectId,
                ':contributor_id' => $contributorsByName[$log['contributor_name']] ?? null,
                ':log_date' => $log['log_date'],
                ':task' => $log['task'],
                ':status' => $log['status'],
                ':note' => $log['note'],
            ]);
        }
    }
}

function ensure_logs_has_contributor_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(logs)')->fetchAll();
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'contributor_id') {
            return;
        }
    }

    $pdo->exec('ALTER TABLE logs ADD COLUMN contributor_id INTEGER');
}

function fetch_contributors(PDO $pdo): array
{
    $contributors = $pdo->query('SELECT id, name FROM contributors ORDER BY name COLLATE NOCASE')->fetchAll();

    return array_map(
        static fn (array $contributor): array => [
            'id' => (int) $contributor['id'],
            'name' => $contributor['name'],
        ],
        $contributors
    );
}

function fetch_projects_with_logs(PDO $pdo): array
{
    $projects = $pdo->query('SELECT id, name, status FROM projects ORDER BY id')->fetchAll();
    $logs = $pdo->query(
        'SELECT logs.id, logs.project_id, logs.contributor_id, logs.log_date, logs.task, logs.status, logs.note, contributors.name AS contributor_name
         FROM logs
         LEFT JOIN contributors ON contributors.id = logs.contributor_id
            ORDER BY logs.log_date DESC, logs.id DESC'
    )->fetchAll();

    $projectMap = [];
    foreach ($projects as $project) {
        $project['logs'] = [];
        $projectMap[(int) $project['id']] = $project;
    }

    foreach ($logs as $log) {
        $projectId = (int) $log['project_id'];
        if (!isset($projectMap[$projectId])) {
            continue;
        }

        $projectMap[$projectId]['logs'][] = [
            'id' => (int) $log['id'],
            'contributorId' => $log['contributor_id'] !== null ? (int) $log['contributor_id'] : null,
            'contributorName' => $log['contributor_name'],
            'date' => $log['log_date'],
            'task' => $log['task'],
            'status' => $log['status'],
            'note' => $log['note'],
        ];
    }

    return array_values($projectMap);
}