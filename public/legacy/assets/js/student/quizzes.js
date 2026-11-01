document.addEventListener('DOMContentLoaded', function() {

    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.getElementById('sidebar-toggle');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }
    
    checkOngoingQuizzes();

    initTooltips();

    const quizCards = document.querySelectorAll('.quiz-card');
    quizCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.zIndex = '10';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.zIndex = '1';
        });
    });
});

function checkOngoingQuizzes() {
    fetch('quiz_logic/check_ongoing_quizzes.php')
        .then(response => response.json())
        .then(data => {
            if (data.ongoing && data.ongoing.length > 0) {
                showOngoingQuizAlert(data.ongoing);
            }
        })
        .catch(error => console.error('Error checking ongoing quizzes:', error));
}

function showOngoingQuizAlert(ongoingQuizzes) {
    if (ongoingQuizzes.length === 1) {
        const quiz = ongoingQuizzes[0];
        if (confirm(`You have an ongoing quiz: "${quiz.quiz_title}". Would you like to continue?`)) {
            window.location.href = `quiz_taking.php?quiz_id=${quiz.quiz_id}&attempt_id=${quiz.attempt_id}`;
        }
    } else if (ongoingQuizzes.length > 1) {
        const message = `You have ${ongoingQuizzes.length} ongoing quizzes:\n` +
            ongoingQuizzes.map(q => `• ${q.quiz_title}`).join('\n') +
            '\n\nGo to your profile to resume any quiz.';
        alert(message);
    }
}

function initTooltips() {
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function(e) {
            const tooltipText = this.getAttribute('data-tooltip');
            const tooltip = document.createElement('div');
            tooltip.className = 'custom-tooltip';
            tooltip.textContent = tooltipText;
            tooltip.style.position = 'absolute';
            tooltip.style.background = 'rgba(0, 0, 0, 0.8)';
            tooltip.style.color = 'white';
            tooltip.style.padding = '8px 12px';
            tooltip.style.borderRadius = '4px';
            tooltip.style.fontSize = '12px';
            tooltip.style.zIndex = '10000';
            tooltip.style.whiteSpace = 'nowrap';
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 10 + 'px';
            
            this.tooltipElement = tooltip;
        });
        
        element.addEventListener('mouseleave', function() {
            if (this.tooltipElement) {
                this.tooltipElement.remove();
                this.tooltipElement = null;
            }
        });
    });
}

function filterQuizzes(difficulty) {
    const allQuizzes = document.querySelectorAll('.quiz-card');
    const filterButtons = document.querySelectorAll('.filter-btn');

    filterButtons.forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-filter') === difficulty) {
            btn.classList.add('active');
        }
    });

    allQuizzes.forEach(card => {
        const cardDifficulty = card.getAttribute('data-difficulty');
        if (difficulty === 'all' || cardDifficulty === difficulty) {
            card.style.display = 'block';
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50);
        } else {
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';
            setTimeout(() => {
                card.style.display = 'none';
            }, 300);
        }
    });
}

function sortQuizzes(sortBy) {
    const quizzesGrid = document.querySelector('.quizzes-grid');
    const quizzes = Array.from(quizzesGrid.querySelectorAll('.quiz-card'));
    
    quizzes.sort((a, b) => {
        switch(sortBy) {
            case 'date':
                const aDate = new Date(a.getAttribute('data-assigned-date'));
                const bDate = new Date(b.getAttribute('data-assigned-date'));
                return bDate - aDate;
                
            case 'difficulty':
                const difficulties = { 'Easy': 1, 'Medium': 2, 'Hard': 3 };
                const aDiff = difficulties[a.getAttribute('data-difficulty')] || 0;
                const bDiff = difficulties[b.getAttribute('data-difficulty')] || 0;
                return bDiff - aDiff;
                
            case 'due_date':
                const aDue = a.getAttribute('data-due-date') ? new Date(a.getAttribute('data-due-date')) : new Date(9999, 11, 31);
                const bDue = b.getAttribute('data-due-date') ? new Date(b.getAttribute('data-due-date')) : new Date(9999, 11, 31);
                return aDue - bDue;
                
            default:
                return 0;
        }
    });
    
    quizzes.forEach(quiz => {
        quizzesGrid.appendChild(quiz);
    });
}

function searchQuizzes(searchTerm) {
    const allQuizzes = document.querySelectorAll('.quiz-card');
    const searchLower = searchTerm.toLowerCase();
    
    allQuizzes.forEach(card => {
        const title = card.querySelector('.quiz-title').textContent.toLowerCase();
        const quarter = card.getAttribute('data-quarter') || '';
        const week = card.getAttribute('data-week') || '';
        
        if (title.includes(searchLower) || 
            quarter.toLowerCase().includes(searchLower) || 
            week.includes(searchTerm)) {
            card.style.display = 'block';
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50);
        } else {
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';
            setTimeout(() => {
                card.style.display = 'none';
            }, 300);
        }
    });
}

window.filterQuizzes = filterQuizzes;
window.sortQuizzes = sortQuizzes;
window.searchQuizzes = searchQuizzes;