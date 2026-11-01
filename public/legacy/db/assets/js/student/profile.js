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
