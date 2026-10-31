<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['student_id']) || !isset($_GET['quiz_id'])) {
    header('Location: ../login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$quiz_id = (int)$_GET['quiz_id'];

$stmt = $conn->prepare("
    SELECT 
        q.quiz_id,
        q.quarter,
        q.week,
        q.quiz_type,
        q.date_created,
        qs.due_date,
        COUNT(DISTINCT qq.question_id) as question_count,
        COALESCE(SUM(quest.points), 0) as total_points,
        sec.section_name
    FROM quizzes q
    JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
    JOIN sections sec ON qs.section_id = sec.section_id
    JOIN students s ON sec.section_id = s.section_id
    LEFT JOIN quiz_questions qq ON q.quiz_id = qq.quiz_id
    LEFT JOIN questions quest ON qq.question_id = quest.question_id
    WHERE q.quiz_id = ?
    AND s.student_id = ?
    AND qs.is_active = 1
    GROUP BY q.quiz_id, q.quarter, q.week, q.quiz_type, q.date_created, qs.due_date, sec.section_name
");
$stmt->bind_param("ii", $quiz_id, $student_id);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    header('Location: ../quizzes.php?error=quiz_not_found');
    exit;
}

$stmt = $conn->prepare("
    SELECT score FROM student_quizzes 
    WHERE student_id = ? AND quiz_id = ?
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$completed = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($completed) {
    header("Location: quiz_review.php?quiz_id=$quiz_id");
    exit;
}

$stmt = $conn->prepare("
    SELECT attempt_id 
    FROM quiz_attempts 
    WHERE student_id = ? AND quiz_id = ? AND is_completed = 0
    ORDER BY timestamp DESC LIMIT 1
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$ongoing = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
    SELECT s.first_name, s.last_name, s.current_level, s.current_points,
           s.learner_category, s.assigned_set, s.p_value,
           s.theta
    FROM students s
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$student_name = trim($student['first_name'] . ' ' . $student['last_name']);

if (!isset($student['p_value']) || $student['p_value'] === null) {
    $student['p_value'] = 0.5;
}
if (!isset($student['theta']) || $student['theta'] === null) {
    $student['theta'] = 0.0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Start Quiz | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/student/quizzes.css">
    <style>
        .quiz-start-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .quiz-start-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 40px;
            box-shadow: var(--box-shadow);
            text-align: center;
            border: 3px solid rgba(67, 97, 238, 0.2);
        }

        .quiz-icon {
            font-size: 64px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .quiz-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 32px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .quiz-subtitle {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }

        .quiz-details {
            background: rgba(76, 201, 240, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(76, 201, 240, 0.2);
        }

        .detail-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-label {
            color: #666;
            font-weight: 500;
        }

        .detail-value {
            color: var(--dark-color);
            font-weight: 600;
        }

        .difficulty-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .difficulty-easy {
            background: rgba(46, 196, 182, 0.1);
            color: var(--success-color);
            border: 2px solid rgba(46, 196, 182, 0.3);
        }

        .difficulty-medium {
            background: rgba(255, 159, 28, 0.1);
            color: var(--warning-color);
            border: 2px solid rgba(255, 159, 28, 0.3);
        }

        .difficulty-hard {
            background: rgba(231, 29, 54, 0.1);
            color: var(--danger-color);
            border: 2px solid rgba(231, 29, 54, 0.3);
        }

        .quiz-instructions {
            text-align: left;
            margin-bottom: 30px;
            padding: 20px;
            background: #F8F9FA;
            border-radius: 10px;
            border-left: 4px solid var(--accent-color);
        }

        .instructions-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .instructions-list {
            list-style: none;
            padding: 0;
        }

        .instructions-list li {
            margin-bottom: 10px;
            padding-left: 25px;
            position: relative;
        }

        .instructions-list li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: var(--success-color);
            font-weight: bold;
        }

        .student-info {
            background: rgba(67, 97, 238, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .student-level {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .level-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #FF9F1C 0%, #FFD166 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .level-info h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .level-info p {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark-color);
        }

        .learner-badge {
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            color: white;
        }

        .slow-learner {
            background: linear-gradient(90deg, #E71D36 0%, #FF6B6B 100%);
        }

        .average-learner {
            background: linear-gradient(90deg, #3A0CA3 0%, #4361EE 100%);
        }

        .fast-learner {
            background: linear-gradient(90deg, #2EC4B6 0%, #4ADE80 100%);
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 30px;
        }

        .btn-large {
            padding: 15px 40px;
            font-size: 18px;
            border-radius: 12px;
            min-width: 180px;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline:hover {
            background: var(--secondary-color);
            color: white;
        }

        .warning-message {
            background: rgba(255, 159, 28, 0.1);
            border: 2px solid var(--warning-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }

        .warning-message i {
            color: var(--warning-color);
            font-size: 24px;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .quiz-start-card {
                padding: 25px;
            }

            .quiz-title {
                font-size: 24px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-large {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="../student_dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i>
                    <span>Dashboard</span>
                </a>
                <a href="../modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i>
                    <span>Learning Materials</span>
                </a>
                <a href="../quizzes.php" class="nav-item">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    <span>Quizzes</span>
                </a>
                <a href="../profile.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span>My Profile</span>
                </a>
                <a href="../logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="profile.php">
                    <div class="user-avatar" id="user-avatar">
                        <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                    </div>
                </a>
                <div class="user-info">
                    <h4 id="user-name"><?= htmlspecialchars($student_name) ?></h4>
                    <p id="user-type">Student</p>
                </div>
            </div>
        </aside>
        <main class="main-content">
            <div class="quiz-start-container">
                <div class="quiz-start-card">
                    <div class="quiz-icon">
                        <i class="fas fa-brain"></i>
                    </div>

                    <h1 class="quiz-title">
                        <?= htmlspecialchars(getQuizDisplayName($quiz['quarter'], $quiz['week'], $quiz['quiz_type'])) ?>
                    </h1>

                    <p class="quiz-subtitle">
                        Get ready to test your math skills!
                    </p>

                    <?php if ($ongoing): ?>
                        <div class="warning-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>You have an ongoing attempt!</h3>
                            <p>You started this quiz previously but didn't finish. You can continue where you left off.</p>
                        </div>
                    <?php endif; ?>

                    <div class="quiz-details">
                        <div class="detail-row">
                            <span class="detail-label">Quarter:</span>
                            <span class="detail-value"><?= htmlspecialchars($quiz['quarter']) ?> Quarter</span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">Week:</span>
                            <span class="detail-value">Week <?= htmlspecialchars($quiz['week']) ?></span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">Difficulty:</span>
                            <span class="detail-value">
                                <span class="difficulty-badge difficulty-<?= strtolower($quiz['quiz_type']) ?>">
                                    <?= htmlspecialchars($quiz['quiz_type']) ?>
                                </span>
                            </span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">Questions:</span>
                            <span class="detail-value"><?= htmlspecialchars($quiz['question_count']) ?> questions</span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">Total Points:</span>
                            <span class="detail-value"><?= htmlspecialchars($quiz['total_points'] ?? 0) ?> points</span>
                        </div>

                        <?php if ($quiz['due_date']): ?>
                            <div class="detail-row">
                                <span class="detail-label">Due Date:</span>
                                <span class="detail-value <?= strtotime($quiz['due_date']) < time() ? 'text-danger' : '' ?>">
                                    <?= date('F j, Y g:i A', strtotime($quiz['due_date'])) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="quiz-instructions">
                        <h3 class="instructions-title">
                            <i class="fas fa-info-circle"></i>
                            Quiz Instructions
                        </h3>
                        <ul class="instructions-list">
                            <li>You get points if you answer right the first time.</li>
                            <li>You can try again if you get an answer wrong.</li>
                            <li>Complete the quiz to earn points and level up!</li>
                        </ul>
                    </div>

                    <div class="student-info">
                        <div class="student-level">
                            <div class="level-icon">
                                Lvl <?= htmlspecialchars($student['current_level']) ?>
                            </div>
                            <div class="level-info">
                                <h4>Your Current Level</h4>
                                <?php
                                $total_points = $student['current_points'];
                                $points_per_level = 20;
                                $max_level = 50;

                                // Current level calculation
                                $student_level = floor($total_points / $points_per_level) + 1;
                                if ($student_level > $max_level) {
                                    $student_level = $max_level;
                                }

                                // Points to next level
                                if ($student_level >= $max_level) {
                                    $points_to_next = 0;
                                    $next_level_text = 'Maximum Level (' . $max_level . ')';
                                } else {
                                    $points_to_next = $points_per_level - ($total_points % $points_per_level);
                                    $next_level_text = $points_to_next . ' points to Level ' . ($student_level + 1);
                                }
                                ?>
                                <p><?= htmlspecialchars($next_level_text) ?></p>
                            </div>
                        </div>

                        <div class="learner-badge <?= strtolower(str_replace(' ', '-', $student['learner_category'])) ?>">
                            <i class="fas fa-user-graduate"></i>
                            <?= htmlspecialchars($student['learner_category']) ?>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <a href="../quizzes.php" class="btn btn-large btn-outline">
                            <i class="fas fa-arrow-left"></i>
                            Back to Quizzes
                        </a>

                        <?php if ($ongoing): ?>
                            <button class="btn btn-large btn-primary" onclick="continueQuiz(<?= $quiz_id ?>, <?= $ongoing['attempt_id'] ?>)">
                                <i class="fas fa-play"></i>
                                Continue Quiz
                            </button>
                        <?php else: ?>
                            <button class="btn btn-large btn-primary" onclick="startNewQuiz(<?= $quiz_id ?>)">
                                <i class="fas fa-rocket"></i>
                                Start Quiz
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../../../assets/js/student/sidebar.js"></script>
    <script>
        function startNewQuiz(quizId) {
            // Show loading state
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting Quiz...';
            btn.disabled = true;

            // Start new quiz attempt
            fetch('../quiz_logic/start_quiz_attempt.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'quiz_id=' + quizId + '&action=start'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'quiz_taking.php?quiz_id=' + quizId + '&attempt_id=' + data.attempt_id;
                    } else {
                        alert('Error: ' + (data.error || 'Failed to start quiz'));
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error. Please try again.');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        function continueQuiz(quizId, attemptId) {
            window.location.href = 'quiz_taking.php?quiz_id=' + quizId + '&attempt_id=' + attemptId;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Add logout handler
            document.getElementById('logout-btn').addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = this.getAttribute('href');
                }
            });
        });
    </script>
</body>

</html>

<?php
function getQuizDisplayName($quarter, $week, $type)
{
    return $quarter . ' Quarter - Week ' . $week;
}
?>