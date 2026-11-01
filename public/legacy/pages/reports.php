<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: logout.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// Teacher info
$stmt = $conn->prepare("
    SELECT first_name, middle_name, last_name, position, profile_image, email
    FROM teachers
    WHERE teacher_id = ?
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

$first_name = $teacher['first_name'] ?? '';
$last_name = $teacher['last_name'] ?? '';
$teacher_name = trim($first_name . ' ' . $last_name);
$teacher_position = $teacher['position'] ?: 'Teacher';
$_SESSION['teacher_name'] = $teacher_name;
$sidebar_initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
$teacher_avatar = $teacher['profile_image'] ?? null;

// Academic settings
$settings = $conn->query("SELECT * FROM academic_settings ORDER BY setting_id DESC LIMIT 1")->fetch_assoc();
$current_quarter = $settings['current_quarter'] ?? '1st';
$current_week = $settings['current_week'] ?? 1;

// Sections
$sections_stmt = $conn->prepare("
    SELECT section_id, section_name, school_year
    FROM sections
    WHERE teacher_id = ? AND archived = 0
    ORDER BY school_year DESC, section_name
");
$sections_stmt->bind_param("i", $teacher_id);
$sections_stmt->execute();
$sections = $sections_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sections_stmt->close();

// Filters
$selected_section = isset($_GET['section']) ? (int)$_GET['section'] : null;
$selected_quarter = $_GET['quarter'] ?? $current_quarter;
$selected_week = $_GET['week'] ?? 'all';
$report_type = $_GET['report'] ?? 'overview';

// Initialize section-specific variables
$section_name = $section_year = '';
$section_student_count = 0;
$section_quiz_stats = [];

// Get section data if selected
if ($selected_section) {
    $section_stmt = $conn->prepare("
        SELECT section_name, school_year
        FROM sections 
        WHERE section_id = ? AND teacher_id = ?
    ");
    $section_stmt->bind_param("ii", $selected_section, $teacher_id);
    $section_stmt->execute();
    $section_data = $section_stmt->get_result()->fetch_assoc();
    $section_stmt->close();
    
    if ($section_data) {
        $section_name = $section_data['section_name'];
        $section_year = $section_data['school_year'];
        
        // Count students in section
        $count_stmt = $conn->prepare("
            SELECT COUNT(*) as student_count
            FROM students 
            WHERE section_id = ? AND archived = 0
        ");
        $count_stmt->bind_param("i", $selected_section);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result()->fetch_assoc();
        $section_student_count = $count_result['student_count'] ?? 0;
        $count_stmt->close();
    }
}

// Helper function to build SQL with filters
function buildFilteredQuery($base_sql, $teacher_id, $selected_section = null, $selected_quarter = null, $selected_week = null, $include_student_join = false) {
    $params = [$teacher_id];
    $types = "i";
    
    // Add section filter
    if ($selected_section) {
        if (strpos($base_sql, 'WHERE') !== false) {
            $base_sql .= " AND";
        } else {
            $base_sql .= " WHERE";
        }
        
        if ($include_student_join) {
            $base_sql .= " s.section_id = ?";
        } else {
            $base_sql .= " sq.student_id IN (SELECT student_id FROM students WHERE section_id = ?)";
        }
        $params[] = $selected_section;
        $types .= "i";
    }
    
    // Add quarter filter
    if ($selected_quarter && $selected_quarter !== 'all') {
        if (strpos($base_sql, 'WHERE') !== false) {
            $base_sql .= " AND";
        } else {
            $base_sql .= " WHERE";
        }
        $base_sql .= " q.quarter = ?";
        $params[] = $selected_quarter;
        $types .= "s";
    }
    
    // Add week filter
    if ($selected_week && $selected_week !== 'all') {
        if (strpos($base_sql, 'WHERE') !== false) {
            $base_sql .= " AND";
        } else {
            $base_sql .= " WHERE";
        }
        $base_sql .= " q.week = ?";
        $params[] = $selected_week;
        $types .= "i";
    }
    
    return [
        'sql' => $base_sql,
        'params' => $params,
        'types' => $types
    ];
}

// Total students WITH FILTER
$total_students_sql = "
    SELECT COUNT(*) as total_students
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE sec.teacher_id = ? AND s.archived = 0 AND sec.archived = 0
";

if ($selected_section) {
    $total_students_sql .= " AND s.section_id = ?";
    $total_students_stmt = $conn->prepare($total_students_sql);
    $total_students_stmt->bind_param("ii", $teacher_id, $selected_section);
} else {
    $total_students_stmt = $conn->prepare($total_students_sql);
    $total_students_stmt->bind_param("i", $teacher_id);
}

$total_students_stmt->execute();
$total_students = $total_students_stmt->get_result()->fetch_assoc()['total_students'] ?? 0;
$total_students_stmt->close();

// Total quizzes WITH FILTERS
$total_quizzes_sql = "SELECT COUNT(*) as total_quizzes FROM quizzes WHERE teacher_id = ?";
$params = [$teacher_id];
$types = "i";

if ($selected_quarter && $selected_quarter !== 'all') {
    $total_quizzes_sql .= " AND quarter = ?";
    $params[] = $selected_quarter;
    $types .= "s";
}

if ($selected_week && $selected_week !== 'all') {
    $total_quizzes_sql .= " AND week = ?";
    $params[] = $selected_week;
    $types .= "i";
}

$total_quizzes_stmt = $conn->prepare($total_quizzes_sql);
$total_quizzes_stmt->bind_param($types, ...$params);
$total_quizzes_stmt->execute();
$total_quizzes = $total_quizzes_stmt->get_result()->fetch_assoc()['total_quizzes'] ?? 0;
$total_quizzes_stmt->close();

// Quiz attempts stats WITH FILTERS
$quiz_attempts_sql = "
    SELECT 
        COUNT(sq.student_quiz_id) as total_attempts,
        AVG((sq.score / q.score) * 100) as average_percentage,
        SUM(sq.score) as total_points_earned
    FROM student_quizzes sq
    JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE q.teacher_id = ?
";

$filtered = buildFilteredQuery($quiz_attempts_sql, $teacher_id, $selected_section, $selected_quarter, $selected_week);
$quiz_attempts_stmt = $conn->prepare($filtered['sql']);
$quiz_attempts_stmt->bind_param($filtered['types'], ...$filtered['params']);
$quiz_attempts_stmt->execute();
$quiz_stats = $quiz_attempts_stmt->get_result()->fetch_assoc();
$total_attempts = $quiz_stats['total_attempts'] ?? 0;
$overall_average = round($quiz_stats['average_percentage'] ?? 0, 2);
$quiz_attempts_stmt->close();

// Performance by learner category WITH FILTERS
$performance_sql = "
    SELECT 
        s.learner_category,
        COUNT(DISTINCT s.student_id) as student_count,
        AVG((sq.score / q.score) * 100) as avg_score
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN student_quizzes sq ON s.student_id = sq.student_id
    LEFT JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE sec.teacher_id = ? 
      AND s.archived = 0 
      AND sec.archived = 0
      AND sq.student_quiz_id IS NOT NULL
";

// Add filters to performance query
$performance_where = " GROUP BY s.learner_category";
$perf_params = [$teacher_id];
$perf_types = "i";

if ($selected_section) {
    $performance_sql .= " AND s.section_id = ?";
    $perf_params[] = $selected_section;
    $perf_types .= "i";
}

if ($selected_quarter && $selected_quarter !== 'all') {
    $performance_sql .= " AND q.quarter = ?";
    $perf_params[] = $selected_quarter;
    $perf_types .= "s";
}

if ($selected_week && $selected_week !== 'all') {
    $performance_sql .= " AND q.week = ?";
    $perf_params[] = $selected_week;
    $perf_types .= "i";
}

$performance_sql .= $performance_where;

$performance_stmt = $conn->prepare($performance_sql);
$performance_stmt->bind_param($perf_types, ...$perf_params);
$performance_stmt->execute();
$performance_by_category = [];
$result = $performance_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $performance_by_category[$row['learner_category']] = $row;
}
$performance_stmt->close();

// Learner category distribution WITH FILTERS
$category_sql = "
    SELECT s.learner_category, COUNT(*) as student_count
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE sec.teacher_id = ? AND s.archived = 0 AND sec.archived = 0
      AND s.learner_category IS NOT NULL AND s.learner_category != ''
";

if ($selected_section) {
    $category_sql .= " AND s.section_id = ?";
    $category_stmt = $conn->prepare($category_sql);
    $category_stmt->bind_param("ii", $teacher_id, $selected_section);
} else {
    $category_stmt = $conn->prepare($category_sql);
    $category_stmt->bind_param("i", $teacher_id);
}

$category_sql .= " GROUP BY s.learner_category
    ORDER BY FIELD(s.learner_category, 'Slow Learner', 'Average Learner', 'Fast Learner')";

$category_stmt->execute();
$category_labels = $category_counts = $category_colors = [];
$learner_categories = [];
$result = $category_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $category_labels[] = $row['learner_category'];
    $category_counts[] = $row['student_count'];
    $learner_categories[$row['learner_category']] = $row['student_count'];
    switch ($row['learner_category']) {
        case 'Slow Learner': $category_colors[] = '#FF6384'; break;
        case 'Average Learner': $category_colors[] = '#36A2EB'; break;
        case 'Fast Learner': $category_colors[] = '#4BC0C0'; break;
        default: $category_colors[] = '#FFCE56';
    }
}
$category_stmt->close();

// Max week WITH FILTERS
$max_week_sql = "SELECT MAX(week) as max_week FROM quizzes WHERE teacher_id = ?";
$max_params = [$teacher_id];
$max_types = "i";

if ($selected_quarter && $selected_quarter !== 'all') {
    $max_week_sql .= " AND quarter = ?";
    $max_params[] = $selected_quarter;
    $max_types .= "s";
}

$max_week_stmt = $conn->prepare($max_week_sql);
$max_week_stmt->bind_param($max_types, ...$max_params);
$max_week_stmt->execute();
$max_week = $max_week_stmt->get_result()->fetch_assoc()['max_week'] ?? 1;
$max_week_stmt->close();

// Weekly performance WITH FILTERS
$weekly_sql = "
    SELECT 
        q.week,
        COUNT(DISTINCT sq.student_id) as students_taken,
        COUNT(sq.student_quiz_id) as total_attempts,
        AVG((sq.score / q.score) * 100) as avg_score
    FROM quizzes q
    LEFT JOIN student_quizzes sq ON q.quiz_id = sq.quiz_id
    WHERE q.teacher_id = ? 
      AND sq.student_quiz_id IS NOT NULL
";

if ($selected_quarter && $selected_quarter !== 'all') {
    $weekly_sql .= " AND q.quarter = ?";
}

if ($selected_week && $selected_week !== 'all') {
    $weekly_sql .= " AND q.week = ?";
}

$weekly_sql .= " GROUP BY q.week ORDER BY q.week";

if ($selected_quarter && $selected_quarter !== 'all') {
    if ($selected_week && $selected_week !== 'all') {
        $weekly_stmt = $conn->prepare($weekly_sql);
        $weekly_stmt->bind_param("isi", $teacher_id, $selected_quarter, $selected_week);
    } else {
        $weekly_stmt = $conn->prepare($weekly_sql);
        $weekly_stmt->bind_param("is", $teacher_id, $selected_quarter);
    }
} else {
    $weekly_stmt = $conn->prepare($weekly_sql);
    $weekly_stmt->bind_param("i", $teacher_id);
}

$weekly_stmt->execute();
$weekly_data = [];
$result = $weekly_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $weekly_data[$row['week']] = $row;
}
$weekly_stmt->close();

// Difficulty distribution WITH FILTERS
$difficulty_sql = "
    SELECT 
        q.difficulty,
        COUNT(DISTINCT q.question_id) as question_count,
        AVG(CASE WHEN ql.is_correct = 1 THEN 100 ELSE 0 END) as accuracy_rate
    FROM questions q
    LEFT JOIN quiz_logs ql ON q.question_id = ql.question_id
    LEFT JOIN quizzes qu ON ql.quiz_id = qu.quiz_id
    WHERE qu.teacher_id = ?
";

if ($selected_quarter && $selected_quarter !== 'all') {
    $difficulty_sql .= " AND qu.quarter = ?";
}

if ($selected_week && $selected_week !== 'all') {
    $difficulty_sql .= " AND qu.week = ?";
}

$difficulty_sql .= " GROUP BY q.difficulty";

if ($selected_quarter && $selected_quarter !== 'all') {
    if ($selected_week && $selected_week !== 'all') {
        $difficulty_stmt = $conn->prepare($difficulty_sql);
        $difficulty_stmt->bind_param("isi", $teacher_id, $selected_quarter, $selected_week);
    } else {
        $difficulty_stmt = $conn->prepare($difficulty_sql);
        $difficulty_stmt->bind_param("is", $teacher_id, $selected_quarter);
    }
} else {
    $difficulty_stmt = $conn->prepare($difficulty_sql);
    $difficulty_stmt->bind_param("i", $teacher_id);
}

$difficulty_stmt->execute();
$difficulty_distribution = [];
$result = $difficulty_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $difficulty_distribution[$row['difficulty']] = $row;
}
$difficulty_stmt->close();

// Top performing students WITH FILTERS
$top_students_sql = "
    SELECT 
        s.student_id,
        s.lrn,
        s.first_name,
        s.last_name,
        sec.section_name,
        AVG((sq.score / q.score) * 100) as avg_score,
        COUNT(sq.student_quiz_id) as quiz_count
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    JOIN student_quizzes sq ON s.student_id = sq.student_id
    JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE sec.teacher_id = ? 
      AND s.archived = 0
      AND sq.score IS NOT NULL
";

$top_params = [$teacher_id];
$top_types = "i";

if ($selected_section) {
    $top_students_sql .= " AND s.section_id = ?";
    $top_params[] = $selected_section;
    $top_types .= "i";
}

if ($selected_quarter && $selected_quarter !== 'all') {
    $top_students_sql .= " AND q.quarter = ?";
    $top_params[] = $selected_quarter;
    $top_types .= "s";
}

if ($selected_week && $selected_week !== 'all') {
    $top_students_sql .= " AND q.week = ?";
    $top_params[] = $selected_week;
    $top_types .= "i";
}

$top_students_sql .= " GROUP BY s.student_id
    HAVING COUNT(sq.student_quiz_id) >= 1
    ORDER BY avg_score DESC
    LIMIT 10";

$top_students_stmt = $conn->prepare($top_students_sql);
$top_students_stmt->bind_param($top_types, ...$top_params);
$top_students_stmt->execute();
$top_students = $top_students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$top_students_stmt->close();

// Students needing support WITH FILTERS
$struggling_sql = "
    SELECT 
        s.student_id,
        s.lrn,
        s.first_name,
        s.last_name,
        sec.section_name,
        AVG((sq.score / q.score) * 100) as avg_score,
        COUNT(sq.student_quiz_id) as quiz_count
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    JOIN student_quizzes sq ON s.student_id = sq.student_id
    JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE sec.teacher_id = ? 
      AND s.archived = 0
      AND sq.score IS NOT NULL
";

$struggle_params = [$teacher_id];
$struggle_types = "i";

if ($selected_section) {
    $struggling_sql .= " AND s.section_id = ?";
    $struggle_params[] = $selected_section;
    $struggle_types .= "i";
}

if ($selected_quarter && $selected_quarter !== 'all') {
    $struggling_sql .= " AND q.quarter = ?";
    $struggle_params[] = $selected_quarter;
    $struggle_types .= "s";
}

if ($selected_week && $selected_week !== 'all') {
    $struggling_sql .= " AND q.week = ?";
    $struggle_params[] = $selected_week;
    $struggle_types .= "i";
}

$struggling_sql .= " GROUP BY s.student_id
    HAVING AVG((sq.score / q.score) * 100) < 70 AND COUNT(sq.student_quiz_id) >= 1
    ORDER BY avg_score ASC
    LIMIT 10";

$struggling_stmt = $conn->prepare($struggling_sql);
$struggling_stmt->bind_param($struggle_types, ...$struggle_params);
$struggling_stmt->execute();
$struggling_students = $struggling_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$struggling_stmt->close();

// Completion rates by section WITH FILTERS
$completion_sql = "
    SELECT 
        sec.section_id,
        sec.section_name,
        COUNT(DISTINCT s.student_id) as total_students,
        COUNT(DISTINCT sq.student_id) as students_with_quizzes,
        ROUND((COUNT(DISTINCT sq.student_id) / COUNT(DISTINCT s.student_id)) * 100, 1) as completion_rate
    FROM sections sec
    LEFT JOIN students s ON sec.section_id = s.section_id AND s.archived = 0
    LEFT JOIN student_quizzes sq ON s.student_id = sq.student_id
    WHERE sec.teacher_id = ? AND sec.archived = 0
";

if ($selected_section) {
    $completion_sql .= " AND sec.section_id = ?";
    $completion_stmt = $conn->prepare($completion_sql);
    $completion_stmt->bind_param("ii", $teacher_id, $selected_section);
} else {
    $completion_stmt = $conn->prepare($completion_sql);
    $completion_stmt->bind_param("i", $teacher_id);
}

$completion_sql .= " GROUP BY sec.section_id";
$completion_stmt->execute();
$completion_rates = $completion_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$completion_stmt->close();

// Get section quiz stats WITH FILTERS (for selected section only)
if ($selected_section) {
    $stats_sql = "
        SELECT 
            COUNT(DISTINCT sq.student_id) as students_taken,
            COUNT(sq.student_quiz_id) as total_attempts,
            AVG((sq.score / q.score) * 100) as avg_score
        FROM student_quizzes sq
        JOIN quizzes q ON sq.quiz_id = q.quiz_id
        JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
        WHERE qs.section_id = ? AND q.teacher_id = ?
    ";
    
    $stats_params = [$selected_section, $teacher_id];
    $stats_types = "ii";
    
    if ($selected_quarter && $selected_quarter !== 'all') {
        $stats_sql .= " AND q.quarter = ?";
        $stats_params[] = $selected_quarter;
        $stats_types .= "s";
    }
    
    if ($selected_week && $selected_week !== 'all') {
        $stats_sql .= " AND q.week = ?";
        $stats_params[] = $selected_week;
        $stats_types .= "i";
    }
    
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param($stats_types, ...$stats_params);
    $stats_stmt->execute();
    $section_quiz_stats = $stats_stmt->get_result()->fetch_assoc() ?? [];
    $stats_stmt->close();
}

$progress_sql = "
    SELECT 
        DATE(qa.timestamp) as date,
        COUNT(DISTINCT qa.student_id) as active_students,
        AVG(sq.score) as daily_avg_score
    FROM quiz_attempts qa
    JOIN quizzes q ON qa.quiz_id = q.quiz_id
    LEFT JOIN student_quizzes sq ON qa.quiz_id = sq.quiz_id 
        AND qa.student_id = sq.student_id
    WHERE q.teacher_id = ? 
      AND qa.is_completed = 1
      AND qa.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
";

$progress_params = [$teacher_id];
$progress_types = "i";

if ($selected_section) {
    $progress_sql .= " AND qa.student_id IN (SELECT student_id FROM students WHERE section_id = ?)";
    $progress_params[] = $selected_section;
    $progress_types .= "i";
}

if ($selected_quarter && $selected_quarter !== 'all') {
    $progress_sql .= " AND q.quarter = ?";
    $progress_params[] = $selected_quarter;
    $progress_types .= "s";
}

if ($selected_week && $selected_week !== 'all') {
    $progress_sql .= " AND q.week = ?";
    $progress_params[] = $selected_week;
    $progress_types .= "i";
}

$progress_sql .= " GROUP BY DATE(qa.timestamp) ORDER BY date";

$progress_stmt = $conn->prepare($progress_sql);
$progress_stmt->bind_param($progress_types, ...$progress_params);
$progress_stmt->execute();
$progress_data = $progress_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$progress_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Reports & Analytics | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="../../assets/css/teacher/reports.css">
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
                <a href="quizzes.php" class="nav-item">
                    <i class="fas fa-clipboard-list nav-icon"></i><span>Quizzes</span>
                </a>
                <a href="reports.php" class="nav-item active">
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
            <div class="top-header">
                <div>
                    <h1 class="page-title">Reports & Analytics</h1>
                    <p class="page-subtitle">View comprehensive reports and analytics for student performance</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="exportReport()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                    <button class="btn btn-secondary" onclick="printReport()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div class="filters-card">
                <div class="filters-header">
                    <h3><i class="fas fa-filter"></i> Report Filters</h3>
                    <button class="btn btn-small" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
                <form method="GET" class="filters-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="section-filter">Section</label>
                            <select id="section-filter" name="section" class="form-control">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?= $section['section_id'] ?>"
                                        <?= $selected_section == $section['section_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($section['section_name']) ?> (<?= $section['school_year'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="quarter-filter">Quarter</label>
                            <select id="quarter-filter" name="quarter" class="form-control">
                                <option value="1st" <?= $selected_quarter == '1st' ? 'selected' : '' ?>>1st Quarter</option>
                                <option value="2nd" <?= $selected_quarter == '2nd' ? 'selected' : '' ?>>2nd Quarter</option>
                                <option value="3rd" <?= $selected_quarter == '3rd' ? 'selected' : '' ?>>3rd Quarter</option>
                                <option value="4th" <?= $selected_quarter == '4th' ? 'selected' : '' ?>>4th Quarter</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="week-filter">Week</label>
                            <select id="week-filter" name="week" class="form-control">
                                <option value="all">All Weeks</option>
                                <?php for ($i = 1; $i <= $max_week; $i++): ?>
                                    <option value="<?= $i ?>" <?= $selected_week == $i ? 'selected' : '' ?>>
                                        Week <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="report-type">Report Type</label>
                            <select id="report-type" name="report" class="form-control">
                                <option value="overview" <?= $report_type == 'overview' ? 'selected' : '' ?>>Overview</option>
                                <option value="performance" <?= $report_type == 'performance' ? 'selected' : '' ?>>Performance</option>
                                <option value="students" <?= $report_type == 'students' ? 'selected' : '' ?>>Students</option>
                                <option value="progress" <?= $report_type == 'progress' ? 'selected' : '' ?>>Progress</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary filter-btn" style="margin-top: 5px;">
                                <i class="fas fa-chart-bar"></i> Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-trend <?= $total_students > 0 ? 'positive' : 'neutral' ?>">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $total_students ?></div>
                    <div class="stat-label">Total Students</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-trend <?= $total_quizzes > 0 ? 'positive' : 'neutral' ?>">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $total_quizzes ?></div>
                    <div class="stat-label">Quizzes Created</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-trend <?= $overall_average >= 70 ? 'positive' : ($overall_average >= 50 ? 'neutral' : 'negative') ?>">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $overall_average ?>%</div>
                    <div class="stat-label">Overall Average</div>
                </div>
            </div>

            <div class="report-content">
                <?php if ($selected_section && isset($selected_section_data)): ?>
                    <div class="section-header-card">
                        <div class="section-header-content">
                            <h3><i class="fas fa-users"></i> <?= htmlspecialchars($section_name) ?></h3>
                            <p>School Year: <?= htmlspecialchars($section_year) ?> • Students: <?= $section_student_count ?></p>
                        </div>
                        <div class="section-stats">
                            <div class="section-stat">
                                <div class="section-stat-value"><?= $section_quiz_stats['students_taken'] ?? 0 ?></div>
                                <div class="section-stat-label">Students Taken Quizzes</div>
                            </div>
                            <div class="section-stat">
                                <div class="section-stat-value"><?= round($section_quiz_stats['avg_score'] ?? 0, 2) ?>%</div>
                                <div class="section-stat-label">Average Score</div>
                            </div>
                            <div class="section-stat">
                                <div class="section-stat-value"><?= $section_quiz_stats['total_attempts'] ?? 0 ?></div>
                                <div class="section-stat-label">Total Attempts</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($report_type == 'overview' || $report_type == 'performance'): ?>
                    <div class="charts-grid" style="margin-top: 20px;">
                        <!-- Column 1: Pie Chart -->
                        <div class="chart-card pie-chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-chart-pie"></i> Learner Category Distribution</h4>
                                <div class="chart-legend">
                                    <?php
                                    $total_categorized_students = array_sum($learner_categories);
                                    foreach ($learner_categories as $category => $count):
                                        $percentage = $total_categorized_students > 0 ? round(($count / $total_categorized_students) * 100, 1) : 0;
                                    ?>
                                        <div class="legend-item">
                                            <span class="legend-color" style="background-color: 
                                <?php
                                        switch ($category) {
                                            case 'Slow Learner':
                                                echo '#FF6384';
                                                break;
                                            case 'Average Learner':
                                                echo '#36A2EB';
                                                break;
                                            case 'Fast Learner':
                                                echo '#4BC0C0';
                                                break;
                                            default:
                                                echo '#FFCE56';
                                        }
                                ?>
                            "></span>
                                            <span class="legend-label"><?= htmlspecialchars($category) ?></span>
                                            <span class="legend-value"><?= $count ?> (<?= $percentage ?>%)</span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($learner_categories)): ?>
                                        <div class="legend-item">
                                            <span class="legend-color" style="background-color: #999"></span>
                                            <span class="legend-label">No data available</span>
                                            <span class="legend-value">0 students</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="chart-body">
                                <?php if (!empty($learner_categories)): ?>
                                    <canvas id="learnerCategoryChart"></canvas>
                                <?php else: ?>
                                    <div class="empty-chart">
                                        <i class="fas fa-chart-pie" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px;"></i>
                                        <p>No learner category data available</p>
                                        <small>Set learner categories for students in the Students section</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="chart-footer">
                                <div class="chart-summary">
                                    <strong>Total Students:</strong> <?= $total_categorized_students ?> / <?= $total_students ?>
                                    <?php if ($total_students > 0): ?>
                                        <span class="text-muted">(<?= round(($total_categorized_students / $total_students) * 100, 1) ?>% categorized)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Column 2: Two smaller charts stacked vertically -->
                        <div class="right-column-charts">
                            <div class="chart-card">
                                <div class="chart-header">
                                    <h4><i class="fas fa-chart-line"></i> Performance by Learner Category</h4>
                                </div>
                                <div class="chart-body">
                                    <div class="category-performance">
                                        <?php
                                        $categories = ['Slow Learner', 'Average Learner', 'Fast Learner'];
                                        foreach ($categories as $category):
                                            $data = $performance_by_category[$category] ?? null;
                                            $avgScore = ($data && is_numeric($data['avg_score']))
                                                ? min((float)$data['avg_score'], 100)
                                                : 0;
                                        ?>
                                            <div class="category-item">
                                                <div class="category-header">
                                                    <span class="category-name"><?= $category ?></span>
                                                    <span class="category-stats">
                                                        <?php if ($data): ?>
                                                            <?= $data['student_count'] ?> students •
                                                            Avg: <?= round($data['avg_score'], 1) ?>%
                                                        <?php else: ?>
                                                            0 students • No data
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="progress-fill" style="width: <?= $avgScore ?>%"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="chart-card">
                                <div class="chart-header">
                                    <h4><i class="fas fa-percentage"></i> Overall Performance vs Category Averages</h4>
                                </div>
                                <div class="chart-body">
                                    <div class="comparison-chart">
                                        <div class="comparison-item">
                                            <div class="comparison-label">Overall Average</div>
                                            <div class="comparison-bar">
                                                <div class="comparison-fill" style="width: <?= min($overall_average, 100) ?>%; background-color: #6c757d;"></div>
                                            </div>
                                            <div class="comparison-value"><?= $overall_average ?>%</div>
                                        </div>

                                        <?php
                                        $categories = ['Slow Learner', 'Average Learner', 'Fast Learner'];
                                        foreach ($categories as $category):
                                            $data = $performance_by_category[$category] ?? null;
                                            $avgScore = ($data && is_numeric($data['avg_score'])) ? min((float)$data['avg_score'], 100) : 0;
                                        ?>
                                            <div class="comparison-item">
                                                <div class="comparison-label"><?= $category ?></div>
                                                <div class="comparison-bar">
                                                    <div class="comparison-fill" style="width: <?= $avgScore ?>%; 
                                        background-color: <?php
                                                            switch ($category) {
                                                                case 'Slow Learner':
                                                                    echo '#FF6384';
                                                                    break;
                                                                case 'Average Learner':
                                                                    echo '#36A2EB';
                                                                    break;
                                                                case 'Fast Learner':
                                                                    echo '#4BC0C0';
                                                                    break;
                                                                default:
                                                                    echo '#FFCE56';
                                                            }
                                                            ?>;">
                                                    </div>
                                                </div>
                                                <div class="comparison-value">
                                                    <?= $data ? round($data['avg_score'], 1) . '%' : 'N/A' ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Performance - Full width below the first row -->
                    <div class="chart-card full-width-chart">
                        <div class="chart-header">
                            <h4><i class="fas fa-calendar-week"></i> Weekly Performance</h4>
                        </div>
                        <div class="chart-body">
                            <div class="weekly-performance">
                                <?php for ($week = 1; $week <= $max_week; $week++): ?>
                                    <?php $week_data = $weekly_data[$week] ?? null; ?>
                                    <div class="week-item">
                                        <div class="week-header">
                                            <span>Week <?= $week ?></span>
                                            <?php if ($week_data && $week_data['avg_score']): ?>
                                                <span><?= round($week_data['avg_score'], 1) ?>%</span>
                                            <?php else: ?>
                                                <span>No data</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($week_data && $week_data['avg_score']): ?>
                                            <div class="week-bar">
                                                <div class="week-fill" style="width: <?= min($week_data['avg_score'], 100) ?>%"></div>
                                            </div>
                                            <div class="week-stats">
                                                <small><?= $week_data['students_taken'] ?? 0 ?> students • <?= $week_data['total_attempts'] ?? 0 ?> attempts</small>
                                            </div>
                                        <?php else: ?>
                                            <div class="week-bar empty">
                                                <div class="week-fill" style="width: 0%"></div>
                                            </div>
                                            <div class="week-stats">
                                                <small>No quizzes this week</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($report_type == 'students' || $report_type == 'overview'): ?>
                    <div class="student-tables">
                        <div class="table-card">
                            <div class="table-header">
                                <h4><i class="fas fa-trophy"></i> Top Performing Students</h4>
                                <span class="badge badge-success"><?= count($top_students) ?> students</span>
                            </div>
                            <div class="table-body">
                                <?php if (empty($top_students)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-chart-bar" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                                        <p>No student quiz data available yet.</p>
                                    </div>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Rank</th>
                                                <th>Student</th>
                                                <th>LRN</th>
                                                <th>Section</th>
                                                <th>Avg Score</th>
                                                <th>Quizzes Taken</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_students as $index => $student): ?>
                                                <tr>
                                                    <td>
                                                        <div class="rank-badge rank-<?= $index + 1 ?>">
                                                            <?= $index + 1 ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                                    </td>
                                                    <td><?= $student['lrn'] ?></td>
                                                    <td><?= htmlspecialchars($student['section_name']) ?></td>
                                                    <td>
                                                        <?php
                                                        $score = round($student['avg_score'], 2);
                                                        $score_class = $score >= 90 ? 'score-excellent' : ($score >= 80 ? 'score-good' : ($score >= 70 ? 'score-average' : 'score-poor'));
                                                        ?>
                                                        <span class="score-badge <?= $score_class ?>">
                                                            <?= $score ?>%
                                                        </span>
                                                    </td>
                                                    <td><?= $student['quiz_count'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-card">
                            <div class="table-header">
                                <h4><i class="fas fa-life-ring"></i> Students Needing Support</h4>
                                <span class="badge badge-warning"><?= count($struggling_students) ?> students</span>
                            </div>
                            <div class="table-body">
                                <?php if (empty($struggling_students)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-user-friends" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                                        <p>No struggling student data available yet.</p>
                                    </div>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>LRN</th>
                                                <th>Section</th>
                                                <th>Avg Score</th>
                                                <th>Quizzes Taken</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($struggling_students as $student): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                                    </td>
                                                    <td><?= $student['lrn'] ?></td>
                                                    <td><?= htmlspecialchars($student['section_name']) ?></td>
                                                    <td>
                                                        <?php
                                                        $score = round($student['avg_score'], 2);
                                                        $score_class = $score >= 90 ? 'score-excellent' : ($score >= 80 ? 'score-good' : ($score >= 70 ? 'score-average' : 'score-poor'));
                                                        ?>
                                                        <span class="score-badge <?= $score_class ?>">
                                                            <?= $score ?>%
                                                        </span>
                                                    </td>
                                                    <td><?= $student['quiz_count'] ?></td>
                                                    <td>
                                                        <a href="sections.php?view=student&id=<?= $student['student_id'] ?>"
                                                            class="btn-icon view"
                                                            title="View Student">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($report_type == 'progress' || $report_type == 'overview'): ?>
                    <div class="progress-section">
                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="fas fa-chart-area"></i> Completion Rates by Section</h4>
                            </div>
                            <div class="section-body">
                                <div class="completion-chart">
                                    <?php if (empty($completion_rates)): ?>
                                        <div class="empty-state">
                                            <p>No completion data available.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($completion_rates as $rate): ?>
                                            <div class="completion-item">
                                                <div class="completion-header">
                                                    <span class="section-name"><?= htmlspecialchars($rate['section_name']) ?></span>
                                                    <span class="completion-rate"><?= $rate['completion_rate'] ?>%</span>
                                                </div>
                                                <div class="completion-bar">
                                                    <div class="completion-fill" style="width: <?= $rate['completion_rate'] ?>%"></div>
                                                </div>
                                                <div class="completion-stats">
                                                    <small><?= $rate['students_with_quizzes'] ?>/<?= $rate['total_students'] ?> students completed quizzes</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="fas fa-signal"></i> Difficulty Analysis</h4>
                            </div>
                            <div class="section-body">
                                <div class="difficulty-analysis">
                                    <?php if (empty($difficulty_distribution)): ?>
                                        <div class="empty-state">
                                            <p>No difficulty analysis data available yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        $difficulties = ['Easy', 'Medium', 'Hard'];
                                        foreach ($difficulties as $difficulty):
                                            $data = $difficulty_distribution[$difficulty] ?? null;
                                        ?>
                                            <div class="difficulty-item">
                                                <div class="difficulty-header">
                                                    <span class="difficulty-badge badge-<?= strtolower($difficulty) ?>">
                                                        <?= $difficulty ?> Questions
                                                    </span>
                                                    <span class="difficulty-count"><?= $data['question_count'] ?? 0 ?> questions</span>
                                                </div>
                                                <?php if ($data): ?>
                                                    <div class="difficulty-stats">
                                                        <div class="stat">
                                                            <span>Accuracy Rate:</span>
                                                            <strong><?= round($data['accuracy_rate'], 1) ?>%</strong>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="difficulty-stats">
                                                        <div class="stat">
                                                            <span>Accuracy Rate:</span>
                                                            <strong>No data</strong>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($progress_data)): ?>
                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="fas fa-history"></i> 30-Day Progress Timeline</h4>
                            </div>
                            <div class="section-body">
                                <div class="timeline">
                                    <?php foreach ($progress_data as $data): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-date"><?= date('M j', strtotime($data['date'])) ?></div>
                                            <div class="timeline-content">
                                                <div class="timeline-stats">
                                                    <span class="stat">
                                                        <i class="fas fa-user"></i> <?= $data['active_students'] ?> active
                                                    </span>
                                                    <span class="stat">
                                                        <i class="fas fa-percentage"></i> <?= round($data['daily_avg_score'], 1) ?>% avg
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="recommendations-card">
                    <div class="recommendations-header">
                        <h4><i class="fas fa-lightbulb"></i> Recommendations</h4>
                    </div>
                    <div class="recommendations-body">
                        <?php if (empty($top_students) && empty($struggling_students)): ?>
                            <div class="recommendation-item">
                                <i class="fas fa-info-circle text-primary"></i>
                                <div>
                                    <strong>No Data Available</strong>
                                    <p>Start by creating quizzes and having students take them to generate analytics.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if (!empty($struggling_students)): ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-exclamation-circle text-warning"></i>
                                    <div>
                                        <strong>Focus on Struggling Students:</strong>
                                        <p><?= count($struggling_students) ?> students have average scores below 70%. Consider providing additional support or review sessions.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($top_students)): ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-chart-line text-success"></i>
                                    <div>
                                        <strong>Celebrate Top Performers:</strong>
                                        <p>Recognize the <?= count($top_students) ?> top-performing students to motivate others.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($overall_average < 70): ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-graduation-cap text-primary"></i>
                                    <div>
                                        <strong>Improve Overall Performance:</strong>
                                        <p>Consider reviewing challenging topics where students are scoring lower.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    </div>
    </main>
    </div>

    <script>
        function resetFilters() {
            window.location.href = 'reports.php';
        }

        // Pass PHP data to JavaScript
        const learnerCategoryData = {
            labels: <?= json_encode($category_labels) ?>,
            counts: <?= json_encode($category_counts) ?>,
            colors: <?= json_encode($category_colors) ?>,
            overallAverage: <?= $overall_average ?>,
            categoryAverages: <?= json_encode($performance_by_category) ?>
        };

        const chartData = {
            labels: <?= json_encode($category_labels) ?>,
            datasets: [{
                data: <?= json_encode($category_counts) ?>,
                backgroundColor: <?= json_encode($category_colors) ?>,
                borderColor: 'white',
                borderWidth: 2,
                hoverOffset: 15
            }]
        };
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const learnerCategoryChart = document.getElementById('learnerCategoryChart');

            if (learnerCategoryChart && chartData) {
                const ctx = learnerCategoryChart.getContext('2d');

                new Chart(ctx, {
                    type: 'pie',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return `${label}: ${value} students (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        animation: {
                            animateScale: true,
                            animateRotate: true
                        }
                    }
                });
            }

            initializeCharts();
            setupPrintFunctionality();

            document.getElementById('section-filter').addEventListener('change', updateReport);
            document.getElementById('quarter-filter').addEventListener('change', updateReport);
            document.getElementById('week-filter').addEventListener('change', updateReport);
            document.getElementById('report-type').addEventListener('change', updateReport);
        });

        function updateReport() {
            const form = document.querySelector('.filters-form');
            form.submit();
        }

        function exportReport() {
            const reportData = {
                title: 'MathQuest Analytics Report',
                generated: new Date().toLocaleString(),
                filters: getCurrentFilters(),
                data: collectReportData()
            };

            let csvContent = "data:text/csv;charset=utf-8,";

            csvContent += "MathQuest Analytics Report\r\n";
            csvContent += `Generated: ${reportData.generated}\r\n`;
            csvContent += `Quarter: ${reportData.filters.quarter}\r\n`;
            if (reportData.filters.section) {
                csvContent += `Section: ${reportData.filters.section}\r\n`;
            }
            csvContent += "\r\n";

            csvContent += "Summary Statistics\r\n";
            csvContent += "Metric,Value\r\n";
            csvContent += `Total Students,${reportData.data.total_students}\r\n`;
            csvContent += `Total Quizzes,${reportData.data.total_quizzes}\r\n`;
            csvContent += `Total Attempts,${reportData.data.total_attempts}\r\n`;
            csvContent += `Overall Average,${reportData.data.overall_average}%\r\n`;
            csvContent += "\r\n";

            csvContent += "Learner Category Distribution\r\n";
            csvContent += "Category,Student Count,Percentage\r\n";
            if (learnerCategoryData && learnerCategoryData.labels) {
                const total = learnerCategoryData.counts.reduce((a, b) => a + b, 0);
                learnerCategoryData.labels.forEach((label, index) => {
                    const count = learnerCategoryData.counts[index];
                    const percentage = total > 0 ? Math.round((count / total) * 100) : 0;
                    csvContent += `${label},${count},${percentage}%\r\n`;
                });
            }
            csvContent += "\r\n";

            csvContent += "Performance by Learner Category\r\n";
            csvContent += "Category,Student Count,Average Score\r\n";
            if (learnerCategoryData && learnerCategoryData.categoryAverages) {
                const categories = ['Slow Learner', 'Average Learner', 'Fast Learner'];
                categories.forEach(category => {
                    const data = learnerCategoryData.categoryAverages[category];
                    if (data) {
                        csvContent += `${category},${data.student_count},${round(data.avg_score, 1)}%\r\n`;
                    } else {
                        csvContent += `${category},0,N/A\r\n`;
                    }
                });
            }

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `mathquest_report_${new Date().toISOString().slice(0, 10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function round(value, decimals) {
            return Number(Math.round(value + 'e' + decimals) + 'e-' + decimals);
        }

        function printReport() {
            const originalContent = document.body.innerHTML;
            const printContent = document.querySelector('.main-content').innerHTML;

            document.body.innerHTML = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>MathQuest Analytics Report</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
                    .stat-card { border: 1px solid #ddd; padding: 15px; border-radius: 8px; text-align: center; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                    th { background-color: #f5f5f5; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="print-header">
                    <h1>MathQuest Analytics Report</h1>
                    <p>Generated: ${new Date().toLocaleString()}</p>
                </div>
                ${printContent}
            </body>
            </html>
        `;

            window.print();
            document.body.innerHTML = originalContent;
            window.location.reload();
        }

        function getCurrentFilters() {
            return {
                section: document.getElementById('section-filter').value,
                quarter: document.getElementById('quarter-filter').value,
                week: document.getElementById('week-filter').value,
                report: document.getElementById('report-type').value
            };
        }

        function collectReportData() {
            const statCards = document.querySelectorAll('.stat-card');
            return {
                total_students: statCards[0]?.querySelector('.stat-value')?.textContent || '0',
                total_quizzes: statCards[1]?.querySelector('.stat-value')?.textContent || '0',
                overall_average: statCards[2]?.querySelector('.stat-value')?.textContent?.replace('%', '') || '0',
            };
        }

        function initializeCharts() {
            const dateInputs = document.querySelectorAll('.date-picker');
            dateInputs.forEach(input => {
                flatpickr(input, {
                    dateFormat: "Y-m-d",
                    allowInput: true
                });
            });
        }

        function setupPrintFunctionality() {
            const style = document.createElement('style');
            style.textContent = `
            @media print {
                .sidebar, .top-header .header-actions, .filters-card, .btn {
                    display: none !important;
                }
                .main-content {
                    margin: 0 !important;
                    padding: 20px !important;
                }
                .stat-card {
                    break-inside: avoid;
                }
            }
        `;
            document.head.appendChild(style);
        }

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printReport();
            }
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportReport();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const learnerCategoryChart = document.getElementById('learnerCategoryChart');

            if (learnerCategoryChart && chartData && chartData.labels && chartData.labels.length > 0) {
                const ctx = learnerCategoryChart.getContext('2d');

                new Chart(ctx, {
                    type: 'pie',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 1.5,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return `${label}: ${value} students (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        animation: {
                            animateScale: true,
                            animateRotate: true
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>