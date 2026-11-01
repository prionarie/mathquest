<?php
session_start();
require '../../../config/dbcon.php';
require '../quiz_logic/badge_checker.php';

if (!isset($_SESSION['student_id']) || !isset($_GET['quiz_id'])) {
    header('Location: ../../login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$quiz_id = (int)$_GET['quiz_id'];
$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;

// First, verify the quiz is completed for this student
$stmt = $conn->prepare("
    SELECT 1 FROM student_quizzes 
    WHERE student_id = ? AND quiz_id = ?
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$quiz_completed = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$quiz_completed) {
    // Quiz not completed yet, redirect back
    header('Location: ../quizzes.php?error=quiz_not_completed');
    exit;
}

// Get quiz results from student_quizzes table (more reliable)
$stmt = $conn->prepare("
    SELECT 
        sq.score,
        sq.date_taken,
        q.quarter,
        q.week,
        q.quiz_type
    FROM student_quizzes sq
    JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE sq.student_id = ? 
    AND sq.quiz_id = ?
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$quiz_result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz_result) {
    header('Location: ../quizzes.php?error=no_results');
    exit;
}

// Get student info
$stmt = $conn->prepare("
    SELECT 
        s.first_name, 
        s.last_name, 
        s.current_level, 
        s.current_points,
        s.learner_category,
        s.theta,
        s.p_value
    FROM students s
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$student_name = trim($student['first_name'] . ' ' . $student['last_name']);

// Get detailed quiz statistics - FIXED QUERY
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT qq.question_id) as total_questions,
        COUNT(DISTINCT CASE WHEN ql.is_correct = 1 THEN ql.question_id END) as correct_count,
        COALESCE(SUM(CASE WHEN ql.is_correct = 1 THEN q.points ELSE 0 END), 0) as total_points
    FROM quiz_questions qq
    LEFT JOIN quiz_logs ql ON qq.question_id = ql.question_id 
        AND ql.student_id = ? 
        AND ql.quiz_id = ?
    LEFT JOIN questions q ON qq.question_id = q.question_id
    WHERE qq.quiz_id = ?
");
$stmt->bind_param("iii", $student_id, $quiz_id, $quiz_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_questions = $stats['total_questions'] ?? 0;
$correct_count = $stats['correct_count'] ?? 0;
$total_points = $stats['total_points'] ?? 0;
$accuracy = $total_questions > 0 ? round(($correct_count / $total_questions) * 100, 2) : 0;

// Get badges earned for this quiz - FIXED: Use your updated BadgeChecker
$badge_checker = new BadgeChecker($conn);
// First, ensure badges are checked (in case they weren't awarded during completion)
$badge_checker->checkAfterQuizCompletion($student_id, $quiz_id);

// Get badges from database that were awarded for this quiz session
$stmt = $conn->prepare("
    SELECT DISTINCT badge_name 
    FROM student_badges 
    WHERE student_id = ? 
    AND date_awarded >= (
        SELECT date_taken 
        FROM student_quizzes 
        WHERE student_id = ? AND quiz_id = ?
        LIMIT 1
    )
    ORDER BY date_awarded DESC
    LIMIT 10
");
$stmt->bind_param("iii", $student_id, $student_id, $quiz_id);
$stmt->execute();
$result = $stmt->get_result();
$earned_badges = [];
while ($row = $result->fetch_assoc()) {
    $earned_badges[] = $row['badge_name'];
}
$stmt->close();

// Calculate level progress
$current_points = $student['current_points'] ?? 0;
$current_level = $student['current_level'] ?? 1;
$points_in_current_level = $current_points % 20;
$points_to_next = 20 - $points_in_current_level;
if ($points_to_next == 20) $points_to_next = 0;
$level_progress = $points_in_current_level * 5; // 5% per point

// Determine performance rating
if ($accuracy >= 90) {
    $performance_color = '#2EC4B6';
    $performance_icon = 'fas fa-trophy';
    $performance_title = 'Outstanding!';
} elseif ($accuracy >= 75) {
    $performance_color = '#FF9F1C';
    $performance_icon = 'fas fa-star';
    $performance_title = 'Great Job!';
} elseif ($accuracy >= 50) {
    $performance_color = '#4361EE';
    $performance_icon = 'fas fa-thumbs-up';
    $performance_title = 'Good Work!';
} else {
    $performance_color = '#E71D36';
    $performance_icon = 'fas fa-redo';
    $performance_title = 'Keep Practicing!';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Quiz Complete! | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student/quizzes.css">
    <style>
        .quiz-complete-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .quiz-complete-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 40px;
            box-shadow: var(--box-shadow);
            text-align: center;
            border: 3px solid <?= $performance_color ?>;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                border-color: <?= $performance_color ?>;
            }

            50% {
                border-color: transparent;
            }
        }

        .completion-icon {
            font-size: 80px;
            color: <?= $performance_color ?>;
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }

        @keyframes bounce {

            0%,
            20%,
            50%,
            80%,
            100% {
                transform: translateY(0);
            }

            40% {
                transform: translateY(-20px);
            }

            60% {
                transform: translateY(-10px);
            }
        }

        .quiz-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 36px;
            color: <?= $performance_color ?>;
            margin-bottom: 10px;
        }

        .quiz-subtitle {
            font-size: 20px;
            color: #666;
            margin-bottom: 30px;
        }

        .performance-badge {
            background: <?= $performance_color ?>;
            color: white;
            padding: 10px 30px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 18px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            box-shadow: 0 8px 20px rgba(<?= hexdec(substr($performance_color, 1, 2)) ?>, <?= hexdec(substr($performance_color, 3, 2)) ?>, <?= hexdec(substr($performance_color, 5, 2)) ?>, 0.3);
        }

        .results-summary {
            background: rgba(<?= hexdec(substr($performance_color, 1, 2)) ?>, <?= hexdec(substr($performance_color, 3, 2)) ?>, <?= hexdec(substr($performance_color, 5, 2)) ?>, 0.1);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .result-item {
            text-align: center;
        }

        .result-value {
            font-size: 36px;
            font-weight: 800;
            color: <?= $performance_color ?>;
            margin-bottom: 5px;
        }

        .result-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }

        .badges-section {
            background: rgba(255, 159, 28, 0.1);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 2px solid rgba(255, 159, 28, 0.3);
        }

        .badges-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: var(--warning-color);
            font-weight: 600;
            font-size: 18px;
            justify-content: center;
        }

        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .badge-item {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 2px solid rgba(255, 159, 28, 0.2);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .badge-item:hover {
            transform: translateY(-5px);
            border-color: var(--warning-color);
        }

        .badge-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--warning-color) 0%, #FFD166 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .badge-info {
            text-align: left;
            flex: 1;
        }

        .badge-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .badge-description {
            font-size: 12px;
            color: #666;
        }

        .progress-section {
            background: rgba(67, 97, 238, 0.1);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 2px solid rgba(67, 97, 238, 0.3);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .level-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .current-level {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        .level-text h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .level-text p {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-color);
        }

        .progress-container {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }

        .progress-bar {
            height: 20px;
            background: #E9ECEF;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color) 0%, var(--accent-color) 100%);
            border-radius: 10px;
            transition: width 1s ease;
            position: relative;
        }

        .progress-text {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            font-size: 12px;
        }

        .points-to-next {
            text-align: center;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .student-stats {
            background: rgba(76, 201, 240, 0.1);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 2px solid rgba(76, 201, 240, 0.3);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid rgba(76, 201, 240, 0.2);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(76, 201, 240, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: var(--accent-color);
            font-size: 18px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
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

        .no-badges {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        @media (max-width: 768px) {
            .quiz-complete-card {
                padding: 25px;
            }

            .quiz-title {
                font-size: 28px;
            }

            .quiz-subtitle {
                font-size: 16px;
            }

            .results-summary {
                grid-template-columns: 1fr;
            }

            .badges-grid {
                grid-template-columns: 1fr;
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
            <div class="quiz-complete-container">
                <div class="quiz-complete-card">
                    <div class="completion-icon">
                        <i class="<?= $performance_icon ?>"></i>
                    </div>

                    <h1 class="quiz-title"><?= $performance_title ?></h1>

                    <p class="quiz-subtitle">
                        You completed <?= htmlspecialchars($quiz_result['quarter']) ?> Quarter - Week <?= htmlspecialchars($quiz_result['week']) ?>
                    </p>

                    <div class="performance-badge">
                        <i class="<?= $performance_icon ?>"></i>
                        <?= $accuracy ?>% Accuracy
                    </div>

                    <div class="results-summary">
                        <div class="result-item">
                            <div class="result-value"><?= $accuracy ?>%</div>
                            <div class="result-label">Accuracy</div>
                        </div>

                        <div class="result-item">
                            <div class="result-value"><?= $correct_count ?>/<?= $total_questions ?></div>
                            <div class="result-label">Questions Correct</div>
                        </div>

                        <div class="result-item">
                            <div class="result-value"><?= $total_points ?></div>
                            <div class="result-label">Points Earned</div>
                        </div>

                        <div class="result-item">
                            <div class="result-value"><?= htmlspecialchars($quiz_result['quiz_type']) ?></div>
                            <div class="result-label">Difficulty Level</div>
                        </div>
                    </div>

                    <div class="badges-section">
                        <div class="badges-header">
                            <i class="fas fa-award"></i>
                            <span>Badges Earned</span>
                        </div>

                        <?php if (!empty($earned_badges)): ?>
                            <div class="badges-grid">
                                <?php foreach ($earned_badges as $badge_name): ?>
                                    <div class="badge-item">
                                        <div class="badge-icon">
                                            <?php
                                            $icon_map = [
                                                'Perfect Score' => 'fas fa-crown',
                                                'Math Whiz' => 'fas fa-brain',
                                                'Quick Learner' => 'fas fa-bolt',
                                                'Consistent Learner' => 'fas fa-calendar-check',
                                                'Math Beginner' => 'fas fa-seedling',
                                                'Math Explorer' => 'fas fa-compass',
                                                'Math Wizard' => 'fas fa-hat-wizard',
                                                'Math Master' => 'fas fa-graduation-cap',
                                                'Math Grandmaster' => 'fas fa-chess-queen',
                                                'Determined Learner' => 'fas fa-mountain',
                                                'Balanced Scholar' => 'fas fa-balance-scale',
                                                'Advanced Prodigy' => 'fas fa-rocket'
                                            ];
                                            $icon = $icon_map[$badge_name] ?? 'fas fa-trophy';
                                            ?>
                                            <i class="<?= $icon ?>"></i>
                                        </div>
                                        <div class="badge-info">
                                            <div class="badge-name"><?= htmlspecialchars($badge_name) ?></div>
                                            <div class="badge-description">
                                                <?php
                                                $descriptions = [
                                                    'Perfect Score' => 'Achieved 100% accuracy in a quiz',
                                                    'Math Whiz' => 'Scored 90% or higher in a quiz',
                                                    'Quick Learner' => 'Improved your θ (theta) score significantly',
                                                    'Consistent Learner' => 'Completed quizzes for 5 consecutive days',
                                                    'Math Beginner' => 'Reached level 1-9',
                                                    'Math Explorer' => 'Reached level 10-19',
                                                    'Math Wizard' => 'Reached level 20-29',
                                                    'Math Master' => 'Reached level 30-39',
                                                    'Math Grandmaster' => 'Reached level 40-50',
                                                    'Determined Learner' => 'Slow Learner showing great determination',
                                                    'Balanced Scholar' => 'Average Learner with consistent performance',
                                                    'Advanced Prodigy' => 'Fast Learner mastering concepts quickly'
                                                ];
                                                echo $descriptions[$badge_name] ?? 'Achievement unlocked!';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-badges" style="text-align: center; padding: 20px; color: #666;">
                                <i class="fas fa-award" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p>No new badges earned this time. Keep practicing!</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="student-stats">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-brain"></i>
                                </div>
                                <div class="stat-value"><?= number_format($student['theta'], 2) ?></div>
                                <div class="stat-label">Theta Score</div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="stat-value"><?= number_format($student['p_value'], 2) ?></div>
                                <div class="stat-label">Learning Rate (P)</div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div class="stat-value"><?= htmlspecialchars($student['learner_category']) ?></div>
                                <div class="stat-label">Learner Category</div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="stat-value"><?= date('M j, g:i a', strtotime($quiz_result['date_taken'])) ?></div>
                                <div class="stat-label">Completed On</div>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <a href="../quizzes.php" class="btn btn-large btn-outline">
                            <i class="fas fa-clipboard-list"></i>
                            Back to Quizzes
                        </a>

                        <a href="../profile.php" class="btn btn-large btn-primary">
                            <i class="fas fa-chart-line"></i>
                            View Full Profile
                        </a>

                        <?php
                        $stmt = $conn->prepare("
        SELECT q.quiz_id 
        FROM quizzes q
        JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
        JOIN students s ON qs.section_id = s.section_id
        WHERE q.quiz_type = ?
        AND q.quiz_id != ?
        AND qs.is_active = 1
        AND s.student_id = ?
        AND (qs.due_date IS NULL OR qs.due_date >= CURDATE())
        LIMIT 1
    ");
                        $stmt->bind_param("sii", $quiz_result['quiz_type'], $quiz_id, $student_id);
                        $stmt->execute();
                        $next_quiz = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($next_quiz): ?>
                            <a href="start_quiz.php?quiz_id=<?= $next_quiz['quiz_id'] ?>" class="btn btn-large btn-success">
                                <i class="fas fa-forward"></i>
                                Try Similar Quiz
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/js/student/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) {
                const computedStyle = getComputedStyle(progressFill);
                const width = computedStyle.getPropertyValue('width');
                progressFill.style.width = '0';
                setTimeout(() => {
                    progressFill.style.width = width;
                }, 500);
            }

            const logoutBtn = document.getElementById('logout-btn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = this.getAttribute('href');
                    }
                });
            }

            if (<?= $accuracy ?> >= 90) {
                setTimeout(() => {
                    createConfetti();
                }, 1000);
            }
        });

        function createConfetti() {
            const colors = ['#2EC4B6', '#4361EE', '#4CC9F0', '#FF9F1C', '#E71D36'];
            const confettiCount = 100;

            for (let i = 0; i < confettiCount; i++) {
                const confetti = document.createElement('div');
                confetti.style.cssText = `
                position: fixed;
                width: 10px;
                height: 10px;
                background: ${colors[Math.floor(Math.random() * colors.length)]};
                top: -20px;
                left: ${Math.random() * 100}vw;
                border-radius: ${Math.random() > 0.5 ? '50%' : '0'};
                opacity: 0.8;
                z-index: 9999;
                pointer-events: none;
            `;
                document.body.appendChild(confetti);

                const duration = Math.random() * 3 + 2;
                const horizontal = Math.random() * 200 - 100;

                confetti.animate([{
                        transform: 'translateY(0) translateX(0) rotate(0deg)',
                        opacity: 1
                    },
                    {
                        transform: `translateY(100vh) translateX(${horizontal}px) rotate(${Math.random() * 360}deg)`,
                        opacity: 0
                    }
                ], {
                    duration: duration * 1000,
                    easing: 'cubic-bezier(0.215, 0.61, 0.355, 1)'
                });

                setTimeout(() => confetti.remove(), duration * 1000);
            }
        }
    </script>
</body>

</html>