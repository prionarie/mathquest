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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_image'])) {

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {

        $fileTmpPath = $_FILES['profile_image']['tmp_name'];
        $fileSize    = $_FILES['profile_image']['size'];
        $fileExt     = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));

        $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($fileExt, $allowedExts)) {
            $error_message = "Invalid file type. Only JPG, JPEG, PNG, GIF allowed.";
        } elseif ($fileSize > 2 * 1024 * 1024) {
            $error_message = "File size must be less than 2MB.";
        } else {

            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/mathquest/assets/uploads/profile_picture/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $newFileName = 'teacher_' . $teacher_id . '.' . $fileExt;

            $destPath = $uploadDir . $newFileName;
            $relativePath = '/mathquest/assets/uploads/profile_picture/' . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {

                $stmt = $conn->prepare("UPDATE teachers SET profile_image=? WHERE teacher_id=?");
                $stmt->bind_param("si", $relativePath, $teacher_id);
                $stmt->execute();
                $stmt->close();

                $teacher_profile_image = $relativePath;
                $teacher_avatar = $relativePath;
                $success_message = "Profile image updated successfully.";
            } else {
                $error_message = "Failed to move uploaded file.";
            }
        }
    } else {
        $error_message = "No file uploaded or file upload error.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {

    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $position    = $_POST['position'] ?? '';
    $email       = trim($_POST['email'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($position) || empty($email)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ? AND teacher_id != ?");
        $stmt->bind_param("si", $email, $teacher_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error_message = "This email is already registered to another teacher.";
        } else {
            $stmt = $conn->prepare("UPDATE teachers SET first_name=?, middle_name=?, last_name=?, position=?, email=? WHERE teacher_id=?");
            $stmt->bind_param("sssssi", $first_name, $middle_name, $last_name, $position, $email, $teacher_id);

            if ($stmt->execute()) {
                $success_message = "Profile information updated successfully!";
                $_SESSION['teacher_name'] = trim($first_name . ' ' . $last_name);

                header("Refresh: 2");
            } else {
                $error_message = "Failed to update information. Please try again.";
            }
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$current_password || !$new_password || !$confirm_password) {
        $error_message = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New password and confirmation do not match.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif (!password_verify($current_password, $teacher_password_hash)) {
        $error_message = "Current password is incorrect.";
    } else {

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE teachers SET password_hash=? WHERE teacher_id=?");
        $stmt->bind_param("si", $new_hash, $teacher_id);

        if ($stmt->execute()) {
            $success_message = "Password updated successfully.";
        } else {
            $error_message = "Failed to update password. Please try again.";
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
    <title>Profile Settings | MathQuest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/teacher/profile.css">
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
                <a href="reports.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i><span>Reports & Analytics</span>
                </a>
                <a href="settings.php" class="nav-item active">
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
            <header class="top-header">
                <h1 class="page-title">Profile Settings</h1>
                <p class="page-subtitle">Update your profile information and password.</p>
            </header>

            <div class="content-area">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
                <?php elseif (!empty($error_message)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <div class="teacher-profile">
                    <div class="teacher-avatar">
                        <?php if ($teacher_avatar): ?>
                            <img id="teacher-avatar-img"
                                src="<?= htmlspecialchars($teacher_avatar) ?>"
                                alt="Profile Picture"
                                style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
                        <?php else: ?>
                            <i class="fas fa-chalkboard-teacher"></i>
                        <?php endif; ?>

                        <div class="upload-btn" onclick="uploadProfilePhoto()" title="Upload Profile Picture">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <h2 class="teacher-name"><?= htmlspecialchars($teacher_name) ?></h2>
                    <p class="teacher-id"><?= htmlspecialchars($teacher_position ?: 'Teacher') ?></p>
                </div>

                <form id="profile-image-form" action="" method="post" enctype="multipart/form-data" style="display:none;">
                    <input type="file" name="profile_image" id="profile-image-input" accept="image/*">
                    <input type="hidden" name="upload_profile_image" value="1">
                </form>

                <div class="action-buttons">
                    <button class="action-btn edit-btn" type="button" onclick="showEditForm()">
                        <i class="fas fa-edit"></i> Edit Information
                    </button>
                    <button class="action-btn change-pass-btn" type="button" onclick="showChangePasswordForm()">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>

                <div id="editForm" class="form-section">
                    <h3 class="form-title"><i class="fas fa-user-edit"></i> Edit Teacher Information</h3>
                    <form action="" method="post">
                        <input type="hidden" name="update_info" value="1">

                        <div class="form-group">
                            <label for="edit-first-name">First Name</label>
                            <input type="text"
                                id="edit-first-name"
                                name="first_name"
                                class="form-input"
                                value="<?= htmlspecialchars($first_name) ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="edit-middle-name">Middle Name</label>
                            <input type="text"
                                id="edit-middle-name"
                                name="middle_name"
                                class="form-input"
                                value="<?= htmlspecialchars($middle_name) ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit-last-name">Last Name</label>
                            <input type="text"
                                id="edit-last-name"
                                name="last_name"
                                class="form-input"
                                value="<?= htmlspecialchars($last_name) ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="edit-position">Position</label>
                            <select id="edit-position" name="position" class="form-input" required>
                                <?php
                                $positions = [
                                    'Teacher I',
                                    'Teacher II',
                                    'Teacher III',
                                    'Teacher IV',
                                    'Teacher V',
                                    'Teacher VI',
                                    'Master Teacher I',
                                    'Master Teacher II',
                                    'Master Teacher III',
                                    'Master Teacher IV',
                                    'Master Teacher V',
                                    'Master Teacher VI'
                                ];
                                foreach ($positions as $pos): ?>
                                    <option value="<?= $pos ?>" <?= ($teacher_position === $pos) ? 'selected' : '' ?>><?= $pos ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-email">Email Address</label>
                            <input type="email" id="edit-email" name="email" class="form-input" value="<?= htmlspecialchars($teacher_email ?? '') ?>" required>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="button" class="cancel-btn" onclick="hideAllForms()"><i class="fas fa-times"></i> Cancel</button>
                        </div>
                    </form>
                </div>

                <div id="passwordForm" class="form-section">
                    <h3 class="form-title"><i class="fas fa-shield-alt"></i> Change Password</h3>
                    <form action="" method="post">
                        <input type="hidden" name="change_password" value="1">

                        <div class="form-group">
                            <label for="current-password">Current Password</label>
                            <input type="password" id="current-password" name="current_password" class="form-input" placeholder="Enter current password" required>
                        </div>
                        <div class="form-group">
                            <label for="new-password">New Password</label>
                            <input type="password" id="new-password" name="new_password" class="form-input" placeholder="Enter new password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm-password">Confirm New Password</label>
                            <input type="password" id="confirm-password" name="confirm_password" class="form-input" placeholder="Confirm new password" required>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="save-btn"><i class="fas fa-key"></i> Update Password</button>
                            <button type="button" class="cancel-btn" onclick="hideAllForms()"><i class="fas fa-times"></i> Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
    </div>
    </div>
    </main>
    </div>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/profile.js"></script>
</body>

</html>