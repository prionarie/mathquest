document.addEventListener('DOMContentLoaded', function () {
    const loginForm = document.getElementById('login-form');
    const forgotForm = document.getElementById('forgot-form');
    const loginAlert = document.getElementById('login-alert');
    const forgotAlert = document.getElementById('forgot-alert');

    const toggleLoginPasswordBtn = document.getElementById('toggle-login-password');
    const loginPasswordInput = document.getElementById('login-password');
    const signupLinks = document.querySelectorAll('.signup-link');

    toggleLoginPasswordBtn?.addEventListener('click', function () {
        const isPassword = loginPasswordInput.type === 'password';
        loginPasswordInput.type = isPassword ? 'text' : 'password';
        this.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });

    signupLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = 'signup.php';
        });
    });

    document.getElementById('forgot-password')?.addEventListener('click', function (e) {
        e.preventDefault();
        loginForm.style.display = 'none';
        forgotForm.style.display = 'block';
        forgotAlert.style.display = 'none';
    });

    document.getElementById('back-to-login')?.addEventListener('click', function (e) {
        e.preventDefault();
        loginForm.style.display = 'block';
        forgotForm.style.display = 'none';
        loginAlert.style.display = 'none';
    });

    function showAlert(alertElement, message, type = 'error') {
        alertElement.textContent = message;
        alertElement.className = 'alert';
        alertElement.classList.add(type === 'error' ? 'alert-error' : 'alert-success');
        alertElement.style.display = 'block';
        setTimeout(() => { alertElement.style.display = 'none'; }, 5000);
    }

    document.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            if (loginForm.style.display !== 'none') {
                loginForm.querySelector('button[type="submit"]')?.click();
            } else if (forgotForm.style.display !== 'none') {
                forgotForm.querySelector('button[type="submit"]')?.click();
            }
        }
    });
});
