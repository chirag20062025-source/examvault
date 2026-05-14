<?php
// ============================================================
//  ExamVault — Database Configuration
//  Edit these values to match your MySQL setup
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'examVaultDB');
define('DB_USER', 'root');        // Change to your MySQL username
define('DB_PASS', '');            // Change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

// Session lifetime (seconds)
define('SESSION_LIFETIME', 86400); // 24 hours

// ============================================================
//  Database Connection (PDO)
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ============================================================
//  Response Helpers
// ============================================================
function jsonResponse(bool $success, string $message = '', mixed $data = null, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    $res = ['success' => $success, 'message' => $message];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res);
    exit;
}

function jsonSuccess(mixed $data = null, string $message = 'OK'): void {
    jsonResponse(true, $message, $data, 200);
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(false, $message, null, $code);
}

// ============================================================
//  CORS & Common Headers (call at top of every API file)
// ============================================================

function initAPI(): void {
    // Allow frontend origin
    header('Access-Control-Allow-Origin: http://localhost');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');  // ← CRITICAL
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => 'localhost',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

// ============================================================
//  Auth Helper — returns current user or dies
// ============================================================
function requireAuth(string $role = ''): array {
    if (empty($_SESSION['user'])) {
        jsonError('Not authenticated. Please log in.', 401);
    }
    $user = $_SESSION['user'];
    if ($role && $user['role'] !== $role) {
        jsonError('Access denied. Required role: ' . $role, 403);
    }
    return $user;
}

// ============================================================
//  Input helper — get JSON body or $_POST
// ============================================================
function getInput(): array {
    $body = file_get_contents('php://input');
    $json = json_decode($body, true);
    if (is_array($json)) return $json;
    return $_POST ?? [];
}
