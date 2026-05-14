<?php
// ============================================================
//  ExamVault — Students API  (Teacher only)
//  GET  /api/students.php               — all students
//  GET  /api/students.php?id=X          — student detail + exam history
//  POST /api/students.php?action=toggle_active  — activate/deactivate student
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$user   = requireAuth('teacher');
$pdo    = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();

// ------------------------------------------------------------------
//  POST
// ------------------------------------------------------------------
if ($method === 'POST') {
    switch ($action) {
        case 'toggle_active':
            $id    = (int) ($input['id'] ?? 0);
            $state = isset($input['is_active']) ? (int)(bool)$input['is_active'] : null;
            if (!$id || $state === null) jsonError('id and is_active required.');
            $pdo->prepare("UPDATE users SET is_active=? WHERE id=? AND role='student'")
                ->execute([$state, $id]);
            jsonSuccess(null, $state ? 'Student activated.' : 'Student deactivated.');

        default:
            jsonError('Unknown action.', 404);
    }
}

// ------------------------------------------------------------------
//  GET
// ------------------------------------------------------------------
if ($method === 'GET') {

    // Student detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT id, username, full_name, email, roll_number, class_sec,
             department, institution, is_active, created_at
             FROM users WHERE id=? AND role='student'"
        );
        $stmt->execute([$id]);
        $student = $stmt->fetch();
        if (!$student) jsonError('Student not found.', 404);

        // All exam results for this student
        $rStmt = $pdo->prepare(
            "SELECT r.id AS result_id, e.title AS exam_title, e.subject,
             r.score, r.total_marks, r.percentage,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result,
             ROUND(r.time_taken/60,1) AS minutes_taken,
             r.submitted_at
             FROM exam_results r
             JOIN exams e ON r.exam_id=e.id
             WHERE r.user_id=?
             ORDER BY r.submitted_at DESC"
        );
        $rStmt->execute([$id]);
        $student['results'] = $rStmt->fetchAll();

        // Summary stats
        $stats = $pdo->prepare(
            "SELECT COUNT(*) AS attempts,
             ROUND(AVG(r.percentage),1) AS avg_pct,
             MAX(r.percentage) AS best_pct,
             SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END) AS pass_count
             FROM exam_results r JOIN exams e ON r.exam_id=e.id
             WHERE r.user_id=?"
        );
        $stats->execute([$id]);
        $student['stats'] = $stats->fetch();

        jsonSuccess($student);
    }

    // All students list
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.username, u.email, u.roll_number,
         u.class_sec, u.department, u.institution, u.is_active, u.created_at,
         COUNT(r.id)                AS exams_attempted,
         ROUND(AVG(r.percentage),1) AS avg_pct,
         SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END) AS passed_count
         FROM users u
         LEFT JOIN exam_results r ON u.id=r.user_id
         LEFT JOIN exams e ON r.exam_id=e.id AND e.created_by=?
         WHERE u.role='student'
         GROUP BY u.id
         ORDER BY u.full_name ASC"
    );
    $stmt->execute([$user['id']]);
    jsonSuccess($stmt->fetchAll());
}

jsonError('Method not allowed.', 405);
