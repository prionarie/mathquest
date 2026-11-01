<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['ongoing' => []]);
    exit;
}

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("
    SELECT 
        a.attempt_id, 
        a.quiz_id, 
        q.quiz_type,
        q.quarter,
        q.week,
        a.timestamp as last_activity,
        (
            SELECT COUNT(*)
            FROM quiz_attempts a2
            WHERE a2.student_id = a.student_id
            AND a2.quiz_id = a.quiz_id
            AND a2.is_completed = 1
        ) as completed_questions,
        (
            SELECT COUNT(*)
            FROM quiz_questions qq
            WHERE qq.quiz_id = a.quiz_id
        ) as total_questions
    FROM quiz_attempts a
    JOIN quizzes q ON a.quiz_id = q.quiz_id
    WHERE a.student_id = ? 
    AND a.is_completed = 0
    GROUP BY a.quiz_id  -- Group by quiz to avoid duplicates
    ORDER BY a.timestamp DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$ongoing = [];
while ($row = $result->fetch_assoc()) {
    $ongoing[] = [
        'attempt_id' => $row['attempt_id'],
        'quiz_id' => $row['quiz_id'],
        'quiz_title' => $row['quarter'] . ' Quarter - Week ' . $row['week'] . ' (' . $row['quiz_type'] . ')',
        'quiz_type' => $row['quiz_type'],
        'quarter' => $row['quarter'],
        'week' => $row['week'],
        'last_activity' => $row['last_activity'],
        'progress' => [
            'completed' => $row['completed_questions'] ?? 0,
            'total' => $row['total_questions'] ?? 0,
            'percentage' => $row['total_questions'] > 0 ? 
                round(($row['completed_questions'] / $row['total_questions']) * 100) : 0
        ]
    ];
}

$stmt->close();

echo json_encode(['ongoing' => $ongoing]);
?>