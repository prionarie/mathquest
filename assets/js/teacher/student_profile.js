function togglePasscode() {
    const button = event.currentTarget;
    const passcode = button.getAttribute('data-passcode');
    const passcodeElement = document.getElementById('student-passcode');

    if (passcodeElement.textContent === '••••••') {
        passcodeElement.textContent = passcode;
        button.innerHTML = '<i class="fas fa-eye-slash"></i> Hide';
    } else {
        passcodeElement.textContent = '••••••';
        button.innerHTML = '<i class="fas fa-eye"></i> Show';
    }
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

function showEditForm() {
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function (event) {
    const modal = document.getElementById('editModal');
    if (event.target == modal) {
        closeEditModal();
    }
}

function confirmResetPasscode(event) {
    if (!confirm("Are you sure you want to reset this student's passcode? This action cannot be undone.")) {
        event.preventDefault();
        return false;
    }
    return true;
}