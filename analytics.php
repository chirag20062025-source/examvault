<?php
// ============================================================
//  ExamVault — Analytics API  (Teacher only)
//  GET /api/analytics.php?type=dashboard    — teacher overview stats
//  GET /api/analytics.php?type=exam&id=X    — per-exam analytics
//  GET /api/analytics.php?type=students     — all students + performance
//  GET /api/analytics.php?type=leaderboard&exam_id=X
// ============================================================
require_once __DIR__ . '/../config.php';
initAPI();

$user = requireAuth('teacher');
$pdo  = getDB();
$type = $_GET['type'] ?? 'dashboard';

switch ($type) {

    // ----------------------------------------------------------
    //  TEACHER DASHBOARD OVERVIEW
    // ----------------------------------------------------------
    case 'dashboard':
        // Total exams
        $totalExams = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE created_by=?");
        $totalExams->execute([$user['id']]);

        // Active exams
        $activeExams = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE created_by=? AND status='active'");
        $activeExams->execute([$user['id']]);

        // Total attempts across all my exams
        $totalAttempts = $pdo->prepare(
            "SELECT COUNT(*) FROM exam_results r
             JOIN exams e ON r.exam_id=e.id WHERE e.created_by=?"
        );
        $totalAttempts->execute([$user['id']]);

        // Total questions written
        $totalQuestions = $pdo->prepare(
            "SELECT COUNT(*) FROM questions q
             JOIN exams e ON q.exam_id=e.id WHERE e.created_by=?"
        );
        $totalQuestions->execute([$user['id']]);

        // Overall pass rate
        $passRate = $pdo->prepare(
            "SELECT
               ROUND(SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END)*100.0
               / NULLIF(COUNT(r.id),0), 1) AS pass_rate
             FROM exam_results r
             JOIN exams e ON r.exam_id=e.id
             WHERE e.created_by=?"
        );
        $passRate->execute([$user['id']]);

        // Average score across all exams
        $avgScore = $pdo->prepare(
            "SELECT ROUND(AVG(r.percentage),1) AS avg_pct
             FROM exam_results r JOIN exams e ON r.exam_id=e.id WHERE e.created_by=?"
        );
        $avgScore->execute([$user['id']]);

        // Recent activity (last 10 submissions)
        $recentActivity = $pdo->prepare(
            "SELECT r.id AS result_id, u.full_name AS student_name, u.roll_number,
             e.title AS exam_title, r.score, r.total_marks, r.percentage,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result,
             r.submitted_at
             FROM exam_results r
             JOIN users u ON r.user_id=u.id
             JOIN exams e ON r.exam_id=e.id
             WHERE e.created_by=?
             ORDER BY r.submitted_at DESC LIMIT 10"
        );
        $recentActivity->execute([$user['id']]);

        // Subject performance breakdown
        $subjectPerf = $pdo->prepare(
            "SELECT e.subject,
             COUNT(DISTINCT e.id)            AS total_exams,
             COUNT(r.id)                     AS total_attempts,
             ROUND(AVG(r.percentage),1)      AS avg_pct,
             SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END) AS pass_count,
             ROUND(SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END)*100.0
                   / NULLIF(COUNT(r.id),0),1) AS pass_rate
             FROM exams e
             LEFT JOIN exam_results r ON e.id=r.exam_id
             WHERE e.created_by=?
             GROUP BY e.subject ORDER BY avg_pct DESC"
        );
        $subjectPerf->execute([$user['id']]);

        jsonSuccess([
            'total_exams'     => (int) $totalExams->fetchColumn(),
            'active_exams'    => (int) $activeExams->fetchColumn(),
            'total_attempts'  => (int) $totalAttempts->fetchColumn(),
            'total_questions' => (int) $totalQuestions->fetchColumn(),
            'pass_rate'       => (float) ($passRate->fetch()['pass_rate'] ?? 0),
            'avg_score'       => (float) ($avgScore->fetch()['avg_pct'] ?? 0),
            'recent_activity' => $recentActivity->fetchAll(),
            'subject_perf'    => $subjectPerf->fetchAll(),
        ]);

    // ----------------------------------------------------------
    //  PER-EXAM ANALYTICS
    // ----------------------------------------------------------
    case 'exam':
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) jsonError('Exam ID required.');

        // Verify ownership
        $own = $pdo->prepare("SELECT * FROM exams WHERE id=? AND created_by=?");
        $own->execute([$id, $user['id']]);
        $exam = $own->fetch();
        if (!$exam) jsonError('Access denied.', 403);

        // Summary stats
        $stats = $pdo->prepare(
            "SELECT
             COUNT(r.id)                     AS total_attempts,
             ROUND(AVG(r.percentage),2)      AS avg_pct,
             MAX(r.percentage)               AS highest_pct,
             MIN(r.percentage)               AS lowest_pct,
             ROUND(AVG(r.time_taken)/60,1)   AS avg_minutes,
             SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END)  AS passed,
             SUM(CASE WHEN r.percentage <  e.passing_pct THEN 1 ELSE 0 END)  AS failed,
             ROUND(SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END)*100.0
                   / NULLIF(COUNT(r.id),0),1) AS pass_rate
             FROM exam_results r JOIN exams e ON r.exam_id=e.id
             WHERE r.exam_id=?"
        );
        $stats->execute([$id]);

        // Score distribution buckets (0-20, 21-40, 41-60, 61-80, 81-100)
        $dist = $pdo->prepare(
            "SELECT
             SUM(CASE WHEN percentage BETWEEN 0  AND 20  THEN 1 ELSE 0 END) AS d0_20,
             SUM(CASE WHEN percentage BETWEEN 21 AND 40  THEN 1 ELSE 0 END) AS d21_40,
             SUM(CASE WHEN percentage BETWEEN 41 AND 60  THEN 1 ELSE 0 END) AS d41_60,
             SUM(CASE WHEN percentage BETWEEN 61 AND 80  THEN 1 ELSE 0 END) AS d61_80,
             SUM(CASE WHEN percentage BETWEEN 81 AND 100 THEN 1 ELSE 0 END) AS d81_100
             FROM exam_results WHERE exam_id=?"
        );
        $dist->execute([$id]);

        // Question accuracy
        $qAcc = $pdo->prepare(
            "SELECT q.id, LEFT(q.question_text,80) AS question_preview,
             q.difficulty, q.marks,
             COUNT(ua.id)  AS total_attempts,
             SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) AS correct_count,
             ROUND(SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END)*100.0
                   / NULLIF(COUNT(ua.id),0),1) AS accuracy_pct
             FROM questions q
             LEFT JOIN user_answers ua ON q.id=ua.question_id
             WHERE q.exam_id=?
             GROUP BY q.id ORDER BY accuracy_pct ASC"
        );
        $qAcc->execute([$id]);

        jsonSuccess([
            'exam'            => $exam,
            'stats'           => $stats->fetch(),
            'score_dist'      => $dist->fetch(),
            'question_analysis' => $qAcc->fetchAll(),
        ]);

    // ----------------------------------------------------------
    //  STUDENTS LIST (teacher view)
    // ----------------------------------------------------------
    case 'students':
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.username, u.email, u.roll_number,
             u.class_sec, u.department, u.institution, u.is_active, u.created_at,
             COUNT(r.id)                AS exams_attempted,
             ROUND(AVG(r.percentage),1) AS avg_pct,
             SUM(CASE WHEN r.percentage >= e.passing_pct THEN 1 ELSE 0 END) AS passed_count
             FROM users u
             LEFT JOIN exam_results r ON u.id=r.user_id
             LEFT JOIN exams e ON r.exam_id=e.id
             WHERE u.role='student' AND u.is_active=1
             GROUP BY u.id
             ORDER BY u.full_name ASC"
        );
        $stmt->execute();
        jsonSuccess($stmt->fetchAll());

    // ----------------------------------------------------------
    //  LEADERBOARD FOR ONE EXAM
    // ----------------------------------------------------------
    case 'leaderboard':
        $exam_id = (int) ($_GET['exam_id'] ?? 0);
        if (!$exam_id) jsonError('exam_id required.');

        // Only teacher's own exam or any active exam for students
        if ($user['role'] === 'teacher') {
            $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
            $own->execute([$exam_id, $user['id']]);
            if (!$own->fetch()) jsonError('Access denied.', 403);
        }

        $stmt = $pdo->prepare(
            "SELECT RANK() OVER (ORDER BY r.percentage DESC) AS `rank`,
             u.full_name, u.roll_number, u.class_sec,
             r.score, r.total_marks, r.percentage,
             ROUND(r.time_taken/60,1) AS minutes_taken,
             CASE WHEN r.percentage >= e.passing_pct THEN 'PASS' ELSE 'FAIL' END AS result
             FROM exam_results r
             JOIN users u ON r.user_id=u.id
             JOIN exams e ON r.exam_id=e.id
             WHERE r.exam_id=?
             ORDER BY r.percentage DESC, r.time_taken ASC"
        );
        $stmt->execute([$exam_id]);
        jsonSuccess($stmt->fetchAll());

    default:
        jsonError('Unknown analytics type.', 404);
}
