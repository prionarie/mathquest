function showEditForm() {
    hideAllForms();
    document.getElementById('editForm').classList.add('active');
}

function showChangePasswordForm() {
    hideAllForms();
    document.getElementById('passwordForm').classList.add('active');
}

function hideAllForms() {
    document.getElementById('editForm').classList.remove('active');
    document.getElementById('passwordForm').classList.remove('active');
}

function validateEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function uploadProfilePhoto() {
    document.getElementById('profile-image-input').click();
}

document.getElementById('profile-image-input').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!allowedTypes.includes(file.type)) {
        alert('Invalid file type. Only JPG, PNG, GIF allowed.');
        this.value = '';
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        alert('File size must be less than 2MB.');
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('teacher-avatar-img');
        if (img) img.src = e.target.result;
    };
    reader.readAsDataURL(file);

    document.getElementById('profile-image-form').submit();
});

document.getElementById('logout-btn').addEventListener('click', function (e) {
    e.preventDefault();
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
});
