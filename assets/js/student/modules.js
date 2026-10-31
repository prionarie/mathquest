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

    setupFilters();
});

function setupFilters() {
    const quarterFilter = document.getElementById('quarter-filter');
    const weekFilter = document.getElementById('week-filter');
    const typeFilter = document.getElementById('type-filter');
    const materialsContainer = document.getElementById('materials-container');
    const noResults = document.getElementById('no-results');

    if (!materialsContainer) return;

    const materials = Array.from(materialsContainer.children);

    function applyFilters() {
        const quarterValue = quarterFilter ? quarterFilter.value : 'all';
        const weekValue = weekFilter ? weekFilter.value : 'all';
        const typeValue = typeFilter ? typeFilter.value : 'all';

        let visibleCount = 0;

        materials.forEach(material => {
            const quarterText = material.querySelector('.material-quarter').textContent.trim();
            const weekText = material.querySelector('.material-week').textContent.trim();
            const typeText = material.querySelector('.material-badge').textContent.trim();

            let show = true;

            if (quarterValue !== 'all' && !quarterText.includes(quarterValue)) show = false;
            if (weekValue !== 'all' && !weekText.includes(`Week ${weekValue}`)) show = false;
            if (typeValue !== 'all' && !typeText.includes(typeValue)) show = false;

            const currentQuarter = typeof CURRENT_QUARTER !== 'undefined' ? CURRENT_QUARTER : '1st';
            const currentWeek = typeof CURRENT_WEEK !== 'undefined' ? CURRENT_WEEK : 1;
            const isCurrentQuarter = quarterText.includes(currentQuarter);
            const isCurrentWeek = weekText.includes(`Week ${currentWeek}`);

            if (isCurrentQuarter && isCurrentWeek) {
                material.classList.add('current');
            } else {
                material.classList.remove('current');
            }

            if (show) {
                material.style.display = 'block';
                visibleCount++;
            } else {
                material.style.display = 'none';
            }
        });

        if (noResults) {
            if (visibleCount === 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    }

    [quarterFilter, weekFilter, typeFilter].forEach(filter => {
        if (filter) {
            filter.addEventListener('change', applyFilters);
        }
    });
}