document.addEventListener('DOMContentLoaded', function () {

    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = 'logout.php';
        });
    }

function updateUserInfo() {
    const userNameEl = document.getElementById('user-name');
    const welcomeMessage = document.getElementById('welcome-message');
    if (userNameEl) {
        const username = userNameEl.textContent || 'Teacher';
        if (welcomeMessage) welcomeMessage.textContent = `Welcome, ${username}!`;
    }
}
    updateUserInfo();

    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function (e) {
            if (this.id === 'logout-btn') return;
            e.preventDefault();
            const href = this.getAttribute('href');
            if (href) window.location.href = href;
        });
    });

    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function () {
            this.style.transform = 'translateY(-10px)';
        });
        card.addEventListener('mouseleave', function () {
            this.style.transform = 'translateY(0)';
        });
    });

    const classPerformance = document.getElementById('class-performance');
    if (classPerformance) {
        const targetWidth = classPerformance.getAttribute('data-score');
        setTimeout(() => {
            classPerformance.style.width = targetWidth + '%';
        }, 100);
    }
});
