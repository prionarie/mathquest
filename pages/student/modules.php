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

$teacher_id = null;
$teacher_stmt = $conn->prepare("
    SELECT t.teacher_id 
    FROM students s
    JOIN sections sec ON s.section_id = sec.section_id
    JOIN teachers t ON sec.teacher_id = t.teacher_id
    WHERE s.student_id = ?
");
$teacher_stmt->bind_param("i", $student_id);
$teacher_stmt->execute();
$teacher_stmt->bind_result($teacher_id);
$teacher_stmt->fetch();
$teacher_stmt->close();

// Fetch all learning materials from the student's teacher
$stmt = $conn->prepare("SELECT * FROM learning_materials 
    WHERE teacher_id = ? 
    ORDER BY 
        CASE quarter 
            WHEN '1st' THEN 1
            WHEN '2nd' THEN 2
            WHEN '3rd' THEN 3
            WHEN '4th' THEN 4
        END DESC,
        week DESC, 
        uploaded_at DESC");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$materials = $result->fetch_all(MYSQLI_ASSOC);

$current_material = null;
if ($teacher_id) {
    foreach ($materials as $material) {
        if ($material['quarter'] == $current_quarter && $material['week'] == $current_week) {
            $current_material = $material;
            break;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Learning Materials | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/student/modules.css">
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
                <a href="modules.php" class="nav-item active">
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
            <div class="page-header">
                <h1>Learning Materials</h1>
                <div class="header-info">
                    <div class="current-week-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="current-display">Current: <?php echo $current_quarter; ?> Quarter, Week <?php echo $current_week; ?></span>
                    </div>
                </div>
            </div>

            <?php if ($current_material): ?>
                <div class="featured-section">
                    <div class="featured-label">
                        <i class="fas fa-star"></i>
                        Featured This Week
                    </div>
                    <div class="featured-header">
                        <h2><?php echo htmlspecialchars($current_material['title']); ?></h2>
                        <div class="featured-meta">
                            <span class="meta-tag">
                                <i class="fas fa-layer-group"></i>
                                <?php echo $current_material['quarter']; ?> Quarter
                            </span>
                            <span class="meta-tag">
                                <i class="fas fa-calendar-week"></i>
                                Week <?php echo $current_material['week']; ?>
                            </span>
                            <span class="meta-tag">
                                <i class="fas fa-tag"></i>
                                <?php echo $current_material['type']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="featured-content">
                        <?php if ($current_material['description']): ?>
                            <p><?php echo htmlspecialchars($current_material['description']); ?></p>
                        <?php endif; ?>
                        <div class="featured-actions">
                            <?php
                            $file_ext = strtolower(pathinfo($current_material['file_path'] ?? '', PATHINFO_EXTENSION));
                            $viewable_exts = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'mp4', 'webm', 'mov', 'avi'];
                            ?>
                            <?php if ($current_material['file_path']): ?>
                                <?php if (in_array($file_ext, $viewable_exts)): ?>
                                    <a href="<?= htmlspecialchars($current_material['file_path']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                                        <i class="fas fa-eye"></i>
                                        View
                                    </a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($current_material['file_path']) ?>" download class="btn btn-secondary">
                                    <i class="fas fa-download"></i>
                                    Download
                                </a>
                            <?php elseif ($current_material['url']): ?>
                                <a href="<?= htmlspecialchars($current_material['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt"></i>
                                    Visit Website
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="filters-section">
                <div class="filters-header">
                    <h3><i class="fas fa-filter"></i> Filter Materials</h3>
                </div>
                <div class="filter-controls">
                    <div class="filter-group">
                        <label for="quarter-filter"><i class="fas fa-layer-group"></i> Quarter</label>
                        <select id="quarter-filter" class="filter-select">
                            <option value="all">All Quarters</option>
                            <?php
                            $quarters = ['1st', '2nd', '3rd', '4th'];
                            foreach ($quarters as $q):
                            ?>
                                <option value="<?php echo $q; ?>" <?php echo $current_quarter == $q ? 'selected' : ''; ?>>
                                    <?php echo $q; ?> Quarter
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="week-filter"><i class="fas fa-calendar-week"></i> Week</label>
                        <select id="week-filter" class="filter-select">
                            <option value="all">All Weeks</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $current_week == $i ? 'selected' : ''; ?>>
                                    Week <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="type-filter"><i class="fas fa-tag"></i> Material Type</label>
                        <select id="type-filter" class="filter-select">
                            <option value="all">All Types</option>
                            <option value="Module">Module</option>
                            <option value="Presentation">Presentation</option>
                            <option value="Video">Video</option>
                            <option value="Image">Image</option>
                            <option value="Website/Link">Website/Link</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="materials-grid" id="materials-container">
                <?php if ($teacher_id && !empty($materials)): ?>
                    <?php foreach ($materials as $material): ?>
                        <div class="material-card <?php echo ($material['quarter'] == $current_quarter && $material['week'] == $current_week) ? 'current' : ''; ?>">
                            <div class="material-badge <?php echo strtolower(str_replace(['/', ' '], '-', $material['type'])); ?>-badge">
                                <i class="<?php echo getTypeIcon($material['type']); ?>"></i>
                                <?php echo $material['type']; ?>
                            </div>
                            <div class="material-header">
                                <div class="material-quarter">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo $material['quarter']; ?> Quarter
                                </div>
                                <h3 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h3>
                            </div>
                            <div class="material-meta">
                                <div class="material-week">
                                    <i class="fas fa-calendar-week"></i>
                                    Week <?php echo $material['week']; ?>
                                </div>
                                <div class="material-date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($material['uploaded_at'])); ?>
                                </div>
                            </div>
                            <?php if ($material['description']): ?>
                                <p class="material-description"><?php echo htmlspecialchars($material['description']); ?></p>
                            <?php endif; ?>
                            <div class="material-actions">
                                <?php
                                $file_ext = strtolower(pathinfo($material['file_path'] ?? '', PATHINFO_EXTENSION));
                                $viewable_exts = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'mp4', 'webm', 'mov', 'avi'];
                                ?>
                                <?php if ($material['file_path']): ?>
                                    <?php if (in_array($file_ext, $viewable_exts)): ?>
                                        <a href="<?= htmlspecialchars($material['file_path']) ?>" target="_blank" rel="noopener noreferrer" class="action-btn view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($material['file_path']) ?>" download class="action-btn download">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php elseif ($material['url']): ?>
                                    <a href="<?= htmlspecialchars($material['url']); ?>" target="_blank" rel="noopener noreferrer" class="action-btn visit">
                                        <i class="fas fa-external-link-alt"></i> Visit Link
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-materials-message">
                        <i class="fas fa-book"></i>
                        <h3>No Learning Materials Available</h3>
                        <p>Your teacher hasn't uploaded any materials yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../../assets/js/student/sidebar.js"></script>
    <script src="../../assets/js/student/modules.js"></script>
    <script>
        const CURRENT_QUARTER = '<?php echo $current_quarter; ?>';
        const CURRENT_WEEK = <?php echo $current_week; ?>;
    </script>
    <?php
    function getTypeIcon($type)
    {
        switch ($type) {
            case 'Module':
                return 'fas fa-book';
            case 'Presentation':
                return 'fas fa-chalkboard-teacher';
            case 'Video':
                return 'fas fa-video';
            case 'Image':
                return 'fas fa-image';
            case 'Website/Link':
                return 'fas fa-link';
            default:
                return 'fas fa-file';
        }
    }
    ?>

</body>

</html>