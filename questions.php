<?php
// ============================================================
//  ExamVault — Questions API
//  GET  /api/questions.php?exam_id=X      — list questions for exam
//  GET  /api/questions.php?id=X           — single question
//  POST /api/questions.php?action=create
//  POST /api/questions.php?action=update
//  POST /api/questions.php?action=delete
//  POST /api/questions.php?action=bulk_create — insert many at once
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$user   = requireAuth();
$pdo    = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();

// ------------------------------------------------------------------
//  GET
// ------------------------------------------------------------------
if ($method === 'GET') {

    // Single question
    if (!empty($_GET['id'])) {
        $id   = (int) $_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE id=?");
        $stmt->execute([$id]);
        $q = $stmt->fetch();
        if (!$q) jsonError('Question not found.', 404);

        // Students: hide correct_answer unless exam is done
        if ($user['role'] === 'student') {
            // Only include correct_answer if student has a result for this exam
            $rStmt = $pdo->prepare(
                "SELECT er.id FROM exam_results er
                 JOIN questions q ON q.exam_id = er.exam_id
                 WHERE er.user_id=? AND q.id=? LIMIT 1"
            );
            $rStmt->execute([$user['id'], $id]);
            if (!$rStmt->fetch()) unset($q['correct_answer']);
        }
        jsonSuccess($q);
    }

    // Questions for an exam
    $exam_id = (int) ($_GET['exam_id'] ?? 0);
    if (!$exam_id) jsonError('exam_id parameter required.');

    // Verify exam exists
    $eStmt = $pdo->prepare("SELECT id, shuffle_q, status, created_by FROM exams WHERE id=?");
    $eStmt->execute([$exam_id]);
    $exam = $eStmt->fetch();
    if (!$exam) jsonError('Exam not found.', 404);

    // Check access
    if ($user['role'] === 'teacher' && $exam['created_by'] != $user['id']) {
        jsonError('Access denied.', 403);
    }

    $stmt = $pdo->prepare("SELECT * FROM questions WHERE exam_id=? ORDER BY id ASC");
    $stmt->execute([$exam_id]);
    $questions = $stmt->fetchAll();

    // For students taking exam: hide correct_answer and optionally shuffle
    if ($user['role'] === 'student') {
        // Check student hasn't already submitted
        $rStmt = $pdo->prepare("SELECT id FROM exam_results WHERE user_id=? AND exam_id=?");
        $rStmt->execute([$user['id'], $exam_id]);
        $alreadyDone = $rStmt->fetch();

        if (!$alreadyDone) {
            // Hide correct answers during exam
            foreach ($questions as &$q) unset($q['correct_answer']);
            // Shuffle if exam setting is on
            if ($exam['shuffle_q']) shuffle($questions);
        }
        // After submission, correct_answer stays visible for review
    }

    jsonSuccess($questions);
}

// ------------------------------------------------------------------
//  POST
// ------------------------------------------------------------------
if ($method === 'POST') {

    // Helper: verify teacher owns the exam
    $verifyOwnership = function(int $exam_id) use ($pdo, $user): void {
        $s = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
        $s->execute([$exam_id, $user['id']]);
        if (!$s->fetch()) jsonError('Exam not found or access denied.', 403);
    };

    switch ($action) {

        // ---- CREATE QUESTION ----
        case 'create':
            requireAuth('teacher');
            $exam_id       = (int)   ($input['exam_id']       ?? 0);
            $question_text = trim($input['question_text']    ?? '');
            $option_a      = trim($input['option_a']         ?? '');
            $option_b      = trim($input['option_b']         ?? '');
            $option_c      = trim($input['option_c']         ?? '');
            $option_d      = trim($input['option_d']         ?? '');
            $correct       = strtoupper(trim($input['correct_answer'] ?? ''));
            $marks         = (int) ($input['marks']          ?? 1);
            $difficulty    = $input['difficulty']            ?? 'medium';

            if (!$exam_id || !$question_text || !$option_a || !$option_b || !$option_c || !$option_d || !$correct) {
                jsonError('All fields (exam_id, question_text, options A-D, correct_answer) are required.');
            }
            if (!in_array($correct, ['A','B','C','D'])) jsonError('correct_answer must be A, B, C, or D.');
            if (!in_array($difficulty, ['easy','medium','hard'])) $difficulty = 'medium';

            $verifyOwnership($exam_id);

            $stmt = $pdo->prepare(
                "INSERT INTO questions
                 (exam_id, question_text, option_a, option_b, option_c, option_d,
                  correct_answer, marks, difficulty)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$exam_id, $question_text, $option_a, $option_b, $option_c,
                            $option_d, $correct, $marks, $difficulty]);

            jsonSuccess(['id' => (int)$pdo->lastInsertId()], 'Question added.');

        // ---- BULK CREATE ----
        case 'bulk_create':
            requireAuth('teacher');
            $exam_id   = (int) ($input['exam_id'] ?? 0);
            $questions = $input['questions'] ?? [];
            if (!$exam_id || !is_array($questions) || !count($questions)) {
                jsonError('exam_id and questions array required.');
            }
            $verifyOwnership($exam_id);

            $stmt = $pdo->prepare(
                "INSERT INTO questions
                 (exam_id, question_text, option_a, option_b, option_c, option_d,
                  correct_answer, marks, difficulty)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $inserted = 0;
            foreach ($questions as $q) {
                $stmt->execute([
                    $exam_id,
                    trim($q['question_text'] ?? ''),
                    trim($q['option_a'] ?? ''),
                    trim($q['option_b'] ?? ''),
                    trim($q['option_c'] ?? ''),
                    trim($q['option_d'] ?? ''),
                    strtoupper(trim($q['correct_answer'] ?? 'A')),
                    (int)($q['marks'] ?? 1),
                    $q['difficulty'] ?? 'medium'
                ]);
                $inserted++;
            }
            jsonSuccess(['inserted' => $inserted], "$inserted questions added.");

        // ---- UPDATE QUESTION ----
        case 'update':
            requireAuth('teacher');
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonError('Question ID required.');

            // Verify ownership via join
            $own = $pdo->prepare(
                "SELECT q.id FROM questions q
                 JOIN exams e ON q.exam_id = e.id
                 WHERE q.id=? AND e.created_by=?"
            );
            $own->execute([$id, $user['id']]);
            if (!$own->fetch()) jsonError('Question not found or access denied.', 403);

            $allowed = ['question_text','option_a','option_b','option_c','option_d',
                        'correct_answer','marks','difficulty'];
            $fields = [];
            $params = [];
            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    $fields[] = "$f = ?";
                    $params[] = ($f === 'correct_answer') ? strtoupper($input[$f]) : $input[$f];
                }
            }
            if (!$fields) jsonError('No fields to update.');
            $params[] = $id;
            $pdo->prepare("UPDATE questions SET " . implode(', ', $fields) . " WHERE id=?")
                ->execute($params);
            jsonSuccess(null, 'Question updated.');

        // ---- DELETE QUESTION ----
        case 'delete':
            requireAuth('teacher');
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonError('Question ID required.');

            $own = $pdo->prepare(
                "SELECT q.id FROM questions q
                 JOIN exams e ON q.exam_id = e.id
                 WHERE q.id=? AND e.created_by=?"
            );
            $own->execute([$id, $user['id']]);
            if (!$own->fetch()) jsonError('Access denied.', 403);

            $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$id]);
            jsonSuccess(null, 'Question deleted.');

        default:
            jsonError('Unknown action.', 404);
    }
}

jsonError('Method not allowed.', 405);
