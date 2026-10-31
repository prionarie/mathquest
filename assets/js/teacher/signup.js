document.addEventListener('DOMContentLoaded', function () {
    const regPasswordInput = document.getElementById('reg-password');
    const regConfirmInput = document.getElementById('reg-confirm');
    const toggleRegPasswordBtn = document.getElementById('toggle-reg-password');
    const toggleRegConfirmBtn = document.getElementById('toggle-reg-confirm');
    const passwordStrengthBar = document.getElementById('password-strength-bar');
    const passwordMatchIndicator = document.getElementById('password-match');

    // Toggle password visibility
    toggleRegPasswordBtn?.addEventListener('click', function () {
        const isPassword = regPasswordInput.type === 'password';
        regPasswordInput.type = isPassword ? 'text' : 'password';
        this.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });

    toggleRegConfirmBtn?.addEventListener('click', function () {
        const isPassword = regConfirmInput.type === 'password';
        regConfirmInput.type = isPassword ? 'text' : 'password';
        this.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });

    // Password strength indicator
    function updatePasswordStrength() {
        const password = regPasswordInput.value;
        let strength = 0;

        if (password.length >= 6) strength++;
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
        if (password.match(/\d/)) strength++;
        if (password.match(/[^a-zA-Z\d]/)) strength++;

        if (password.length === 0) {
            passwordStrengthBar.className = 'strength-bar';
            passwordStrengthBar.style.width = '0%';
            passwordMatchIndicator.style.display = 'none';
            return;
        }

        if (strength < 2) {
            passwordStrengthBar.className = 'strength-bar strength-weak';
        } else if (strength < 4) {
            passwordStrengthBar.className = 'strength-bar strength-medium';
        } else {
            passwordStrengthBar.className = 'strength-bar strength-strong';
        }

        passwordStrengthBar.style.width = Math.min(100, (strength / 4) * 100) + '%';
    }

    // Password match indicator
    function checkPasswordMatch() {
        const password = regPasswordInput.value;
        const confirm = regConfirmInput.value;

        if (confirm.length === 0) {
            passwordMatchIndicator.style.display = 'none';
            return;
        }

        passwordMatchIndicator.style.display = 'block';
        if (password === confirm && password.length >= 6) {
            passwordMatchIndicator.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> Passwords match</span>';
        } else {
            passwordMatchIndicator.innerHTML = '<span style="color: #EF4444;"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
        }
    }

    regPasswordInput.addEventListener('input', function () {
        updatePasswordStrength();
        checkPasswordMatch();
    });

    regConfirmInput.addEventListener('input', checkPasswordMatch);

    // Prevent form submission on Enter in inputs
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            const el = document.activeElement;
            if (el && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
                e.preventDefault();
            }
        }
    });

    // Initialize
    passwordStrengthBar.className = 'strength-bar';
    passwordStrengthBar.style.width = '0%';
    passwordMatchIndicator.style.display = 'none';
});
