<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

header('Content-Type: application/json; charset=utf-8');

function clear_auth_session(): void
{
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_username'],
        $_SESSION['contributor_id'],
        $_SESSION['contributor_name']
    );
}

function current_admin(): ?array
{
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['admin_id'],
        'username' => (string) $_SESSION['admin_username'],
    ];
}

function current_contributor(): ?array
{
    if (!isset($_SESSION['contributor_id'], $_SESSION['contributor_name'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['contributor_id'],
        'name' => (string) $_SESSION['contributor_name'],
    ];
}

function current_auth(): array
{
    $admin = current_admin();
    $contributor = current_contributor();

    return [
        'isAuthenticated' => $admin !== null || $contributor !== null,
        'role' => $admin !== null ? 'admin' : ($contributor !== null ? 'contributor' : null),
        'isAdmin' => $admin !== null,
        'isContributor' => $contributor !== null,
        'admin' => $admin,
        'contributor' => $contributor,
    ];
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        http_response_code(403);
        echo json_encode([
            'message' => 'Admin access required.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    return $admin;
}

function require_authenticated(): array
{
    $auth = current_auth();
    if (!$auth['isAuthenticated']) {
        http_response_code(403);
        echo json_encode([
            'message' => 'Login required.',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    return $auth;
}

function build_response(PDO $pdo): array
{
    $auth = current_auth();
    $admins = [];

    if ($auth['isAdmin']) {
        $admins = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'username' => $row['username'],
                'createdAt' => $row['created_at'],
            ],
            $pdo->query('SELECT id, username, created_at FROM admins ORDER BY username COLLATE NOCASE')->fetchAll()
        );
    }

    return [
        'projects' => fetch_projects_with_logs($pdo),
        'contributors' => fetch_contributors($pdo),
        'auth' => $auth,
        'admins' => $admins,
    ];
}

try {
    $pdo = trackflow_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $entity = (string) ($payload['entity'] ?? 'log');

        if ($entity === 'admin-login') {
            $username = trim((string) ($payload['username'] ?? ''));
            $password = (string) ($payload['password'] ?? '');

            if ($username === '' || $password === '') {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Username and password are required.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $statement = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username');
            $statement->execute([':username' => $username]);
            $admin = $statement->fetch();

            if ($admin === false || !password_verify($password, $admin['password_hash'])) {
                http_response_code(401);
                echo json_encode([
                    'message' => 'Invalid admin credentials.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            clear_auth_session();
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];

            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        if ($entity === 'contributor-login') {
            $contributorId = filter_var($payload['contributorId'] ?? null, FILTER_VALIDATE_INT);

            if ($contributorId === false || $contributorId === null) {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Contributor selection is required.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $statement = $pdo->prepare('SELECT id, name FROM contributors WHERE id = :id');
            $statement->execute([':id' => $contributorId]);
            $contributor = $statement->fetch();

            if ($contributor === false) {
                http_response_code(404);
                echo json_encode([
                    'message' => 'Contributor not found.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            clear_auth_session();
            $_SESSION['contributor_id'] = (int) $contributor['id'];
            $_SESSION['contributor_name'] = $contributor['name'];

            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        if ($entity === 'admin-logout' || $entity === 'contributor-logout' || $entity === 'logout') {
            clear_auth_session();

            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        if ($entity === 'admin') {
            require_admin();

            $username = trim((string) ($payload['username'] ?? ''));
            $password = (string) ($payload['password'] ?? '');

            if ($username === '' || $password === '') {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Admin username and password are required.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $insertStatement = $pdo->prepare(
                'INSERT INTO admins (username, password_hash)
                 VALUES (:username, :password_hash)'
            );

            try {
                $insertStatement->execute([
                    ':username' => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (PDOException $exception) {
                http_response_code(409);
                echo json_encode([
                    'message' => 'Admin username already exists.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            http_response_code(201);
            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        if ($entity === 'project') {
            require_admin();

            $name = trim((string) ($payload['name'] ?? ''));
            $status = trim((string) ($payload['status'] ?? ''));
            $allowedProjectStatuses = ['Active', 'Delayed', 'Completed', 'On Hold'];

            if ($name === '' || !in_array($status, $allowedProjectStatuses, true)) {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Invalid project payload.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $insertStatement = $pdo->prepare(
                'INSERT INTO projects (name, status)
                 VALUES (:name, :status)'
            );
            $insertStatement->execute([
                ':name' => $name,
                ':status' => $status,
            ]);

            http_response_code(201);
            $response = build_response($pdo);
            $response['projectId'] = (int) $pdo->lastInsertId();
            echo json_encode($response, JSON_THROW_ON_ERROR);
            exit;
        }

        if ($entity === 'contributor') {
            require_admin();

            $name = trim((string) ($payload['name'] ?? ''));

            if ($name === '') {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Contributor name is required.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $insertStatement = $pdo->prepare('INSERT INTO contributors (name) VALUES (:name)');

            try {
                $insertStatement->execute([':name' => $name]);
            } catch (PDOException $exception) {
                http_response_code(409);
                echo json_encode([
                    'message' => 'Contributor already exists.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            http_response_code(201);
            $response = build_response($pdo);
            $response['contributorId'] = (int) $pdo->lastInsertId();
            echo json_encode($response, JSON_THROW_ON_ERROR);
            exit;
        }

        $projectId = filter_var($payload['projectId'] ?? null, FILTER_VALIDATE_INT);
        $contributorId = filter_var($payload['contributorId'] ?? null, FILTER_VALIDATE_INT);
        $task = trim((string) ($payload['task'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $auth = require_authenticated();

        $allowedStatuses = ['Done', 'In Progress', 'Blocked'];
        if ($projectId === false || $projectId === null || $contributorId === false || $contributorId === null || $task === '' || $note === '' || !in_array($status, $allowedStatuses, true)) {
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

        $statement = $pdo->prepare('SELECT 1 FROM contributors WHERE id = :id');
        $statement->execute([':id' => $contributorId]);
        if ($statement->fetchColumn() === false) {
            http_response_code(404);
            echo json_encode([
                'message' => 'Contributor not found.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($auth['isContributor'] && ($auth['contributor']['id'] ?? null) !== $contributorId) {
            http_response_code(403);
            echo json_encode([
                'message' => 'Contributors can only log work under their own account.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $insertStatement = $pdo->prepare(
            'INSERT INTO logs (project_id, contributor_id, log_date, task, status, note)
             VALUES (:project_id, :contributor_id, :log_date, :task, :status, :note)'
        );
        $insertStatement->execute([
            ':project_id' => $projectId,
            ':contributor_id' => $contributorId,
            ':log_date' => date('Y-m-d'),
            ':task' => $task,
            ':status' => $status,
            ':note' => $note,
        ]);

        echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method === 'PUT') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $entity = (string) ($payload['entity'] ?? 'project');

        if ($entity === 'contributor') {
            require_admin();

            $contributorId = filter_var($payload['contributorId'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($payload['name'] ?? ''));

            if ($contributorId === false || $contributorId === null || $name === '') {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Invalid contributor payload.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $updateStatement = $pdo->prepare(
                'UPDATE contributors
                 SET name = :name
                 WHERE id = :id'
            );

            try {
                $updateStatement->execute([
                    ':id' => $contributorId,
                    ':name' => $name,
                ]);
            } catch (PDOException $exception) {
                http_response_code(409);
                echo json_encode([
                    'message' => 'Contributor already exists.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            if ($updateStatement->rowCount() === 0) {
                $statement = $pdo->prepare('SELECT 1 FROM contributors WHERE id = :id');
                $statement->execute([':id' => $contributorId]);
                if ($statement->fetchColumn() === false) {
                    http_response_code(404);
                    echo json_encode([
                        'message' => 'Contributor not found.',
                    ], JSON_THROW_ON_ERROR);
                    exit;
                }
            }

            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        $projectId = filter_var($payload['projectId'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($payload['name'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $allowedProjectStatuses = ['Active', 'Delayed', 'Completed', 'On Hold'];

        require_admin();

        if ($projectId === false || $projectId === null || $name === '' || !in_array($status, $allowedProjectStatuses, true)) {
            http_response_code(422);
            echo json_encode([
                'message' => 'Invalid project payload.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $updateStatement = $pdo->prepare(
            'UPDATE projects
             SET name = :name, status = :status
             WHERE id = :id'
        );
        $updateStatement->execute([
            ':id' => $projectId,
            ':name' => $name,
            ':status' => $status,
        ]);

        if ($updateStatement->rowCount() === 0) {
            $statement = $pdo->prepare('SELECT 1 FROM projects WHERE id = :id');
            $statement->execute([':id' => $projectId]);
            if ($statement->fetchColumn() === false) {
                http_response_code(404);
                echo json_encode([
                    'message' => 'Project not found.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }
        }

        echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method === 'DELETE') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $entity = (string) ($payload['entity'] ?? 'project');

        if ($entity === 'contributor') {
            require_admin();

            $contributorId = filter_var($payload['contributorId'] ?? null, FILTER_VALIDATE_INT);

            if ($contributorId === false || $contributorId === null) {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Invalid contributor id.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $usageStatement = $pdo->prepare('SELECT COUNT(*) FROM logs WHERE contributor_id = :id');
            $usageStatement->execute([':id' => $contributorId]);
            if ((int) $usageStatement->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode([
                    'message' => 'Contributor is still assigned to task logs.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            $deleteStatement = $pdo->prepare('DELETE FROM contributors WHERE id = :id');
            $deleteStatement->execute([':id' => $contributorId]);

            if ($deleteStatement->rowCount() === 0) {
                http_response_code(404);
                echo json_encode([
                    'message' => 'Contributor not found.',
                ], JSON_THROW_ON_ERROR);
                exit;
            }

            echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
            exit;
        }

        $projectId = filter_var($payload['projectId'] ?? null, FILTER_VALIDATE_INT);

        require_admin();

        if ($projectId === false || $projectId === null) {
            http_response_code(422);
            echo json_encode([
                'message' => 'Invalid project id.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $deleteStatement = $pdo->prepare('DELETE FROM projects WHERE id = :id');
        $deleteStatement->execute([':id' => $projectId]);

        if ($deleteStatement->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'message' => 'Project not found.',
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        echo json_encode(build_response($pdo), JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE');
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