<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("
    SELECT s.first_name, s.last_name, s.theta, s.current_points, 
           s.current_level, s.learner_category, s.assigned_set,
           sec.section_id, sec.section_name,
           s.current_level
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE s.student_id = ? AND s.archived = 0
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    header('Location: login.php');
    exit;
}

$student_name = trim($student['first_name'] . ' ' . $student['last_name']);
$_SESSION['student_name'] = $student_name;
$sidebar_initials = strtoupper(
    substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)
);

$settings_query = "SELECT * FROM academic_settings ORDER BY setting_id DESC LIMIT 1";
$settings_result = $conn->query($settings_query);
$settings = $settings_result->fetch_assoc();

$current_quarter = $settings['current_quarter'] ?? '1st';
$current_week    = $settings['current_week'] ?? 1;
$school_year     = $settings['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

$learner_category = $student['learner_category'] ?? 'Slow Learner';
switch(strtolower($learner_category)) {
    case 'slow learner':  $allowed_types = ["Easy","Mixed"]; break;
    case 'average learner': $allowed_types = ["Medium","Mixed"]; break;
    case 'fast learner': $allowed_types = ["Hard","Mixed"]; break;
    default: $allowed_types = ["Mixed"];
}
$allowed_types_placeholder = implode(',', array_fill(0, count($allowed_types), '?'));

$sql = "
    SELECT 
        q.quiz_id,
        q.quarter,
        q.week,
        q.quiz_type,
        q.date_created,
        qs.assigned_date,
        qs.due_date,
        qs.is_active,
        sq.score,
        sq.date_taken,
        COUNT(DISTINCT qq.question_id) as question_count,
        -- Check if student has any quiz attempts for this quiz
        (
            SELECT COUNT(*) 
            FROM quiz_attempts qa 
            WHERE qa.quiz_id = q.quiz_id 
            AND qa.student_id = ? 
            AND qa.is_completed = 1
        ) as completed_questions,
        -- Get the completion time from quiz_attempts
        (
            SELECT MAX(qa.timestamp) 
            FROM quiz_attempts qa 
            WHERE qa.quiz_id = q.quiz_id 
            AND qa.student_id = ? 
            AND qa.is_completed = 1
        ) as completed_timestamp
    FROM quizzes q
    JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
    LEFT JOIN quiz_questions qq ON q.quiz_id = qq.quiz_id
    LEFT JOIN student_quizzes sq ON q.quiz_id = sq.quiz_id AND sq.student_id = ?
    WHERE qs.section_id = ?
      AND qs.is_active = 1
      AND q.quiz_type IN ($allowed_types_placeholder)
    GROUP BY q.quiz_id, q.quarter, q.week, q.quiz_type, q.date_created, 
             qs.assigned_date, qs.due_date, qs.is_active, sq.score, sq.date_taken
    ORDER BY 
        CASE 
            WHEN q.quarter = ? AND q.week = ? THEN 0
            WHEN q.quarter = ? THEN 1
            ELSE 2
        END,
        qs.assigned_date DESC,
        q.date_created DESC
";

$type_params = array_merge([$student_id, $student_id, $student_id, $student['section_id']], $allowed_types, [$current_quarter, $current_week, $current_quarter]);
$param_types = str_repeat('i', 4) . str_repeat('s', count($allowed_types) + 3);

$stmt = $conn->prepare($sql);
$bind_names = [$param_types];
foreach ($type_params as $key => $value) {
    $bind_names[] = &$type_params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

$stmt->execute();
$quizzes_result = $stmt->get_result();
$quizzes = $quizzes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$featured_quizzes = [];
$assigned_quizzes = [];
$completed_quizzes = [];

foreach ($quizzes as $quiz) {
    $quiz['is_current'] = ($quiz['quarter'] == $current_quarter && $quiz['week'] == $current_week);
    $quiz['is_completed'] = !is_null($quiz['completed_timestamp']);
    $quiz['is_overdue'] = $quiz['due_date'] && strtotime($quiz['due_date']) < time() && !$quiz['is_completed'];
    $quiz['can_take'] = !$quiz['is_completed'] && (!$quiz['due_date'] || strtotime($quiz['due_date']) >= time());
    $quiz['has_ongoing_attempt'] = ($quiz['completed_questions'] > 0 && $quiz['completed_questions'] < $quiz['question_count']);

    if ($quiz['is_current']) {
        $featured_quizzes[] = $quiz;
    } elseif ($quiz['is_completed']) {
        $completed_quizzes[] = $quiz;
    } else {
        $assigned_quizzes[] = $quiz;
    }
}

$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT qa.quiz_id) as total_quizzes,
        IFNULL(SUM(sq.score) / SUM(qq_points.total_points) * 100, 0) as avg_score,
        SUM(CASE WHEN q.quiz_type = 'Easy' THEN 1 ELSE 0 END) as easy_quizzes,
        SUM(CASE WHEN q.quiz_type = 'Medium' THEN 1 ELSE 0 END) as medium_quizzes,
        SUM(CASE WHEN q.quiz_type = 'Hard' THEN 1 ELSE 0 END) as hard_quizzes,
        SUM(sq.score) as total_points
    FROM quiz_attempts qa
    JOIN quizzes q ON qa.quiz_id = q.quiz_id
    LEFT JOIN student_quizzes sq ON qa.quiz_id = sq.quiz_id AND sq.student_id = qa.student_id
    JOIN (
        SELECT qq.quiz_id, SUM(q.points) AS total_points
        FROM quiz_questions qq
        JOIN questions q ON qq.question_id = q.question_id
        GROUP BY qq.quiz_id
    ) AS qq_points ON qq_points.quiz_id = qa.quiz_id
    WHERE qa.student_id = ?
      AND qa.is_completed = 1
");
$stats_stmt->bind_param("i", $student_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

$ongoing_stmt = $conn->prepare("
    SELECT qa.quiz_id, COUNT(*) as ongoing_count
    FROM quiz_attempts qa
    WHERE qa.student_id = ? 
      AND qa.is_completed = 0
      AND qa.timestamp >= DATE_SUB(NOW(), INTERVAL 2 HOUR) -- Within last 2 hours
    GROUP BY qa.quiz_id
");
$ongoing_stmt->bind_param("i", $student_id);
$ongoing_stmt->execute();
$ongoing_attempts = [];
$result = $ongoing_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $ongoing_attempts[$row['quiz_id']] = $row['ongoing_count'];
}
$ongoing_stmt->close();

$badges_stmt = $conn->prepare("SELECT COUNT(*) as badge_count FROM student_badges WHERE student_id = ?");
$badges_stmt->bind_param("i", $student_id);
$badges_stmt->execute();
$badges_count = $badges_stmt->get_result()->fetch_assoc()['badge_count'];
$badges_stmt->close();

$points_to_next = 20 - ($student['current_points'] % 20);
if ($points_to_next == 20) $points_to_next = 0;
$level_progress = ($student['current_points'] % 20) * 5;

$total_earned = 0;
$total_possible = 0;

$stmt = $conn->prepare("
    SELECT sq.score, SUM(qs_question.points) AS total_quiz_points
    FROM student_quizzes sq
    JOIN quiz_questions qq ON sq.quiz_id = qq.quiz_id
    JOIN questions qs_question ON qq.question_id = qs_question.question_id
    WHERE sq.student_id = ?
      AND sq.score IS NOT NULL
    GROUP BY sq.quiz_id
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $total_earned += $row['score'];
    $total_possible += $row['total_quiz_points'];
}
$stmt->close();

$average_score = $total_possible > 0 
    ? round(($total_earned / $total_possible) * 100, 2) 
    : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Quizzes | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student/quizzes.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="student_dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i>
                    <span>Dashboard</span>
                </a>
                <a href="modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i>
                    <span>Learning Materials</span>
                </a>
                <a href="quizzes.php" class="nav-item active">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    <span>Quizzes</span>
                </a>
                <a href="profile.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span>My Profile</span>
                </a>
                <a href="logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="profile.php">
                    <div class="user-avatar" id="user-avatar">
                        <?= htmlspecialchars($sidebar_initials) ?>
                    </div>
                </a>
                <div class="user-info">
                    <h4 id="user-name"><?= htmlspecialchars($student_name) ?></h4>
                    <p id="user-type">Student</p>
                </div>
            </div>
        </aside>
        <main class="main-content">
            <div class="page-header">
                <h1>Quizzes</h1>
                <div class="header-info">
                    <div class="current-week-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Current: <?= htmlspecialchars($current_quarter) ?> Quarter, Week <?= htmlspecialchars($current_week) ?></span>
                    </div>
                    <div class="learner-category-badge <?= strtolower(str_replace(' ', '-', $student['learner_category'])) ?>">
                        <i class="fas fa-user-graduate"></i>
                        <span><?= htmlspecialchars($student['learner_category']) ?></span>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon total-quizzes">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-title">
                            <h3>Quizzes Completed</h3>
                            <div class="stat-value"><?= htmlspecialchars($stats['total_quizzes'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon avg-score">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-title">
                            <h3>Average Score</h3>
                            <div class="stat-value"><?= htmlspecialchars(round($stats['avg_score'] ?? 0, 1)) ?>%</div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon level">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-title">
                            <h3>Current Level</h3>
                            <div class="stat-value"><?= htmlspecialchars($student['current_level']) ?></div>
                        </div>
                    </div>
                    <div class="level-progress">
                        <div class="progress-info">
                            <span><?= htmlspecialchars($student['current_points'] % 20) ?>/20</span>
                            <span><?= $points_to_next ?> to next level</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill"
                                style="width: <?= (int)$level_progress ?>%;">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon badges">
                            <i class="fas fa-award"></i>
                        </div>
                        <div class="stat-title">
                            <h3>Badges Earned</h3>
                            <div class="stat-value"><?= htmlspecialchars($badges_count) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($featured_quizzes)): ?>
                <div class="featured-section">
                    <div class="section-header">
                        <h2><i class="fas fa-star"></i>This Week's Quizzes</h2>
                        <span class="section-count"><?= count($featured_quizzes) ?></span>
                    </div>
                    <div class="quizzes-grid">
                        <?php foreach ($featured_quizzes as $quiz): ?>
                            <div class="quiz-card featured">
                                <div class="quiz-header">
                                    <div class="quiz-status-badge featured-badge">
                                        <i class="fas fa-star"></i>
                                        Current Week
                                    </div>
                                    <div class="quiz-type-badge <?= strtolower($quiz['quiz_type']) ?>-type">
                                        <i class="fas fa-<?= getDifficultyIcon($quiz['quiz_type']) ?>"></i>
                                        <?= htmlspecialchars($quiz['quiz_type']) ?>
                                    </div>
                                </div>
                                <div class="quiz-content">
                                    <h3 class="quiz-title">
                                        <?= htmlspecialchars(getQuizTitle($quiz['quarter'], $quiz['week'], $quiz['quiz_type'])) ?>
                                    </h3>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($quiz['quarter']) ?> Quarter
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-week"></i>
                                            Week <?= htmlspecialchars($quiz['week']) ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-question-circle"></i>
                                            <?= htmlspecialchars($quiz['question_count']) ?> Questions
                                        </div>
                                    </div>
                                    <?php if ($quiz['due_date']): ?>
                                        <div class="quiz-due-date <?= $quiz['is_overdue'] ? 'overdue' : '' ?>">
                                            <i class="fas fa-clock"></i>
                                            Due: <?= date('M j, Y g:i A', strtotime($quiz['due_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="quiz-actions">
                                    <?php if ($quiz['is_completed']): ?>
                                        <button class="btn btn-success btn-full" onclick="reviewQuiz(<?= $quiz['quiz_id'] ?>)">
                                            <i class="fas fa-chart-bar"></i>
                                            Review Results
                                        </button>
                                    <?php elseif ($quiz['can_take']): ?>
                                        <button class="btn btn-primary btn-full" onclick="startQuiz(<?= $quiz['quiz_id'] ?>)">
                                            <i class="fas fa-play"></i>
                                            Start Quiz
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-disabled btn-full" disabled>
                                            <i class="fas fa-lock"></i>
                                            Not Available
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($assigned_quizzes)): ?>
                <div class="featured-section">
                    <div class="section-header">
                        <h2><i class="fas fa-tasks"></i> Assigned Quizzes</h2>
                        <span class="section-count"><?= count($assigned_quizzes) ?></span>
                    </div>
                    <div class="quizzes-grid">
                        <?php foreach ($assigned_quizzes as $quiz): ?>
                            <div class="quiz-card">
                                <div class="quiz-header">
                                    <?php if ($quiz['is_overdue']): ?>
                                        <div class="quiz-status-badge overdue-badge">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            Overdue
                                        </div>
                                    <?php else: ?>
                                        <div class="quiz-status-badge">
                                            <i class="fas fa-calendar-plus"></i>
                                            Assigned
                                        </div>
                                    <?php endif; ?>
                                    <div class="quiz-type-badge <?= strtolower($quiz['quiz_type']) ?>-type">
                                        <i class="fas fa-<?= getDifficultyIcon($quiz['quiz_type']) ?>"></i>
                                        <?= htmlspecialchars($quiz['quiz_type']) ?>
                                    </div>
                                </div>
                                <div class="quiz-content">
                                    <h3 class="quiz-title">
                                        <?= htmlspecialchars(getQuizTitle($quiz['quarter'], $quiz['week'], $quiz['quiz_type'])) ?>
                                    </h3>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($quiz['quarter']) ?> Quarter
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-week"></i>
                                            Week <?= htmlspecialchars($quiz['week']) ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-question-circle"></i>
                                            <?= htmlspecialchars($quiz['question_count']) ?> Questions
                                        </div>
                                    </div>
                                    <?php if ($quiz['due_date']): ?>
                                        <div class="quiz-due-date <?= $quiz['is_overdue'] ? 'overdue' : '' ?>">
                                            <i class="fas fa-clock"></i>
                                            Due: <?= date('M j, Y g:i A', strtotime($quiz['due_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-plus"></i>
                                            Assigned: <?= date('M j, Y', strtotime($quiz['assigned_date'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="quiz-actions">
                                    <?php if ($quiz['can_take']): ?>
                                        <button class="btn btn-primary btn-full" onclick="startQuiz(<?= $quiz['quiz_id'] ?>)">
                                            <i class="fas fa-play"></i>
                                            Start Quiz
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-disabled btn-full" disabled>
                                            <i class="fas fa-lock"></i>
                                            Not Available
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($completed_quizzes)): ?>
                <div class="featured-section">
                    <div class="section-header">
                        <h2><i class="fas fa-check-circle"></i> Completed Quizzes</h2>
                        <span class="section-count"><?= count($completed_quizzes) ?></span>
                    </div>
                    <div class="quizzes-grid">
                        <?php foreach ($completed_quizzes as $quiz): ?>
                            <div class="quiz-card completed">
                                <div class="quiz-header">
                                    <div class="quiz-status-badge completed-badge">
                                        <i class="fas fa-check-circle"></i>
                                        Completed
                                    </div>
                                    <div class="quiz-type-badge <?= strtolower($quiz['quiz_type']) ?>-type">
                                        <i class="fas fa-<?= getDifficultyIcon($quiz['quiz_type']) ?>"></i>
                                        <?= htmlspecialchars($quiz['quiz_type']) ?>
                                    </div>
                                </div>
                                <div class="quiz-content">
                                    <h3 class="quiz-title">
                                        <?= htmlspecialchars(getQuizTitle($quiz['quarter'], $quiz['week'], $quiz['quiz_type'])) ?>
                                    </h3>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($quiz['quarter']) ?> Quarter
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-week"></i>
                                            Week <?= htmlspecialchars($quiz['week']) ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-check"></i>
                                            Completed: <?= date('M j, Y', strtotime($quiz['completed_timestamp'])) ?>
                                        </div>
                                    </div>
                                    <div class="score-display">
                                        <?= htmlspecialchars($quiz['score']) ?>%
                                    </div>
                                    <div class="quiz-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-history"></i>
                                            <?= time_ago($quiz['completed_timestamp']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="quiz-actions">
                                    <button class="btn btn-success btn-full" onclick="reviewQuiz(<?= $quiz['quiz_id'] ?>)">
                                        <i class="fas fa-chart-bar"></i>
                                        Review Results
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($featured_quizzes) && empty($assigned_quizzes) && empty($completed_quizzes)): ?>
                <div class="no-quizzes">
                    <div class="no-quizzes-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3>No Quizzes Available</h3>
                    <p>Your teacher hasn't assigned any quizzes yet. Check back later!</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/student/sidebar.js"></script>
    <script src="../../assets/js/student/quizzes.js"></script>
    <script>
        const STUDENT_ID = <?= $student_id ?>;
        const LEARNER_CATEGORY = '<?= $student['learner_category'] ?>';
        
        function startQuiz(quizId) {
            fetch('quiz_logic/check_quiz_status.php?quiz_id=' + quizId)
                .then(response => response.json())
                .then(data => {
                    if (data.ongoing) {
                        if (confirm('You have an ongoing attempt for this quiz. Would you like to continue?')) {
                            window.location.href = `quiz_pages/quiz_taking.php?quiz_id=${quizId}&attempt_id=${data.attempt_id}`;
                        }
                    } else {
                        window.location.href = `quiz_pages/start_quiz.php?quiz_id=${quizId}`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    window.location.href = `quiz_pages/start_quiz.php?quiz_id=${quizId}`;
                });
        }
        
        function reviewQuiz(quizId) {
            window.location.href = `quiz_pages/review_quiz.php?quiz_id=${quizId}`;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const categoryBadge = document.querySelector('.learner-category-badge');
            if (categoryBadge) {
                const category = LEARNER_CATEGORY.toLowerCase();
                let color;
                switch(category) {
                    case 'slow learner': color = '#E71D36'; break;
                    case 'average learner': color = '#3A0CA3'; break;
                    case 'fast learner': color = '#2EC4B6'; break;
                    default: color = '#6c757d';
                }
                categoryBadge.style.borderColor = color;
            }

            document.getElementById('logout-btn').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = this.getAttribute('href');
            });

            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                if (item.href === window.location.href) {
                    item.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>

<?php
function getQuizTitle($quarter, $week, $type) {
    $typeMap = [
        'Easy' => 'Basic Level',
        'Medium' => 'Intermediate Level', 
        'Hard' => 'Advanced Level'
    ];
    return "{$quarter} Quarter - Week {$week} (" . ($typeMap[$type] ?? $type) . ")";
}

function getDifficultyIcon($type) {
    switch (strtolower($type)) {
        case 'easy': return 'seedling';
        case 'medium': return 'tree';
        case 'hard': return 'mountain';
        default: return 'question-circle';
    }
}

function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>