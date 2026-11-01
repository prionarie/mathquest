// View Quiz JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // Update active tab button
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Show active tab content
            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === `${tabId}-tab`) {
                    content.classList.add('active');
                }
            });
        });
    });
    
    // Print functionality
    if (window.location.search.includes('print=true')) {
        window.print();
    }
});

// Question expansion/collapse
function toggleQuestion(header) {
    const questionItem = header.closest('.question-item');
    questionItem.classList.toggle('expanded');
    
    const icon = header.querySelector('.fa-chevron-down');
    if (icon) {
        icon.style.transform = questionItem.classList.contains('expanded') 
            ? 'rotate(180deg)' 
            : 'rotate(0deg)';
    }
}

function expandAllQuestions() {
    document.querySelectorAll('.question-item').forEach(item => {
        item.classList.add('expanded');
        const icon = item.querySelector('.fa-chevron-down');
        if (icon) icon.style.transform = 'rotate(180deg)';
    });
}

function collapseAllQuestions() {
    document.querySelectorAll('.question-item').forEach(item => {
        item.classList.remove('expanded');
        const icon = item.querySelector('.fa-chevron-down');
        if (icon) icon.style.transform = 'rotate(0deg)';
    });
}

// View attempt details
function viewAttemptDetails(attemptId) {
    // In a real implementation, you would fetch this data via AJAX
    // For now, we'll show a sample modal
    
    const modalContent = `
        <div class="attempt-details">
            <div class="detail-section">
                <h4>Attempt Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Attempt ID:</strong>
                        <span>${attemptId}</span>
                    </div>
                    <div class="detail-item">
                        <strong>Date Taken:</strong>
                        <span id="attempt-date">Loading...</span>
                    </div>
                    <div class="detail-item">
                        <strong>Time Spent:</strong>
                        <span id="time-spent">Loading...</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Score Breakdown</h4>
                <div class="score-breakdown">
                    <div class="score-item">
                        <span>Total Questions:</span>
                        <strong id="total-questions">Loading...</strong>
                    </div>
                    <div class="score-item">
                        <span>Correct Answers:</span>
                        <strong id="correct-answers">Loading...</strong>
                    </div>
                    <div class="score-item">
                        <span>Incorrect Answers:</span>
                        <strong id="incorrect-answers">Loading...</strong>
                    </div>
                    <div class="score-item">
                        <span>Total Score:</span>
                        <strong id="detail-score">Loading...</strong>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Question Review</h4>
                <div id="question-review">
                    <p>Loading question details...</p>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('attemptDetails').innerHTML = modalContent;
    document.getElementById('attemptModal').style.display = 'flex';
    
    // In a real implementation, you would make an AJAX call here
    // fetchAttemptDetails(attemptId);
}

function closeModal() {
    document.getElementById('attemptModal').style.display = 'none';
}

// Export attempts data as CSV
function exportAttemptsData() {
    const table = document.querySelector('.attempts-table');
    const rows = table.querySelectorAll('tr');
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const rowData = Array.from(cells).map(cell => {
            // Remove HTML tags and clean text
            let text = cell.innerText;
            // Escape commas and quotes for CSV
            if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        }).join(',');
        csvContent += rowData + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `quiz_attempts_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('attemptModal');
    if (event.target === modal) {
        closeModal();
    }
});

// Sample AJAX function (to be implemented)
function fetchAttemptDetails(attemptId) {
    fetch(`../api/get_attempt_details.php?id=${attemptId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update modal with fetched data
                document.getElementById('attempt-date').textContent = data.date_taken;
                document.getElementById('time-spent').textContent = data.time_spent;
                document.getElementById('total-questions').textContent = data.total_questions;
                document.getElementById('correct-answers').textContent = data.correct_answers;
                document.getElementById('incorrect-answers').textContent = data.incorrect_answers;
                document.getElementById('detail-score').textContent = data.score + '%';
                
                // Build question review
                let questionReview = '';
                data.questions.forEach((q, index) => {
                    questionReview += `
                        <div class="review-question ${q.correct ? 'correct' : 'incorrect'}">
                            <p><strong>Q${index + 1}:</strong> ${q.content}</p>
                            <p>Student's Answer: ${q.student_answer} ${q.correct ? '✓' : '✗'}</p>
                            <p>Correct Answer: ${q.correct_answer}</p>
                            ${q.hint ? `<p><em>Hint:</em> ${q.hint}</p>` : ''}
                        </div>
                    `;
                });
                document.getElementById('question-review').innerHTML = questionReview;
            } else {
                alert('Error loading attempt details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading attempt details');
        });
}