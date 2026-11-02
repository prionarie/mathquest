<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['student_id']) || !isset($_POST['quiz_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$student_id = $_SESSION['student_id'];
$quiz_id = (int)$_POST['quiz_id'];

$stmt = $conn->prepare("
    SELECT 1 
    FROM quiz_sections qs
    JOIN students s ON s.section_id = qs.section_id
    WHERE qs.quiz_id = ?
    AND s.student_id = ?
    AND qs.is_active = 1
    AND (qs.due_date IS NULL OR qs.due_date >= NOW())
");
$stmt->bind_param("ii", $quiz_id, $student_id);
$stmt->execute();
$has_access = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$has_access) {
    echo json_encode(['success' => false, 'error' => 'Quiz not available']);
    exit;
}

$stmt = $conn->prepare("
    SELECT 1 FROM student_quizzes 
    WHERE student_id = ? AND quiz_id = ?
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$completed = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($completed) {
    echo json_encode(['success' => false, 'error' => 'Quiz already completed']);
    exit;
}

$stmt = $conn->prepare("
    SELECT attempt_id FROM quiz_attempts 
    WHERE student_id = ? AND quiz_id = ? AND is_completed = 0
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$result = $stmt->get_result();
$existing_attempt = $result->fetch_assoc();
$stmt->close();

if ($existing_attempt) {
    echo json_encode(['success' => true, 'attempt_id' => $existing_attempt['attempt_id']]);
    exit;
}

$conn->begin_transaction();
try {
    $questions_stmt = $conn->prepare("
        SELECT qq.question_id 
        FROM quiz_questions qq
        WHERE qq.quiz_id = ?
        ORDER BY qq.id
    ");
    $questions_stmt->bind_param("i", $quiz_id);
    $questions_stmt->execute();
    $questions = $questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $questions_stmt->close();
    
    if (empty($questions)) {
        throw new Exception("No questions found for this quiz");
    }

    $stmt = $conn->prepare("
        INSERT INTO quiz_attempts (student_id, quiz_id, question_id, is_completed, timestamp)
        VALUES (?, ?, ?, 0, NOW())
    ");

    $first_question_id = $questions[0]['question_id'];
    $stmt->bind_param("iii", $student_id, $quiz_id, $first_question_id);
    $stmt->execute();
    $attempt_id = $stmt->insert_id;
    $stmt->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'attempt_id' => $attempt_id,
        'total_questions' => count($questions)
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Start Quiz Attempt Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>