<?php
session_start();
require '../../config/dbcon.php';
include 'dashboard/recent_activities.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = '';
$teacher_position = '';
$teacher_profile_image = '';
$teacher_email = '';
$first_name = '';
$last_name = '';

$stmt = $conn->prepare("SELECT first_name, last_name, position, profile_image, email FROM teachers WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row = $res->fetch_assoc()) {
    $first_name = $row['first_name'];
    $last_name = $row['last_name'];
    $teacher_name = trim($first_name . ' ' . $last_name);
    $teacher_position = $row['position'] ?: 'Teacher';
    $teacher_profile_image = $row['profile_image'];
    $teacher_email = $row['email'];
    $_SESSION['teacher_name'] = $teacher_name;
}
$stmt->close();

$sidebar_initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));

function getTeacherAvatar($profile_image)
{
    if (!$profile_image) {
        return null;
    }

    $project_root = dirname(__DIR__, 2);

    $absolutePath = $project_root . $profile_image;

    return file_exists($absolutePath) ? $profile_image : null;
}

$teacher_avatar = $teacher_profile_image;

$sections = [];
$sections_list = [];
$students_count = 0;
$materials_count = 0;
$quizzes_count = 0;

$stmt = $conn->prepare("
    SELECT section_id, section_name
    FROM sections
    WHERE teacher_id = ?
      AND archived = 0
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $sections[] = $row;
    $sections_list[] = $row['section_name'];
}
$stmt->close();

$students_count = 0;

if (!empty($sections)) {
    $section_ids = implode(',', array_map('intval', array_column($sections, 'section_id')));

    $query = "
        SELECT COUNT(*) AS total_students
        FROM students
        WHERE section_id IN ($section_ids)
    ";

    $res = $conn->query($query);
    $students_count = $res ? (int)$res->fetch_assoc()['total_students'] : 0;
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total_materials FROM learning_materials WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$materials_count = $stmt->get_result()->fetch_assoc()['total_materials'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total_quizzes FROM quizzes WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$quizzes_count = $stmt->get_result()->fetch_assoc()['total_quizzes'] ?? 0;
$stmt->close();

$class_average_score = "0.00";

if (!empty($sections)) {
    $query_avg = "
        SELECT AVG((sq.score / qq_points.total_points) * 100) AS avg_score
        FROM student_quizzes sq
        JOIN students st ON sq.student_id = st.student_id
        JOIN quiz_attempts qa ON sq.student_id = qa.student_id AND sq.quiz_id = qa.quiz_id
        JOIN (
            SELECT qq.quiz_id, SUM(q.points) AS total_points
            FROM quiz_questions qq
            JOIN questions q ON qq.question_id = q.question_id
            GROUP BY qq.quiz_id
        ) AS qq_points ON qq_points.quiz_id = sq.quiz_id
        WHERE st.section_id IN ($section_ids)
          AND sq.score IS NOT NULL
          AND qa.is_completed = 1
    ";

    $res = $conn->query($query_avg);

    if ($res && $row = $res->fetch_assoc()) {
        $class_average_score = $row['avg_score'] !== null
            ? number_format((float)$row['avg_score'], 2)
            : "0.00";
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | MathQuest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/teacher/dashboard.css">
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo" id="sidebarToggle">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-item active">
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
                        <?php if ($teacher_profile_image): ?>
                            <img src="<?= htmlspecialchars($teacher_profile_image) ?>" alt="Avatar"
                                style="width:100%; height:100%; border-radius:50%;"
                                onerror="this.style.display='none'; this.parentNode.innerHTML='<?= htmlspecialchars($sidebar_initials) ?>';">
                        <?php else: ?>
                            <?= htmlspecialchars($sidebar_initials) ?>
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
            <div class="welcome-banner">
                <h2 id="welcome-message">Teacher Dashboard - Manage Your Math Classes</h2>
                <p>Welcome to your MathQuest Teacher Portal. Monitor your student's<br>progress, create and upload learning materials, and manage<br>your Grade 3 Mathematics classes all in one place.</p>
            </div>

            <header class="top-header">
                <h1>Teacher Dashboard</h1>
            </header>

            <div class="stats-cards">
                <div class="stat-card sections">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <h3><?= count($sections) ?></h3>
                        <p>Total Sections</p>
                    </div>
                </div>

                <div class="stat-card students">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-info">
                        <h3><?= $students_count ?></h3>
                        <p>Total Students</p>
                    </div>
                </div>

                <div class="stat-card materials">
                    <div class="stat-icon"><i class="fas fa-book"></i></div>
                    <div class="stat-info">
                        <h3><?= $materials_count ?></h3>
                        <p>Learning Materials</p>
                    </div>
                </div>

                <div class="stat-card quizzes">
                    <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div class="stat-info">
                        <h3><?= $quizzes_count ?></h3>
                        <p>Total  Quizzes</p>
                    </div>
                </div>
            </div>

            <div class="progress-section">
                <div class="section-header">
                    <h2><i class="fas fa-history"></i>Recent Activity</h2>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recent_activities) && is_array($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon"><i class="fas <?= htmlspecialchars($activity['icon']) ?>"></i></div>
                                <div class="activity-content">
                                    <div class="activity-title"><?= htmlspecialchars($activity['title']) ?></div>
                                    <div class="activity-desc"><?= htmlspecialchars($activity['desc']) ?></div>
                                    <div class="activity-time"><?= htmlspecialchars($activity['time']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-activity">No recent activity.</div><br>
                    <?php endif; ?>
                </div><br>

                <div class="progress-section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-bar"></i>Overall Class Performance</h2>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <span><strong>Average Score:</strong> <?= $class_average_score ?>%</span>
                    </div>

                    <div class="progress-bar">
                        <div class="progress-fill" id="class-performance" style="width: <?= floatval($class_average_score) ?>%;"></div>
                    </div>


                    <p style="margin-top: 15px; font-size: 15px; color: #666;">
                        <i class="fas fa-lightbulb" style="margin-right: 8px; color: var(--accent-color);"></i>
                        Class average score across all sections for the current quarter. Monitor individual section performance in the Reports section.
                    </p>
                </div>

        </main>
    </div>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/dashboard.js"></script>
</body>

</html>