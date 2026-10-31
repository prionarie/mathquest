<?php
session_start();
require '../../config/dbcon.php';

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT teacher_id, first_name, last_name, password_hash FROM teachers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($teacher_id, $first_name, $last_name, $password_hash);

        if ($stmt->num_rows > 0) {
            $stmt->fetch();
            if ($password_hash !== null && password_verify($password, $password_hash)) {
                $_SESSION['teacher_id'] = $teacher_id;
                $_SESSION['teacher_name'] = "$first_name $last_name";

                if (isset($_POST['remember-me'])) {
                    setcookie('teacher_login', $teacher_id, time() + (86400 * 30), "/");
                }

                header('Location: dashboard.php');
                exit;
            } else {
                $login_error = 'Invalid email or password.';
            }
        } else {
            $login_error = 'Invalid email or password.';
        }
        $stmt->close();
    } else {
        $login_error = 'Please fill in all fields.';
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MathQuest</title>
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
                <p>Sign in to MathQuest Teacher Portal</p>
            </div>

            <div class="modal-body">
                <?php if ($login_error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($login_error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <input type="email" name="email" id="login-identifier" class="form-input" placeholder="Enter your email" required>
                            <i class="fas fa-user input-icon" style="cursor: default; pointer-events: none;"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="login-password" class="form-input" placeholder="Enter your password" required>
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
                        <a href="#" class="forgot-link" id="forgot-password">Forgot password?</a>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>

                    <div class="register-link">
                        Don't have an account? <a href="signup.php" class="signup-link">Sign Up</a>
                    </div>

                    <div class="back-btn-container">
                        <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Home</a>
                    </div>

                    <div class="form-footer">
                        <p>MathQuest © 2025 | [PLACEHOLDER] Elementary School</p>
                    </div>
                </form>
            </div>
        </div>

        
    </div>

            <div id="forgot-form">
            <div class="modal-header">
                <h2>Reset Password</h2>
                <p>Enter your email to receive reset instructions</p>
            </div>

            <div class="modal-body">
                <div class="alert" id="forgot-alert"></div>

                <div class="form-group">
                    <br>
                    <label class="form-label">Email Address</label>
                    <div class="input-group">
                        <input type="email" id="forgot-email" class="form-input" placeholder="Enter your registered email" required>
                        <i class="fas fa-envelope input-icon" style="cursor: default; pointer-events: none;"></i>
                    </div>
                </div>

                <button type="button" class="submit-btn" id="reset-btn">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>

                <div class="register-link">
                    <a href="#" id="back-to-login">Back to Sign In</a>
                </div>

                <div class="form-footer">
                    <p>MathQuest © 2023 | [PLACEHOLDER] Elementary School</p>
                </div>
            </div>
    <script src="../../assets/js/teacher/login.js"></script>
</body>

</html>