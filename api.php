<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = trackflow_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'projects' => fetch_projects_with_logs($pdo),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);

        $projectId = filter_var($payload['projectId'] ?? null, FILTER_VALIDATE_INT);
        $task = trim((string) ($payload['task'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));

        $allowedStatuses = ['Done', 'In Progress', 'Blocked'];
        if ($projectId === false || $projectId === null || $task === '' || $note === '' || !in_array($status, $allowedStatuses, true)) {
            http_response_code(422);
            echo json_encode([
                'message' => 'Invalid task log payload.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $statement = $pdo->prepare('SELECT 1 FROM projects WHERE id = :id');
        $statement->execute([':id' => $projectId]);
        if ($statement->fetchColumn() === false) {
            http_response_code(404);
            echo json_encode([
                'message' => 'Project not found.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $insertStatement = $pdo->prepare(
            'INSERT INTO logs (project_id, log_date, task, status, note)
             VALUES (:project_id, :log_date, :task, :status, :note)'
        );
        $insertStatement->execute([
            ':project_id' => $projectId,
            ':log_date' => date('Y-m-d'),
            ':task' => $task,
            ':status' => $status,
            ':note' => $note,
        ]);

        echo json_encode([
            'projects' => fetch_projects_with_logs($pdo),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'message' => 'Method not allowed.',
    ], JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode([
        'message' => 'Malformed JSON payload.',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Server error.',
        'details' => $exception->getMessage(),
    ]);
}