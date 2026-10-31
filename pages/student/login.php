<?php
session_start();
require '../../config/dbcon.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn = trim($_POST['lrn'] ?? '');
    $passcode = trim($_POST['passcode'] ?? '');

    if (!$lrn || !$passcode) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT student_id, passcode, first_name, last_name FROM students WHERE lrn = ?");
        $stmt->bind_param("s", $lrn);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
    $stmt->bind_result($student_id, $db_passcode, $first_name, $last_name);
    $stmt->fetch();

    if ($passcode === $db_passcode) {
        $_SESSION['student_id'] = $student_id;
        $_SESSION['student_name'] = $first_name . ' ' . $last_name;
        $_SESSION['logged_in'] = true;

        if (isset($_POST['remember-me'])) {
            setcookie('student_login', $student_id, time() + (86400 * 30), "/");
        }

        header('Location: student_dashboard.php');
        exit;
    } else {
        $error = 'Incorrect LRN or passcode.';
    }
} else {
    $error = 'Incorrect LRN or passcode.';
}

        $stmt->close();
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | MathQuest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/login.css">
</head>

<body>
    <div class="top-logo-container">
        <a href="index.php" class="logo-link">
            <div class="logo-icon">
                <i class="fas fa-gamepad"></i>
            </div>
            <div class="logo-text">MATHQUEST</div>
        </a>
    </div>

    <div class="modal-container">
        <div id="login-form">
            <div class="modal-header">
                <h2>Welcome!</h2>
                <p>Sign in with the details given by your teacher.</p>
            </div>

            <div class="modal-body">
                <?php if ($error): ?>
                    <div class="alert alert-error" style="display:block;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">LRN (Learner Reference Number)</label>
                        <div class="input-group">
                            <input type="text" name="lrn" id="login-identifier" class="form-input" placeholder="Enter your LRN" required>
                            <i class="fas fa-user input-icon" style="cursor: default; pointer-events: none;"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Passcode</label>
                        <div class="input-group">
                            <input type="password" name="passcode" id="login-password" class="form-input" placeholder="Enter your passcode" required>
                            <button type="button" class="input-icon" id="toggle-login-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="remember-me">
                            <span>Remember me</span>
                        </label>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>

                    <div style="color: red; font-weight: bold; text-align: center; margin-top: 10px;">
                        If you're having trouble logging in,<br>please talk to your teacher.
                    </div>
                </form>

                <div class="back-btn-container">
                    <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Home</a>
                </div>

                <div class="form-footer">
                    <p>MathQuest © 2025 | [PLACEHOLDER] Elementary School</p>
                </div>
            </div>
        </div>
    </div>
    <script src="../../assets/js/student/login.js"></script>
</body>

</html>
