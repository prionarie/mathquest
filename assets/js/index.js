document.addEventListener('DOMContentLoaded', function () {
    const loginBtn = document.querySelector('.login-icon-btn');
    if (loginBtn) {
        loginBtn.addEventListener('click', function () {
            window.location.href = 'login.php';
        });
    }

    const registerBtns = document.querySelectorAll('.secondary-btn');
    registerBtns.forEach(button => {
        button.addEventListener('click', function () {
            if (this.textContent.includes('Sign Up')) {
                window.location.href = 'signup.php';
            }
        });
    });

    const startQuestBtns = document.querySelectorAll('.primary-btn');
    startQuestBtns.forEach(button => {
        button.addEventListener('click', function () {
            if (this.textContent.includes('Log In')) {
                window.location.href = 'login.php';
            } else if (this.textContent.includes('Start Learning Journey')) {
                window.location.href = 'login.php';
            }
        });
    });

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();

            const targetId = this.getAttribute('href');
            if (targetId === '#') return;

            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });

    const statValues = document.querySelectorAll('.hero-stat-value');
    statValues.forEach(stat => {
        const originalValue = stat.textContent;
        const numericValue = parseFloat(originalValue);

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    let count = 0;
                    const target = numericValue;
                    const increment = target / 20;
                    const timer = setInterval(() => {
                        count += increment;
                        if (count >= target) {
                            count = target;
                            clearInterval(timer);
                        }

                        if (originalValue.includes('+')) {
                            stat.textContent = Math.floor(count) + '+';
                        } else if (originalValue.includes('★')) {
                            stat.textContent = count.toFixed(1) + '★';
                        } else if (originalValue.includes('%')) {
                            stat.textContent = Math.floor(count) + '%';
                        }
                    }, 50);

                    observer.unobserve(entry.target);
                }
            });
        });

        observer.observe(stat);
    });
});