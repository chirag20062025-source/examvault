<?php
// ============================================================
//  ExamVault — Results API
//  POST /api/results.php?action=submit       — submit exam answers
//  GET  /api/results.php                     — list results (own for student, all for teacher)
//  GET  /api/results.php?id=X                — single result detail
//  GET  /api/results.php?exam_id=X           — all results for an exam (teacher)
//  GET  /api/results.php?student_id=X        — all results for a student (teacher)
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$user   = requireAuth();
$pdo    = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = getInput();

// ------------------------------------------------------------------
//  POST — Submit Exam
// ------------------------------------------------------------------
if ($method === 'POST' && $action === 'submit') {
    requireAuth('student');

    $exam_id    = (int)   ($input['exam_id']    ?? 0);
    $time_taken = (int)   ($input['time_taken'] ?? 0); // seconds
    $answers    = $input['answers'] ?? [];   // [ {question_id: X, answer: 'A'|null}, ... ]

    if (!$exam_id || !is_array($answers)) {
        jsonError('exam_id and answers array are required.');
    }

    // Fetch exam
    $eStmt = $pdo->prepare("SELECT * FROM exams WHERE id=? AND status='active'");
    $eStmt->execute([$exam_id]);
    $exam = $eStmt->fetch();
    if (!$exam) jsonError('Exam not found or not active.', 404);

    // Prevent duplicate attempt (extra guard on top of DB trigger)
    $dupStmt = $pdo->prepare("SELECT id FROM exam_results WHERE user_id=? AND exam_id=?");
    $dupStmt->execute([$user['id'], $exam_id]);
    if ($dupStmt->fetch()) jsonError('You have already submitted this exam.');

    // Fetch all questions for this exam
    $qStmt = $pdo->prepare("SELECT * FROM questions WHERE exam_id=?");
    $qStmt->execute([$exam_id]);
    $questions = $qStmt->fetchAll();

    // Build a lookup {question_id => question}
    $qMap = [];
    foreach ($questions as $q) $qMap[$q['id']] = $q;

    // Calculate score
    $score    = 0.0;
    $negMark  = (float) $exam['negative_mark'];
    $userAnsData = [];

    foreach ($answers as $ans) {
        $qid     = (int) ($ans['question_id'] ?? 0);
        $userAns = isset($ans['answer']) && in_array(strtoupper($ans['answer']), ['A','B','C','D'])
                   ? strtoupper($ans['answer'])
                   : null;

        if (!isset($qMap[$qid])) continue;  // unknown question — skip

        $q         = $qMap[$qid];
        $isCorrect = $userAns !== null && $userAns === $q['correct_answer'];

        if ($isCorrect) {
            $score += (float) $q['marks'];
        } elseif ($userAns !== null && $negMark > 0) {
            $score -= $negMark;
        }

        $userAnsData[] = [
            'question_id' => $qid,
            'user_answer' => $userAns,
            'is_correct'  => $isCorrect,
        ];
    }

    $score      = max(0, round($score, 2));
    $totalMarks = (int) $exam['total_marks'];

    // Transaction: insert result + all answers atomically
    try {
        $pdo->beginTransaction();

        // Insert result
        $rStmt = $pdo->prepare(
            "INSERT INTO exam_results (user_id, exam_id, score, total_marks, time_taken)
             VALUES (?, ?, ?, ?, ?)"
        );
        $rStmt->execute([$user['id'], $exam_id, $score, $totalMarks, $time_taken]);
        $resultId = (int) $pdo->lastInsertId();

        // Insert individual answers
        $aStmt = $pdo->prepare(
            "INSERT INTO user_answers (result_id, question_id, user_answer, is_correct)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($userAnsData as $ua) {
            $aStmt->execute([
                $resultId,
                $ua['question_id'],
                $ua['user_answer'],
                (int) $ua['is_correct'],
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to save exam result. Please try again.', 500);
    }

    // Fetch the saved result (percentage is computed by DB)
    $saved = $pdo->prepare("SELECT * FROM exam_results WHERE id=?");
    $saved->execute([$resultId]);
    $result = $saved->fetch();

    jsonSuccess([
        'result_id'   => $resultId,
        'score'       => $score,
        'total_marks' => $totalMarks,
        'percentage'  => (float) $result['percentage'],
        'passed'      => (float) $result['percentage'] >= (float) $exam['passing_pct'],
    ], 'Exam submitted successfully.');
}

// ------------------------------------------------------------------
//  GET — Results
// ------------------------------------------------------------------
if ($method === 'GET') {

    // Single result with full detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];

        // Basic result + exam info
        $stmt = $pdo->prepare(
            "SELECT r.*, e.title AS exam_title, e.subject, e.passing_pct, e.negative_mark,
             e.instructions, u.full_name AS student_name, u.roll_number,
             ROUND(r.time_taken/60, 1) AS minutes_taken
             FROM exam_results r
             JOIN exams e ON r.exam_id = e.id
             JOIN users u ON r.user_id = u.id
             WHERE r.id=?"
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        if (!$result) jsonError('Result not found.', 404);

        // Access control: student can only view own, teacher can view own exam results
        if ($user['role'] === 'student' && $result['user_id'] != $user['id']) {
            jsonError('Access denied.', 403);
        }

        // Fetch answer breakdown
        $aStmt = $pdo->prepare(
            "SELECT ua.user_answer, ua.is_correct,
             q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
             q.correct_answer, q.marks, q.difficulty
             FROM user_answers ua
             JOIN questions q ON ua.question_id = q.id
             WHERE ua.result_id=?
             ORDER BY q.id ASC"
        );
        $aStmt->execute([$id]);
        $result['answers'] = $aStmt->fetchAll();

        jsonSuccess($result);
    }

    // Results by exam_id (teacher)
    if (!empty($_GET['exam_id'])) {
        requireAuth('teacher');
        $exam_id = (int) $_GET['exam_id'];

        // Verify ownership
        $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
        $own->execute([$exam_id, $user['id']]);
        if (!$own->fetch()) jsonError('Access denied.', 403);

        $stmt = $pdo->prepare(
            "SELECT r.*, u.full_name AS student_name, u.roll_number, u.class_sec,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result,
             RANK() OVER (ORDER BY r.percentage DESC) AS `rank`
             FROM exam_results r
             JOIN users u ON r.user_id  = u.id
             JOIN exams e ON r.exam_id  = e.id
             WHERE r.exam_id=?
             ORDER BY r.percentage DESC"
        );
        $stmt->execute([$exam_id]);
        jsonSuccess($stmt->fetchAll());
    }

    // Results by student_id (teacher or self)
    if (!empty($_GET['student_id'])) {
        $sid = (int) $_GET['student_id'];
        if ($user['role'] === 'student' && $user['id'] !== $sid) {
            jsonError('Access denied.', 403);
        }

        $stmt = $pdo->prepare(
            "SELECT r.*, e.title AS exam_title, e.subject, e.passing_pct,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result,
             ROUND(r.time_taken/60, 1) AS minutes_taken
             FROM exam_results r
             JOIN exams e ON r.exam_id = e.id
             WHERE r.user_id=?
             ORDER BY r.submitted_at DESC"
        );
        $stmt->execute([$sid]);
        jsonSuccess($stmt->fetchAll());
    }

    // Default: list results for current user
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare(
            "SELECT r.*, e.title AS exam_title, e.subject, e.passing_pct, e.total_marks AS exam_total,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result,
             ROUND(r.time_taken/60, 1) AS minutes_taken
             FROM exam_results r
             JOIN exams e ON r.exam_id = e.id
             WHERE r.user_id=?
             ORDER BY r.submitted_at DESC"
        );
        $stmt->execute([$user['id']]);
    } else {
        // Teacher: results for all their exams
        $stmt = $pdo->prepare(
            "SELECT r.*, e.title AS exam_title, e.subject, e.passing_pct,
             u.full_name AS student_name, u.roll_number,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result
             FROM exam_results r
             JOIN exams e ON r.exam_id = e.id
             JOIN users u ON r.user_id = u.id
             WHERE e.created_by=?
             ORDER BY r.submitted_at DESC"
        );
        $stmt->execute([$user['id']]);
    }

    jsonSuccess($stmt->fetchAll());
}

jsonError('Method not allowed.', 405);
