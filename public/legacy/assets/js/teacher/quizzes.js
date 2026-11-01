document.addEventListener('DOMContentLoaded', function () {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function (e) {
            if (this.id === 'logout-btn') return;
            e.preventDefault();
            const href = this.getAttribute('href');
            if (href) window.location.href = href;
        });
    });

    setupQuizFilters();
});

function setupQuizFilters() {
    const quarterFilter = document.getElementById('quarter-filter');
    const weekFilter = document.getElementById('week-filter');
    const typeFilter = document.getElementById('type-filter');
    const quizzesContainer = document.getElementById('quizzes-container');

    if (!quizzesContainer) return;

    const quizzes = Array.from(quizzesContainer.querySelectorAll('.quiz-card'));
    const noQuizzes = quizzesContainer.querySelector('.no-quizzes');

    function applyFilters() {
        const quarterValue = quarterFilter ? quarterFilter.value : 'all';
        const weekValue = weekFilter ? weekFilter.value : 'all';
        const typeValue = typeFilter ? typeFilter.value : 'all';

        let visibleCount = 0;

        quizzes.forEach(quiz => {
            const quarter = quiz.getAttribute('data-quarter');
            const week = quiz.getAttribute('data-week');
            const type = quiz.getAttribute('data-type');

            let show = true;

            if (quarterValue !== 'all' && quarter !== quarterValue) show = false;
            if (weekValue !== 'all' && week !== weekValue) show = false;
            if (typeValue !== 'all' && type !== typeValue) show = false;

            if (show) {
                quiz.style.display = 'block';
                visibleCount++;
            } else {
                quiz.style.display = 'none';
            }
        });

        if (noQuizzes) {
            if (visibleCount === 0 && quizzes.length > 0) {
                noQuizzes.style.display = 'block';
            } else {
                noQuizzes.style.display = 'none';
            }
        }
    }

    [quarterFilter, weekFilter, typeFilter].forEach(filter => {
        if (filter) {
            filter.addEventListener('change', applyFilters);
        }
    });
}