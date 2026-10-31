<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
require '../../../config/dbcon.php';
require 'theta_calculator.php';
require 'badge_checker.php';

class QuizProcessor
{
    private $conn;
    private $student_id;
    private $quiz_id;

    public function __construct($conn, $student_id, $quiz_id)
    {
        $this->conn = $conn;
        $this->student_id = (int)$student_id;
        $this->quiz_id = (int)$quiz_id;
    }

    public function startQuiz()
    {
        if (!$this->hasQuizAccess()) {
            return ['error' => 'Quiz not available'];
        }

        // Check if quiz already completed in student_quizzes
        if ($this->isQuizCompleted()) {
            return ['error' => 'Quiz already completed'];
        }

        // Get ongoing attempt OR create new one
        $attempt_id = $this->getOngoingAttempt();
        if (!$attempt_id) {
            $attempt_id = $this->createAttempt();
        }

        // Get next question
        $question = $this->getNextQuestion();

        if (!$question) {
            // No more questions - quiz is complete
            $this->completeQuiz($attempt_id);
            $score_data = $this->calculateFinalScore();
            return [
                'quiz_complete' => true,
                'score_data' => $score_data,
                'attempt_id' => $attempt_id
            ];
        }

        // Combine all data into one response
        return array_merge([
            'success' => true,
            'attempt_id' => $attempt_id,
            'quiz_started' => true
        ], $question);
    }

 public function processAnswer($attempt_id, $question_id, $selected_option)
{
    if (!$this->validateAttempt($attempt_id, true)) {
        return ['error' => 'Invalid attempt (not active)'];
    }

    $question = $this->getQuestion($question_id);
    if (!$question) {
        return ['error' => 'Question not found'];
    }

    // Clean and compare answers
    $student_answer = strtoupper(trim($selected_option));
    $correct_answer = strtoupper(trim($question['correct_option']));
    $is_correct = ($student_answer === $correct_answer);

    // Debug log
    error_log("Answer check: Student selected '$student_answer', correct is '$correct_answer', is_correct = " . ($is_correct ? 'true' : 'false'));

    // -------------------- CHECK FIRST CORRECT ATTEMPT --------------------
    $first_correct = false;
    if ($is_correct) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS correct_attempts
            FROM quiz_logs
            WHERE student_id = ?
              AND quiz_id = ?
              AND question_id = ?
              AND is_correct = 1
        ");
        $stmt->bind_param("iii", $this->student_id, $this->quiz_id, $question_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        $first_correct = ($data['correct_attempts'] == 0);
    }
    // --------------------------------------------------------------------

    // Always record attempt (for skill assessment)
    $this->recordQuestionAttempt($question_id, $selected_option, $is_correct);

    if (!$is_correct) {
        return [
            'is_correct' => false,
            'hint' => $question['hint'] ?? '',
            'retry' => true,
            'points_awarded' => 0,
            'correct_answer' => $question['correct_option']
        ];
    }

    // Prepare response
    $response = ['is_correct' => true];

    // -------------------- AWARD POINTS IF FIRST CORRECT --------------------
    if ($first_correct) {
        $points_awarded = $question['points'];
        $response['points_awarded'] = $points_awarded;

        $this->updateStudentScore($points_awarded);

        // Theta calculation
        $theta_calc = new ThetaCalculator($this->conn);
        $theta_result = $theta_calc->processAnswer(
            $this->student_id,
            $this->quiz_id,
            $question_id,
            $is_correct
        );
        $this->updateQuizLogTheta($question_id, $theta_result);

        // Badge check
        try {
            $badges = new BadgeChecker($this->conn);
            $badges->checkAfterQuizCompletion($this->student_id, $this->quiz_id);
        } catch (Exception $e) {
            error_log("Badge check error: " . $e->getMessage());
        }
    } else {
        $response['points_awarded'] = 0;
    }
    // --------------------------------------------------------------------

    // Update student stats
    $updated_student = $this->getStudentData();
    $response['student_stats'] = [
        'theta' => $updated_student['theta'],
        'current_points' => $updated_student['current_points'],
        'current_level' => $updated_student['current_level'],
        'learner_category' => $updated_student['learner_category'],
        'assigned_set' => $updated_student['assigned_set'],
        'p_value' => $updated_student['p_value']
    ];

    // Get next question
    $next_question = $this->getNextQuestion();

    if (!$next_question) {
        // Quiz complete
        $this->completeQuiz($attempt_id);

        $score_data = $this->calculateFinalScore();
        $this->recordFinalQuizScore($score_data);

        $response['quiz_complete'] = true;
        $response['score_data'] = $score_data;
    } else {
        // Merge next question into response
        foreach ($next_question as $key => $value) {
            $response[$key] = $value;
        }
    }

    return $response;
}

    public function getQuizResults($attempt_id)
    {
        // Allow completed attempts for results view
        if (!$this->validateAttempt($attempt_id, false)) {
            return ['error' => 'Invalid attempt'];
        }

        // First, ensure quiz is marked as completed
        if (!$this->isQuizCompleted()) {
            $this->completeQuiz($attempt_id);
            
            $score_data = $this->calculateFinalScore();
            $this->recordFinalQuizScore($score_data);
        }

        // Return redirect URL
        return [
            'success' => true,
            'redirect_url' => '../quizzes/quiz_complete.php?quiz_id=' . $this->quiz_id . '&attempt_id=' . $attempt_id
        ];
    }

    private function hasQuizAccess()
    {
        $stmt = $this->conn->prepare("
            SELECT qs.* 
            FROM quiz_sections qs
            JOIN students s ON s.section_id = qs.section_id
            WHERE qs.quiz_id = ? 
            AND s.student_id = ?
            AND qs.is_active = 1
            AND (qs.due_date IS NULL OR qs.due_date >= NOW())
        ");
        $stmt->bind_param("ii", $this->quiz_id, $this->student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasAccess = $result->num_rows > 0;
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        return $hasAccess;
    }

    private function isQuizCompleted()
    {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM student_quizzes 
            WHERE student_id = ? AND quiz_id = ?
        ");
        $stmt->bind_param("ii", $this->student_id, $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $completed = $result->num_rows > 0;
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        return $completed;
    }

    private function getOngoingAttempt()
    {
        $stmt = $this->conn->prepare("
            SELECT attempt_id 
            FROM quiz_attempts 
            WHERE student_id = ? 
            AND quiz_id = ? 
            AND is_completed = 0
            ORDER BY timestamp DESC
            LIMIT 1
        ");
        $stmt->bind_param("ii", $this->student_id, $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $result->free();  // ADDED
            $stmt->close();   // ADDED
            return null;
        }
        
        $data = $result->fetch_assoc();
        $attempt_id = $data['attempt_id'];
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        
        return $attempt_id;
    }

    private function createAttempt()
    {
        $stmt = $this->conn->prepare("
            INSERT INTO quiz_attempts (student_id, quiz_id, is_completed, timestamp)
            VALUES (?, ?, 0, NOW())
        ");
        $stmt->bind_param("ii", $this->student_id, $this->quiz_id);
        $stmt->execute();
        $insert_id = $stmt->insert_id;
        $stmt->close();  // ADDED
        return $insert_id;
    }

    private function getNextQuestion()
    {
        $stmt = $this->conn->prepare("
            SELECT q.*
            FROM questions q
            JOIN quiz_questions qq ON q.question_id = qq.question_id
            WHERE qq.quiz_id = ?
            AND q.question_id NOT IN (
                SELECT question_id 
                FROM quiz_logs 
                WHERE student_id = ? 
                AND quiz_id = ?
                AND is_correct = 1
            )
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->bind_param("iii", $this->quiz_id, $this->student_id, $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $result->free();  // ADDED
            $stmt->close();   // ADDED
            return null;
        }

        $question = $result->fetch_assoc();
        $result->free();  // ADDED
        $stmt->close();   // ADDED

        return [
            'question_id' => $question['question_id'],
            'content' => $question['content'],
            'content_image' => $question['content_image'],
            'option_a' => $question['option_a'],
            'option_a_image' => $question['option_a_image'],
            'option_b' => $question['option_b'],
            'option_b_image' => $question['option_b_image'],
            'option_c' => $question['option_c'],
            'option_c_image' => $question['option_c_image'],
            'option_d' => $question['option_d'],
            'option_d_image' => $question['option_d_image'],
            'points' => $question['points'],
            'difficulty' => $question['difficulty'],
            'hint' => $question['hint'],
            'correct_option' => $question['correct_option']
        ];
    }

    private function recordQuestionAttempt($question_id, $selected_option, $is_correct)
    {
        $student_data = $this->getStudentData();
        $current_theta = $student_data['theta'] ?? 0;
        
        $stmt = $this->conn->prepare("
            INSERT INTO quiz_logs (student_id, quiz_id, question_id, is_correct, old_theta, new_theta, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiiidd", $this->student_id, $this->quiz_id, $question_id, $is_correct, $current_theta, $current_theta);
        $stmt->execute();
        $stmt->close();  // ADDED

        return true;
    }

    private function updateQuizLogTheta($question_id, $theta_result)
    {
        if (isset($theta_result['old_theta']) && isset($theta_result['new_theta'])) {
            $stmt = $this->conn->prepare("
                UPDATE quiz_logs 
                SET old_theta = ?, new_theta = ?
                WHERE student_id = ? 
                AND quiz_id = ?
                AND question_id = ?
                AND is_correct = 1
                ORDER BY timestamp DESC
                LIMIT 1
            ");
            $stmt->bind_param(
                "ddiii",
                $theta_result['old_theta'],
                $theta_result['new_theta'],
                $this->student_id,
                $this->quiz_id,
                $question_id
            );
            $stmt->execute();
            $stmt->close();  // ADDED
        }
    }

    private function updateStudentScore($points)
    {
        $stmt = $this->conn->prepare("
            UPDATE students 
            SET current_points = current_points + ?
            WHERE student_id = ?
        ");
        $stmt->bind_param("ii", $points, $this->student_id);
        $stmt->execute();
        $stmt->close();  // ADDED
        
        $this->updateStudentLevel();
    }

    private function updateStudentLevel()
    {
        $stmt = $this->conn->prepare("
            SELECT current_points FROM students WHERE student_id = ?
        ");
        $stmt->bind_param("i", $this->student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $current_points = $data['current_points'];
        $result->free();  // ADDED
        $stmt->close();   // ADDED

        $new_level = floor($current_points / 20) + 1;
        if ($new_level > 50) $new_level = 50;

        $stmt = $this->conn->prepare("
            UPDATE students 
            SET current_level = ?
            WHERE student_id = ?
        ");
        $stmt->bind_param("ii", $new_level, $this->student_id);
        $stmt->execute();
        $stmt->close();  // ADDED
    }

    private function completeQuiz($attempt_id)
    {
        $stmt = $this->conn->prepare("
            UPDATE quiz_attempts 
            SET is_completed = 1
            WHERE attempt_id = ?
        ");
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $stmt->close();  // ADDED
    }

    private function calculateFinalScore()
    {
        // Get total questions in quiz
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total
            FROM quiz_questions 
            WHERE quiz_id = ?
        ");
        $stmt->bind_param("i", $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_result = $result->fetch_assoc();
        $total_questions = $total_result['total'] ?? 0;
        $result->free();  // ADDED
        $stmt->close();   // ADDED

        // Get correct answers
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(DISTINCT ql.question_id) as correct_count,
                COALESCE(SUM(q.points), 0) as total_points
            FROM quiz_logs ql
            JOIN questions q ON ql.question_id = q.question_id
            WHERE ql.student_id = ? 
            AND ql.quiz_id = ?
            AND ql.is_correct = 1
        ");
        $stmt->bind_param("ii", $this->student_id, $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $score_result = $result->fetch_assoc();
        $result->free();  // ADDED
        $stmt->close();   // ADDED

        $correct_count = $score_result['correct_count'] ?? 0;
        $total_points = $score_result['total_points'] ?? 0;

        $accuracy = $total_questions > 0 ? ($correct_count / $total_questions) * 100 : 0;

        return [
            'total_questions' => $total_questions,
            'questions_correct' => $correct_count,
            'total_points' => $total_points,
            'accuracy' => round($accuracy, 2),
            'score' => $total_points
        ];
    }

    private function recordFinalQuizScore($score_data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO student_quizzes (student_id, quiz_id, score, date_taken)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param("iid", $this->student_id, $this->quiz_id, $score_data['score']);
        $stmt->execute();
        $stmt->close();  // ADDED
    }

    private function getStudentData()
    {
        $stmt = $this->conn->prepare("
            SELECT theta, p_value, current_points, current_level, 
                   learner_category, assigned_set
            FROM students 
            WHERE student_id = ?
        ");
        $stmt->bind_param("i", $this->student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        return $data;
    }

    private function validateAttempt($attempt_id, $requireActive = false)
    {
        if ($requireActive) {
            $sql = "SELECT 1 FROM quiz_attempts WHERE attempt_id = ? AND student_id = ? AND quiz_id = ? AND is_completed = 0";
        } else {
            $sql = "SELECT 1 FROM quiz_attempts WHERE attempt_id = ? AND student_id = ? AND quiz_id = ?";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $attempt_id, $this->student_id, $this->quiz_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        
        return $valid;
    }

    private function getQuestion($question_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM questions WHERE question_id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $question = $result->fetch_assoc();
        $result->free();  // ADDED
        $stmt->close();   // ADDED
        return $question;
    }
}

// Main execution
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$action = $_POST['action'] ?? '';
$quiz_id = $_POST['quiz_id'] ?? 0;

if (empty($quiz_id)) {
    echo json_encode(['error' => 'Quiz ID required']);
    exit;
}

$processor = new QuizProcessor($conn, $_SESSION['student_id'], $quiz_id);

try {
    switch ($action) {
        case 'start':
            $result = $processor->startQuiz();
            break;

        case 'submit':
            $attempt_id = $_POST['attempt_id'] ?? 0;
            $question_id = $_POST['question_id'] ?? 0;
            $answer = $_POST['answer'] ?? '';

            if (empty($attempt_id) || empty($question_id) || empty($answer)) {
                echo json_encode(['error' => 'Missing parameters']);
                exit;
            }

            $result = $processor->processAnswer($attempt_id, $question_id, $answer);
            break;

        case 'results':
            $attempt_id = $_POST['attempt_id'] ?? 0;
            if (empty($attempt_id)) {
                echo json_encode(['error' => 'Attempt ID required']);
                exit;
            }
            $result = $processor->getQuizResults($attempt_id);
            break;

        default:
            $result = ['error' => 'Invalid action'];
    }

    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Quiz Processor Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Clean any output buffer
    if (ob_get_length()) ob_clean();
    
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

// Clean up connection
if (isset($conn) && $conn instanceof mysqli) {
    // Free any remaining results
    while ($conn->more_results()) {
        $conn->next_result();
        if ($res = $conn->store_result()) {
            $res->free();
        }
    }
}

ob_end_flush();
?>