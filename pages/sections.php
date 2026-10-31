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

$action = $_GET['action'] ?? 'list';
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : null;

$success = $error = $student_success = '';

function archiveStudent(mysqli $conn, int $student_id, string $school_year): bool
{
    $stmt = $conn->prepare("
        SELECT student_id, lrn, first_name, middle_name, last_name,
               section_id, passcode, learner_category
        FROM students
        WHERE student_id = ? AND archived = 0
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $student = $res->fetch_assoc();
    $stmt->close();

    if (!$student) return false;

    $insert = $conn->prepare("
        INSERT INTO student_archives
        (student_id, lrn, first_name, middle_name, last_name,
         section_id, school_year, passcode, learner_category, archived_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $insert->bind_param(
        "issssisss",
        $student['student_id'],
        $student['lrn'],
        $student['first_name'],
        $student['middle_name'],
        $student['last_name'],
        $student['section_id'],
        $school_year,
        $student['passcode'],
        $student['learner_category']
    );

    if (!$insert->execute()) {
        $insert->close();
        return false;
    }
    $insert->close();

    $upd = $conn->prepare("UPDATE students SET archived = 1 WHERE student_id = ?");
    $upd->bind_param("i", $student_id);
    $ok = $upd->execute();
    $upd->close();

    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_section'])) {
        $section_name = trim($_POST['section_name']);
        $school_year = trim($_POST['school_year'] ?? '');

        if ($section_name === '' || $school_year === '') {
            $error = "Section name and school year are required.";
        } else {
            $stmt_check = $conn->prepare("SELECT section_id FROM sections WHERE section_name = ? AND teacher_id = ? AND school_year = ?");
            $stmt_check->bind_param("sis", $section_name, $teacher_id, $school_year);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();
            if ($res_check->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO sections (section_name, teacher_id, school_year) VALUES (?, ?, ?)");
                $stmt->bind_param("sis", $section_name, $teacher_id, $school_year);
                if ($stmt->execute()) $success = "Section created successfully.";
                else $error = "Failed to create section.";
                $stmt->close();
            } else {
                $error = "Section with that name and school year already exists.";
            }
            $stmt_check->close();
        }
    }

    // Add Student
    if (isset($_POST['add_student'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $lrn = trim($_POST['lrn'] ?? '');
        $section_id_post = intval($_POST['section_id'] ?? 0);

        if ($first_name === '' || $last_name === '' || $lrn === '' || $section_id_post <= 0) {
            $error = "Please fill in all required fields.";
        } elseif (!preg_match('/^\d{12}$/', $lrn)) {
            $error = "LRN must be exactly 12 digits.";
        } else {
            // Check school-wide LRN uniqueness
            $stmt_check = $conn->prepare("SELECT student_id FROM students WHERE lrn = ?");
            $stmt_check->bind_param("s", $lrn);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();

            if ($res_check->num_rows === 0) {
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $passcode = '';
                for ($i = 0; $i < 6; $i++) {
                    $passcode .= $chars[random_int(0, strlen($chars) - 1)];
                }

                $stmt = $conn->prepare("INSERT INTO students (section_id, lrn, first_name, middle_name, last_name, passcode) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $section_id_post, $lrn, $first_name, $middle_name, $last_name, $passcode);
                if ($stmt->execute()) {
                    $student_success = "Student added successfully. <br><strong>LRN:</strong> " . htmlspecialchars($lrn) . "<br><strong>Passcode:</strong> " . htmlspecialchars($passcode);
                } else {
                    $error = "Failed to add student.";
                }
                $stmt->close();
            } else {
                $error = "LRN already exists.";
            }
            $stmt_check->close();
        }
    }

    // Edit Student
    if (isset($_POST['edit_student'])) {
        $student_id = intval($_POST['edit_student_id']);
        $first_name = trim($_POST['edit_first_name'] ?? '');
        $middle_name = trim($_POST['edit_middle_name'] ?? '');
        $last_name = trim($_POST['edit_last_name'] ?? '');
        $lrn = trim($_POST['edit_lrn'] ?? '');
        $section_id_post = intval($_POST['edit_section_id'] ?? 0);
        $learner_category = $_POST['edit_learner_category'] ?? 'Average Learner';

        if ($student_id <= 0 || $first_name === '' || $last_name === '' || $lrn === '' || $section_id_post <= 0) {
            $error = "Please fill in all required fields for edit.";
        } elseif (!preg_match('/^\d{12}$/', $lrn)) {
            $error = "LRN must be exactly 12 digits.";
        } else {
            // Check school-wide LRN uniqueness (excluding current student)
            $stmt_check = $conn->prepare("SELECT student_id FROM students WHERE lrn = ? AND student_id != ?");
            $stmt_check->bind_param("si", $lrn, $student_id);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();

            if ($res_check->num_rows > 0) {
                $error = "This LRN already belongs to another student.";
            } else {
                $stmt = $conn->prepare("UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, lrn = ?, section_id = ?, learner_category = ? WHERE student_id = ?");
                $stmt->bind_param("ssssisi", $first_name, $middle_name, $last_name, $lrn, $section_id_post, $learner_category, $student_id);
                if ($stmt->execute()) $success = "Student updated successfully.";
                else $error = "Failed to update student.";
                $stmt->close();
            }
            $stmt_check->close();
        }
    }

    if (isset($_POST['reset_passcode'])) {
        $student_id = intval($_POST['student_id'] ?? 0);
        if ($student_id <= 0) {
            $error = "Invalid student.";
        } else {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $new_passcode = '';
            for ($i = 0; $i < 6; $i++) $new_passcode .= $chars[random_int(0, strlen($chars) - 1)];

            $stmt = $conn->prepare("UPDATE students SET passcode = ? WHERE student_id = ?");
            $stmt->bind_param("si", $new_passcode, $student_id);
            if ($stmt->execute()) $success = "Passcode reset successfully. New passcode: $new_passcode";
            else $error = "Failed to reset passcode.";
            $stmt->close();
        }
    }

    if (isset($_POST['delete_student'])) {
        $student_id = intval($_POST['student_id'] ?? 0);
        if ($student_id <= 0) {
            $error = "Invalid student ID.";
        } else {
            $stmt = $conn->prepare("SELECT s.section_id, s.school_year FROM students st JOIN sections s ON st.section_id = s.section_id WHERE st.student_id = ?");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            $school_year = $row['school_year'] ?? '';

            if ($school_year === '') {
                $error = "Could not determine school year for student.";
            } else {
                $conn->begin_transaction();
                $ok = archiveStudent($conn, $student_id, $school_year);
                if ($ok) {
                    $conn->commit();
                    $success = "Student archived successfully.";
                } else {
                    $conn->rollback();
                    $error = "Failed to archive student.";
                }
            }
        }
    }

    if (isset($_POST['delete_student_list'])) {
        $section_to_clear = intval($_POST['section_id'] ?? 0);
        if ($section_to_clear <= 0) {
            $error = "Invalid section.";
        } else {
            $stmt = $conn->prepare("SELECT school_year FROM sections WHERE section_id = ? AND teacher_id = ?");
            $stmt->bind_param("ii", $section_to_clear, $teacher_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $sec = $res->fetch_assoc();
            $stmt->close();

            if (!$sec) {
                $error = "Section not found or not owned by you.";
            } else {
                $school_year = $sec['school_year'];
                $stmt = $conn->prepare("SELECT student_id FROM students WHERE section_id = ?");
                $stmt->bind_param("i", $section_to_clear);
                $stmt->execute();
                $result_students = $stmt->get_result();
                $student_ids = [];
                while ($r = $result_students->fetch_assoc()) $student_ids[] = intval($r['student_id']);
                $stmt->close();

                if (empty($student_ids)) {
                    $success = "No students to archive in this section.";
                } else {
                    $conn->begin_transaction();
                    $all_ok = true;
                    foreach ($student_ids as $sid) {
                        if (!archiveStudent($conn, $sid, $school_year)) {
                            $all_ok = false;
                            break;
                        }
                    }
                    if ($all_ok) {
                        $conn->commit();
                        $success = "Student list archived successfully.";
                    } else {
                        $conn->rollback();
                        $error = "Failed to archive the entire student list. No changes were saved.";
                    }
                }
            }
        }
    }

    if (isset($_POST['edit_section'])) {
        $section_id_post = intval($_POST['edit_section_id'] ?? 0);
        $section_name = trim($_POST['edit_section_name'] ?? '');
        $school_year = trim($_POST['edit_school_year'] ?? '');

        if ($section_id_post <= 0 || $section_name === '' || $school_year === '') {
            $error = "Section name and school year are required.";
        } else {
            $stmt = $conn->prepare("UPDATE sections SET section_name = ?, school_year = ? WHERE section_id = ? AND teacher_id = ?");
            $stmt->bind_param("ssii", $section_name, $school_year, $section_id_post, $teacher_id);
            if ($stmt->execute()) $success = "Section updated successfully.";
            else $error = "Failed to update section.";
            $stmt->close();
        }
    }

    if (isset($_POST['archive_section'])) {
        $section_id = (int)$_POST['section_id'];

        $stmt = $conn->prepare("SELECT * FROM students WHERE section_id = ?");
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $stmt->close();

        while ($student = $result->fetch_assoc()) {
            $stmt2 = $conn->prepare("INSERT INTO student_archives (student_id, lrn, first_name, middle_name, last_name, section_id, school_year, passcode, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt2->bind_param("issssiss", $student['student_id'], $student['lrn'], $student['first_name'], $student['middle_name'], $student['last_name'], $student['section_id'], $student['school_year'], $student['passcode']);
            $stmt2->execute();
            $stmt2->close();
        }

        $stmt3 = $conn->prepare("DELETE FROM students WHERE section_id = ?");
        $stmt3->bind_param("i", $section_id);
        $stmt3->execute();
        $stmt3->close();

        $stmt4 = $conn->prepare("UPDATE sections SET archived = 1 WHERE section_id = ?");
        $stmt4->bind_param("i", $section_id);
        $stmt4->execute();
        $stmt4->close();

        $_SESSION['success'] = "Section and all its students have been archived.";
        header("Location: sections.php");
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT s.section_id, s.section_name, s.school_year,
           (SELECT COUNT(*) FROM students WHERE section_id = s.section_id) AS student_count
    FROM sections s
    WHERE s.teacher_id = ? AND s.archived = 0
    ORDER BY s.section_name
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$sections = $stmt->get_result();
$stmt->close();

$sections_array = [];
if ($sections && $sections->num_rows > 0) {
    $sections->data_seek(0);
    while ($r = $sections->fetch_assoc()) $sections_array[] = $r;
}

$section_ids = [];

foreach ($sections_array as $sec) {
    $section_ids[] = (int)$sec['section_id'];
}

$students_query = "
    SELECT st.*, sec.section_name
    FROM students st
    LEFT JOIN sections sec ON st.section_id = sec.section_id
";
$params = [];
if ($section_id) {
    $students_query .= " WHERE st.section_id = ?";
    $params[] = $section_id;
}
$students_query .= " ORDER BY st.last_name, st.first_name";

if (count($params) === 1) {
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $params[0]);
    $stmt->execute();
    $students = $stmt->get_result();
    $stmt->close();
} else {
    $students = $conn->query($students_query);
}

$learner_categories = ['Average Learner', 'Slow Learner', 'Fast Learner'];

$class_average_score = "0.00";

if (!empty($section_ids)) {
    $ids = implode(',', $section_ids);

    $query_avg = "
        SELECT 
            SUM(sq.score) AS total_earned,
            SUM(q.score) AS total_possible
        FROM student_quizzes sq
        JOIN students st ON sq.student_id = st.student_id
        JOIN quizzes q ON sq.quiz_id = q.quiz_id
        JOIN quiz_attempts qa ON sq.student_id = qa.student_id AND sq.quiz_id = qa.quiz_id
        WHERE st.section_id IN ($ids)
          AND sq.score IS NOT NULL
          AND qa.is_completed = 1
    ";

    $res = $conn->query($query_avg);

    if ($res && $row = $res->fetch_assoc()) {
        $total_earned = (float)$row['total_earned'] ?? 0;
        $total_possible = (float)$row['total_possible'] ?? 0;

        if ($total_possible > 0) {
            $class_average_score = number_format(($total_earned / $total_possible) * 100, 2);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Sections & Students | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
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
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i><span>Dashboard</span>
                </a>
                <a href="sections.php" class="nav-item active">
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
                <div>
                    <h1 class="page-title">Sections & Students</h1>
                    <p class="page-subtitle">Manage class sections and student accounts</p>
                </div>
            </div>

            <div class="content-area">
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                <?php endif; ?>
                <?php if ($student_success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $student_success ?></div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?php echo count($sections_array); ?></div>
                                <div class="stat-label">Total Sections</div>
                            </div>
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?php echo $students ? $students->num_rows : 0; ?></div>
                                <div class="stat-label">Total Students</div>
                            </div>
                            <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?= $class_average_score ?>%</div>
                                <div class="stat-label">Avg. Score</div>
                            </div>
                            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        </div>
                    </div>
                </div>

                <div class="quick-actions">
                    <button class="action-btn" onclick="openModal('createSectionModal')">
                        <div class="action-icon"><i class="fas fa-plus"></i></div>
                        <div class="action-text">Create Section</div>
                        <div class="action-desc">Add new class section</div>
                    </button>

                    <button class="action-btn" onclick="openModal('addStudentModal')">
                        <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="action-text">Add Student</div>
                        <div class="action-desc">Create student account</div>
                    </button>

                    <a href="student_archives.php" class="action-btn">
                        <div class="action-icon"><i class="fas fa-archive"></i></div>
                        <div class="action-text">View Archives</div>
                        <div class="action-desc">View archived classes and students</div>
                    </a>
                </div>

                <div id="sectionsTab" class="tab-content" style="<?= $action == 'list' ? 'display: block;' : 'display: none;' ?>">
                    <div class="data-table">
                        <div class="table-header">
                            <h2 class="table-title">Class Sections</h2>
                            <div class="table-actions" style="display:flex; gap:5px; align-items:center;">
                                <button class="btn btn-primary" onclick="openModal('createSectionModal')">
                                    <i class="fas fa-plus"></i> Create Section
                                </button>
                                <input type="text" class="table-search" placeholder="Search sections..." id="searchSections" onkeyup="filterSections()">
                            </div>
                        </div>

                        <div class="table-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Section Name</th>
                                        <th>Student Count</th>
                                        <th>School Year</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="sectionsTableBody">
                                    <?php if (count($sections_array) > 0): ?>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($sections_array as $section): ?>
                                            <tr>
                                                <td><?= $counter++ ?></td>
                                                <td><strong><?= htmlspecialchars($section['section_name']) ?></strong></td>
                                                <td><?= (int)$section['student_count'] ?> students</td>
                                                <td><?= htmlspecialchars($section['school_year']) ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-secondary" title="View Students" onclick="window.location='?action=students&section_id=<?= $section['section_id'] ?>#studentsTab'">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>

                                                        <button class="btn btn-primary" title="Edit Section" onclick="openEditSectionModal(<?= $section['section_id'] ?>, '<?= htmlspecialchars(addslashes($section['section_name'])) ?>', '<?= htmlspecialchars($section['school_year']) ?>')">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button class="btn btn-danger" title="Archive Section"
                                                            onclick="confirmArchiveSection(<?= $section['section_id'] ?>, '<?= htmlspecialchars(addslashes($section['section_name'])) ?>')">
                                                            <i class="fas fa-archive"></i> Archive
                                                        </button>


                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align:center; padding:40px;">
                                                <div style="color: var(--muted-text); margin-bottom:20px;"><i class="fas fa-users fa-3x" style="opacity:0.5"></i></div>
                                                <h3 style="color: var(--dark-text); margin-bottom:10px;">No Sections Yet</h3>
                                                <p style="color: var(--muted-text); margin-bottom:20px;">Create a class section to get started.</p>
                                                <button class="btn btn-primary" onclick="openModal('createSectionModal')">
                                                    <i class="fas fa-plus"></i> Create Section
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="filters" id="studentFilters" style="<?= $action != 'students' ? 'display: none;' : '' ?>">
                    <div class="filter-group">
                        <div class="filter-label">Filter by Category:</div>
                        <select class="select-input" onchange="filterByCategory(this.value)" style="width: 200px;">
                            <option value="">All Categories</option>
                            <?php foreach ($learner_categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <div class="filter-label">Search:</div>
                        <input type="text" class="form-input" id="searchInput" placeholder="Search students..." style="width: 250px;" onkeyup="searchStudents()">
                    </div>

                    <a href="sections.php#sectionsTab" class="btn btn-primary btn-small">
                        <i class="fas fa-arrow-left"></i> Back to Sections
                    </a>
                </div>

                <div id="studentsTab" class="tab-content" style="<?= $action == 'students' ? 'display: block;' : 'display: none;' ?>">
                    <div class="data-table">
                        <div class="table-header">
                            <h2 class="table-title">Student List</h2>
                            <div class="table-actions">
                                <button class="btn btn-primary" onclick="openModal('addStudentModal')"><i class="fas fa-user-plus"></i> Add Student</button>
                                <?php if ($section_id): ?>
                                    <button class="btn btn-secondary" onclick="confirmArchiveStudentList(<?= $section_id ?>, '')"><i class="fas fa-archive"></i> Archive Student List</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Name</th>
                                        <th>LRN</th>
                                        <th>Section</th>
                                        <th>Category</th>
                                        <th>Passcode</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="studentsTableBody">
                                    <?php if ($students && $students->num_rows > 0): ?>
                                        <?php $students->data_seek(0);
                                        $counter = 1;
                                        while ($student = $students->fetch_assoc()):
                                            $category_class = 'category-average';
                                            if ($student['learner_category'] === 'Slow Learner') $category_class = 'category-slow';
                                            if ($student['learner_category'] === 'Fast Learner') $category_class = 'category-fast';
                                        ?>
                                            <tr data-section="<?= (int)$student['section_id'] ?>" data-category="<?= htmlspecialchars($student['learner_category']) ?>">
                                                <td><strong><?php echo $counter++; ?></strong></td>
                                                <td>
                                                    <div style="font-weight:600"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></div>
                                                    <div style="font-size:0.85rem; color:var(--muted-text)"><?= htmlspecialchars($student['middle_name']) ?></div>
                                                </td>
                                                <td><?= htmlspecialchars($student['lrn']) ?></td>
                                                <td><?= htmlspecialchars($student['section_name'] ?? 'Unassigned') ?></td>
                                                <td><span class="status-badge <?= $category_class ?>"><?= htmlspecialchars($student['learner_category'] ?? 'Average Learner') ?></span></td>
                                                <td>
                                                    <div class="passcode-display" style="font-size:1rem"><?= htmlspecialchars($student['passcode']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons-2">
                                                        <button class="btn-icon view" title="View Profile" onclick="viewStudentProfile('<?php echo $student['student_id']; ?>')">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn-icon edit" title="Edit Student" onclick="openEditStudentModal(
                                                        <?= (int)$student['student_id'] ?>,
                                                        '<?= htmlspecialchars(addslashes($student['first_name'])) ?>',
                                                        '<?= htmlspecialchars(addslashes($student['middle_name'] ?? '')) ?>',
                                                        '<?= htmlspecialchars(addslashes($student['last_name'])) ?>',
                                                        '<?= htmlspecialchars($student['lrn']) ?>',
                                                        <?= (int)$student['section_id'] ?>,
                                                        '<?= htmlspecialchars($student['learner_category'] ?? 'Average Learner') ?>'
                                                    )"><i class="fas fa-edit"></i></button>

                                                        <button class="btn-icon delete" title="Archive Student" onclick="confirmArchiveStudent(<?= (int)$student['student_id'] ?>, '<?= htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])) ?>')"><i class="fas fa-archive"></i></button>

                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="student_id" value="<?= (int)$student['student_id'] ?>">
                                                            <button type="submit" class="btn-icon reset" title="Reset Passcode" name="reset_passcode">
                                                                <i class="fas fa-key"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" style="text-align:center; padding:40px;">
                                                <div style="color: var(--muted-text); margin-bottom:20px;"><i class="fas fa-user-graduate fa-3x" style="opacity:0.5"></i></div>
                                                <h3 style="color:var(--dark-text); margin-bottom:10px;">No Students Yet</h3>
                                                <p style="color:var(--muted-text); margin-bottom:20px;">Add a student to get started.</p>
                                                <button class="btn btn-primary" onclick="openModal('addStudentModal')"><i class="fas fa-user-plus"></i>Add Student</button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-overlay" id="createSectionModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title">Create Section</h2>
                            <p>Add a new class section</p>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group"><label class="form-label">Section Name *</label><input class="form-input" name="section_name" required></div>
                                <div class="form-group"><label class="form-label">School Year *</label><input class="form-input" name="school_year" placeholder="2024-2025" required></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('createSectionModal')">Cancel</button>
                                <button type="submit" class="btn btn-primary" name="create_section">Create Section</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal-overlay" id="addStudentModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title">Add Student</h2>
                            <p>Create student account and passcode</p>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="section_id" value="<?= $section_id ?>">
                                <div class="form-row">
                                    <div class="form-group"><label class="form-label">First Name *</label><input class="form-input" name="first_name" required></div>
                                    <div class="form-group"><label class="form-label">Middle Name</label><input class="form-input" name="middle_name"></div>
                                </div>
                                <div class="form-group"><label class="form-label">Last Name *</label><input class="form-input" name="last_name" required></div>
                                <div class="form-group">
                                    <label class="form-label">LRN *</label>
                                    <input
                                        class="form-input"
                                        name="lrn"
                                        required
                                        pattern="[0-9]{12}"
                                        maxlength="12"
                                        minlength="12"
                                        inputmode="numeric"
                                        title="LRN must be exactly 12 digits">
                                </div>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('addStudentModal')">Cancel</button>
                                <button type="submit" class="btn btn-primary" name="add_student">Add Student</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal-overlay" id="editStudentModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title">Edit Student</h2>
                            <p>Update student info</p>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="edit_student_id" id="editStudentId">
                                <div class="form-row">
                                    <div class="form-group"><label class="form-label">First Name *</label><input class="form-input" name="edit_first_name" id="editFirstName" required></div>
                                    <div class="form-group"><label class="form-label">Middle Name</label><input class="form-input" name="edit_middle_name" id="editMiddleName"></div>
                                </div>
                                <div class="form-group"><label class="form-label">Last Name *</label><input class="form-input" name="edit_last_name" id="editLastName" required></div>
                                <div class="form-group">
                                    <label class="form-label">LRN *</label>
                                    <input
                                        class="form-input"
                                        name="edit_lrn"
                                        id="editLRN"
                                        required
                                        pattern="[0-9]{12}"
                                        maxlength="12"
                                        minlength="12"
                                        inputmode="numeric"
                                        title="LRN must be exactly 12 digits">
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Section *</label>
                                        <select class="select-input" name="edit_section_id" id="editSectionId" required>
                                            <option value="">Select Section</option>
                                            <?php foreach ($sections_array as $s): ?>
                                                <option value="<?= (int)$s['section_id'] ?>"><?= htmlspecialchars($s['section_name'] . ' — ' . $s['school_year']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Learner Category</label>
                                        <select class="select-input" name="edit_learner_category" id="editLearnerCategory">
                                            <?php foreach ($learner_categories as $category): ?>
                                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
                                <button type="submit" class="btn btn-primary" name="edit_student">Update Student</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal-overlay" id="editSectionModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title">Edit Section</h2>
                            <p>Update section</p>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="edit_section_id" id="editSectionIdField">
                                <div class="form-group"><label class="form-label">Section Name *</label><input class="form-input" name="edit_section_name" id="editSectionName" required></div>
                                <div class="form-group"><label class="form-label">School Year *</label><input class="form-input" name="edit_school_year" id="editSectionYear" required></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('editSectionModal')">Cancel</button>
                                <button type="submit" class="btn btn-primary" name="edit_section">Update Section</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal-overlay" id="archiveSectionModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title">Archive Section</h2>
                            <p>Archiving a section will also archive all students in it. This action cannot be undone.</p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="section_id" id="archiveSectionId">
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('archiveSectionModal')">Cancel</button>
                                <button type="submit" class="btn btn-danger" name="archive_section">Archive</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
    </div>
    </main>
    </div>
    <script src="../../assets/js/teacher/sidebar.js"></script>
    <script src="../../assets/js/teacher/sections.js"></script>
</body>

</html>