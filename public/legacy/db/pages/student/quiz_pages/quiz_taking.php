<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['student_id']) || !isset($_GET['quiz_id']) || !isset($_GET['attempt_id'])) {
    header('Location: ../login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$quiz_id = (int)$_GET['quiz_id'];
$attempt_id = (int)$_GET['attempt_id'];

$stmt = $conn->prepare("
    SELECT 1 FROM quiz_attempts 
    WHERE attempt_id = ? AND student_id = ? AND quiz_id = ? AND is_completed = 0
");
$stmt->bind_param("iii", $attempt_id, $student_id, $quiz_id);
$stmt->execute();
$valid_attempt = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$valid_attempt) {
    header('Location: ../quizzes.php?error=invalid_attempt');
    exit;
}

$stmt = $conn->prepare("
    SELECT q.quiz_type, q.quarter, q.week,
           COUNT(DISTINCT qq.question_id) as total_questions,
           sec.section_id
    FROM quizzes q
    JOIN quiz_sections qs ON q.quiz_id = qs.quiz_id
    JOIN sections sec ON qs.section_id = sec.section_id
    LEFT JOIN quiz_questions qq ON q.quiz_id = qq.quiz_id
    WHERE q.quiz_id = ?
    GROUP BY q.quiz_id, q.quiz_type, q.quarter, q.week, sec.section_id
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$quiz_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
    SELECT s.first_name, s.last_name, s.current_level, s.current_points,
           s.learner_category, s.theta, s.p_value
    FROM students s
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$student_name = trim($student['first_name'] . ' ' . $student['last_name']);

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT question_id) as answered
    FROM quiz_logs 
    WHERE student_id = ? AND quiz_id = ? AND is_correct = 1
");
$stmt->bind_param("ii", $student_id, $quiz_id);
$stmt->execute();
$progress = $stmt->get_result()->fetch_assoc();
$stmt->close();

$answered = $progress['answered'] ?? 0;
$total = $quiz_info['total_questions'] ?? 0;
$progress_percent = $total > 0 ? round(($answered / $total) * 100) : 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) as completed_questions
    FROM quiz_attempts
    WHERE attempt_id = ? AND is_completed = 1
");
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$attempt_info = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Taking Quiz | MathQuest</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/student/quizzes.css">
    <style>
        .quiz-taking-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .quiz-header-bar {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px 30px;
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 2px solid rgba(67, 97, 238, 0.1);
        }

        .quiz-title-section h2 {
            font-family: 'Orbitron', sans-serif;
            font-size: 24px;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .quiz-meta {
            display: flex;
            gap: 15px;
            font-size: 14px;
            color: #666;
        }

        .student-info-mini {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .level-badge {
            background: linear-gradient(135deg, #FF9F1C 0%, #FFD166 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .theta-display {
            background: rgba(76, 201, 240, 0.1);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            color: var(--dark-color);
        }

        .quiz-progress-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            border: 2px solid rgba(67, 97, 238, 0.1);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .progress-stats {
            display: flex;
            gap: 20px;
        }

        .stat-box {
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 12px;
            color: #666;
        }

        .progress-bar-container {
            height: 10px;
            background: #E9ECEF;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color) 0%, var(--accent-color) 100%);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .question-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            border: 2px solid rgba(67, 97, 238, 0.1);
            min-height: 400px;
            display: flex;
            flex-direction: column;
        }

        .question-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            flex-direction: column;
            gap: 20px;
        }

        .loading-spinner {
            font-size: 48px;
            color: var(--accent-color);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .question-content {
            flex: 1;
            display: none;
        }

        .question-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .question-number {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .question-text {
            font-size: 20px;
            line-height: 1.6;
            margin-bottom: 30px;
            color: var(--dark-color);
        }

        .question-image {
            max-width: 100%;
            max-height: 300px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .options-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }

        .option-item {
            padding: 18px 20px;
            border: 2px solid #E9ECEF;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            position: relative;
        }

        .option-item:hover {
            border-color: var(--accent-color);
            background: rgba(76, 201, 240, 0.05);
            transform: translateX(5px);
        }

        .option-item.selected {
            border-color: var(--secondary-color);
            background: rgba(67, 97, 238, 0.1);
            transform: translateX(5px);
        }

        .option-item.correct {
            border-color: var(--success-color);
            background: rgba(46, 196, 182, 0.1);
        }

        .option-item.incorrect {
            border-color: var(--danger-color);
            background: rgba(231, 29, 54, 0.1);
        }

        .option-label {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #E9ECEF;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .option-item.selected .option-label {
            background: var(--secondary-color);
            color: white;
        }

        .option-item.correct .option-label {
            background: var(--success-color);
            color: white;
        }

        .option-item.incorrect .option-label {
            background: var(--danger-color);
            color: white;
        }

        .option-text {
            flex: 1;
            font-size: 16px;
            line-height: 1.5;
        }

        .option-image {
            max-width: 150px;
            max-height: 100px;
            margin-left: 15px;
            border-radius: 8px;
            object-fit: cover;
        }

        .hint-container {
            background: rgba(255, 159, 28, 0.1);
            border: 2px solid var(--warning-color);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            display: none;
            animation: slideDown 0.3s ease;
        }

        .hint-container.show {
            display: block;
        }

        .hint-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: var(--warning-color);
            font-weight: 600;
        }

        .hint-content {
            color: #666;
            line-height: 1.6;
        }

        .quiz-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }

        .points-display {
            background: rgba(46, 196, 182, 0.1);
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            color: var(--success-color);
            border: 2px solid rgba(46, 196, 182, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
        }

        .btn-large {
            padding: 12px 30px;
            font-size: 16px;
            min-width: 150px;
        }

        .btn-success {
            background: linear-gradient(90deg, var(--success-color) 0%, #4ADE80 100%);
            color: white;
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(46, 196, 182, 0.3);
        }

        .completion-screen {
            display: none;
            text-align: center;
            padding: 50px 20px;
            flex: 1;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .completion-screen.show {
            display: flex;
            animation: fadeIn 0.5s ease;
        }

        .completion-icon {
            font-size: 80px;
            color: var(--success-color);
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }

        @keyframes bounce {

            0%,
            20%,
            50%,
            80%,
            100% {
                transform: translateY(0);
            }

            40% {
                transform: translateY(-20px);
            }

            60% {
                transform: translateY(-10px);
            }
        }

        .completion-message {
            font-family: 'Orbitron', sans-serif;
            font-size: 32px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .completion-submessage {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }

        .completion-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 30px 0;
            max-width: 600px;
            width: 100%;
        }

        .stat-card-mini {
            background: #F8F9FA;
            padding: 20px;
            border-radius: 10px;
        }

        .stat-card-mini h4 {
            font-size: 28px;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-card-mini p {
            font-size: 14px;
            color: #666;
        }

        @media (max-width: 768px) {
            .quiz-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .student-info-mini {
                width: 100%;
                justify-content: space-between;
            }

            .options-grid {
                grid-template-columns: 1fr;
            }

            .quiz-controls {
                flex-direction: column;
                gap: 15px;
            }

            .action-buttons {
                width: 100%;
            }

            .btn-large {
                width: 100%;
            }

            .completion-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-gamepad logo-icon"></i>
                <div class="logo-text">MATHQUEST</div>
            </div>
            <nav class="nav-menu">
                <a href="../student_dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i>
                    <span>Dashboard</span>
                </a>
                <a href="../modules.php" class="nav-item">
                    <i class="fas fa-book-open nav-icon"></i>
                    <span>Learning Materials</span>
                </a>
                <a href="../quizzes.php" class="nav-item">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    <span>Quizzes</span>
                </a>
                <a href="../profile.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span>My Profile</span>
                </a>
                <a href="../logout.php" class="nav-item" id="logout-btn">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <span>Logout</span>
                </a>
            </nav>
            <div class="user-profile">
                <a href="profile.php">
                    <div class="user-avatar" id="user-avatar">
                        <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                    </div>
                </a>
                <div class="user-info">
                    <h4 id="user-name"><?= htmlspecialchars($student_name) ?></h4>
                    <p id="user-type">Student</p>
                </div>
            </div>
        </aside>
        <main class="main-content">
            <div class="quiz-taking-container">
                <div class="quiz-header-bar">
                    <div class="quiz-title-section">
                        <h2><?= htmlspecialchars($quiz_info['quarter']) ?> Quarter - Week <?= htmlspecialchars($quiz_info['week']) ?></h2>
                        <div class="quiz-meta">
                            <span>Difficulty: <?= htmlspecialchars($quiz_info['quiz_type']) ?></span>
                            <span>|</span>
                            <span>Total Questions: <?= htmlspecialchars($total) ?></span>
                        </div>
                    </div>
                    <div class="student-info-mini">
                        <div class="level-badge">
                            Level <?= htmlspecialchars($student['current_level']) ?>
                        </div>
                        <div class="theta-display">
                            <i class="fas fa-brain"></i>
                            Theta: <?= htmlspecialchars(round($student['theta'], 2)) ?>
                        </div>
                        <div class="p-value-display" style="background: rgba(76, 201, 240, 0.1); padding: 6px 12px; border-radius: 20px; font-size: 14px; color: var(--dark-color);">
                            <i class="fas fa-chart-line"></i>
                            P: <?= number_format($student['p_value'], 2) ?>
                        </div>
                        <button class="btn btn-outline" onclick="exitQuiz()">
                            <i class="fas fa-sign-out-alt"></i>
                            Exit Quiz
                        </button>
                    </div>
                </div>

                <!-- Progress Section -->
                <div class="quiz-progress-section">
                    <div class="progress-header">
                        <h3>Quiz Progress</h3>
                        <div class="progress-stats">
                            <div class="stat-box">
                                <div class="stat-value"><?= $progress_percent ?>%</div>
                                <div class="stat-label">Completed</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?= $answered ?>/<?= $total ?></div>
                                <div class="stat-label">Questions</div>
                            </div>
                        </div>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" id="progress-bar" style="width: <?= $progress_percent ?>%;"></div>
                    </div>
                </div>

                <!-- Question Container -->
                <div class="question-container">
                    <!-- Loading State -->
                    <div class="question-loading" id="loading-state">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <h3>Loading Question...</h3>
                        <p>Please wait while we prepare your next question.</p>
                    </div>

                    <!-- Question Content -->
                    <div class="question-content" id="question-content">
                        <div class="question-number" id="question-number">Question 1 of <?= $total ?></div>
                        <div class="question-text" id="question-text"></div>
                        <img id="question-image" class="question-image" style="display: none;">

                        <div class="options-grid" id="options-container">
                            <!-- Options will be dynamically inserted -->
                        </div>

                        <!-- Hint Container -->
                        <div class="hint-container" id="hint-container">
                            <div class="hint-header">
                                <i class="fas fa-lightbulb"></i>
                                <span>Hint</span>
                            </div>
                            <div class="hint-content" id="hint-content"></div>
                        </div>

                        <!-- Quiz Controls -->
                        <div class="quiz-controls">
                            <div class="points-display" id="points-display">
                                <i class="fas fa-star"></i>
                                <span id="points-value">0</span> points available
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-success btn-large" id="submit-btn" onclick="submitAnswer()" disabled>
                                    <i class="fas fa-paper-plane"></i>
                                    Submit Answer
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Completion Screen -->
                    <div class="completion-screen" id="completion-screen">
                        <div class="completion-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <h2 class="completion-message">Quiz Completed!</h2>
                        <p class="completion-submessage">Great job! Let's see your results.</p>

                        <div class="completion-stats">
                            <div class="stat-card-mini">
                                <h4 id="final-correct">0</h4>
                                <p>Correct Answers</p>
                            </div>
                            <div class="stat-card-mini">
                                <h4 id="final-points">0</h4>
                                <p>Points Earned</p>
                            </div>
                            <div class="stat-card-mini">
                                <h4 id="final-accuracy">0%</h4>
                                <p>Accuracy</p>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn btn-primary btn-large" onclick="viewResults()">
                                <i class="fas fa-chart-bar"></i>
                                View Detailed Results
                            </button>
                            <button class="btn btn-outline btn-large" onclick="window.location.href='../quizzes.php'">
                                <i class="fas fa-arrow-left"></i>
                                Back to Quizzes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <input type="hidden" id="student_id" value="<?= $student_id ?>">
    <input type="hidden" id="quiz_id" value="<?= $quiz_id ?>">
    <input type="hidden" id="attempt_id" value="<?= $attempt_id ?>">
    <input type="hidden" id="current_theta" value="<?= $student['theta'] ?>">
    <input type="hidden" id="p_value" value="<?= $student['p_value'] ?>">

    <script src="../../../assets/js/student/sidebar.js"></script>
    <script>
        let currentQuestion = null;
        let selectedOption = null;
        let isAnswered = false;
        let totalQuestions = <?= $total ?>;
        let answeredCount = <?= $answered ?>;
        let quizStarted = false;

        document.addEventListener('DOMContentLoaded', function() {
            startQuiz();

            document.addEventListener('click', function(e) {
                if (e.target.closest('.option-item') && !isAnswered) {
                    const optionItem = e.target.closest('.option-item');
                    selectOption(optionItem);
                }
            });

            document.querySelectorAll('.option-item').forEach(item => {
                item.addEventListener('contextmenu', e => e.preventDefault());
            });
        });

        function startQuiz() {
            document.getElementById('loading-state').style.display = 'flex';
            document.getElementById('question-content').classList.remove('active');
            document.getElementById('completion-screen').classList.remove('show');

            const formData = new FormData();
            formData.append('action', 'start');
            formData.append('quiz_id', document.getElementById('quiz_id').value);
            formData.append('attempt_id', document.getElementById('attempt_id').value);

            fetch('../quiz_logic/quiz_processor.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        quizStarted = true;
                        if (data.quiz_complete) {
                            showCompletionScreen(data);
                        } else {
                            displayQuestion(data);
                        }
                    } else {
                        alert('Error starting quiz: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error starting quiz. Please try again.');
                });
        }

function displayQuestion(questionData) {
    currentQuestion = questionData;

    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('question-content').classList.add('active');

    const questionNum = answeredCount + 1;
    document.getElementById('question-number').textContent =
        `Question ${questionNum} of ${totalQuestions}`;

    document.getElementById('question-text').textContent = questionData.content || questionData.question_text;

    const questionImage = document.getElementById('question-image');
    if (questionData.content_image) {
        questionImage.src = questionData.content_image;
        questionImage.style.display = 'block';
    } else {
        questionImage.style.display = 'none';
    }

    const optionsContainer = document.getElementById('options-container');
    optionsContainer.innerHTML = '';

    const options = [{
            key: 'A',
            text: questionData.option_a,
            image: questionData.option_a_image
        },
        {
            key: 'B',
            text: questionData.option_b,
            image: questionData.option_b_image
        },
        {
            key: 'C',
            text: questionData.option_c,
            image: questionData.option_c_image
        },
        {
            key: 'D',
            text: questionData.option_d,
            image: questionData.option_d_image
        }
    ];

    options.forEach(option => {
        if (option.text) {
            const optionElement = document.createElement('div');
            optionElement.className = 'option-item';
            optionElement.dataset.option = option.key;

            optionElement.innerHTML = `
                <div class="option-label">${option.key}</div>
                <div class="option-text">${option.text}</div>
                ${option.image ? `<img src="${option.image}" class="option-image" alt="Option ${option.key}">` : ''}
            `;

            // Add click event listener to each option
            optionElement.addEventListener('click', function(e) {
                if (quizCompleted || isAnswered) return;
                selectOption(this);
            });
            
            // Prevent right-click
            optionElement.addEventListener('contextmenu', e => e.preventDefault());

            optionsContainer.appendChild(optionElement);
        }
    });

    const points = questionData.points || questionData.question_points || 0;
    document.getElementById('points-value').textContent = points;

    const progressPercent = Math.round((answeredCount / totalQuestions) * 100);
    document.getElementById('progress-bar').style.width = `${progressPercent}%`;
    
    // Reset selection state for new question
    selectedOption = null;
    isAnswered = false;
    document.getElementById('submit-btn').disabled = true;
    document.getElementById('hint-container').classList.remove('show');
}
        let quizCompleted = false;

        function showCompletionScreen(data) {
            quizCompleted = true;

            document.getElementById('loading-state').style.display = 'none';
            document.getElementById('question-content').classList.remove('active');
            document.getElementById('completion-screen').classList.add('show');

            if (data) {
                document.getElementById('final-correct').textContent = data.correct_count || 0;
                document.getElementById('final-points').textContent = data.total_points || 0;

                let accuracy = data.accuracy;
                if (!accuracy && totalQuestions > 0) {
                    const correctCount = data.correct_count || 0;
                    accuracy = Math.round((correctCount / totalQuestions) * 100);
                }
                document.getElementById('final-accuracy').textContent = (accuracy || 0) + '%';
            }
        }

        function selectOption(optionElement) {
            if (quizCompleted) return;

            document.querySelectorAll('.option-item').forEach(item => {
                item.classList.remove('selected');
            });

            optionElement.classList.add('selected');
            selectedOption = optionElement.dataset.option;
            document.getElementById('submit-btn').disabled = false;
        }

        function submitAnswer() {
            if (quizCompleted || !selectedOption || isAnswered) return;

            isAnswered = true;
            document.getElementById('submit-btn').disabled = true;

            const submitBtn = document.getElementById('submit-btn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

            handleAnswerSubmission();
        }

        function handleAnswerSubmission() {
            const formData = new FormData();
            formData.append('action', 'submit');
            formData.append('quiz_id', document.getElementById('quiz_id').value);
            formData.append('attempt_id', document.getElementById('attempt_id').value);
            formData.append('question_id', currentQuestion.question_id);
            formData.append('answer', selectedOption);
            formData.append('theta', document.getElementById('current_theta').value);
            formData.append('p_value', document.getElementById('p_value').value);

            fetch('../quiz_logic/quiz_processor.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    const submitBtn = document.getElementById('submit-btn');
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Answer';

                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }

                    if (data.is_correct === false || !data.is_correct) {
                        showWrongAnswer(data);
                    } else {
                        handleCorrectAnswer(data);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const submitBtn = document.getElementById('submit-btn');
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Answer';
                    alert('Network error. Please try again.');
                });
        }

        function showWrongAnswer(data) {
            document.querySelector(`.option-item[data-option="${selectedOption}"]`).classList.add('incorrect');

            if (data.correct_answer) {
                document.querySelector(`.option-item[data-option="${data.correct_answer}"]`).classList.add('correct');
            }

            if (data.hint) {
                document.getElementById('hint-content').textContent = data.hint;
                document.getElementById('hint-container').classList.add('show');
            }

            const submitBtn = document.getElementById('submit-btn');
            submitBtn.innerHTML = '<i class="fas fa-redo"></i> Try Again';
            submitBtn.disabled = false;
            submitBtn.onclick = retryQuestion;

            document.getElementById('points-value').textContent = '0';
        }

        function retryQuestion() {
            document.querySelectorAll('.option-item').forEach(item => {
                item.classList.remove('incorrect', 'correct');
            });

            document.getElementById('hint-container').classList.remove('show');

            const submitBtn = document.getElementById('submit-btn');
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Answer';
            submitBtn.onclick = submitAnswer;

            isAnswered = false;
            document.getElementById('submit-btn').disabled = !selectedOption;
        }

        function handleCorrectAnswer(data) {
            // Mark correct option
            document.querySelectorAll('.option-item').forEach(item => {
                if (item.dataset.option === selectedOption) {
                    item.classList.add('correct');
                }
            });

            answeredCount++;

            if (data.student_stats) {
                updateStudentStats(data.student_stats);
            }

            const pointsEarned = data.points_awarded || 0;
            showSuccessMessage(pointsEarned);

            // Check if there's another question in the response
            if (data.quiz_complete) {
                setTimeout(() => {
                    showCompletionScreen(data);
                }, 1500);
            } else if (data.question_id) {
                // There's a next question in the response
                setTimeout(() => {
                    displayQuestion(data);
                }, 1500);
            } else {
                // No next question but not complete - try to get next
                setTimeout(() => {
                    startQuiz();
                }, 1500);
            }
        }

        function showSuccessMessage(points) {
            const successMsg = document.createElement('div');
            successMsg.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(46, 196, 182, 0.95);
            color: white;
            padding: 20px 40px;
            border-radius: 10px;
            font-size: 24px;
            font-weight: bold;
            z-index: 10000;
            animation: fadeInOut 1.5s ease;
            box-shadow: 0 10px 30px rgba(46, 196, 182, 0.3);
        `;
            successMsg.innerHTML = `<i class="fas fa-check-circle"></i> Correct! +${points} points`;
            document.body.appendChild(successMsg);

            setTimeout(() => {
                successMsg.remove();
            }, 1500);
        }

        function updateStudentStats(stats) {
            // Update theta display
            const thetaDisplay = document.querySelector('.theta-display');
            thetaDisplay.innerHTML = `<i class="fas fa-brain"></i> Theta: ${parseFloat(stats.theta).toFixed(2)}`;
            document.getElementById('current_theta').value = stats.theta;

            // Update p_value
            if (stats.p_value) {
                document.getElementById('p_value').value = stats.p_value;
            }

            // Update level badge if level changed
            const levelBadge = document.querySelector('.level-badge');
            if (stats.current_level) {
                levelBadge.textContent = `Level ${stats.current_level}`;
            }

            // Update progress
            const progressPercent = Math.round((answeredCount / totalQuestions) * 100);
            document.getElementById('progress-bar').style.width = `${progressPercent}%`;
        }

        function showCompletionScreen(data) {
            document.getElementById('loading-state').style.display = 'none';
            document.getElementById('question-content').classList.remove('active');
            document.getElementById('completion-screen').classList.add('show');

            // Update completion stats
            if (data) {
                document.getElementById('final-correct').textContent = data.correct_count || 0;
                document.getElementById('final-points').textContent = data.total_points || 0;

                // Calculate accuracy if not provided
                let accuracy = data.accuracy;
                if (!accuracy && totalQuestions > 0) {
                    const correctCount = data.correct_count || 0;
                    accuracy = Math.round((correctCount / totalQuestions) * 100);
                }
                document.getElementById('final-accuracy').textContent = (accuracy || 0) + '%';
            }
        }

 function viewResults() {
    // Get results via quiz_processor.php
    const formData = new FormData();
    formData.append('action', 'results');
    formData.append('quiz_id', document.getElementById('quiz_id').value);
    formData.append('attempt_id', document.getElementById('attempt_id').value);

    console.log('Requesting results...');

    fetch('../quiz_logic/quiz_processor.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Results response:', data);
            
            if (data.success && data.redirect) {
                // Redirect to the quiz_complete page
                console.log('Redirecting to:', data.redirect_url);
                window.location.href = data.redirect_url;
            } else if (data.error) {
                alert('Error: ' + (data.error || 'Failed to get results'));
                console.error('Server error:', data);
            } else {
                // Fallback: try to redirect anyway
                console.log('No redirect flag, trying direct redirect');
                window.location.href = '../../quizzes/quiz_complete.php?quiz_id=' + 
                    document.getElementById('quiz_id').value + 
                    '&attempt_id=' + document.getElementById('attempt_id').value;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error. Please try again.');
            
            // Fallback: try direct redirect on error
            window.location.href = '../../quizzes/quiz_complete.php?quiz_id=' + 
                document.getElementById('quiz_id').value + 
                '&attempt_id=' + document.getElementById('attempt_id').value;
        });
}

        function exitQuiz() {
            if (confirm('Are you sure you want to exit? Your progress will be saved.')) {
                window.location.href = '../quizzes.php';
            }
        }
    </script>
</body>

</html>