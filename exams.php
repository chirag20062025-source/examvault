<?php
// ============================================================
//  ExamVault — Exams API
//  GET    /api/exams.php              — list exams (teacher: own, student: active)
//  GET    /api/exams.php?id=X         — get single exam
//  POST   /api/exams.php?action=create
//  POST   /api/exams.php?action=update
//  POST   /api/exams.php?action=delete
//  POST   /api/exams.php?action=status — change status
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$user   = requireAuth();
$pdo    = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();

// ------------------------------------------------------------------
//  GET — List / Single
// ------------------------------------------------------------------
if ($method === 'GET') {

    // Single exam
    if (!empty($_GET['id'])) {
        $id   = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT e.*, u.full_name AS teacher_name
             FROM exams e JOIN users u ON e.created_by = u.id
             WHERE e.id = ?"
        );
        $stmt->execute([$id]);
        $exam = $stmt->fetch();
        if (!$exam) jsonError('Exam not found.', 404);

        // Students can only see active exams
        if ($user['role'] === 'student' && $exam['status'] !== 'active') {
            jsonError('Exam not available.', 403);
        }

        // Attach question count
        $qStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM questions WHERE exam_id = ?");
        $qStmt->execute([$id]);
        $exam['question_count'] = (int) $qStmt->fetch()['cnt'];

        // Check if student already attempted
        if ($user['role'] === 'student') {
            $rStmt = $pdo->prepare(
                "SELECT id FROM exam_results WHERE user_id=? AND exam_id=?"
            );
            $rStmt->execute([$user['id'], $id]);
            $exam['attempted'] = (bool) $rStmt->fetch();
        }

        jsonSuccess($exam);
    }

    // List exams
    if ($user['role'] === 'teacher') {
        $stmt = $pdo->prepare(
            "SELECT e.*, u.full_name AS teacher_name,
             (SELECT COUNT(*) FROM questions  q WHERE q.exam_id   = e.id) AS question_count,
             (SELECT COUNT(*) FROM exam_results r WHERE r.exam_id = e.id) AS attempt_count
             FROM exams e JOIN users u ON e.created_by = u.id
             WHERE e.created_by = ?
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([$user['id']]);
    } else {
        // Student: active exams + attempt status
        $stmt = $pdo->prepare(
            "SELECT e.*,
             u.full_name AS teacher_name,
             (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS question_count,
             (SELECT id   FROM exam_results r WHERE r.user_id = ? AND r.exam_id = e.id LIMIT 1) AS result_id
             FROM exams e JOIN users u ON e.created_by = u.id
             WHERE e.status = 'active'
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([$user['id']]);
    }

    $exams = $stmt->fetchAll();
    // Add attempted flag for students
    if ($user['role'] === 'student') {
        foreach ($exams as &$e) {
            $e['attempted'] = $e['result_id'] !== null;
        }
    }
    jsonSuccess($exams);
}

// ------------------------------------------------------------------
//  POST — Create / Update / Delete / Status
// ------------------------------------------------------------------
if ($method === 'POST') {

    switch ($action) {

        // ---- CREATE EXAM ----
        case 'create':
            $teacher = requireAuth('teacher');

            $title        = trim($input['title']        ?? '');
            $subject      = trim($input['subject']      ?? '');
            $duration     = (int)   ($input['duration']     ?? 0);
            $total_marks  = (int)   ($input['total_marks']  ?? 0);
            $passing_pct  = (int)   ($input['passing_pct']  ?? 60);
            $negative_mark= (float) ($input['negative_mark']?? 0.0);
            $shuffle_q    = !empty($input['shuffle_q']) ? 1 : 0;
            $instructions = trim($input['instructions'] ?? '');
            $status       = $input['status'] ?? 'draft';

            if (!$title || !$duration || !$total_marks) {
                jsonError('Title, duration, and total_marks are required.');
            }
            if (!in_array($status, ['active', 'draft', 'closed'])) $status = 'draft';

            $stmt = $pdo->prepare(
                "INSERT INTO exams (title, subject, duration, total_marks, passing_pct,
                 negative_mark, shuffle_q, instructions, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $title, $subject, $duration, $total_marks, $passing_pct,
                $negative_mark, $shuffle_q, $instructions, $status, $teacher['id']
            ]);
            $newId = $pdo->lastInsertId();
            jsonSuccess(['id' => (int)$newId], 'Exam created successfully.');

        // ---- UPDATE EXAM ----
        case 'update':
            $teacher = requireAuth('teacher');
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonError('Exam ID required.');

            // Verify ownership
            $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
            $own->execute([$id, $teacher['id']]);
            if (!$own->fetch()) jsonError('Exam not found or access denied.', 403);

            $fields = [];
            $params = [];
            $allowed = ['title','subject','duration','total_marks','passing_pct',
                        'negative_mark','shuffle_q','instructions','status'];
            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    $fields[] = "$f = ?";
                    $params[] = ($f === 'shuffle_q') ? (int)$input[$f] : $input[$f];
                }
            }
            if (!$fields) jsonError('No fields to update.');
            $params[] = $id;
            $pdo->prepare("UPDATE exams SET " . implode(', ', $fields) . " WHERE id=?")
                ->execute($params);

            jsonSuccess(null, 'Exam updated successfully.');

        // ---- DELETE EXAM ----
        case 'delete':
            $teacher = requireAuth('teacher');
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonError('Exam ID required.');

            $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
            $own->execute([$id, $teacher['id']]);
            if (!$own->fetch()) jsonError('Exam not found or access denied.', 403);

            $pdo->prepare("DELETE FROM exams WHERE id=?")->execute([$id]);
            jsonSuccess(null, 'Exam deleted.');

        // ---- CHANGE STATUS ----
        case 'status':
            $teacher = requireAuth('teacher');
            $id     = (int)($input['id']     ?? 0);
            $status = $input['status'] ?? '';
            if (!$id || !in_array($status, ['active','draft','closed'])) {
                jsonError('Valid exam ID and status required.');
            }
            $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
            $own->execute([$id, $teacher['id']]);
            if (!$own->fetch()) jsonError('Access denied.', 403);

            $pdo->prepare("UPDATE exams SET status=? WHERE id=?")->execute([$status, $id]);
            jsonSuccess(null, "Exam status changed to $status.");

        default:
            jsonError('Unknown action.', 404);
    }
}

jsonError('Method not allowed.', 405);
