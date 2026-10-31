document.addEventListener('DOMContentLoaded', function () {
    const loginPasswordInput = document.getElementById('login-password');
    const toggleLoginPasswordBtn = document.getElementById('toggle-login-password');

    toggleLoginPasswordBtn?.addEventListener('click', function () {
        const isPassword = loginPasswordInput.type === 'password';
        loginPasswordInput.type = isPassword ? 'text' : 'password';
        this.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });

    document.querySelector('form')?.addEventListener('submit', function (e) {
        const lrn = document.getElementById('login-identifier').value.trim();
        const passcode = loginPasswordInput.value;
        const loginAlert = document.getElementById('login-alert');

        if (!lrn || !passcode) {
            e.preventDefault();
            showAlert(loginAlert, 'Please fill in all fields', 'error');
        }
    });

    function showAlert(alertElement, message, type) {
        alertElement.textContent = message;
        alertElement.className = 'alert';
        alertElement.classList.add(type === 'error' ? 'alert-error' : 'alert-success');
        alertElement.style.display = 'block';
        setTimeout(() => {
            alertElement.style.display = 'none';
        }, 5000);
    }
});
