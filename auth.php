<?php
// ============================================================
//  ExamVault — Auth API
//  Endpoints:
//    POST /api/auth.php?action=login
//    POST /api/auth.php?action=register
//    POST /api/auth.php?action=logout
//    GET  /api/auth.php?action=me
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$action = $_GET['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ----------------------------------------------------------
    //  LOGIN
    // ----------------------------------------------------------
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role     = $input['role'] ?? ''; // 'student' or 'teacher'

        if (!$username || !$password || !$role) {
            jsonError('Username, password, and role are required.');
        }
        if (!in_array($role, ['student', 'teacher'])) {
            jsonError('Invalid role.');
        }

        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT * FROM users WHERE username = ? AND role = ? AND is_active = 1"
        );
        $stmt->execute([$username, $role]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            // Also allow plain-text passwords for legacy/demo data
            if (!$user || $user['password'] !== $password) {
                jsonError('Invalid username or password.', 401);
            }
        }

        // Store in session (exclude password)
        unset($user['password']);
        $_SESSION['user'] = $user;

        jsonSuccess($user, 'Login successful.');

    // ----------------------------------------------------------
    //  REGISTER
    // ----------------------------------------------------------
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

        $role      = $input['role'] ?? '';
        $username  = trim($input['username'] ?? '');
        $password  = $input['password'] ?? '';
        $full_name = trim($input['full_name'] ?? '');
        $email     = trim($input['email'] ?? '');

        if (!$role || !$username || !$password || !$full_name) {
            jsonError('Required fields: role, username, password, full_name.');
        }
        if (!in_array($role, ['student', 'teacher'])) {
            jsonError('Invalid role.');
        }
        if (strlen($password) < 6) {
            jsonError('Password must be at least 6 characters.');
        }

        $pdo = getDB();

        // Check duplicate username
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) jsonError('Username already taken.');

        // Check duplicate email
        if ($email) {
            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->execute([$email]);
            if ($checkEmail->fetch()) jsonError('Email already registered.');
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        if ($role === 'teacher') {
            $dept    = trim($input['department'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, email, role, department, subject)
                 VALUES (?, ?, ?, ?, 'teacher', ?, ?)"
            );
            $stmt->execute([$username, $hashed, $full_name, $email ?: null, $dept, $subject]);
        } else {
            $roll_number = trim($input['roll_number'] ?? '');
            $class_sec   = trim($input['class_sec'] ?? '');
            $institution = trim($input['institution'] ?? '');
            $department  = trim($input['department'] ?? '');

            // Check duplicate roll number
            if ($roll_number) {
                $checkRoll = $pdo->prepare("SELECT id FROM users WHERE roll_number = ?");
                $checkRoll->execute([$roll_number]);
                if ($checkRoll->fetch()) jsonError('Roll number already registered.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, email, role, roll_number, class_sec, institution, department)
                 VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?)"
            );
            $stmt->execute([$username, $hashed, $full_name, $email ?: null,
                            $roll_number ?: null, $class_sec, $institution, $department]);
        }

        jsonSuccess(null, 'Account created successfully. Please log in.');

    // ----------------------------------------------------------
    //  LOGOUT
    // ----------------------------------------------------------
    case 'logout':
        $_SESSION = [];
        session_destroy();
        jsonSuccess(null, 'Logged out successfully.');

    // ----------------------------------------------------------
    //  ME — return current logged-in user
    // ----------------------------------------------------------
    case 'me':
        $user = requireAuth();
        // Refresh from DB
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh) jsonError('User not found.', 404);
        unset($fresh['password']);
        $_SESSION['user'] = $fresh;
        jsonSuccess($fresh);

    // ----------------------------------------------------------
    //  UPDATE PROFILE
    // ----------------------------------------------------------
    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
        $user = requireAuth();
        $pdo  = getDB();

        $full_name   = trim($input['full_name']   ?? $user['full_name']);
        $email       = trim($input['email']        ?? $user['email'] ?? '');
        $department  = trim($input['department']   ?? $user['department'] ?? '');
        $subject     = trim($input['subject']      ?? $user['subject'] ?? '');
        $roll_number = trim($input['roll_number']  ?? $user['roll_number'] ?? '');
        $class_sec   = trim($input['class_sec']    ?? $user['class_sec'] ?? '');
        $institution = trim($input['institution']  ?? $user['institution'] ?? '');

        $stmt = $pdo->prepare(
            "UPDATE users SET full_name=?, email=?, department=?, subject=?,
             roll_number=?, class_sec=?, institution=? WHERE id=?"
        );
        $stmt->execute([$full_name, $email ?: null, $department, $subject,
                        $roll_number ?: null, $class_sec, $institution, $user['id']]);

        // Optionally update password
        if (!empty($input['new_password'])) {
            $old = $input['old_password'] ?? '';
            $dbStmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
            $dbStmt->execute([$user['id']]);
            $row = $dbStmt->fetch();
            if (!password_verify($old, $row['password']) && $row['password'] !== $old) {
                jsonError('Current password is incorrect.');
            }
            $hashed = password_hash($input['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $user['id']]);
        }

        jsonSuccess(null, 'Profile updated successfully.');

    default:
        jsonError('Unknown action.', 404);
}
