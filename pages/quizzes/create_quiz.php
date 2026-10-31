<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: ../login.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

$stmt = $conn->prepare("
    SELECT first_name, middle_name, last_name, position, profile_image
    FROM teachers
    WHERE teacher_id = ?
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    $first_name  = $row['first_name'];
    $last_name   = $row['last_name'];
    $teacher_name = trim($first_name . ' ' . $last_name);
    $teacher_position = $row['position'] ?: 'Teacher';
    $teacher_profile_image = $row['profile_image'];
}
$stmt->close();

$sidebar_initials = strtoupper(
    substr($first_name, 0, 1) . substr($last_name, 0, 1)
);

function getTeacherAvatar($profile_image)
{
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

$questions_query = $conn->prepare("
    SELECT q.question_id, q.content, q.difficulty, q.points
    FROM questions q
    ORDER BY q.difficulty, q.question_id
");
$questions_query->execute();
$questions_result = $questions_query->get_result();
$existing_questions = [];
while ($question = $questions_result->fetch_assoc()) {
    $existing_questions[] = $question;
}

function uploadQuizImage($file, $type, $question_num)
{
    if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024;

        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception("Invalid image type. Only JPG, PNG, and GIF are allowed.");
        }

        if ($file['size'] > $max_size) {
            throw new Exception("Image size exceeds 5MB limit.");
        }

        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/mathquest/assets/uploads/quiz_images/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'quiz_' . time() . '_' . $question_num . '_' . $type . '_' . uniqid() . '.' . $extension;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return '/mathquest/assets/uploads/quiz_images/' . $filename;
        }
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    if (empty($_POST['quarter']) || empty($_POST['week']) || empty($_POST['quiz_type'])) {
        $errors[] = "Please fill in all required fields";
    }

    if (empty($_POST['questions']) || !is_array($_POST['questions'])) {
        $errors[] = "Please add at least one question";
    } else {
        foreach ($_POST['questions'] as $index => $question) {
            if (empty($question['content'])) {
                $errors[] = "Question " . ($index + 1) . " content is required";
            }
            if (empty($question['correct_option'])) {
                $errors[] = "Question " . ($index + 1) . " must have a correct answer";
            }
            if (!isset($question['points']) || $question['points'] < 1) {
                $errors[] = "Question " . ($index + 1) . " must have at least 1 point";
            }

            if (empty($question['options']['A']) || empty($question['options']['B'])) {
                $errors[] = "Question " . ($index + 1) . " must have at least options A and B filled";
            }
        }
    }

    if (empty($_POST['sections']) || !is_array($_POST['sections'])) {
        $errors[] = "Please select at least one section";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            $total_points = 0;
            foreach ($_POST['questions'] as $question) {
                $total_points += (int)($question['points'] ?? 1);
            }

            $quiz_stmt = $conn->prepare("
                INSERT INTO quizzes (teacher_id, quarter, week, quiz_type, score)
                VALUES (?, ?, ?, ?, ?)
            ");
            $quiz_stmt->bind_param(
                "isisd",
                $teacher_id,
                $_POST['quarter'],
                $_POST['week'],
                $_POST['quiz_type'],
                $total_points
            );

            if (!$quiz_stmt->execute()) {
                throw new Exception("Failed to create quiz: " . $quiz_stmt->error);
            }

            $quiz_id = $conn->insert_id;
            $quiz_stmt->close();

            $question_number = 1;
            foreach ($_POST['questions'] as $question) {
                $content_image_path = '';
                $option_a_image = '';
                $option_b_image = '';
                $option_c_image = '';
                $option_d_image = '';



                if (
                    isset($_FILES['questions']['tmp_name'][$question_number - 1]['content_image']) &&
                    $_FILES['questions']['tmp_name'][$question_number - 1]['content_image']
                ) {
                    $content_image_path = uploadQuizImage(
                        [
                            'name' => $_FILES['questions']['name'][$question_number - 1]['content_image'],
                            'type' => $_FILES['questions']['type'][$question_number - 1]['content_image'],
                            'tmp_name' => $_FILES['questions']['tmp_name'][$question_number - 1]['content_image'],
                            'error' => $_FILES['questions']['error'][$question_number - 1]['content_image'],
                            'size' => $_FILES['questions']['size'][$question_number - 1]['content_image']
                        ],
                        'question',
                        $question_number
                    );
                }

                $option_images = ['A', 'B', 'C', 'D'];
                foreach ($option_images as $option) {
                    $file_key = 'option_' . strtolower($option) . '_image';
                    if (
                        isset($_FILES['questions']['tmp_name'][$question_number - 1][$file_key]) &&
                        $_FILES['questions']['tmp_name'][$question_number - 1][$file_key]
                    ) {
                        ${'option_' . strtolower($option) . '_image'} = uploadQuizImage(
                            [
                                'name' => $_FILES['questions']['name'][$question_number - 1][$file_key],
                                'type' => $_FILES['questions']['type'][$question_number - 1][$file_key],
                                'tmp_name' => $_FILES['questions']['tmp_name'][$question_number - 1][$file_key],
                                'error' => $_FILES['questions']['error'][$question_number - 1][$file_key],
                                'size' => $_FILES['questions']['size'][$question_number - 1][$file_key]
                            ],
                            'option_' . strtolower($option),
                            $question_number
                        );
                    }
                }

                if (!empty($question['question_id'])) {
                    $question_id = $question['question_id'];
                } else {

                    $question_stmt = $conn->prepare("
                        INSERT INTO questions (
                        content, content_image, 
                        option_a, option_a_image,
                        option_b, option_b_image,
                        option_c, option_c_image,
                        option_d, option_d_image,
                        correct_option, difficulty, points, hint
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $content = $question['content'];
                    $option_a = $question['options']['A'] ?? '';
                    $option_b = $question['options']['B'] ?? '';
                    $option_c = $question['options']['C'] ?? '';
                    $option_d = $question['options']['D'] ?? '';
                    $correct_option = $question['correct_option'];
                    $difficulty = $question['difficulty'];
                    $points = $question['points'];
                    $hint = $question['hint'] ?? '';

                    $question_stmt->bind_param(
                        "ssssssssssssis",
                        $content,
                        $content_image_path,
                        $option_a,
                        $option_a_image,
                        $option_b,
                        $option_b_image,
                        $option_c,
                        $option_c_image,
                        $option_d,
                        $option_d_image,
                        $correct_option,
                        $difficulty,
                        $points,
                        $hint
                    );

                    if (!$question_stmt->execute()) {
                        throw new Exception("Failed to create question: " . $question_stmt->error);
                    }

                    $question_id = $conn->insert_id;
                    $question_stmt->close();
                }

                $link_stmt = $conn->prepare("
                    INSERT INTO quiz_questions (quiz_id, question_id)
                    VALUES (?, ?)
                ");
                $link_stmt->bind_param("ii", $quiz_id, $question_id);

                if (!$link_stmt->execute()) {
                    throw new Exception("Failed to link question to quiz: " . $link_stmt->error);
                }
                $link_stmt->close();

                $question_number++;
            }

            foreach ($_POST['sections'] as $section_id) {
                $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;

                $section_stmt = $conn->prepare("
                    INSERT INTO quiz_sections (quiz_id, section_id, due_date)
                    VALUES (?, ?, ?)
                ");
                $section_stmt->bind_param("iis", $quiz_id, $section_id, $due_date);

                if (!$section_stmt->execute()) {
                    throw new Exception("Failed to assign quiz to sections: " . $section_stmt->error);
                }
                $section_stmt->close();
            }

            $conn->commit();

            header("Location: ../quizzes.php?success=Quiz created successfully!");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error creating quiz: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Create New Quiz | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/teacher/quizzes.css">
    <link rel="stylesheet" href="../../../assets/css/teacher/create_quiz.css">
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo" id="sidebarToggle">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="../dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i><span>Dashboard</span>
                </a>
                <a href="../sections.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i><span>Sections & Students</span>
                </a>
                <a href="../modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i><span>Learning Materials</span>
                </a>
                <a href="../quizzes.php" class="nav-item active">
                    <i class="fas fa-clipboard-list nav-icon"></i><span>Quizzes</span>
                </a>
                <a href="../reports.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i><span>Reports & Analytics</span>
                </a>
                <a href="../settings.php" class="nav-item">
                    <i class="fas fa-cog nav-icon"></i><span>Settings</span>
                </a>
                <a href="../logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i><span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="../settings.php">
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
                <h1>Create New Quiz</h1>
                <div class="header-info">
                    <div class="current-week-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Current: <?php echo $current_quarter; ?> Quarter, Week <?php echo $current_week; ?></span>
                    </div>
                    <a href="../quizzes.php" class="create-quiz-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Quizzes
                    </a>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="quiz-creation-container">
                <div class="creation-steps">
                    <div class="step active" id="step-1">
                        <div class="step-circle">1</div>
                        <div class="step-label">Basic Info</div>
                    </div>
                    <div class="step" id="step-2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Questions</div>
                    </div>
                    <div class="step" id="step-3">
                        <div class="step-circle">3</div>
                        <div class="step-label">Assign Sections</div>
                    </div>
                    <div class="step" id="step-4">
                        <div class="step-circle">4</div>
                        <div class="step-label">Review & Create</div>
                    </div>
                </div>

                <form id="quizCreationForm" method="POST" enctype="multipart/form-data" novalidate>
                    <!-- Step 1: Basic Information -->
                    <div class="creation-form">
                        <div class="form-step active" id="step1-content">
                            <h2>Quiz Information</h2>
                            <p class="text-muted" style="color: #666; margin-bottom: 20px;">Set up the basic details of your quiz</p>

                            <div class="form-group">
                                <label for="quarter">Quarter <span style="color: var(--danger-color);">*</span></label>
                                <select id="quarter" name="quarter" class="form-control" required>
                                    <option value="">Select Quarter</option>
                                    <option value="1st" <?php echo ($current_quarter == '1st') ? 'selected' : ''; ?>>1st Quarter</option>
                                    <option value="2nd" <?php echo ($current_quarter == '2nd') ? 'selected' : ''; ?>>2nd Quarter</option>
                                    <option value="3rd" <?php echo ($current_quarter == '3rd') ? 'selected' : ''; ?>>3rd Quarter</option>
                                    <option value="4th" <?php echo ($current_quarter == '4th') ? 'selected' : ''; ?>>4th Quarter</option>
                                </select>
                                <div class="error-message" id="quarter-error"></div>
                            </div>

                            <div class="form-group">
                                <label for="week">Week <span style="color: var(--danger-color);">*</span></label>
                                <select id="week" name="week" class="form-control" required>
                                    <option value="">Select Week</option>
                                    <?php for ($i = 1; $i <= 52; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($current_week == $i) ? 'selected' : ''; ?>>Week <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <div class="error-message" id="week-error"></div>
                            </div>

                            <div class="form-group">
                                <label>Quiz Type <span style="color: var(--danger-color);">*</span></label>
                                <div class="radio-group">
                                    <div class="radio-option">
                                        <input type="radio" id="type-mixed" name="quiz_type" value="Mixed" checked>
                                        <label class="radio-label" for="type-mixed">
                                            <div class="radio-icon"><i class="fas fa-random"></i></div>
                                            <div>Mixed</div>
                                            <small>Questions of various difficulties</small>
                                        </label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="type-easy" name="quiz_type" value="Easy">
                                        <label class="radio-label" for="type-easy">
                                            <div class="radio-icon"><i class="fas fa-seedling"></i></div>
                                            <div>Easy</div>
                                            <small>All easy questions</small>
                                        </label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="type-medium" name="quiz_type" value="Medium">
                                        <label class="radio-label" for="type-medium">
                                            <div class="radio-icon"><i class="fas fa-mountain"></i></div>
                                            <div>Medium</div>
                                            <small>All medium questions</small>
                                        </label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="type-hard" name="quiz_type" value="Hard">
                                        <label class="radio-label" for="type-hard">
                                            <div class="radio-icon"><i class="fas fa-fire"></i></div>
                                            <div>Hard</div>
                                            <small>All hard questions</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="error-message" id="type-error"></div>
                            </div>

                            <div class="form-navigation">
                                <div></div>
                                <button type="button" class="btn-next" onclick="nextStep(2)">Next: Add Questions <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>

                        <!-- Step 2: Add Questions -->
                        <div class="form-step" id="step2-content">
                            <h2>Add Questions</h2>
                            <p class="text-muted" style="color: #666; margin-bottom: 20px;">Add questions to your quiz. Each question can have an image and 4 options.</p>

                            <?php if (!empty($existing_questions)): ?>
                                <div class="question-bank">
                                    <h4><i class="fas fa-database"></i> Question Bank</h4>
                                    <p style="font-size: 13px; color: #666; margin-bottom: 15px;">Filter and select questions from your existing bank:</p>

                                    <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                                        <div style="flex: 1; min-width: 150px;">
                                            <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">Filter by Difficulty</label>
                                            <select id="filter-difficulty" class="form-control" style="padding: 8px; font-size: 13px;" onchange="filterQuestionBank()">
                                                <option value="all">All Difficulties</option>
                                                <option value="Easy">Easy</option>
                                                <option value="Medium">Medium</option>
                                                <option value="Hard">Hard</option>
                                            </select>
                                        </div>

                                        <div style="flex: 2; min-width: 200px;">
                                            <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">Search Question Text</label>
                                            <input type="text" id="search-question" class="form-control" style="padding: 8px; font-size: 13px;"
                                                placeholder="Type to search..." onkeyup="filterQuestionBank()">
                                        </div>

                                        <div style="flex: 1; min-width: 100px;">
                                            <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">Show</label>
                                            <select id="page-size" class="form-control" style="padding: 8px; font-size: 13px;" onchange="filterQuestionBank()">
                                                <option value="10">10 questions</option>
                                                <option value="20">20 questions</option>
                                                <option value="30">30 questions</option>
                                                <option value="40">40 questions</option>
                                                <option value="50">50 questions</option>
                                                <option value="all">All questions</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div id="question-bank-items" style="max-height: 300px; overflow-y: auto;">
                                        <?php

                                        $display_limit = 10;
                                        $displayed_count = 0;
                                        ?>
                                        <?php foreach ($existing_questions as $index => $question): ?>
                                            <?php if ($displayed_count < $display_limit): ?>
                                                <div class="question-bank-item"
                                                    data-difficulty="<?php echo $question['difficulty']; ?>"
                                                    data-content="<?php echo htmlspecialchars($question['content']); ?>">
                                                    <div class="question-bank-header">
                                                        <span class="existing-question-badge difficulty-<?php echo strtolower($question['difficulty']); ?>">
                                                            <?php echo $question['difficulty']; ?> (<?php echo $question['points']; ?> pts)
                                                        </span>
                                                        <small style="color: #999; font-size: 11px;">ID: <?php echo $question['question_id']; ?></small>
                                                    </div>
                                                    <div class="question-bank-content">
                                                        <?php echo htmlspecialchars(substr($question['content'], 0, 100)); ?>
                                                        <?php echo strlen($question['content']) > 100 ? '...' : ''; ?>
                                                    </div>
                                                    <div style="text-align: right; margin-top: 5px;">
                                                        <button type="button" class="btn btn-small"
                                                            onclick="addExistingQuestion(<?php echo htmlspecialchars(json_encode($question)); ?>)"
                                                            style="padding: 3px 10px; font-size: 11px;">
                                                            <i class="fas fa-plus"></i> Add to Quiz
                                                        </button>
                                                    </div>
                                                </div>
                                                <?php $displayed_count++; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                        <?php if (count($existing_questions) > $display_limit): ?>
                                            <div id="more-questions-message" style="text-align: center; padding: 15px; color: #666; font-style: italic;">
                                                Showing <?php echo $display_limit; ?> of <?php echo count($existing_questions); ?> questions.
                                                <br>Use filters to find specific questions.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div id="all-questions-data" style="display: none;">
                                        <?php foreach ($existing_questions as $question): ?>
                                            <div class="question-data-item"
                                                data-difficulty="<?php echo $question['difficulty']; ?>"
                                                data-content="<?php echo htmlspecialchars($question['content']); ?>"
                                                data-question='<?php echo json_encode($question); ?>'>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>


                            <div class="questions-container" id="questions-container">>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="addNewQuestion()" style="width: 100%;">
                                <i class="fas fa-plus"></i> Add New Question
                            </button>

                            <div class="preview-section">
                                <div class="preview-header">
                                    <h4>Quiz Preview</h4>
                                    <div class="total-score">Total: <span id="total-score">0</span> points</div>
                                </div>
                                <div id="questions-preview">
                                    <p style="color: #666; font-style: italic;">No questions added yet</p>
                                </div>
                            </div>

                            <div class="form-navigation">
                                <button type="button" class="btn btn-prev" onclick="prevStep(1)"><i class="fas fa-arrow-left"></i> Previous</button>
                                <button type="button" class="btn btn-next" onclick="nextStep(3)">Next: Assign Sections <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>
                        <!-- Step 3: Assign Sections -->
                        <div class="form-step" id="step3-content">
                            <h2>Assign to Sections</h2>
                            <p class="text-muted" style="color: #666; margin-bottom: 20px;">Select which sections will take this quiz</p>

                            <div class="form-group">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label>Select Sections <span style="color: var(--danger-color);">*</span></label>
                                    <div>
                                        <button type="button" class="btn btn-small" onclick="selectAllSections(true)" style="margin-right: 5px; padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-check-square"></i> Select All
                                        </button>
                                        <button type="button" class="btn btn-small" onclick="selectAllSections(false)" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-square"></i> Deselect All
                                        </button>
                                    </div>
                                </div>
                                <div class="section-list">
                                    <?php if (empty($sections)): ?>
                                        <p style="color: #666; font-style: italic;">No sections available. Please create sections first.</p>
                                    <?php else: ?>
                                        <?php foreach ($sections as $section): ?>
                                            <div class="section-item">
                                                <input type="checkbox"
                                                    id="section-<?php echo $section['section_id']; ?>"
                                                    name="sections[]"
                                                    value="<?php echo $section['section_id']; ?>">
                                                <label for="section-<?php echo $section['section_id']; ?>">
                                                    <?php echo htmlspecialchars($section['section_name']); ?>
                                                    <small style="color: #666;">(<?php echo $section['school_year']; ?>)</small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="error-message" id="sections-error"></div>
                            </div>

                            <div class="form-group">
                                <label for="due_date">Due Date (Optional)</label>
                                <input type="datetime-local" id="due_date" name="due_date" class="form-control">
                                <small style="color: #666;">Leave blank if there's no due date</small>
                            </div>

                            <div class="form-navigation">
                                <button type="button" class="btn-prev" onclick="prevStep(2)"><i class="fas fa-arrow-left"></i> Previous</button>
                                <button type="button" class="btn-next" onclick="nextStep(4)">Next: Review & Create <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>

                        <!-- Step 4: Review & Create -->
                        <div class="form-step" id="step4-content">
                            <h2>Review & Create Quiz</h2>
                            <p class="text-muted" style="color: #666; margin-bottom: 20px;">Review your quiz details before creating</p>

                            <div class="preview-section">
                                <h4>Quiz Summary</h4>
                                <div id="review-summary">

                                </div>
                            </div>

                            <div class="preview-section">
                                <h4>Questions Preview</h4>
                                <div id="final-questions-preview">

                                </div>
                            </div>

                            <div class="preview-section">
                                <h4>Assigned Sections</h4>
                                <div id="final-sections-preview">

                                </div>
                            </div>

                            <div class="form-navigation">
                                <button type="button" class="btn-prev" onclick="prevStep(3)"><i class="fas fa-arrow-left"></i> Previous</button>
                                <button type="submit" class="btn-submit" id="submit-quiz">
                                    <i class="fas fa-check"></i> Create Quiz
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <template id="question-template">
        <div class="question-card" data-question-index="{{index}}">
            <div class="question-header">
                <div class="question-number">Question #{{index}}</div>
                <div class="question-actions">
                    <button type="button" class="btn-icon btn-icon-primary" onclick="moveQuestionUp(this)">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button type="button" class="btn-icon btn-icon-primary" onclick="moveQuestionDown(this)">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button type="button" class="btn-icon btn-icon-danger" onclick="removeQuestion(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>

            <input type="hidden" name="questions[{{index}}][question_id]" value="">

            <div class="form-group">
                <label>Question Text <span style="color: var(--danger-color);">*</span></label>
                <textarea name="questions[{{index}}][content]" class="form-control" rows="3" placeholder="Enter your question here..." required></textarea>
            </div>

            <div class="form-group">
                <label>Question Image (Optional)</label>
                <input type="file" name="questions[{{index}}][content_image]" class="form-control" accept="image/*" onchange="previewImage(this)">
                <div class="image-upload">
                    <img class="image-preview" alt="Question image preview">
                </div>
            </div>

            <div class="form-group">
                <label>Difficulty <span style="color: var(--danger-color);">*</span></label>
                <select name="questions[{{index}}][difficulty]" class="form-control" required>
                    <option value="Easy">Easy</option>
                    <option value="Medium">Medium</option>
                    <option value="Hard">Hard</option>
                </select>
            </div>

            <div class="form-group">
                <label>Points <span style="color: var(--danger-color);">*</span></label>
                <input type="number" name="questions[{{index}}][points]" class="form-control" min="1" value="1" required>
            </div>

            <div class="form-group">
                <label>Answer Options <span style="color: var(--danger-color);">*</span></label>
                <div class="options-grid">
                    <?php
                    $options = ['A', 'B', 'C', 'D'];
                    foreach ($options as $option):
                        $isRequired = ($option === 'A' || $option === 'B') ? 'required' : '';
                    ?>
                        <div class="option-with-image">
                            <div class="option-item">
                                <input type="radio" name="questions[{{index}}][correct_option]" value="<?php echo $option; ?>" required>
                                <label><strong><?php echo $option; ?>:</strong></label>
                                <input type="text" name="questions[{{index}}][options][<?php echo $option; ?>]" class="form-control" placeholder="Option <?php echo $option; ?>" <?php echo $isRequired; ?>>
                            </div>
                            <div class="option-image-upload">
                                <input type="file" name="questions[{{index}}][option_<?php echo strtolower($option); ?>_image]" class="form-control" accept="image/*" onchange="previewOptionImage(this, '<?php echo $option; ?>')">
                                <img class="image-preview option-<?php echo strtolower($option); ?>-preview" alt="Option <?php echo $option; ?> image preview" style="max-width: 100px;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Hint for Wrong Answer (Optional)</label>
                <textarea name="questions[{{index}}][hint]" class="form-control" rows="2" placeholder="This hint will appear when a student gets this question wrong"></textarea>
            </div>
        </div>
    </template>

    <script src="../../../assets/js/teacher/sidebar.js"></script>
    <script>
        let currentStep = 1;
        let questionCount = 0;

        function nextStep(step) {
            if (!validateStep(currentStep)) {
                return false;
            }

            document.querySelectorAll('.step').forEach(stepEl => {
                stepEl.classList.remove('active');
            });
            document.getElementById(`step-${step}`).classList.add('active');

            document.querySelectorAll('.form-step').forEach(formStep => {
                formStep.classList.remove('active');
            });

            document.getElementById(`step${step}-content`).classList.add('active');

            currentStep = step;

            for (let i = 1; i < step; i++) {
                document.getElementById(`step-${i}`).classList.add('completed');
            }

            if (step === 4) {
                updateReviewSummary();
            }

            return false;
        }

        function prevStep(step) {
            document.querySelectorAll('.step').forEach(stepEl => {
                stepEl.classList.remove('active');
            });
            document.getElementById(`step-${step}`).classList.add('active');

            document.querySelectorAll('.form-step').forEach(formStep => {
                formStep.classList.remove('active');
            });

            document.getElementById(`step${step}-content`).classList.add('active');

            currentStep = step;

            for (let i = step + 1; i <= 4; i++) {
                document.getElementById(`step-${i}`).classList.remove('completed');
            }

            return false;
        }

        function validateStep(step) {
            let isValid = true;

            if (step === 1) {
                const quarter = document.getElementById('quarter').value;
                const week = document.getElementById('week').value;
                const quizType = document.querySelector('input[name="quiz_type"]:checked');

                if (!quarter) {
                    showError('quarter-error', 'Please select a quarter');
                    isValid = false;
                } else {
                    hideError('quarter-error');
                }

                if (!week) {
                    showError('week-error', 'Please select a week');
                    isValid = false;
                } else {
                    hideError('week-error');
                }

                if (!quizType) {
                    showError('type-error', 'Please select a quiz type');
                    isValid = false;
                } else {
                    hideError('type-error');
                }
            } else if (step === 2) {
                if (questionCount === 0) {
                    alert('Please add at least one question');
                    isValid = false;
                }
            } else if (step === 3) {
                const sectionCheckboxes = document.querySelectorAll('input[name="sections[]"]:checked');
                if (sectionCheckboxes.length === 0) {
                    showError('sections-error', 'Please select at least one section');
                    isValid = false;
                } else {
                    hideError('sections-error');
                }
            }

            return isValid;
        }

        function showError(elementId, message) {
            const errorElement = document.getElementById(elementId);
            errorElement.textContent = message;
            errorElement.classList.add('visible');
        }

        function hideError(elementId) {
            const errorElement = document.getElementById(elementId);
            errorElement.classList.remove('visible');
        }

        function addNewQuestion() {
            const template = document.getElementById('question-template').innerHTML;
            const questionIndex = questionCount;

            const questionHtml = template
                .replace(/{{index}}/g, questionIndex);

            const questionElement = document.createElement('div');
            questionElement.innerHTML = questionHtml;

            const questionCard = questionElement.firstElementChild;
            document.getElementById('questions-container').appendChild(questionCard);

            questionCard.querySelector('.question-number').textContent = `Question #${questionCount + 1}`;

            if (questionCount === 0) {
                const quizType = document.querySelector('input[name="quiz_type"]:checked');
                if (quizType && quizType.value !== 'Mixed') {
                    questionCard.querySelector('select[name^="questions"][name$="[difficulty]"]').value = quizType.value;
                }
            }

            questionCount++;
            updateQuestionsPreview();

            questionCard.scrollIntoView({
                behavior: 'smooth'
            });
            return false;
        }

        function addExistingQuestion(questionData) {
            const template = document.getElementById('question-template').innerHTML;
            const questionIndex = questionCount;

            let questionHtml = template
                .replace(/{{index}}/g, questionIndex);

            const questionElement = document.createElement('div');
            questionElement.innerHTML = questionHtml;

            const questionCard = questionElement.firstElementChild;

            questionCard.querySelector('.question-number').textContent = `Question #${questionCount + 1}`;

            questionCard.querySelector('textarea[name^="questions"][name$="[content]"]').value = questionData.content;
            questionCard.querySelector('input[name^="questions"][name$="[question_id]"]').value = questionData.question_id;
            questionCard.querySelector('select[name^="questions"][name$="[difficulty]"]').value = questionData.difficulty;
            questionCard.querySelector('input[name^="questions"][name$="[points]"]').value = questionData.points;

            const options = ['A', 'B', 'C', 'D'];
            const randomOption = options[Math.floor(Math.random() * options.length)];
            questionCard.querySelector(`input[name="questions[${questionIndex}][correct_option]"][value="${randomOption}"]`).checked = true;

            options.forEach(option => {
                const optionText = `Option ${option} for: "${questionData.content.substring(0, 30)}..."`;
                questionCard.querySelector(`input[name="questions[${questionIndex}][options][${option}]"]`).value = optionText;
            });

            document.getElementById('questions-container').appendChild(questionCard);
            questionCount++;
            updateQuestionsPreview();

            showToast('Question added from question bank');

            questionCard.scrollIntoView({
                behavior: 'smooth'
            });
            return false;
        }

        function removeQuestion(button) {
            if (questionCount <= 1) {
                alert('You must have at least one question');
                return false;
            }

            const questionCard = button.closest('.question-card');
            questionCard.remove();
            questionCount--;

            updateQuestionNumbers();
            updateQuestionsPreview();
            return false;
        }

        function moveQuestionUp(button) {
            const questionCard = button.closest('.question-card');
            const prevCard = questionCard.previousElementSibling;

            if (prevCard && prevCard.classList.contains('question-card')) {
                questionCard.parentNode.insertBefore(questionCard, prevCard);
                updateQuestionNumbers();
                updateQuestionsPreview();
            }
            return false;
        }

        function moveQuestionDown(button) {
            const questionCard = button.closest('.question-card');
            const nextCard = questionCard.nextElementSibling;

            if (nextCard && nextCard.classList.contains('question-card')) {
                questionCard.parentNode.insertBefore(nextCard, questionCard);
                updateQuestionNumbers();
                updateQuestionsPreview();
            }
            return false;
        }

        function updateQuestionNumbers() {
            const questionCards = document.querySelectorAll('.question-card');
            questionCards.forEach((card, index) => {
                // FIX: Display as index + 1
                card.querySelector('.question-number').textContent = `Question #${index + 1}`;

                const inputs = card.querySelectorAll('[name]');
                inputs.forEach(input => {
                    const oldName = input.name;
                    const newName = oldName.replace(/questions\[\d+\]/, `questions[${index}]`);
                    input.name = newName;
                });
            });
        }

        function updateQuestionsPreview() {
            const questionsContainer = document.getElementById('questions-container');
            const questionsPreview = document.getElementById('questions-preview');
            const totalScoreElement = document.getElementById('total-score');

            if (questionCount === 0) {
                questionsPreview.innerHTML = '<p style="color: #666; font-style: italic;">No questions added yet</p>';
                totalScoreElement.textContent = '0';
                return;
            }

            let totalPoints = 0;
            let previewHtml = '<div style="display: grid; gap: 10px;">';

            const questionCards = questionsContainer.querySelectorAll('.question-card');
            questionCards.forEach((card, index) => {
                const content = card.querySelector('textarea[name^="questions"][name$="[content]"]').value;
                const difficulty = card.querySelector('select[name^="questions"][name$="[difficulty]"]').value;
                const points = parseInt(card.querySelector('input[name^="questions"][name$="[points]"]').value) || 1;

                totalPoints += points;

                previewHtml += `
            <div style="padding: 10px; background: white; border-radius: 8px; border-left: 4px solid ${getDifficultyColor(difficulty)};">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <strong>Q${index + 1}:</strong>
                    <span style="padding: 2px 8px; border-radius: 10px; font-size: 12px; background: ${getDifficultyBgColor(difficulty)}; color: ${getDifficultyColor(difficulty)};">
                        ${difficulty} (${points} pts)
                    </span>
                </div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">
                    ${content.substring(0, 100)}${content.length > 100 ? '...' : ''}
                </div>
            </div>
        `;
            });

            previewHtml += '</div>';
            questionsPreview.innerHTML = previewHtml;
            totalScoreElement.textContent = totalPoints;
        }

        function getDifficultyColor(difficulty) {
            switch (difficulty) {
                case 'Easy':
                    return '#2EC4B6';
                case 'Medium':
                    return '#FF9F1C';
                case 'Hard':
                    return '#E71D36';
                default:
                    return '#6c757d';
            }
        }

        function getDifficultyBgColor(difficulty) {
            switch (difficulty) {
                case 'Easy':
                    return 'rgba(46, 196, 182, 0.1)';
                case 'Medium':
                    return 'rgba(255, 159, 28, 0.1)';
                case 'Hard':
                    return 'rgba(231, 29, 54, 0.1)';
                default:
                    return '#f5f5f5';
            }
        }

        function previewImage(input) {
            const preview = input.closest('.image-upload').querySelector('.image-preview');

            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.add('visible');
                };

                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.remove('visible');
            }
        }

        function previewOptionImage(input, option) {
            const preview = input.closest('.option-image-upload').querySelector(`.option-${option.toLowerCase()}-preview`);

            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.add('visible');
                };

                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.remove('visible');
            }
        }

        function updateReviewSummary() {
            const quarter = document.getElementById('quarter').value;
            const week = document.getElementById('week').value;
            const quizType = document.querySelector('input[name="quiz_type"]:checked').value;

            let totalPoints = 0;
            const questionCards = document.querySelectorAll('.question-card');
            questionCards.forEach(card => {
                const points = parseInt(card.querySelector('input[name^="questions"][name$="[points]"]').value) || 1;
                totalPoints += points;
            });

            const selectedSections = [];
            document.querySelectorAll('input[name="sections[]"]:checked').forEach(checkbox => {
                const label = checkbox.nextElementSibling.textContent.trim();
                selectedSections.push(label);
            });

            const summaryHtml = `
            <div style="display: grid; gap: 15px;">
                <div style="display: flex; justify-content: space-between; padding: 10px; background: white; border-radius: 8px;">
                    <span><strong>Quarter:</strong></span>
                    <span>${quarter}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: white; border-radius: 8px;">
                    <span><strong>Week:</strong></span>
                    <span>${week}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: white; border-radius: 8px;">
                    <span><strong>Quiz Type:</strong></span>
                    <span>${quizType}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: white; border-radius: 8px;">
                    <span><strong>Total Questions:</strong></span>
                    <span>${questionCount}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 10px; background: white; border-radius: 8px;">
                    <span><strong>Total Points:</strong></span>
                    <span>${totalPoints}</span>
                </div>
            </div>
        `;

            document.getElementById('review-summary').innerHTML = summaryHtml;

            updateQuestionsPreview();
            document.getElementById('final-questions-preview').innerHTML = document.getElementById('questions-preview').innerHTML;

            let sectionsHtml = '<div style="display: grid; gap: 10px;">';
            if (selectedSections.length > 0) {
                selectedSections.forEach(section => {
                    sectionsHtml += `
                    <div style="padding: 10px; background: white; border-radius: 8px; border-left: 4px solid var(--secondary-color);">
                        ${section}
                    </div>
                `;
                });
            } else {
                sectionsHtml += '<p style="color: #666; font-style: italic;">No sections selected</p>';
            }
            sectionsHtml += '</div>';
            document.getElementById('final-sections-preview').innerHTML = sectionsHtml;
        }

        function showToast(message) {
            const toast = document.createElement('div');
            toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 10000;
            animation: slideIn 0.3s ease;
        `;

            toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-check-circle"></i>
                <span>${message}</span>
            </div>
        `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function selectAllSections(selectAll) {
            document.querySelectorAll('input[name="sections[]"]').forEach(checkbox => {
                checkbox.checked = selectAll;
            });
            return false;
        }

        const style = document.createElement('style');
        style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
        document.head.appendChild(style);

        document.getElementById('questions-container').addEventListener('input', updateQuestionsPreview);
        document.getElementById('quarter').addEventListener('change', updateQuestionsPreview);
        document.getElementById('week').addEventListener('change', updateQuestionsPreview);

        document.addEventListener('DOMContentLoaded', function() {
            addNewQuestion();
        });

        function filterQuestionBank() {
            const difficultyFilter = document.getElementById('filter-difficulty').value;
            const searchText = document.getElementById('search-question').value.toLowerCase();
            const pageSize = document.getElementById('page-size').value;

            const questionBankContainer = document.getElementById('question-bank-items');
            const allQuestionsData = document.querySelectorAll('#all-questions-data .question-data-item');

            questionBankContainer.innerHTML = '';

            let displayedCount = 0;
            const maxDisplay = pageSize === 'all' ? allQuestionsData.length : parseInt(pageSize);

            allQuestionsData.forEach(item => {
                const difficulty = item.getAttribute('data-difficulty');
                const content = item.getAttribute('data-content').toLowerCase();
                const questionData = JSON.parse(item.getAttribute('data-question'));

                let shouldDisplay = true;

                if (difficultyFilter !== 'all' && difficulty !== difficultyFilter) {
                    shouldDisplay = false;
                }

                if (searchText && !content.includes(searchText)) {
                    shouldDisplay = false;
                }

                if (shouldDisplay && displayedCount < maxDisplay) {
                    const questionHtml = `
                <div class="question-bank-item">
                    <div class="question-bank-header">
                        <span class="existing-question-badge difficulty-${difficulty.toLowerCase()}">
                            ${difficulty} (${questionData.points} pts)
                        </span>
                        <small style="color: #999; font-size: 11px;">ID: ${questionData.question_id}</small>
                    </div>
                    <div class="question-bank-content">
                        ${content.substring(0, 100)}${content.length > 100 ? '...' : ''}
                    </div>
                    <div style="text-align: right; margin-top: 5px;">
                        <button type="button" class="btn btn-small" 
                                onclick="addExistingQuestion(${JSON.stringify(questionData)})"
                                style="padding: 3px 10px; font-size: 11px;">
                            <i class="fas fa-plus"></i> Add to Quiz
                        </button>
                    </div>
                </div>
            `;

                    const div = document.createElement('div');
                    div.innerHTML = questionHtml;
                    questionBankContainer.appendChild(div.firstElementChild);
                    displayedCount++;
                }
            });

            if (displayedCount === 0) {
                questionBankContainer.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                No questions found matching your filters.
            </div>
        `;
            } else if (displayedCount < allQuestionsData.length && pageSize !== 'all') {
                const moreMsg = document.createElement('div');
                moreMsg.innerHTML = `
            <div style="text-align: center; padding: 15px; color: #666; font-style: italic;">
                Showing ${displayedCount} of ${allQuestionsData.length} questions.
                ${searchText ? 'Try a different search term.' : 'Use filters to find more questions.'}
            </div>
        `;
                questionBankContainer.appendChild(moreMsg);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('filter-difficulty')) {
                filterQuestionBank();
            }
        });
    </script>
</body>

</html>