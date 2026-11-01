<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$student_id = $_SESSION['student_id'];

$student_name = '';
$first_name = '';
$last_name = '';
$lrn = '';
$total_points = 0;
$current_level = 1;
$level_title = 'Math Beginner';
$progress_percentage = 0;
$points_to_next_level = 20;
$badges_earned = 0;
$learner_category = 'Average';
$category_tag = 'category-average';
$learner_text = 'Average Learner';

$stmt = $conn->prepare("
    SELECT 
        s.lrn,
        s.first_name, 
        s.last_name, 
        s.current_points,
        s.current_level,
        s.learner_category
    FROM students s
    WHERE s.student_id = ? AND s.archived = 0
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    $first_name = $row['first_name'];
    $last_name = $row['last_name'];
    $lrn = $row['lrn'];
    $total_points = $row['current_points'];
    $current_level = $row['current_level'];
    $learner_category = $row['learner_category'];

    $student_name = trim($first_name . ' ' . $last_name);
    $_SESSION['student_name'] = $student_name;
    $_SESSION['current_points'] = $total_points;
    $_SESSION['current_level'] = $current_level;

    $points_in_current_level = $total_points % 20;
    $progress_percentage = min(100, ($points_in_current_level / 20) * 100);
    $points_to_next_level = max(0, 20 - $points_in_current_level);

    if ($current_level >= 1 && $current_level <= 9) {
        $level_title = 'Math Beginner';
    } elseif ($current_level >= 10 && $current_level <= 19) {
        $level_title = 'Math Explorer';
    } elseif ($current_level >= 20 && $current_level <= 29) {
        $level_title = 'Math Wizard';
    } elseif ($current_level >= 30 && $current_level <= 39) {
        $level_title = 'Math Champion';
    } elseif ($current_level >= 40 && $current_level <= 49) {
        $level_title = 'Math Master';
    }
    elseif ($current_level == 50) {
        $level_title = 'Math Grandmaster';
    }

    switch ($learner_category) {
        case 'Slow Learner':
            $category_tag = 'category-slow';
            $learner_text = 'Slow Learner';
            break;
        case 'Average Learner':
            $category_tag = 'category-average';
            $learner_text = 'Average Learner';
            break;
        case 'Fast Learner':
            $category_tag = 'category-fast';
            $learner_text = 'Fast Learner';
            break;
        default:
            $category_tag = 'category-average';
            $learner_text = 'Average Learner';
    }
}

$stmt->close();

$badges_earned = 0;
$badge_stmt = $conn->prepare("
    SELECT COUNT(*) as badge_count 
    FROM student_badges 
    WHERE student_id = ?
");
$badge_stmt->bind_param("i", $student_id);
$badge_stmt->execute();
$badge_result = $badge_stmt->get_result();

if ($badge_result && $badge_row = $badge_result->fetch_assoc()) {
    $badges_earned = $badge_row['badge_count'];
}
$badge_stmt->close();

$section_name = '';
$school_year = '';
$stmt = $conn->prepare("
    SELECT sec.section_name, sec.school_year 
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($section_name, $school_year);
$stmt->fetch();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MATHQUEST - Student Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student/student_dashboard.css">
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="student_dashboard.php" class="nav-item active">
                    <i class="fas fa-home nav-icon"></i>
                    <span>Dashboard</span>
                </a>
                <a href="modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i>
                    <span>Learning Materials</span>
                </a>
                <a href="quizzes.php" class="nav-item">
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
            <div class="welcome-banner">

                <body data-username="<?= htmlspecialchars($_SESSION['student_name'] ?? 'Student') ?>">
                    <h2 id="welcome-message">Welcome to Math Quest</h2>
                    <p>Start your learning journey in Grade 3 Mathematics with MathQuest.<br>Study lessons, answer quizzes, level up and earn badges!</p>
            </div>

            <header class="top-header">
                <div class="header-content">
                    <div>
                        <h1>Student Dashboard</h1><br>
                        <div class="student-info">
                            <span class="info-item">
                                <i class="fas fa-id-card"></i>
                                LRN: <?= htmlspecialchars($lrn) ?><br>
                            </span>
                            <span class="info-item">
                                <i class="fas fa-users"></i>
                                Section: <?= htmlspecialchars($section_name) ?><br>
                            </span>

                            <span class="info-item">
                                <i class="fas fa-calendar-alt"></i>
                                School Year: <?= htmlspecialchars($school_year) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </header>

            <div class="stats-cards">
                <div class="stat-card points">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= htmlspecialchars($total_points) ?></h3>
                        <p>Points</p>
                    </div>
                </div>

                <div class="stat-card level">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= htmlspecialchars($current_level) ?></h3>
                        <p>Level</p>
                        <small><?= htmlspecialchars($points_to_next_level) ?>/20 points to next level</small>
                    </div>
                </div>

                <div class="stat-card badges">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= htmlspecialchars($badges_earned) ?></h3>
                        <p>Badges Earned</p>
                        <small>Complete quests to earn badges</small>
                    </div>
                </div>

                <div class="stat-card learner-category">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= htmlspecialchars(ucfirst(str_replace(' Learner', '', $learner_category))) ?></h3>
                        <p>Learner Category</p>
                        <span class="category-tag <?= htmlspecialchars($category_tag) ?>">
                            <?= htmlspecialchars($learner_text) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="progress-section">
                <div class="section-header">
                    <h2><i class="fas fa-flag-checkered"></i>Level Progress</h2>
                </div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span><strong>Level <?= htmlspecialchars($current_level) ?></strong> - <?= htmlspecialchars($level_title) ?></span>
                    <span><?= htmlspecialchars($total_points % 20) ?>/20 points</span>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill" id="level-progress" style="width: <?= htmlspecialchars($progress_percentage) ?>%"></div>
                </div>

                <p style="margin-top: 15px; font-size: 15px; color: #666;">
                    <i class="fas fa-lightbulb" style="margin-right: 8px; color: var(--accent-color);"></i>
                    Complete quizzes and answer questions correctly to earn points and level up! Each level unlocks new titles.
                </p>
            </div>
        </main>
    </div>
    <script src="../../assets/js/student/sidebar.js"></script>
    <script src="../../assets/js/student/student_dashboard.js"></script>
</body>

</html>