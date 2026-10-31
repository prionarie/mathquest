document.addEventListener('DOMContentLoaded', function() {

    const dateInputs = document.querySelectorAll('.date-picker');
    dateInputs.forEach(input => {
        flatpickr(input, {
            dateFormat: "Y-m-d",
            allowInput: true
        });
    });

    const learnerCategoryChart = document.getElementById('learnerCategoryChart');
    
    if (learnerCategoryChart && typeof chartData !== 'undefined') {
        const ctx = learnerCategoryChart.getContext('2d');
        
        new Chart(ctx, {
            type: 'pie',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} students (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });
    }

    initializeCharts();

    document.getElementById('section-filter').addEventListener('change', updateReport);
    document.getElementById('quarter-filter').addEventListener('change', updateReport);
    document.getElementById('week-filter').addEventListener('change', updateReport);
    document.getElementById('report-type').addEventListener('change', updateReport);


    setupPrintFunctionality();
});



function updateReport() {

    const form = document.querySelector('.filters-form');
    form.submit();
}

function resetFilters() {
    const url = new URL(window.location);
    url.search = '';
    window.location.href = url.toString();
}

function exportReport() {
    const reportData = {
        title: 'MathQuest Analytics Report',
        generated: new Date().toLocaleString(),
        filters: getCurrentFilters(),
        data: collectReportData()
    };

    let csvContent = "data:text/csv;charset=utf-8,";

    csvContent += "MathQuest Analytics Report\r\n";
    csvContent += `Generated: ${reportData.generated}\r\n`;
    csvContent += `Quarter: ${reportData.filters.quarter}\r\n`;
    if (reportData.filters.section) {
        csvContent += `Section: ${reportData.filters.section}\r\n`;
    }
    csvContent += "\r\n";

    csvContent += "Summary Statistics\r\n";
    csvContent += "Metric,Value\r\n";
    csvContent += `Total Students,${reportData.data.total_students}\r\n`;
    csvContent += `Total Quizzes,${reportData.data.total_quizzes}\r\n`;
    csvContent += `Total Attempts,${reportData.data.total_attempts}\r\n`;
    csvContent += `Overall Average,${reportData.data.overall_average}%\r\n`;
    csvContent += "\r\n";

    csvContent += "Top Performing Students\r\n";
    csvContent += "Rank,Student Name,LRN,Section,Average Score,Quizzes Taken\r\n";
    reportData.data.top_students.forEach((student, index) => {
        csvContent += `${index + 1},"${student.name}",${student.lrn},"${student.section}",${student.score}%,${student.quizzes}\r\n`;
    });
    csvContent += "\r\n";

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `mathquest_report_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function printReport() {
    const originalContent = document.body.innerHTML;
    const printContent = document.querySelector('.main-content').innerHTML;

    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>MathQuest Analytics Report</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
                .stat-card { border: 1px solid #ddd; padding: 15px; border-radius: 8px; text-align: center; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                th { background-color: #f5f5f5; }
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h1>MathQuest Analytics Report</h1>
                <p>Generated: ${new Date().toLocaleString()}</p>
            </div>
            ${printContent}
        </body>
        </html>
    `;

    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}

function getCurrentFilters() {
    return {
        section: document.getElementById('section-filter').value,
        quarter: document.getElementById('quarter-filter').value,
        week: document.getElementById('week-filter').value,
        report: document.getElementById('report-type').value
    };
}

function collectReportData() {
    return {
        total_students: document.querySelectorAll('.stat-card')[0].querySelector('.stat-value').textContent,
        total_quizzes: document.querySelectorAll('.stat-card')[1].querySelector('.stat-value').textContent,
        total_attempts: document.querySelectorAll('.stat-card')[2].querySelector('.stat-value').textContent,
        overall_average: document.querySelectorAll('.stat-card')[3].querySelector('.stat-value').textContent.replace('%', ''),
        top_students: []
    };
}

function initializeCharts() {
    const performanceCtx = document.getElementById('performanceChart');
    if (performanceCtx) {
        new Chart(performanceCtx, {
            type: 'bar',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [{
                    label: 'Average Score',
                    data: [75, 82, 68, 90],
                    backgroundColor: 'rgba(58, 12, 163, 0.8)',
                    borderColor: 'rgba(58, 12, 163, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    }

    const categoryCtx = document.getElementById('categoryChart');
    if (categoryCtx) {
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: ['Fast Learner', 'Average Learner', 'Slow Learner'],
                datasets: [{
                    data: [30, 50, 20],
                    backgroundColor: [
                        'rgba(46, 196, 182, 0.8)',
                        'rgba(255, 159, 28, 0.8)',
                        'rgba(231, 29, 54, 0.8)'
                    ]
                }]
            }
        });
    }
}

function setupPrintFunctionality() {
    const style = document.createElement('style');
    style.textContent = `
        @media print {
            .sidebar, .top-header .header-actions, .filters-card, .btn {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 20px !important;
            }
            .stat-card {
                break-inside: avoid;
            }
        }
    `;
    document.head.appendChild(style);
}

document.addEventListener('keydown', function (e) {
    // Ctrl + P for print
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        printReport();
    }
    // Ctrl + E for export
    if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        exportReport();
    }
});
