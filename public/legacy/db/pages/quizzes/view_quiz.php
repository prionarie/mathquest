<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: ../quizzes.php?error=Quiz ID not specified');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$quiz_id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT first_name, middle_name, last_name, position, profile_image
    FROM teachers
    WHERE teacher_id = ?
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    $first_name  = $row['first_name'];
    $middle_name = $row['middle_name'];
    $last_name   = $row['last_name'];
    $teacher_name = trim($first_name . ' ' . $last_name);
    $teacher_position = $row['position'] ?: 'Teacher';
    $teacher_profile_image = $row['profile_image'];
}
$stmt->close();

$sidebar_initials = strtoupper(
    substr($first_name, 0, 1) . substr($last_name, 0, 1)
);

function getTeacherAvatar($profile_image) {
    if (!$profile_image) return null;
    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $profile_image;
    return file_exists($absolutePath) ? $profile_image : null;
}
$teacher_avatar = getTeacherAvatar($teacher_profile_image);

$settings_query = "SELECT * FROM academic_settings ORDER BY setting_id DESC LIMIT 1";
$settings_result = $conn->query($settings_query);
$settings = $settings_result->fetch_assoc();

if ($settings) {
    $current_quarter = $settings['current_quarter'];
    $current_week = $settings['current_week'];
} else {
    $current_quarter = '1st';
    $current_week = 1;
}

$quiz_query = $conn->prepare("
    SELECT 
        q.quiz_id,
        q.quarter,
        q.week,
        q.quiz_type,
        q.score as total_points,
        q.date_created,
        t.teacher_id,
        CONCAT(t.first_name, ' ', t.last_name) as created_by
    FROM quizzes q
    JOIN teachers t ON q.teacher_id = t.teacher_id
    WHERE q.quiz_id = ? AND q.teacher_id = ?
");
$quiz_query->bind_param("ii", $quiz_id, $teacher_id);
$quiz_query->execute();
$quiz_result = $quiz_query->get_result();

if ($quiz_result->num_rows === 0) {
    header('Location: ../quizzes.php?error=Quiz not found or unauthorized');
    exit;
}

$quiz = $quiz_result->fetch_assoc();

$sections_query = $conn->prepare("
    SELECT 
        s.section_id,
        s.section_name,
        s.school_year,
        qs.assigned_date,
        qs.due_date,
        qs.is_active
    FROM quiz_sections qs
    JOIN sections s ON qs.section_id = s.section_id
    WHERE qs.quiz_id = ?
    ORDER BY s.school_year DESC, s.section_name
");
$sections_query->bind_param("i", $quiz_id);
$sections_query->execute();
$sections_result = $sections_query->get_result();
$assigned_sections = [];
$total_students = 0;

while ($section = $sections_result->fetch_assoc()) {
    $student_count_query = $conn->prepare("
        SELECT COUNT(*) as student_count
        FROM students
        WHERE section_id = ? AND archived = 0
    ");
    $student_count_query->bind_param("i", $section['section_id']);
    $student_count_query->execute();
    $student_count_result = $student_count_query->get_result();
    $student_count = $student_count_result->fetch_assoc()['student_count'] ?? 0;
    
    $section['student_count'] = $student_count;
    $total_students += $student_count;
    $assigned_sections[] = $section;
}

$questions_query = $conn->prepare("
    SELECT 
        q.question_id,
        q.content,
        q.content_image,
        q.option_a,
        q.option_a_image,
        q.option_b,
        q.option_b_image,
        q.option_c,
        q.option_c_image,
        q.option_d,
        q.option_d_image,
        q.correct_option,
        q.difficulty,
        q.points,
        q.hint
    FROM quiz_questions qq
    JOIN questions q ON qq.question_id = q.question_id
    WHERE qq.quiz_id = ?
    ORDER BY qq.id
");
$questions_query->bind_param("i", $quiz_id);
$questions_query->execute();
$questions_result = $questions_query->get_result();
$questions = [];
$total_points = 0;

while ($question = $questions_result->fetch_assoc()) {
    $questions[] = $question;
    $total_points += $question['points'];
}

$attempts_query = $conn->prepare("
    SELECT 
        sq.student_quiz_id,
        sq.student_id,
        sq.score,
        sq.date_taken,
        s.first_name,
        s.last_name,
        s.lrn,
        sec.section_name
    FROM student_quizzes sq
    JOIN students s ON sq.student_id = s.student_id
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE sq.quiz_id = ?
    ORDER BY sq.date_taken DESC
");
$attempts_query->bind_param("i", $quiz_id);
$attempts_query->execute();
$attempts_result = $attempts_query->get_result();
$attempts = [];
$total_attempts = 0;
$average_score = 0;
$total_score_sum = 0;

while ($attempt = $attempts_result->fetch_assoc()) {
    $attempts[] = $attempt;
    $total_attempts++;
    $total_score_sum += $attempt['score'];
}

if ($total_attempts > 0) {
    $average_score = round($total_score_sum / $total_attempts, 2);
}

$question_stats_query = $conn->prepare("
    SELECT 
        ql.question_id,
        COUNT(*) as total_attempts,
        SUM(CASE WHEN ql.is_correct = 1 THEN 1 ELSE 0 END) as correct_attempts,
        ROUND((SUM(CASE WHEN ql.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as accuracy_rate
    FROM quiz_logs ql
    WHERE ql.quiz_id = ?
    GROUP BY ql.question_id
");
$question_stats_query->bind_param("i", $quiz_id);
$question_stats_query->execute();
$question_stats_result = $question_stats_query->get_result();
$question_stats = [];

while ($stat = $question_stats_result->fetch_assoc()) {
    $question_stats[$stat['question_id']] = $stat;
}

$students_taken_query = $conn->prepare("
    SELECT COUNT(DISTINCT student_id) as students_taken
    FROM student_quizzes
    WHERE quiz_id = ?
");
$students_taken_query->bind_param("i", $quiz_id);
$students_taken_query->execute();
$students_taken_result = $students_taken_query->get_result();
$students_taken = $students_taken_result->fetch_assoc()['students_taken'] ?? 0;

$score_range_query = $conn->prepare("
    SELECT 
        MAX(score) as highest_score,
        MIN(score) as lowest_score
    FROM student_quizzes
    WHERE quiz_id = ?
");
$score_range_query->bind_param("i", $quiz_id);
$score_range_query->execute();
$score_range_result = $score_range_query->get_result();
$score_range = $score_range_result->fetch_assoc();

$section_performance_query = $conn->prepare("
    SELECT 
        sec.section_name,
        COUNT(DISTINCT sq.student_id) as students_taken,
        AVG(sq.score) as average_score,
        MAX(sq.score) as highest_score,
        MIN(sq.score) as lowest_score
    FROM student_quizzes sq
    JOIN students s ON sq.student_id = s.student_id
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE sq.quiz_id = ?
    GROUP BY sec.section_id
    ORDER BY sec.section_name
");
$section_performance_query->bind_param("i", $quiz_id);
$section_performance_query->execute();
$section_performance_result = $section_performance_query->get_result();
$section_performance = [];

while ($performance = $section_performance_result->fetch_assoc()) {
    $section_performance[] = $performance;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>View Quiz | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/teacher/quizzes.css">
    <link rel="stylesheet" href="../../../assets/css/teacher/view_quiz.css">
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo" id="sidebarToggle">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="../dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i><span>Dashboard</span>
                </a>
                <a href="../sections.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i><span>Sections & Students</span>
                </a>
                <a href="../modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i><span>Learning Materials</span>
                </a>
                <a href="../quizzes.php" class="nav-item active">
                    <i class="fas fa-clipboard-list nav-icon"></i><span>Quizzes</span>
                </a>
                <a href="../reports.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i><span>Reports & Analytics</span>
                </a>
                <a href="../settings.php" class="nav-item">
                    <i class="fas fa-cog nav-icon"></i><span>Settings</span>
                </a>
                <a href="../logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i><span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="../settings.php">
                    <div class="user-avatar" id="user-avatar">
                        <?php if ($teacher_avatar): ?>
                            <img src="<?= htmlspecialchars($teacher_avatar) ?>" alt="Avatar" style="width:100%; height:100%; border-radius:50%;">
                        <?php else: ?>
                            <?= $sidebar_initials ?>
                        <?php endif; ?>
                    </div>
                </a>
                <div class="user-info">
                    <h4 id="user-name"><?= htmlspecialchars($teacher_name) ?></h4>
                    <p id="user-type"><?= htmlspecialchars($teacher_position) ?></p>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <div class="header-left">
                    <a href="../quizzes.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Quizzes
                    </a>
                    <h1>Quiz Details</h1>
                </div>
                <div class="header-right">
                    <div class="quiz-header-badge">
                        <i class="fas fa-layer-group"></i>
                        <span><?= $quiz['quarter'] ?> Quarter, Week <?= $quiz['week'] ?></span>
                    </div>
                    <div class="header-actions">
                        <a href="edit_quiz.php?id=<?= $quiz_id ?>" class="btn btn-edit">
                            <i class="fas fa-edit"></i> Edit Quiz
                        </a>
                        <a href="?id=<?= $quiz_id ?>&print=true" target="_blank" class="btn btn-print">
                            <i class="fas fa-print"></i> Print
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>


            <div class="quiz-overview">
                <div class="overview-card main-card">
                    <div class="overview-header">
                        <div class="quiz-type-badge badge-<?= strtolower($quiz['quiz_type']) ?>">
                            <i class="fas fa-<?= getQuizTypeIcon($quiz['quiz_type']) ?>"></i>
                            <?= $quiz['quiz_type'] ?> Quiz
                        </div>
                        <div class="quiz-date">
                            <i class="fas fa-calendar"></i>
                            Created: <?= date('F j, Y', strtotime($quiz['date_created'])) ?>
                        </div>
                    </div>
                    <div class="overview-body">
                        <h2>Quiz #<?= $quiz_id ?></h2>
                        <p class="quiz-description">Created by <?= $quiz['created_by'] ?></p>
                        
                        <div class="overview-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?= count($questions) ?></div>
                                <div class="stat-label">Questions</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= $total_points ?></div>
                                <div class="stat-label">Total Points</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= $total_students ?></div>
                                <div class="stat-label">Total Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= $students_taken ?></div>
                                <div class="stat-label">Taken</div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($total_attempts > 0): ?>
                <div class="performance-card">
                    <div class="performance-header">
                        <h3><i class="fas fa-chart-line"></i> Performance Overview</h3>
                    </div>
                    <div class="performance-body">
                        <div class="performance-stats">
                            <div class="performance-item">
                                <div class="performance-value"><?= $average_score ?>%</div>
                                <div class="performance-label">Average Score</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-value"><?= $score_range['highest_score'] ?? 0 ?>%</div>
                                <div class="performance-label">Highest Score</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-value"><?= $score_range['lowest_score'] ?? 0 ?>%</div>
                                <div class="performance-label">Lowest Score</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-value"><?= $total_attempts ?></div>
                                <div class="performance-label">Total Attempts</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab-btn active" data-tab="questions">
                        <i class="fas fa-question-circle"></i> Questions
                    </button>
                    <button class="tab-btn" data-tab="sections">
                        <i class="fas fa-users"></i> Assigned Sections (<?= count($assigned_sections) ?>)
                    </button>
                    <button class="tab-btn" data-tab="attempts">
                        <i class="fas fa-history"></i> Student Attempts (<?= $total_attempts ?>)
                    </button>
                    <button class="tab-btn" data-tab="analytics">
                        <i class="fas fa-chart-bar"></i> Analytics
                    </button>
                </div>

                <div class="tab-content active" id="questions-tab">
                    <div class="tab-header">
                        <h3>Quiz Questions</h3>
                        <div class="tab-actions">
                            <button class="btn btn-small" onclick="expandAllQuestions()">
                                <i class="fas fa-expand"></i> Expand All
                            </button>
                            <button class="btn btn-small" onclick="collapseAllQuestions()">
                                <i class="fas fa-compress"></i> Collapse All
                            </button>
                        </div>
                    </div>
                    
                    <?php if (empty($questions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-question-circle"></i>
                            <h4>No Questions Found</h4>
                            <p>This quiz doesn't have any questions yet.</p>
                            <a href="edit_quiz.php?id=<?= $quiz_id ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Questions
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="questions-list">
                            <?php foreach ($questions as $index => $question): ?>
                                <?php 
                                $question_num = $index + 1;
                                $stats = $question_stats[$question['question_id']] ?? null;
                                $accuracy = $stats ? $stats['accuracy_rate'] : 0;
                                ?>
                                <div class="question-item">
                                    <div class="question-header" onclick="toggleQuestion(this)">
                                        <div class="question-title">
                                            <span class="question-number">Q<?= $question_num ?></span>
                                            <h4><?= htmlspecialchars($question['content']) ?></h4>
                                        </div>
                                        <div class="question-meta">
                                            <span class="difficulty-badge badge-<?= strtolower($question['difficulty']) ?>">
                                                <?= $question['difficulty'] ?> (<?= $question['points'] ?> pts)
                                            </span>
                                            <?php if ($stats): ?>
                                            <span class="accuracy-badge" style="background: <?= getAccuracyColor($accuracy) ?>">
                                                <?= $accuracy ?>% Accuracy
                                            </span>
                                            <?php endif; ?>
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    
                                    <div class="question-details">
                                        <?php if ($question['content_image']): ?>
                                        <div class="question-image">
                                            <p><strong>Question Image:</strong></p>
                                            <img src="<?= htmlspecialchars($question['content_image']) ?>" 
                                                 alt="Question image"
                                                 style="max-width: 300px; border-radius: 5px;">
                                        </div>
                                        <?php endif; ?>

                                        <div class="options-grid">
                                            <?php 
                                            $options = [
                                                'A' => $question['option_a'],
                                                'B' => $question['option_b'],
                                                'C' => $question['option_c'],
                                                'D' => $question['option_d']
                                            ];
                                            $option_images = [
                                                'A' => $question['option_a_image'],
                                                'B' => $question['option_b_image'],
                                                'C' => $question['option_c_image'],
                                                'D' => $question['option_d_image']
                                            ];
                                            
                                            foreach ($options as $letter => $option):
                                                if (!empty($option)):
                                            ?>
                                            <div class="option-item <?= $letter == $question['correct_option'] ? 'correct-option' : '' ?>">
                                                <div class="option-header">
                                                    <span class="option-letter"><?= $letter ?></span>
                                                    <?php if ($letter == $question['correct_option']): ?>
                                                    <span class="correct-indicator">
                                                        <i class="fas fa-check-circle"></i> Correct Answer
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="option-content">
                                                    <?= htmlspecialchars($option) ?>
                                                    <?php if ($option_images[$letter]): ?>
                                                    <div class="option-image">
                                                        <img src="<?= htmlspecialchars($option_images[$letter]) ?>" 
                                                             alt="Option <?= $letter ?> image"
                                                             style="max-width: 150px; margin-top: 5px;">
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>

                                        <?php if (!empty($question['hint'])): ?>
                                        <div class="question-hint">
                                            <p><strong><i class="fas fa-lightbulb"></i> Hint:</strong></p>
                                            <p><?= htmlspecialchars($question['hint']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($stats): ?>
                                        <div class="question-stats">
                                            <div class="stat-row">
                                                <span><i class="fas fa-check-circle"></i> Correct: <?= $stats['correct_attempts'] ?></span>
                                                <span><i class="fas fa-times-circle"></i> Incorrect: <?= $stats['total_attempts'] - $stats['correct_attempts'] ?></span>
                                                <span><i class="fas fa-chart-bar"></i> Accuracy: <?= $accuracy ?>%</span>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-content" id="sections-tab">
                    <div class="tab-header">
                        <h3>Assigned Sections</h3>
                    </div>
                    
                    <?php if (empty($assigned_sections)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h4>No Sections Assigned</h4>
                            <p>This quiz hasn't been assigned to any sections yet.</p>
                            <a href="edit_quiz.php?id=<?= $quiz_id ?>" class="btn btn-primary">
                                <i class="fas fa-link"></i> Assign to Sections
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="sections-grid">
                            <?php foreach ($assigned_sections as $section): ?>
                                <div class="section-card">
                                    <div class="section-header">
                                        <h4><?= htmlspecialchars($section['section_name']) ?></h4>
                                        <span class="section-status <?= $section['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $section['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>
                                    <div class="section-body">
                                        <p><i class="fas fa-calendar-alt"></i> School Year: <?= $section['school_year'] ?></p>
                                        <p><i class="fas fa-user-graduate"></i> Students: <?= $section['student_count'] ?></p>
                                        <p><i class="fas fa-clock"></i> Assigned: <?= date('M j, Y', strtotime($section['assigned_date'])) ?></p>
                                        <?php if ($section['due_date']): ?>
                                        <p><i class="fas fa-hourglass-end"></i> Due: <?= date('M j, Y H:i', strtotime($section['due_date'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="section-actions">
                                        <?php
                                        $section_attempts_query = $conn->prepare("
                                            SELECT COUNT(DISTINCT s.student_id) as taken_count
                                            FROM students s
                                            JOIN student_quizzes sq ON s.student_id = sq.student_id
                                            WHERE s.section_id = ? AND sq.quiz_id = ?
                                        ");
                                        $section_attempts_query->bind_param("ii", $section['section_id'], $quiz_id);
                                        $section_attempts_query->execute();
                                        $section_attempts_result = $section_attempts_query->get_result();
                                        $taken_count = $section_attempts_result->fetch_assoc()['taken_count'] ?? 0;
                                        ?>
                                        <span class="completion-status">
                                            <?= $taken_count ?>/<?= $section['student_count'] ?> students completed
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-content" id="attempts-tab">
                    <div class="tab-header">
                        <h3>Student Attempts</h3>
                        <?php if (!empty($attempts)): ?>
                        <div class="tab-actions">
                            <button class="btn btn-small" onclick="exportAttemptsData()">
                                <i class="fas fa-download"></i> Export CSV
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($attempts)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h4>No Attempts Yet</h4>
                            <p>No students have taken this quiz yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="attempts-table-container">
                            <table class="attempts-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>LRN</th>
                                        <th>Section</th>
                                        <th>Score</th>
                                        <th>Date Taken</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attempts as $attempt): ?>
                                    <tr>
                                        <td>
                                            <div class="student-info">
                                                <strong><?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?></strong>
                                            </div>
                                        </td>
                                        <td><?= $attempt['lrn'] ?></td>
                                        <td><?= htmlspecialchars($attempt['section_name']) ?></td>
                                        <td>
                                            <div class="score-display">
                                                <span class="score-value <?= getScoreClass($attempt['score']) ?>">
                                                    <?= $attempt['score'] ?>%
                                                </span>
                                                <div class="score-bar">
                                                    <div class="score-fill" style="width: <?= $attempt['score'] ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= date('M j, Y H:i', strtotime($attempt['date_taken'])) ?></td>
                                        <td>
                                            <button class="btn-icon" onclick="viewAttemptDetails(<?= $attempt['student_quiz_id'] ?>)"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="../reports.php?student=<?= $attempt['student_id'] ?>&quiz=<?= $quiz_id ?>"
                                               class="btn-icon"
                                               title="View Full Report">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="attempt-stats">
                            <div class="stat-card">
                                <div class="stat-value"><?= $total_attempts ?></div>
                                <div class="stat-label">Total Attempts</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $average_score ?>%</div>
                                <div class="stat-label">Average Score</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= count(array_filter($attempts, function($a) { return $a['score'] >= 70; })) ?></div>
                                <div class="stat-label">Passing (≥70%)</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= count(array_filter($attempts, function($a) { return $a['score'] < 70; })) ?></div>
                                <div class="stat-label">Needs Improvement</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-content" id="analytics-tab">
                    <div class="tab-header">
                        <h3>Quiz Analytics</h3>
                    </div>
                    
                    <?php if ($total_attempts == 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h4>No Analytics Available</h4>
                            <p>Analytics will appear after students take the quiz.</p>
                        </div>
                    <?php else: ?>
                        <div class="analytics-grid">
                            <div class="analytics-card">
                                <h4><i class="fas fa-chart-pie"></i> Score Distribution</h4>
                                <div class="score-distribution">
                                    <?php
                                    $score_ranges = [
                                        '90-100' => 0,
                                        '80-89' => 0,
                                        '70-79' => 0,
                                        '60-69' => 0,
                                        '0-59' => 0
                                    ];
                                    
                                    foreach ($attempts as $attempt) {
                                        $score = $attempt['score'];
                                        if ($score >= 90) $score_ranges['90-100']++;
                                        elseif ($score >= 80) $score_ranges['80-89']++;
                                        elseif ($score >= 70) $score_ranges['70-79']++;
                                        elseif ($score >= 60) $score_ranges['60-69']++;
                                        else $score_ranges['0-59']++;
                                    }
                                    ?>
                                    <?php foreach ($score_ranges as $range => $count): ?>
                                        <?php if ($count > 0): ?>
                                        <div class="distribution-item">
                                            <div class="distribution-header">
                                                <span><?= $range ?></span>
                                                <span><?= $count ?> students</span>
                                            </div>
                                            <div class="distribution-bar">
                                                <div class="bar-fill" 
                                                     style="width: <?= ($count / $total_attempts) * 100 ?>%"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if (!empty($section_performance)): ?>
                            <div class="analytics-card">
                                <h4><i class="fas fa-users"></i> Section Performance</h4>
                                <div class="section-performance">
                                    <?php foreach ($section_performance as $performance): ?>
                                    <div class="performance-row">
                                        <div class="section-name">
                                            <strong><?= $performance['section_name'] ?></strong>
                                            <small><?= $performance['students_taken'] ?> students</small>
                                        </div>
                                        <div class="section-score">
                                            <span class="score-value"><?= round($performance['average_score'], 1) ?>%</span>
                                            <div class="score-bar-small">
                                                <div class="score-fill" 
                                                     style="width: <?= $performance['average_score'] ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="analytics-card">
                                <h4><i class="fas fa-signal"></i> Difficulty Analysis</h4>
                                <div class="difficulty-analysis">
                                    <?php
                                    $difficulty_stats = [
                                        'Easy' => ['count' => 0, 'accuracy' => 0],
                                        'Medium' => ['count' => 0, 'accuracy' => 0],
                                        'Hard' => ['count' => 0, 'accuracy' => 0]
                                    ];
                                    
                                    foreach ($questions as $question) {
                                        $difficulty = $question['difficulty'];
                                        $stats = $question_stats[$question['question_id']] ?? null;
                                        $difficulty_stats[$difficulty]['count']++;
                                        if ($stats) {
                                            $difficulty_stats[$difficulty]['accuracy'] += $stats['accuracy_rate'];
                                        }
                                    }
                                    ?>
                                    <?php foreach ($difficulty_stats as $difficulty => $stat): ?>
                                        <?php if ($stat['count'] > 0): ?>
                                        <div class="difficulty-item">
                                            <div class="difficulty-header">
                                                <span class="difficulty-badge-small badge-<?= strtolower($difficulty) ?>">
                                                    <?= $difficulty ?>
                                                </span>
                                                <span><?= $stat['count'] ?> questions</span>
                                            </div>
                                            <?php if ($stat['accuracy'] > 0): ?>
                                            <div class="difficulty-stats">
                                                <span>Avg Accuracy: <?= round($stat['accuracy'] / $stat['count'], 1) ?>%</span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="analytics-card">
                                <h4><i class="fas fa-clock"></i> Recent Activity</h4>
                                <div class="recent-activity">
                                    <?php
                                    $recent_attempts = array_slice($attempts, 0, 5);
                                    ?>
                                    <?php foreach ($recent_attempts as $attempt): ?>
                                    <div class="activity-item">
                                        <div class="activity-info">
                                            <strong><?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?></strong>
                                            <small><?= $attempt['section_name'] ?></small>
                                        </div>
                                        <div class="activity-details">
                                            <span class="score-badge <?= getScoreClass($attempt['score']) ?>">
                                                <?= $attempt['score'] ?>%
                                            </span>
                                            <small><?= date('M j, H:i', strtotime($attempt['date_taken'])) ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <div class="modal" id="attemptModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Attempt Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="attemptDetails">
            </div>
        </div>
    </div>

    <script src="../../../assets/js/teacher/sidebar.js"></script>
    <script src="../../../assets/js/teacher/view_quiz.js"></script>
</body>
</html>

<?php
function getQuizTypeIcon($type) {
    switch ($type) {
        case 'Mixed': return 'random';
        case 'Easy': return 'seedling';
        case 'Medium': return 'mountain';
        case 'Hard': return 'fire';
        default: return 'question';
    }
}

function getAccuracyColor($accuracy) {
    if ($accuracy >= 80) return '#2EC4B6';
    if ($accuracy >= 60) return '#FF9F1C';
    return '#E71D36';
}

function getScoreClass($score) {
    if ($score >= 90) return 'score-excellent';
    if ($score >= 80) return 'score-good';
    if ($score >= 70) return 'score-average';
    return 'score-poor';
}
?>