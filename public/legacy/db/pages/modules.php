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
$teacher_password_hash = '';
$first_name = '';
$middle_name = '';
$last_name = '';
$success_message = '';
$error_message = '';

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

    $teacher_position       = $row['position'] ?: 'Teacher';
    $teacher_profile_image  = $row['profile_image'];
    $teacher_email          = $row['email'];
    $teacher_password_hash  = $row['password_hash'];

    $_SESSION['teacher_name'] = $teacher_name;
}
$stmt->close();

$sidebar_initials = strtoupper(
    substr($first_name, 0, 1) . substr($last_name, 0, 1)
);

function getTeacherAvatar($profile_image)
{
    if (!$profile_image) {
        return null;
    }
    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $profile_image;
    return file_exists($absolutePath) ? $profile_image : null;
}
$teacher_avatar = getTeacherAvatar($teacher_profile_image);

$settings_query = "SELECT * FROM academic_settings ORDER BY setting_id DESC LIMIT 1";
$settings_result = $conn->query($settings_query);
$settings = $settings_result->fetch_assoc();

$settings_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $current_quarter = $_POST['current_quarter'];
    $current_week = (int)$_POST['current_week'];

    if ($settings) {
        $stmt = $conn->prepare("UPDATE academic_settings SET current_quarter = ?, current_week = ?, updated_by = ? WHERE setting_id = ?");
        $stmt->bind_param("siii", $current_quarter, $current_week, $teacher_id, $settings['setting_id']);
    } else {

        $stmt = $conn->prepare("INSERT INTO academic_settings (current_quarter, current_week, updated_by) VALUES (?, ?, ?)");
        $stmt->bind_param("sii", $current_quarter, $current_week, $teacher_id);
    }

    if ($stmt->execute()) {
        $settings_message = '<div class="alert alert-success">Current quarter/week updated successfully!</div>';
        $settings_query = "SELECT * FROM academic_settings ORDER BY setting_id DESC LIMIT 1";
        $settings_result = $conn->query($settings_query);
        $settings = $settings_result->fetch_assoc();
        echo '<meta http-equiv="refresh" content="0">';
    } else {
        $settings_message = '<div class="alert alert-error">Error updating settings: ' . $conn->error . '</div>';
    }
}

if ($settings) {
    $current_quarter = $settings['current_quarter'];
    $current_week = $settings['current_week'];
} else {
    $current_quarter = '1st';
    $current_week = 1;
}

$upload_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_material'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $type = $_POST['type'];
    $quarter = $_POST['quarter'];
    $week = $_POST['week'];

    $file_path = null;
    $url = null;
    if ($type === 'Website/Link') {

        $raw_url = trim(html_entity_decode($_POST['url'] ?? '', ENT_QUOTES));
        $url = filter_var($raw_url, FILTER_VALIDATE_URL);

        if (!$url) {
            $upload_message = '<div class="alert alert-error">Please enter a valid URL.</div>';
            $error = true;
        }
    } else {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $allowed_extensions = [];
            switch ($type) {
                case 'Module':
                    $allowed_extensions = ['pdf', 'docx'];
                    break;
                case 'Presentation':
                    $allowed_extensions = ['pdf', 'ppt', 'pptx'];
                    break;
                case 'Video':
                    $allowed_extensions = ['mp4', 'webm', 'avi', 'mov'];
                    break;
                case 'Image':
                    $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                    break;
            }

            $file_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_extensions)) {

                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/mathquest/assets/uploads/learning_materials/';

                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $upload_message = '<div class="alert alert-error">Could not create upload directory.</div>';
                    }
                }

                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $_FILES['file']['name']);
                $target_file = $upload_dir . $filename;
                $file_path = '/mathquest/assets/uploads/learning_materials/' . $filename;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
                    $upload_message = '<div class="alert alert-error">Error uploading file. Please check directory permissions.</div>';
                }
            } else {
                $upload_message = '<div class="alert alert-error">Invalid file type for selected material type.</div>';
            }
        } else {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
            ];

            $error_code = isset($_FILES['file']) ? $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
            $error_msg = isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : 'Please select a file to upload.';
            $upload_message = '<div class="alert alert-error">' . $error_msg . '</div>';
        }
    }

    if (!$upload_message) {
        $stmt = $conn->prepare("INSERT INTO learning_materials (teacher_id, title, description, type, quarter, week, file_path, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssiss", $teacher_id, $title, $description, $type, $quarter, $week, $file_path, $url);

        if ($stmt->execute()) {
            $upload_message = '<div class="alert alert-success">Material uploaded successfully!</div>';
            echo '<meta http-equiv="refresh" content="0">';
        } else {
            $upload_message = '<div class="alert alert-error">Error saving to database: ' . $conn->error . '</div>';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_material'])) {
    $material_id = $_POST['material_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $type = $_POST['type'];
    $quarter = $_POST['quarter'];
    $week = (int)$_POST['week'];

    $check_stmt = $conn->prepare("SELECT file_path, url FROM learning_materials WHERE material_id = ? AND teacher_id = ?");
    $check_stmt->bind_param("ii", $material_id, $teacher_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 1) {
        $material = $check_result->fetch_assoc();

        $file_path = $material['file_path'];
        $url = $material['url'];
        $error = false;

        if ($type === 'Website/Link') {

            $raw_url = trim(html_entity_decode($_POST['url'] ?? '', ENT_QUOTES));
            $new_url = filter_var($raw_url, FILTER_VALIDATE_URL);

            if (!$new_url) {
                $upload_message = '<div class="alert alert-error">Please enter a valid URL.</div>';
                $error = true;
            } else {
                $url = $new_url;

                if ($file_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $file_path);
                }
                $file_path = null;
            }
        } else {
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $allowed_extensions = [];
                switch ($type) {
                    case 'Module':
                        $allowed_extensions = ['pdf', 'docx'];
                        break;
                    case 'Presentation':
                        $allowed_extensions = ['pdf', 'ppt', 'pptx'];
                        break;
                    case 'Video':
                        $allowed_extensions = ['mp4', 'webm', 'avi', 'mov'];
                        break;
                    case 'Image':
                        $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                        break;
                }

                $file_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

                if (in_array($file_ext, $allowed_extensions)) {
                    if ($file_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $file_path);
                    }

                    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/mathquest/assets/uploads/learning_materials/';
                    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $_FILES['file']['name']);
                    $target_file = $upload_dir . $filename;
                    $file_path = '/mathquest/assets/uploads/learning_materials/' . $filename;

                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
                        $upload_message = '<div class="alert alert-error">Error uploading file. Please check directory permissions.</div>';
                        $error = true;
                    }
                } else {
                    $upload_message = '<div class="alert alert-error">Invalid file type for selected material type.</div>';
                    $error = true;
                }
            } elseif ($material['url'] && $type !== 'Website/Link') {
                $upload_message = '<div class="alert alert-error">Please upload a file when changing from website to file type.</div>';
                $error = true;
            }
        }

        if (!$error) {
            $update_stmt = $conn->prepare("UPDATE learning_materials SET title = ?, description = ?, type = ?, quarter = ?, week = ?, file_path = ?, url = ? WHERE material_id = ? AND teacher_id = ?");
            $update_stmt->bind_param("sssssssii", $title, $description, $type, $quarter, $week, $file_path, $url, $material_id, $teacher_id);

            if ($update_stmt->execute()) {
                $upload_message = '<div class="alert alert-success">Material updated successfully!</div>';
                echo '<meta http-equiv="refresh" content="0">';
            } else {
                $upload_message = '<div class="alert alert-error">Error updating material: ' . $conn->error . '</div>';
            }
        }
    } else {
        $upload_message = '<div class="alert alert-error">Material not found or unauthorized.</div>';
    }
}

$stmt = $conn->prepare("SELECT * FROM learning_materials WHERE teacher_id = ? ORDER BY 
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
foreach ($materials as $material) {
    if ($material['quarter'] == $current_quarter && $material['week'] == $current_week) {
        $current_material = $material;
        break;
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
    <link rel="stylesheet" href="../../assets/css/teacher/modules.css">
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
                <a href="modules.php" class="nav-item active">
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
            <div class="page-header">
                <h1>Learning Materials</h1>
                <div class="header-info">
                    <div class="current-week-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="current-display">Current: <?php echo $current_quarter; ?> Quarter, Week <?php echo $current_week; ?></span>
                    </div>
                    <button class="btn btn-primary" onclick="openUploadModal()">
                        <i class="fas fa-upload"></i>
                        Upload Material
                    </button>
                </div>
            </div>

            <?php echo $settings_message; ?>
            <?php echo $upload_message; ?>

            <div class="settings-section">
                <div class="settings-header">
                    <h3><i class="fas fa-cog"></i> Current Academic Settings</h3>
                </div>
                <form method="POST" action="" id="settingsForm">
                    <div class="settings-controls">
                        <div class="setting-group">
                            <label for="current_quarter">Current Quarter:</label>
                            <select id="current_quarter" name="current_quarter" class="setting-select" required>
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

                        <div class="setting-group">
                            <label for="current_week">Current Week:</label>
                            <select id="current_week" name="current_week" class="setting-select" required>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $current_week == $i ? 'selected' : ''; ?>>
                                        Week <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <button type="submit" name="update_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update Settings
                        </button>
                    </div>
                    <div class="settings-info">
                        <i class="fas fa-info-circle"></i>
                        This sets the current quarter and week that will be featured for students.
                    </div>
                </form>
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
                                <button class="btn btn-secondary" onclick="downloadMaterial(<?php echo $current_material['material_id']; ?>)">
                                    <i class="fas fa-download"></i>
                                    Download
                                </button>
                            <?php elseif ($current_material['url']): ?>
                                <a href="<?= htmlspecialchars($current_material['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt"></i>
                                    Visit Website
                                </a>
                            <?php endif; ?>

                            <button class="btn btn-secondary btn-small"
                                onclick="editMaterial(
                                <?php echo $material['material_id']; ?>,
                                '<?php echo htmlspecialchars($material['title'], ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars($material['description'], ENT_QUOTES); ?>',
                                '<?php echo $material['type']; ?>',
                                '<?php echo $material['quarter']; ?>',
                                <?php echo $material['week']; ?>,
                                '<?php echo htmlspecialchars($material['file_path'] ?? '', ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars($material['url'] ?? '', ENT_QUOTES); ?>'
                                )">
                                <i class="fas fa-edit"></i>
                                Edit
                            </button>
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
                            <?php foreach ($quarters as $q): ?>
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
                                <button class="action-btn download" onclick="downloadMaterial(<?= $material['material_id'] ?>)">
                                    <i class="fas fa-download"></i> Download
                                </button>
                            <?php elseif ($material['url']): ?>
                                <a href="<?= htmlspecialchars($material['url']); ?>" target="_blank" rel="noopener noreferrer" class="action-btn visit">
                                    <i class="fas fa-external-link-alt"></i> Visit Link
                                </a>
                            <?php endif; ?>

                            <button class="action-btn edit" onclick="editMaterial(<?= $material['material_id']; ?>, '<?= htmlspecialchars($material['title'], ENT_QUOTES); ?>', '<?= htmlspecialchars($material['description'], ENT_QUOTES); ?>', '<?= $material['type']; ?>', '<?= $material['quarter']; ?>', <?= $material['week']; ?>, '<?= htmlspecialchars($material['file_path'] ?? '', ENT_QUOTES); ?>', '<?= htmlspecialchars($material['url'] ?? '', ENT_QUOTES); ?>')">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="action-btn delete" onclick="deleteMaterial(<?= $material['material_id']; ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="no-results" id="no-results" style="display: <?php echo empty($materials) ? 'block' : 'none'; ?>;">
                <i class="fas fa-search"></i>
                <h3>No Learning Materials Found</h3>
                <p>Try adjusting your filters or upload new materials.</p>
            </div>
        </main>
    </div>

    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Upload Learning Material</h2>
                <button type="button" class="close-modal" onclick="closeUploadModal()">&times;</button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-form">
                    <div class="form-group full-width">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" class="form-control" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Quarter *</label>
                        <div class="quarter-selector">
                            <?php foreach ($quarters as $q): ?>
                                <label class="quarter-option <?php echo $q == $current_quarter ? 'active' : ''; ?>">
                                    <input type="radio" name="quarter" value="<?php echo $q; ?>"
                                        <?php echo $q == $current_quarter ? 'checked' : ''; ?> required>
                                    <?php echo $q; ?> Quarter
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="week">Week *</label>
                        <input type="number" id="week" name="week" class="form-control"
                            min="1" max="52" step="1"
                            value="<?php echo $current_week; ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="type">Material Type *</label>
                        <select id="type" name="type" class="form-control" required onchange="toggleUploadFields()">
                            <option value="">Select Type</option>
                            <option value="Module">Module (DOCX/PDF)</option>
                            <option value="Presentation">Presentation (PDF/PPT/PPTX)</option>
                            <option value="Video">Video (MP4/WebM/AVI/MOV)</option>
                            <option value="Image">Image (PNG/JPG/GIF/SVG)</option>
                            <option value="Website/Link">Website/Link</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" placeholder="Brief description of the material..."></textarea>
                    </div>

                    <div class="form-group full-width" id="fileUploadGroup">
                        <label for="file">File Upload *</label>
                        <input type="file" id="file" name="file" class="form-control">
                        <div class="file-preview" id="filePreview"></div>
                    </div>

                    <div class="form-group full-width" id="urlGroup" style="display: none;">
                        <label for="url">Website URL *</label>
                        <input type="url" id="url" name="url" class="form-control" placeholder="https://example.com">
                    </div>

                    <div class="form-group full-width">
                        <button type="submit" name="upload_material" class="btn btn-primary" style="width: 100%;" id="uploadSubmitBtn" onclick="submitUploadForm()">
                            <i class="fas fa-upload"></i>
                            Upload Material
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Learning Material</h2>
                <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" id="edit_material_id" name="material_id">

                <div class="upload-form">
                    <div class="form-group full-width">
                        <label for="edit_title">Title *</label>
                        <input type="text" id="edit_title" name="title" class="form-control" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Quarter *</label>
                        <div class="quarter-selector" id="editQuarterSelector">
                            <?php foreach ($quarters as $q): ?>
                                <label class="quarter-option">
                                    <input type="radio" name="quarter" value="<?php echo $q; ?>" required>
                                    <?php echo $q; ?> Quarter
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="edit_week">Week *</label>
                        <input type="number" id="edit_week" name="week" class="form-control"
                            min="1" max="52" step="1" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_type">Material Type *</label>
                        <select id="edit_type" name="type" class="form-control" required onchange="toggleEditUploadFields()">
                            <option value="">Select Type</option>
                            <option value="Module">Module (DOCX/PDF)</option>
                            <option value="Presentation">Presentation (PDF/PPT/PPTX)</option>
                            <option value="Video">Video (MP4/WebM/AVI/MOV)</option>
                            <option value="Image">Image (PNG/JPG/GIF/SVG)</option>
                            <option value="Website/Link">Website/Link</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" placeholder="Brief description of the material..."></textarea>
                    </div>

                    <div class="form-group full-width" id="editFileUploadGroup">
                        <label for="edit_file">File Upload (Leave empty to keep current file)</label>
                        <input type="file" id="edit_file" name="file" class="form-control">
                        <div class="file-preview" id="editFilePreview"></div>
                        <div class="current-file-info" id="editCurrentFileInfo" style="margin-top: 10px; padding: 8px; background: #f5f5f5; border-radius: 4px; display: none;">
                            <small><strong>Current file:</strong> <span id="currentFileName"></span></small>
                        </div>
                    </div>

                    <div class="form-group full-width" id="editUrlGroup" style="display: none;">
                        <label for="edit_url">Website URL *</label>
                        <input type="url" id="edit_url" name="url" class="form-control" placeholder="https://example.com">
                    </div>

                    <div class="form-group full-width">
                        <button type="submit" name="edit_material" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-save"></i>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/modules.js"></script>
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