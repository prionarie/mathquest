<?php
session_start();
require '../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: logout.php');
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
    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $profile_image;
    return file_exists($absolutePath) ? $profile_image : null;
}
$teacher_avatar = getTeacherAvatar($teacher_profile_image);

$section_id = isset($_GET['section_id']) && is_numeric($_GET['section_id']) ? intval($_GET['section_id']) : null;
$search = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;

$stmt = $conn->prepare("SELECT section_id, section_name, school_year FROM sections WHERE teacher_id = ? ORDER BY section_name");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$sections_result = $stmt->get_result();
$sections_array = [];
while ($s = $sections_result->fetch_assoc()) {
    $sections_array[] = $s;
}
$stmt->close();

$sql = "
    SELECT sa.*, s.section_name
    FROM student_archives sa
    JOIN sections s ON sa.section_id = s.section_id
    WHERE s.teacher_id = ?
";
$params = [$teacher_id];
$types = "i";

if ($section_id) {
    $sql .= " AND sa.section_id = ?";
    $types .= "i";
    $params[] = $section_id;
}

if ($search) {
    $sql .= " AND (sa.first_name LIKE ? OR sa.middle_name LIKE ? OR sa.last_name LIKE ? OR sa.lrn LIKE ?)";
    $types .= "ssss";
    $likeSearch = "%$search%";
    $params = array_merge($params, [$likeSearch, $likeSearch, $likeSearch, $likeSearch]);
}

$sql .= " ORDER BY sa.archived_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $archives = $stmt->get_result();
    $stmt->close();
} else {
    $archives = null;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Student Archives | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/teacher/sections.css">
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
                <h1 class="page-title">Student Archives</h1>
                <p class="page-subtitle">View student lists from previous school years</p>
            </div>

            <div class="content-area">
                <div class="filters">
                    <div class="filter-group">
                        <div class="filter-label">Filter by Section:</div>
                        <select class="select-input" onchange="window.location='?section_id='+this.value+'&search=<?= urlencode($search ?? '') ?>'">
                            <option value="">All Sections</option>
                            <?php foreach ($sections_array as $s): ?>
                                <option value="<?= $s['section_id'] ?>" <?= $section_id == $s['section_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['section_name'] . ' — ' . $s['school_year']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <div class="filter-label">Search:</div>
                        <div style="display:flex; gap:5px; align-items:center;">
                            <input type="text" class="form-input" id="searchInput" placeholder="Search students..."
                                value="<?= htmlspecialchars($search ?? '') ?>"
                                onkeyup="if(event.key==='Enter'){ window.location='?section_id=<?= $section_id ?? '' ?>&search='+encodeURIComponent(this.value) }">
                            <button type="button" class="btn btn-secondary btn-small" onclick="window.location='?section_id=<?= $section_id ?? '' ?>'">
                                Clear
                            </button>
                        </div>
                    </div>

                    <a href="sections.php" class="btn btn-primary btn-small"><i class="fas fa-arrow-left"></i> Back to Sections</a>
                </div>

                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Archived At</th>
                                <th>Name</th>
                                <th>LRN</th>
                                <th>Section</th>
                                <th>School Year</th>
                                <th>Passcode</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($archives && $archives->num_rows > 0): ?>
                                <?php while ($a = $archives->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['archived_at']) ?></td>
                                        <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['middle_name'] . ' ' . $a['last_name']) ?></td>
                                        <td><?= htmlspecialchars($a['lrn']) ?></td>
                                        <td><?= htmlspecialchars($a['section_name']) ?></td>
                                        <td><?= htmlspecialchars($a['school_year']) ?></td>
                                        <td><?= htmlspecialchars($a['passcode']) ?></td>
                                        <td>
                                            <a href="student_profile.php?student_id=<?= $a['student_id'] ?>" class="btn btn-secondary btn-small">
                                                <i class="fas fa-eye"></i> View Profile
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:40px;">No archived students found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>
    </div>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/sections.js"></script>
</body>

</html>