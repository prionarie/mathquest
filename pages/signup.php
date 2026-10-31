<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require '../../config/dbcon.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname  = trim($_POST['firstname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '');
    $lastname   = trim($_POST['lastname'] ?? '');
    $position   = trim($_POST['position'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = trim($_POST['password'] ?? '');
    $confirm    = trim($_POST['confirm'] ?? '');
    $terms      = isset($_POST['terms']) ? true : false;

    if (!$firstname || !$lastname || !$position || !$email || !$password || !$confirm || !$terms) {
        $error = "Please fill in all required fields and agree to the terms.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO teachers (first_name, middle_name, last_name, position, email, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $firstname, $middlename, $lastname, $position, $email, $hashedPassword);
            if ($stmt->execute()) {
                $success = "Congratulations $firstname $lastname! Your account has been created successfully.";
                $firstname = $middlename = $lastname = $position = $email = '';
            } else {
                $error = "Failed to create account. Please try again.";
            }
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | MathQuest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Orbitron:wght@400;500;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/signup.css">
</head>

<body>

    <?php if (!empty($success)): ?>
        <div class="success-modal active" id="successModal">
            <div class="success-content">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2>Registration Successful!</h2>
                <p id="successMessage"><?= $success ?></p>
                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                    <button class="success-btn" onclick="window.location.href='login.php'" style="margin: 0;">
                        <i class="fas fa-sign-in-alt"></i> Go to Login
                    </button>
                    <button class="success-btn" onclick="hideSuccessModal()" style="background: var(--muted-text); margin: 0;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <p style="margin-top: 15px; font-size: 0.9rem; color: var(--muted-text);">
                    Auto-redirecting to login in <span id="countdown">5</span> seconds...
                </p>
            </div>
        </div>

        <script>
            let seconds = 5;
            const countdownElement = document.getElementById('countdown');
            const interval = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(interval);
                    window.location.href = 'login.php';
                }
            }, 1000);

            function hideSuccessModal() {
                document.getElementById('successModal').classList.remove('active');
                document.body.style.overflow = 'auto';
                window.location.href = 'login.php';
            }
            document.body.style.overflow = 'hidden';
        </script>
    <?php endif; ?>


    <div class="top-logo-container">
        <a href="index.php" class="logo-link">
            <div class="logo-icon">
                <i class="fas fa-gamepad"></i>
            </div>
            <div class="logo-text">MATHQUEST</div>
        </a>
    </div>

    <div class="modal-container">
        <div id="register-form">
            <div class="modal-header">
                <h2>Start Your Quest!</h2>
                <p>Create your MathQuest teacher account</p>
            </div>

            <div class="modal-body">
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST" action="" id="signupForm">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" id="firstname" name="firstname" class="form-input" placeholder="First Name" required value="<?= htmlspecialchars($firstname ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" id="middlename" name="middlename" class="form-input" placeholder="Middle Name" value="<?= htmlspecialchars($middlename ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" id="lastname" name="lastname" class="form-input" placeholder="Last Name" required value="<?= htmlspecialchars($lastname ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <select id="position" name="position" class="select-input" required>
                            <option value="">Select Position</option>
                            <option value="Teacher I" <?= (isset($position) && $position == 'Teacher I') ? 'selected' : '' ?>>Teacher I</option>
                            <option value="Teacher II" <?= (isset($position) && $position == 'Teacher II') ? 'selected' : '' ?>>Teacher II</option>
                            <option value="Teacher III" <?= (isset($position) && $position == 'Teacher III') ? 'selected' : '' ?>>Teacher III</option>
                            <option value="Teacher IV" <?= (isset($position) && $position == 'Teacher IV') ? 'selected' : '' ?>>Teacher IV</option>
                            <option value="Teacher V" <?= (isset($position) && $position == 'Teacher V') ? 'selected' : '' ?>>Teacher V</option>
                            <option value="Teacher VI" <?= (isset($position) && $position == 'Teacher VI') ? 'selected' : '' ?>>Teacher VI</option>
                            <option value="Master Teacher I" <?= (isset($position) && $position == 'Master Teacher I') ? 'selected' : '' ?>>Master Teacher I</option>
                            <option value="Master Teacher II" <?= (isset($position) && $position == 'Master Teacher II') ? 'selected' : '' ?>>Master Teacher II</option>
                            <option value="Master Teacher III" <?= (isset($position) && $position == 'Master Teacher III') ? 'selected' : '' ?>>Master Teacher III</option>
                            <option value="Master Teacher IV" <?= (isset($position) && $position == 'Master Teacher IV') ? 'selected' : '' ?>>Master Teacher IV</option>
                            <option value="Master Teacher V" <?= (isset($position) && $position == 'Master Teacher V') ? 'selected' : '' ?>>Master Teacher V</option>
                            <option value="Master Teacher VI" <?= (isset($position) && $position == 'Master Teacher VI') ? 'selected' : '' ?>>Master Teacher VI</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <div class="input-group">
                            <input type="email" id="email" name="email" class="form-input" placeholder="Email Address" required value="<?= htmlspecialchars($email ?? '') ?>">
                            <i class="fas fa-envelope input-icon" style="cursor: default; pointer-events: none;"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Create a password</label>
                        <div class="input-group">
                            <input type="password" id="reg-password" name="password" class="form-input" placeholder="Create a password" required>
                            <button type="button" class="input-icon" id="toggle-reg-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="password-strength-bar"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm your password</label>
                        <div class="input-group">
                            <input type="password" id="reg-confirm" name="confirm" class="form-input" placeholder="Confirm your password" required>
                            <button type="button" class="input-icon" id="toggle-reg-confirm">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="password-match" style="font-size: 0.85rem; margin-top: 5px; display: none;"></div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.95rem;">
                            <input type="checkbox" name="terms" id="reg-terms" style="width: 18px; height: 18px; accent-color: var(--primary);" required>
                            <span>I agree to the <a href="#" class="forgot-link" style="color: var(--primary); text-decoration: none; font-weight: 600; transition: color 0.3s;">Terms and Conditions</a></span>
                        </label>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </form>

                <div class="login-link">
                    Already have an account? <a href="login.php" class="login-link-text">Sign In</a>
                </div>

                <div class="back-btn-container">
                    <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Home</a>
                </div>

                <div class="form-footer">
                    <p>MathQuest © 2025 | [PLACEHOLDER] Elementary School</p>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/teacher/signup.js"></script>
</body>

</html>