document.addEventListener('DOMContentLoaded', function () {
    const username =
        document.body.getAttribute('data-username')?.trim() || 'Student';

    updateUserInfo(username);

    document.getElementById('logout-btn')?.addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = this.getAttribute('href');
    });

    function updateUserInfo(username) {
        const welcomeMessage = document.getElementById('welcome-message');
        if (welcomeMessage) {
            welcomeMessage.textContent = `Welcome, ${username}!`;
        }

        const userAvatar = document.getElementById('user-avatar');
        const userName = document.getElementById('user-name');

        if (userAvatar) {
            const words = username.split(' ').filter(Boolean);
            let initials = '';

            if (words.length >= 2) {
                initials =
                    words[0][0].toUpperCase() +
                    words[words.length - 1][0].toUpperCase();
            } else {
                initials = username.substring(0, 2).toUpperCase();
            }

            userAvatar.textContent = initials;
        }

        if (userName) {
            userName.textContent = username;
        }
    }

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
});