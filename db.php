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
        'CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            log_date TEXT NOT NULL,
            task TEXT NOT NULL,
            status TEXT NOT NULL,
            note TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        )'
    );

    $projectCount = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($projectCount > 0) {
        return;
    }

    $projectStatement = $pdo->prepare('INSERT INTO projects (name, status) VALUES (:name, :status)');
    $logStatement = $pdo->prepare(
        'INSERT INTO logs (project_id, log_date, task, status, note)
         VALUES (:project_id, :log_date, :task, :status, :note)'
    );

    $seedProjects = [
        [
            'name' => 'Shop Signage',
            'status' => 'Active',
            'logs' => [
                [
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
                ':log_date' => $log['log_date'],
                ':task' => $log['task'],
                ':status' => $log['status'],
                ':note' => $log['note'],
            ]);
        }
    }
}

function fetch_projects_with_logs(PDO $pdo): array
{
    $projects = $pdo->query('SELECT id, name, status FROM projects ORDER BY id')->fetchAll();
    $logs = $pdo->query(
        'SELECT id, project_id, log_date, task, status, note
         FROM logs
         ORDER BY log_date DESC, id DESC'
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
            'date' => $log['log_date'],
            'task' => $log['task'],
            'status' => $log['status'],
            'note' => $log['note'],
        ];
    }

    return array_values($projectMap);
}