<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: logout.php');
    exit;
}

$student_id = $_SESSION['student_id'];

$student_name = '';
$first_name = '';
$last_name = '';

$stmt = $conn->prepare("
    SELECT first_name, last_name
    FROM students
    WHERE student_id = ? AND archived = 0
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    $first_name = $row['first_name'];
    $last_name  = $row['last_name'];

    $student_name = trim($first_name . ' ' . $last_name);
    $_SESSION['student_name'] = $student_name;
}

$stmt->close();

$sidebar_initials = strtoupper(
    substr($first_name, 0, 1) . substr($last_name, 0, 1)
);

$student = [];
$section = [];
$quiz_history = [];
$badges_count = 0;
$average_score = 0;
$total_points = 0;

if (!empty($student_id)) {
    $stmt_avg = $conn->prepare("
        SELECT 
            SUM(sq.score) AS total_earned,
            SUM(q.score) AS total_possible
        FROM student_quizzes sq
        JOIN quizzes q ON sq.quiz_id = q.quiz_id
        JOIN quiz_attempts qa ON sq.student_id = qa.student_id AND sq.quiz_id = qa.quiz_id
        WHERE sq.student_id = ?
          AND sq.score IS NOT NULL
          AND qa.is_completed = 1
    ");
    $stmt_avg->bind_param("i", $student_id);
    $stmt_avg->execute();
    $result_avg = $stmt_avg->get_result();

    if ($result_avg && $row = $result_avg->fetch_assoc()) {
        $total_earned = (float)($row['total_earned'] ?? 0);
        $total_possible = (float)($row['total_possible'] ?? 0);

        if ($total_possible > 0) {
            $average_score = round(($total_earned / $total_possible) * 100, 2);
        }
    }

    $stmt_avg->close();
}

$stmt = $conn->prepare("
    SELECT s.*, 
           sec.section_name, 
           sec.school_year,
           t.first_name as teacher_first,
           t.last_name as teacher_last
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    LEFT JOIN teachers t ON sec.teacher_id = t.teacher_id
    WHERE s.student_id = ? AND s.archived = 0
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $student = $result->fetch_assoc();

    $stmt2 = $conn->prepare("SELECT COUNT(*) as badge_count FROM student_badges WHERE student_id = ?");
    $stmt2->bind_param("i", $student_id);
    $stmt2->execute();
    $badges_result = $stmt2->get_result();
    $badges_row = $badges_result->fetch_assoc();
    $badges_count = $badges_row['badge_count'] ?? 0;
    $stmt2->close();

$stmt3 = $conn->prepare("
    SELECT sq.*, 
           q.quarter, 
           q.week, 
           q.quiz_type,
           q.score AS total_score,
           q.date_created as quiz_date,
           q.quiz_id
    FROM student_quizzes sq
    LEFT JOIN quizzes q ON sq.quiz_id = q.quiz_id
    WHERE sq.student_id = ?
    ORDER BY 
        CASE q.quarter 
            WHEN '1st' THEN 1
            WHEN '2nd' THEN 2
            WHEN '3rd' THEN 3
            WHEN '4th' THEN 4
            ELSE 5
        END,
        q.week,
        sq.date_taken DESC
");

    $stmt3->bind_param("i", $student_id);
    $stmt3->execute();
    $quiz_result = $stmt3->get_result();
   
    $total_points = $student['current_points'] ?? 0;
    $total_score = 0;
    $quiz_count = 0;

    while ($quiz = $quiz_result->fetch_assoc()) {
        $quiz_history[] = $quiz;
        if ($quiz['score'] !== null) {
            $total_score += $quiz['score'];
            $quiz_count++;
        }
    }
    $stmt3->close();

} else {
    header('Location: sections.php');
    exit;
}
$stmt->close();

function getLearnerCategory($theta)
{
    if ($theta < -0.5) {
        return 'Slow Learner';
    } elseif ($theta <= 0.5) {
        return 'Average Learner';
    } else {
        return 'Fast Learner';
    }
}

$student_level = $student['current_level'] ?? 1;
$points_needed = 20 - ($total_points % 20);
$theta = $student['theta'] ?? 0;
$learner_category = getLearnerCategory($theta);

function getGradeSection($section_name)
{
    if (strpos($section_name, 'Grade') !== false) {
        return $section_name;
    }
    if (preg_match('/(\d+)/', $section_name, $matches)) {
        return "Grade " . $matches[1] . " - " . $section_name;
    }
    return $section_name;
}

$section_id = $student['section_id'] ?? 0;
$back_url = $section_id > 0 ? "sections.php?action=students&section_id=$section_id#studentsTab" : "sections.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $lrn = trim($_POST['lrn'] ?? '');
    $learner_category = $_POST['learner_category'] ?? 'Average Learner';
    $assigned_set = $_POST['assigned_set'] ?? 'Medium';

    if (empty($first_name) || empty($last_name) || empty($lrn)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!is_numeric($lrn) || strlen($lrn) != 12) {
        $error_message = "LRN must be a 12-digit number.";
    } else {

        $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE lrn = ? AND student_id != ?");
        $check_stmt->bind_param("si", $lrn, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error_message = "This LRN is already registered to another student.";
        } else {
            $update_stmt = $conn->prepare("
                UPDATE students SET 
                first_name = ?, 
                middle_name = ?, 
                last_name = ?, 
                lrn = ?, 
                learner_category = ?, 
                assigned_set = ? 
                WHERE student_id = ?
            ");
            $update_stmt->bind_param("ssssssi", $first_name, $middle_name, $last_name, $lrn, $learner_category, $assigned_set, $student_id);

            if ($update_stmt->execute()) {
                $success_message = "Student information updated successfully!";
                header("Location: profile.php?student_id=" . $student_id . "&updated=1");
                exit();
            } else {
                $error_message = "Failed to update student information. Please try again.";
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}

if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $success_message = "Student information updated successfully!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_passcode'])) {
    $reset_student_id = intval($_POST['student_id'] ?? 0);

    if ($reset_student_id <= 0) {
        $error_message = "Invalid student.";
    } else {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $new_passcode = '';
        for ($i = 0; $i < 6; $i++) {
            $new_passcode .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $stmt = $conn->prepare("UPDATE students SET passcode = ? WHERE student_id = ?");
        $stmt->bind_param("si", $new_passcode, $reset_student_id);

        if ($stmt->execute()) {
            $success_message = "Passcode reset successfully! New passcode: <strong>" . htmlspecialchars($new_passcode) . "</strong>";
            $student['passcode'] = $new_passcode;
        } else {
            $error_message = "Failed to reset passcode.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View My Profile | MathQuest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student/profile.css">
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
                <a href="quizzes.php" class="nav-item">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    <span>Quizzes</span>
                </a>
                <a href="profile.php" class="nav-item active">
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
            <header class="top-header">
                <h1 class="page-title">View My Profile</h1>
            </header>

            <div class="content-area">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($student)): ?>
                    <div class="student-profile">
                        <div class="profile-header">
                            <div class="student-avatar">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div class="student-info">
                                <h2 class="student-name">
                                    <?= htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']) ?>
                                </h2>
                                <p class="student-lrn">Learner Reference Number: <?= htmlspecialchars($student['lrn']) ?></p>
                                <span class="student-level">Level <?= $student_level ?> -
                                    <?php
                                    if ($student_level == 50) {
                                        echo 'Math Grandmaster';
                                    } elseif ($student_level >= 40) {
                                        echo 'Math Master';
                                    } elseif ($student_level >= 30) {
                                        echo 'Math Champion';
                                    } elseif ($student_level >= 20) {
                                        echo 'Math Wizard';
                                    } elseif ($student_level >= 10) {
                                        echo 'Math Explorer';
                                    } else {
                                        echo 'Math Beginner';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>

                        <div class="student-stats">
                            <div class="stat-card">
                                <div class="stat-value"><?= number_format($total_points) ?></div>
                                <div class="stat-label">Current Points</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $badges_count ?></div>
                                <div class="stat-label">Badges Earned</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $average_score ?>%</div>
                                <div class="stat-label">Average Score</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $learner_category ?></div>
                                <div class="stat-label">Learner Category</div>
                            </div>
                        </div>

                        <div class="details-grid">
                            <div class="detail-box">
                                <h3 class="detail-title">
                                    <i class="fas fa-id-card"></i> Student Information
                                </h3>
                                <div class="detail-item">
                                    <span class="detail-label">Full Name:</span>
                                    <span class="detail-value">
                                        <?= htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']) ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">LRN:</span>
                                    <span class="detail-value"><?= htmlspecialchars($student['lrn']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Passcode:</span>
                                    <span class="detail-value">
                                        <span id="student-passcode">••••••</span>
                                        <button class="passcode-toggle" onclick="togglePasscode()" data-passcode="<?= htmlspecialchars($student['passcode']) ?>">
                                            <i class="fas fa-eye"></i> Show
                                        </button>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Section:</span>
                                    <span class="detail-value">
                                        <?= htmlspecialchars(getGradeSection($student['section_name'])) ?> (<?= htmlspecialchars($student['school_year']) ?>)
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Teacher:</span>
                                    <span class="detail-value">
                                        <?= htmlspecialchars($student['teacher_first'] . ' ' . $student['teacher_last']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="detail-box">
                                <h3 class="detail-title">
                                    <i class="fas fa-chart-line"></i> Progress Summary
                                </h3>
                                <div class="detail-item">
                                    <span class="detail-label">Current Level:</span>
                                    <span class="detail-value"><?= $student_level ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Total Points:</span>
                                    <span class="detail-value"><?= number_format($total_points) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Points to Next Level:</span>
                                    <span class="detail-value">
                                        <?php
                                        if ($student_level >= 50) {
                                            echo 'Maximum Level (50)!';
                                        } else {
                                            $points_to_next = 20 - ($total_points % 20);
                                            echo $points_to_next . ' (Level ' . ($student_level + 1) . ')';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Learning Category:</span>
                                    <span class="detail-value"><?= $learner_category ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Theta (Ability):</span>
                                    <span class="detail-value"><?= number_format($theta, 2) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="quiz-history">
                            <h3 class="history-title">
                                <i class="fas fa-history"></i> Quiz History
                            </h3>

                            <?php if (empty($quiz_history)): ?>
                                <div class="no-quizzes">
                                    <i class="fas fa-clipboard-list"></i>
                                    <p>No quiz attempts yet.</p>
                                </div>
                                <?php else:
                                $quarters = [
                                    '1st' => [],
                                    '2nd' => [],
                                    '3rd' => [],
                                    '4th' => []
                                ];

                                foreach ($quiz_history as $quiz) {
                                    $quarter = $quiz['quarter'] ?? '1st';
                                    $week = $quiz['week'] ?? 1;

                                    if (!isset($quarters[$quarter][$week])) {
                                        $quarters[$quarter][$week] = [];
                                    }
                                    $quarters[$quarter][$week][] = $quiz;
                                }

                                foreach ($quarters as $quarter_name => $weeks):
                                    if (empty($weeks)) {
                                        continue;
                                    }

                                    $quarter_word = '';
                                    switch ($quarter_name) {
                                        case '1st':
                                            $quarter_word = 'First';
                                            break;
                                        case '2nd':
                                            $quarter_word = 'Second';
                                            break;
                                        case '3rd':
                                            $quarter_word = 'Third';
                                            break;
                                        case '4th':
                                            $quarter_word = 'Fourth';
                                            break;
                                        default:
                                            $quarter_word = $quarter_name;
                                            break;
                                    }
                                ?>
                                    <div class="quarter-section">
                                        <h4 class="quarter-title">
                                            <i class="fas fa-calendar-alt"></i> <?= $quarter_word ?> Quarter
                                        </h4>

                                        <?php
                                        ksort($weeks);
                                        foreach ($weeks as $week_num => $quizzes):
                                        ?>
                                            <div class="week-section">
                                                <h5 class="week-title">Week <?= $week_num ?></h5>

                                                <?php foreach ($quizzes as $quiz):
                                                    $quiz_date = !empty($quiz['date_taken']) ? date('M j, Y', strtotime($quiz['date_taken'])) : date('M j, Y', strtotime($quiz['quiz_date']));
                                                    $is_missed = $quiz['score'] === null;
                                                    $quiz_type_display = ucwords(str_replace('_', ' ', $quiz['quiz_type']));
                                                ?>
                                                    <div class="quiz-item <?= $is_missed ? 'quiz-missed' : '' ?>">
                                                        <div class="quiz-info">
                                                            <h4>
                                                                <?= htmlspecialchars($quiz_type_display) ?> Quiz
                                                                <span class="quiz-id">(#<?= $quiz['quiz_id'] ?>)</span>
                                                            </h4>
                                                            <span class="quiz-date"><?= $quiz_date ?></span>
                                                        </div>
                                                        <div class="quiz-score">
                                                            <?php
                                                            if ($is_missed) {
                                                                echo 'Missed';
                                                            } else {
                                                                // Make sure $quiz['score'] and $quiz['total_score'] exist
                                                                $earned = isset($quiz['score']) ? $quiz['score'] : 0;
                                                                $total = isset($quiz['total_score']) ? $quiz['total_score'] : 0;

                                                                echo htmlspecialchars($earned) . ' / ' . htmlspecialchars($total);
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-student">
                        <i class="fas fa-user-slash"></i>
                        <h3>Student not found</h3>
                        <p>The student profile you're looking for doesn't exist or has been removed.</p>
                        <a href="sections.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="../../assets/js/student/sidebar.js"></script>
    <script src="../../assets/js/student/profile.js"></script>
    <script>
        <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
            document.addEventListener('DOMContentLoaded', function() {
                closeEditModal();
            });
        <?php endif; ?>
    </script>
</body>

</html>