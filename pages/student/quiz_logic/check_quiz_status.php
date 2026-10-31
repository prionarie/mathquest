<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['student_id']) || !isset($_GET['quiz_id'])) {
    echo json_encode(['ongoing' => false]);
    exit;
}

$student_id = $_SESSION['student_id'];
$quiz_id = (int)$_GET['quiz_id'];

$stmt = $conn->prepare("
    SELECT 
        a.attempt_id, 
        q.quiz_type,
        q.quarter,
        q.week,
        a.timestamp as last_activity,
        a.is_completed,
        -- Check if there's a student_quizzes entry (quiz already completed)
        (
            SELECT 1 
            FROM student_quizzes sq 
            WHERE sq.student_id = a.student_id 
            AND sq.quiz_id = a.quiz_id
            LIMIT 1
        ) as already_completed
    FROM quiz_attempts a
    JOIN quizzes q ON a.quiz_id = q.quiz_id
    WHERE a.student_id = ? 
    AND a.quiz_id = ?
    ORDER BY a.timestamp DESC
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    $is_completed = $row['is_completed'] == 1;
    $already_completed = $row['already_completed'] == 1;
    $is_ongoing = !$is_completed && !$already_completed;
    
    if ($is_ongoing) {
        echo json_encode([
            'ongoing' => true, 
            'attempt_id' => $row['attempt_id'], 
            'quiz_title' => $row['quarter'] . ' Quarter - Week ' . $row['week'] . ' (' . $row['quiz_type'] . ')',
            'last_activity' => $row['last_activity'],
            'quiz_info' => [
                'quarter' => $row['quarter'],
                'week' => $row['week'],
                'type' => $row['quiz_type']
            ]
        ]);
    } else {
        echo json_encode([
            'ongoing' => false,
            'reason' => $already_completed ? 'already_completed' : 'attempt_completed',
            'attempt_id' => $row['attempt_id']
        ]);
    }
} else {
    echo json_encode(['ongoing' => false, 'reason' => 'no_attempt']);
}

$stmt->close();
?>