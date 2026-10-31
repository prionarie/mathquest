<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

$stmt = $conn->prepare("
    SELECT first_name, middle_name, last_name, position, profile_image, email, password_hash
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
    $_SESSION['teacher_name'] = $teacher_name;
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

$sections_query = $conn->prepare("
    SELECT section_id, section_name, school_year 
    FROM sections 
    WHERE teacher_id = ? AND archived = 0 
    ORDER BY school_year DESC, section_name
");
$sections_query->bind_param("i", $teacher_id);
$sections_query->execute();
$sections_result = $sections_query->get_result();
$sections = [];
while ($section = $sections_result->fetch_assoc()) {
    $sections[] = $section;
}

$quizzes_query = $conn->prepare("
    SELECT 
        q.quiz_id,
        q.quarter,
        q.week,
        q.quiz_type,
        q.score,
        q.date_created,
        GROUP_CONCAT(DISTINCT s.section_name ORDER BY s.section_name) as assigned_sections,
        COUNT(DISTINCT qs.section_id) as sections_count
    FROM quizzes q
    LEFT JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
    LEFT JOIN sections s ON qs.section_id = s.section_id
    WHERE q.teacher_id = ?
    GROUP BY q.quiz_id
    ORDER BY q.date_created DESC
");
$quizzes_query->bind_param("i", $teacher_id);
$quizzes_query->execute();
$quizzes_result = $quizzes_query->get_result();
$quizzes = [];
while ($quiz = $quizzes_result->fetch_assoc()) {
    $quizzes[] = $quiz;
}

foreach ($quizzes as &$quiz) {
    $quiz_id = $quiz['quiz_id'];

    $questions_query = $conn->prepare("
        SELECT COUNT(*) as question_count, SUM(q.points) as total_points
        FROM quiz_questions qq
        JOIN questions q ON qq.question_id = q.question_id
        WHERE qq.quiz_id = ?
    ");
    $questions_query->bind_param("i", $quiz_id);
    $questions_query->execute();
    $questions_result = $questions_query->get_result();
    $questions_data = $questions_result->fetch_assoc();
    
    $quiz['question_count'] = $questions_data['question_count'] ?? 0;
    $quiz['total_points'] = $questions_data['total_points'] ?? 0;

    $attempts_query = $conn->prepare("
        SELECT 
            COUNT(DISTINCT sq.student_id) as students_taken,
            AVG(sq.score) as average_score
        FROM student_quizzes sq
        WHERE sq.quiz_id = ?
    ");
    $attempts_query->bind_param("i", $quiz_id);
    $attempts_query->execute();
    $attempts_result = $attempts_query->get_result();
    $attempts_data = $attempts_result->fetch_assoc();
    
    $quiz['students_taken'] = $attempts_data['students_taken'] ?? 0;
    $quiz['average_score'] = $attempts_data['average_score'] ?? 0;
}
unset($quiz);

$question_stats_query = $conn->prepare("
    SELECT 
        COUNT(*) as total_questions
    FROM questions
");
$question_stats_query->execute();
$total_questions = $question_stats_query->get_result()->fetch_assoc()['total_questions'] ?? 0;

$question_usage_query = $conn->prepare("
    SELECT 
        q.difficulty,
        COUNT(DISTINCT qq.quiz_id) AS times_used_in_quizzes,
        COUNT(DISTINCT ql.quizlog_id) AS times_answered,
        COALESCE(SUM(ql.is_correct), 0) AS times_correct
    FROM questions q
    LEFT JOIN quiz_questions qq 
        ON q.question_id = qq.question_id
    LEFT JOIN quiz_logs ql
        ON q.question_id = ql.question_id
    GROUP BY q.difficulty
");
$question_usage_query->execute();
$usage_result = $question_usage_query->get_result();

$question_stats = [
    'Easy' => ['total' => 0, 'used' => 0, 'correct' => 0],
    'Medium' => ['total' => 0, 'used' => 0, 'correct' => 0],
    'Hard' => ['total' => 0, 'used' => 0, 'correct' => 0]
];

while ($stat = $usage_result->fetch_assoc()) {
    $difficulty = $stat['difficulty'];
    $question_stats[$difficulty]['used'] = $stat['times_used'] ?? 0;
    $question_stats[$difficulty]['correct'] = $stat['times_correct'] ?? 0;
}

$difficulty_query = $conn->prepare("
    SELECT difficulty, COUNT(*) as count
    FROM questions
    GROUP BY difficulty
");
$difficulty_query->execute();
$difficulty_result = $difficulty_query->get_result();
while ($row = $difficulty_result->fetch_assoc()) {
    $question_stats[$row['difficulty']]['total'] = $row['count'] ?? 0;
}

if (isset($_GET['delete_quiz'])) {
    $quiz_id = (int)$_GET['delete_quiz'];

    $check_stmt = $conn->prepare("SELECT quiz_id FROM quizzes WHERE quiz_id = ? AND teacher_id = ?");
    $check_stmt->bind_param("ii", $quiz_id, $teacher_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows === 1) {
        $delete_stmt = $conn->prepare("DELETE FROM quizzes WHERE quiz_id = ?");
        $delete_stmt->bind_param("i", $quiz_id);
        
        if ($delete_stmt->execute()) {
            header("Location: quizzes.php?success=Quiz deleted successfully");
            exit;
        } else {
            header("Location: quizzes.php?error=Error deleting quiz");
            exit;
        }
    } else {
        header("Location: quizzes.php?error=Quiz not found or unauthorized");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Quizzes | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/teacher/quizzes.css">
</head>
<body>
    <div class="container">
       <aside class="sidebar">
            <div class="logo" id="sidebarToggle">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i><span>Dashboard</span>
                </a>
                <a href="sections.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i><span>Sections & Students</span>
                </a>
                <a href="modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i><span>Learning Materials</span>
                </a>
                <a href="quizzes.php" class="nav-item active">
                    <i class="fas fa-clipboard-list nav-icon"></i><span>Quizzes</span>
                </a>
                <a href="reports.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i><span>Reports & Analytics</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <i class="fas fa-cog nav-icon"></i><span>Settings</span>
                </a>
                <a href="logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i><span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="settings.php">
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
                <h1>Quizzes</h1>
                <div class="header-info">
                    <div class="current-week-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Current: <?php echo $current_quarter; ?> Quarter, Week <?php echo $current_week; ?></span>
                    </div>
                    <a href="quizzes/create_quiz.php" class="create-quiz-btn">
                        <i class="fas fa-plus"></i>
                        Create New Quiz
                    </a>
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

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($quizzes); ?></div>
                    <div class="stat-label">Total Quizzes</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_questions; ?></div>
                    <div class="stat-label">Total Questions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value">
                        <?php 
                        $students_taken = array_sum(array_column($quizzes, 'students_taken'));
                        echo $students_taken ?: 0;
                        ?>
                    </div>
                    <div class="stat-label">Total Quiz Attempts</div>
                </div>
            </div>

            <div class="filters-section">
                <div class="filters-header">
                    <h3><i class="fas fa-database"></i> Question Bank Statistics</h3>
                </div>
                <div class="question-stats">
                    <?php foreach ($question_stats as $difficulty => $stats): ?>
                        <div class="question-stat-card <?php echo strtolower($difficulty); ?>">
                            <div class="stat-header">
                                <h4><?php echo $difficulty; ?> Questions</h4>
                                <span class="total-count"><?php echo $stats['total']; ?> total</span>
                            </div>
                            <div class="stat-details">
                                <div class="stat-detail">
                                    <i class="fas fa-check-circle"></i>
                                    <span><?php echo $stats['correct'] ?: 0; ?> correct answers</span>
                                </div>
                                <div class="stat-detail">
                                    <i class="fas fa-play-circle"></i>
                                    <span><?php echo $stats['used'] ?: 0; ?> times used</span>
                                </div>
                                <?php if ($stats['used'] > 0): ?>
                                <div class="stat-detail">
                                    <i class="fas fa-percentage"></i>
                                    <span><?php echo round(($stats['correct'] / $stats['used']) * 100, 1); ?>% success rate</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($current_quizzes)): ?>
                <div class="featured-section">
                    <div class="featured-label">
                        <i class="fas fa-star"></i>
                        Featured This Week
                    </div>
                    <div class="featured-quizzes">
                        <?php foreach ($current_quizzes as $quiz): ?>
                            <div class="quiz-card featured-quiz">
                                <div class="quiz-badge quiz-<?php echo strtolower($quiz['quiz_type']); ?>">
                                    <i class="fas fa-<?php echo getQuizTypeIcon($quiz['quiz_type']); ?>"></i>
                                    <?php echo $quiz['quiz_type']; ?>
                                </div>
                                <div class="quiz-header">
                                    <div class="quiz-quarter">
                                        <i class="fas fa-layer-group"></i>
                                        <?php echo $quiz['quarter']; ?> Quarter, Week <?php echo $quiz['week']; ?>
                                    </div>
                                    <h3 class="quiz-title">Quiz #<?php echo $quiz['quiz_id']; ?></h3>
                                </div>
                                <div class="quiz-meta">
                                    <div class="quiz-type">
                                        <i class="fas fa-tag"></i>
                                        <?php echo $quiz['quiz_type']; ?> Type
                                    </div>
                                    <div class="quiz-date">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M j, Y', strtotime($quiz['date_created'])); ?>
                                    </div>
                                </div>
                                <div class="quiz-info">
                                    <p><strong>Assigned to:</strong> <?php echo $quiz['assigned_sections'] ?: 'No sections assigned'; ?></p>
                                    <p><strong>Questions:</strong> <?php echo $quiz['question_count']; ?> questions</p>
                                    <p><strong>Total Points:</strong> <?php echo $quiz['total_points']; ?></p>
                                    <?php if ($quiz['students_taken'] > 0): ?>
                                        <p><strong>Average Score:</strong> <?php echo round($quiz['average_score'], 2); ?>%</p>
                                        <p><strong>Students Taken:</strong> <?php echo $quiz['students_taken']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="actions">
                                    <a href="quizzes/view_quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-small btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="quizzes/edit_quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-small btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="?delete_quiz=<?php echo $quiz['quiz_id']; ?>" 
                                       class="btn btn-small btn-delete"
                                       onclick="return confirm('Are you sure you want to delete this quiz?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="filters-section">
                <div class="filters-header">
                    <h3><i class="fas fa-filter"></i> Filter Quizzes</h3>
                </div>
                <div class="filter-controls">
                    <div class="filter-group">
                        <label for="quarter-filter"><i class="fas fa-layer-group"></i> Quarter</label>
                        <select id="quarter-filter" class="filter-select">
                            <option value="all">All Quarters</option>
                            <option value="1st">1st Quarter</option>
                            <option value="2nd">2nd Quarter</option>
                            <option value="3rd">3rd Quarter</option>
                            <option value="4th">4th Quarter</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="week-filter"><i class="fas fa-calendar-week"></i> Week</label>
                        <select id="week-filter" class="filter-select">
                            <option value="all">All Weeks</option>
                            <?php for ($i = 1; $i <= 52; $i++): ?>
                                <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="type-filter"><i class="fas fa-tag"></i> Quiz Type</label>
                        <select id="type-filter" class="filter-select">
                            <option value="all">All Types</option>
                            <option value="Mixed">Mixed</option>
                            <option value="Easy">Easy</option>
                            <option value="Medium">Medium</option>
                            <option value="Hard">Hard</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="quizzes-grid" id="quizzes-container">
                <?php if (empty($quizzes)): ?>
                    <div class="no-quizzes">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Quizzes Found</h3>
                        <p>Create your first quiz to get started!</p>
                        <a href="quizzes/create_quiz.php" class="create-quiz-btn" style="margin-top: 20px;">
                            <i class="fas fa-plus"></i>
                            Create First Quiz
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($quizzes as $quiz): ?>
                        <div class="quiz-card <?php echo ($quiz['quarter'] == $current_quarter && $quiz['week'] == $current_week) ? 'current' : ''; ?>"
                             data-quarter="<?php echo $quiz['quarter']; ?>"
                             data-week="<?php echo $quiz['week']; ?>"
                             data-type="<?php echo $quiz['quiz_type']; ?>">
                            <div class="quiz-badge quiz-<?php echo strtolower($quiz['quiz_type']); ?>">
                                <i class="fas fa-<?php echo getQuizTypeIcon($quiz['quiz_type']); ?>"></i>
                                <?php echo $quiz['quiz_type']; ?>
                            </div>
                            <div class="quiz-header">
                                <div class="quiz-quarter">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo $quiz['quarter']; ?> Quarter, Week <?php echo $quiz['week']; ?>
                                </div>
                                <h3 class="quiz-title">Quiz #<?php echo $quiz['quiz_id']; ?></h3>
                            </div>
                            <div class="quiz-meta">
                                <div class="quiz-type">
                                    <i class="fas fa-tag"></i>
                                    <?php echo $quiz['quiz_type']; ?> Type
                                </div>
                                <div class="quiz-date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($quiz['date_created'])); ?>
                                </div>
                            </div>
                            <div class="quiz-info">
                                <p><strong>Assigned to:</strong> <?php echo $quiz['sections_count'] ? $quiz['sections_count'] . ' section(s)' : 'No sections'; ?></p>
                                <p><strong>Questions:</strong> <?php echo $quiz['question_count']; ?> questions</p>
                                <p><strong>Total Points:</strong> <?php echo $quiz['total_points']; ?></p>
                                <?php if ($quiz['students_taken'] > 0): ?>
                                    <p><strong>Average Score:</strong> <?php echo round($quiz['average_score'], 2); ?>%</p>
                                    <p><strong>Students Taken:</strong> <?php echo $quiz['students_taken']; ?></p>
                                <?php else: ?>
                                    <p><em>No students have taken this quiz yet</em></p>
                                <?php endif; ?>
                            </div>
                            <div class="actions">
                                <a href="quizzes/view_quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-small btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="quizzes/edit_quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-small btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="?delete_quiz=<?php echo $quiz['quiz_id']; ?>" 
                                   class="btn btn-small btn-delete"
                                   onclick="return confirm('Are you sure you want to delete this quiz?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>


    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/quizzes.js"></script>
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
?>