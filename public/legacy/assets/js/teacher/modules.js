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
    setupFilePreview();
    setupQuarterSelectors();
    setupSettingsChangeListeners();

    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            const uploadBtn = document.getElementById('uploadSubmitBtn');
            if (uploadBtn) {
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            }
            return true;
        });
    }
});

function openUploadModal() {
    document.getElementById('uploadModal').style.display = 'block';
    const form = document.getElementById('uploadForm');
    form.querySelectorAll('input[type=text], textarea, select, input[type=number]').forEach(i => i.value = '');
    form.querySelector('#type').value = '';
    document.getElementById('urlGroup').style.display = 'none';
    document.getElementById('fileUploadGroup').style.display = 'block';
}


function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
}

function submitUploadForm() {
    console.log("submitUploadForm called");

    const form = document.getElementById('uploadForm');
    if (!form) {
        console.error("Upload form not found!");
        alert("Form not found. Please refresh the page.");
        return;
    }

    const title = document.getElementById('title');
    const type = document.getElementById('type');
    const week = document.getElementById('week');
    const file = document.getElementById('file');
    const url = document.getElementById('url');

    if (!title || !title.value.trim()) {
        alert("Please enter a title");
        title.focus();
        return;
    }

    if (!type || !type.value) {
        alert("Please select a material type");
        if (type) type.focus();
        return;
    }

    if (!week || !week.value || week.value < 1 || week.value > 52) {
        alert("Please enter a valid week (1-52)");
        if (week) week.focus();
        return;
    }

    if (type.value === 'Website/Link') {
        if (!url || !url.value.trim()) {
            alert("Please enter a URL for website/link type");
            if (url) url.focus();
            return;
        }

        const rawUrl = url.value.trim();

        if (!rawUrl) {
            alert("Please enter a URL for website/link type");
            url.focus();
            return;
        }

        try {
            new URL(rawUrl);
        } catch {
            alert("Please enter a valid URL");
            url.focus();
            return;
        }


    } else {
        if (!file || !file.files || file.files.length === 0) {
            alert("Please select a file to upload");
            if (file) file.click();
            return;
        }

        const maxSize = 50 * 1024 * 1024;
        if (file.files[0].size > maxSize) {
            alert("File is too large. Maximum size is 50MB.");
            return;
        }
    }

    const quarterSelected = form.querySelector('input[name="quarter"]:checked');
    if (!quarterSelected) {
        alert("Please select a quarter");
        return;
    }

    const submitBtn = document.getElementById('uploadSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    }


    const formData = new FormData(form);

    formData.append('upload_material', '1');

    console.log("FormData contents:");
    for (let [key, value] of formData.entries()) {
        console.log(key + ": " + (value instanceof File ? value.name : value));
    }

    fetch('', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            console.log("Response received");
            if (response.redirected) {

                window.location.href = response.url;
                return null;
            }
            return response.text();
        })
        .then(html => {
            if (html === null) return;

            console.log("Processing response");

            if (html.includes('alert-success') || html.includes('alert-error')) {

                window.location.reload();
            } else {

                window.location.reload();
            }
        })
        .catch(error => {
            console.error("Upload error:", error);
            alert("An error occurred during upload. Please try again.");

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Material';
            }
        });

    return false;
}

function resetUploadForm() {
    const form = document.getElementById('uploadForm');
    if (form) {
        form.reset();
    }

    const preview = document.getElementById('filePreview');
    if (preview) {
        preview.classList.remove('active');
        preview.innerHTML = '';
    }

    const fileUploadGroup = document.getElementById('fileUploadGroup');
    const urlGroup = document.getElementById('urlGroup');
    if (fileUploadGroup) fileUploadGroup.style.display = 'block';
    if (urlGroup) urlGroup.style.display = 'none';

    const uploadBtn = document.getElementById('uploadSubmitBtn');
    if (uploadBtn) {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Material';
    }

    const weekInput = document.getElementById('week');
    if (weekInput) {
        weekInput.value = typeof CURRENT_WEEK !== 'undefined' ? CURRENT_WEEK : 1;
    }

    document.querySelectorAll('.quarter-option').forEach(opt => {
        opt.classList.remove('active');
        const input = opt.querySelector('input');
        if (input && input.value === (typeof CURRENT_QUARTER !== 'undefined' ? CURRENT_QUARTER : '1st')) {
            opt.classList.add('active');
            input.checked = true;
        }
    });

    const typeSelect = document.getElementById('type');
    if (typeSelect) {
        typeSelect.value = '';
        toggleUploadFields();
    }
}

function setupQuarterSelectors() {
    document.querySelectorAll('.quarter-option').forEach(option => {
        option.addEventListener('click', function () {
            document.querySelectorAll('.quarter-option').forEach(opt => opt.classList.remove('active'));
            this.classList.add('active');
            const input = this.querySelector('input');
            if (input) {
                input.checked = true;
            }
        });
    });
}

function setupSettingsChangeListeners() {
    const currentQuarterSelect = document.getElementById('current_quarter');
    const currentWeekSelect = document.getElementById('current_week');
    const currentDisplay = document.getElementById('current-display');

    if (currentQuarterSelect && currentWeekSelect && currentDisplay) {
        function updateDisplay() {
            const quarter = currentQuarterSelect.options[currentQuarterSelect.selectedIndex].text;
            const week = currentWeekSelect.options[currentWeekSelect.selectedIndex].text;
            currentDisplay.textContent = `Current: ${quarter}, ${week}`;
        }

        currentQuarterSelect.addEventListener('change', updateDisplay);
        currentWeekSelect.addEventListener('change', updateDisplay);
    }
}

function toggleUploadFields() {
    const type = document.getElementById('type').value;
    const fileUploadGroup = document.getElementById('fileUploadGroup');
    const urlGroup = document.getElementById('urlGroup');
    const fileInput = document.getElementById('file');
    const urlInput = document.getElementById('url');

    if (type === 'Website/Link') {
        if (fileUploadGroup) fileUploadGroup.style.display = 'none';
        if (urlGroup) urlGroup.style.display = 'block';
        if (fileInput) {
            fileInput.removeAttribute('required');
            fileInput.value = '';
        }
        if (urlInput) {
            urlInput.setAttribute('required', 'required');
            urlInput.value = urlInput.value || '';
        }
    } else {
        if (fileUploadGroup) fileUploadGroup.style.display = 'block';
        if (urlGroup) urlGroup.style.display = 'none';
        if (fileInput) {
            fileInput.setAttribute('required', 'required');
            fileInput.value = fileInput.value || '';
        }
        if (urlInput) {
            urlInput.removeAttribute('required');
        }

        let accept = '';
        switch (type) {
            case 'Module':
                accept = '.pdf,.docx';
                break;
            case 'Presentation':
                accept = '.pdf,.ppt,.pptx';
                break;
            case 'Video':
                accept = '.mp4,.webm,.avi,.mov';
                break;
            case 'Image':
                accept = '.png,.jpg,.jpeg,.gif,.svg';
                break;
            default:
                accept = '';
        }
        if (fileInput) fileInput.setAttribute('accept', accept);
    }

    const preview = document.getElementById('filePreview');
    if (preview) {
        preview.classList.remove('active');
        preview.innerHTML = '';
    }
}

function setupFilePreview() {
    const fileInput = document.getElementById('file');
    const preview = document.getElementById('filePreview');

    if (fileInput && preview) {
        fileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                preview.innerHTML = `
                    <strong>Selected file:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB<br>
                    <strong>Type:</strong> ${file.type}
                `;
                preview.classList.add('active');
            } else {
                preview.classList.remove('active');
                preview.innerHTML = '';
            }
        });
    }
}

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

function downloadMaterial(materialId) {
    window.location.href = `modules/download_material.php?id=${materialId}`;
}

function editMaterial(materialId, title, description, type, quarter, week, filePath, url) {
    console.log("Editing material:", materialId);

    document.getElementById('edit_material_id').value = materialId;
    document.getElementById('edit_title').value = title || '';
    document.getElementById('edit_description').value = description || '';
    document.getElementById('edit_type').value = type || '';
    document.getElementById('edit_week').value = week || CURRENT_WEEK;

    document.querySelectorAll('#editQuarterSelector .quarter-option').forEach(option => {
        const input = option.querySelector('input[type="radio"]');
        if (input && input.value === quarter) {
            option.classList.add('active');
            input.checked = true;
        } else {
            option.classList.remove('active');
            if (input) input.checked = false;
        }
    });

    if (type === 'Website/Link') {
        document.getElementById('edit_url').value = url || '';
        toggleEditUploadFields();
    } else if (filePath) {

        const fileName = filePath.split('/').pop();
        document.getElementById('currentFileName').textContent = fileName;
        document.getElementById('editCurrentFileInfo').style.display = 'block';
        toggleEditUploadFields();
    } else {
        document.getElementById('editCurrentFileInfo').style.display = 'none';
        toggleEditUploadFields();
    }

    openEditModal();
}

function openEditModal() {
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function toggleEditUploadFields() {
    const type = document.getElementById('edit_type').value;
    const fileUploadGroup = document.getElementById('editFileUploadGroup');
    const urlGroup = document.getElementById('editUrlGroup');

    if (type === 'Website/Link') {
        if (fileUploadGroup) fileUploadGroup.style.display = 'none';
        if (urlGroup) urlGroup.style.display = 'block';
    } else {
        if (fileUploadGroup) fileUploadGroup.style.display = 'block';
        if (urlGroup) urlGroup.style.display = 'none';

        let accept = '';
        switch (type) {
            case 'Module':
                accept = '.pdf,.docx';
                break;
            case 'Presentation':
                accept = '.pdf,.ppt,.pptx';
                break;
            case 'Video':
                accept = '.mp4,.webm,.avi,.mov';
                break;
            case 'Image':
                accept = '.png,.jpg,.jpeg,.gif,.svg';
                break;
            default:
                accept = '';
        }
        const fileInput = document.getElementById('edit_file');
        if (fileInput) fileInput.setAttribute('accept', accept);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#editQuarterSelector .quarter-option').forEach(option => {
        option.addEventListener('click', function () {
            document.querySelectorAll('#editQuarterSelector .quarter-option').forEach(opt => opt.classList.remove('active'));
            this.classList.add('active');
            const input = this.querySelector('input');
            if (input) {
                input.checked = true;
            }
        });
    });

    const editFileInput = document.getElementById('edit_file');
    const editPreview = document.getElementById('editFilePreview');
    if (editFileInput && editPreview) {
        editFileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                editPreview.innerHTML = `
                    <strong>New file:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB<br>
                    <strong>Type:</strong> ${file.type}
                `;
                editPreview.classList.add('active');
            } else {
                editPreview.classList.remove('active');
                editPreview.innerHTML = '';
            }
        });
    }
});

function deleteMaterial(materialId) {
    if (confirm('Are you sure you want to delete this material? This action cannot be undone.')) {
        fetch(`modules/delete_material.php?id=${materialId}`, {
            method: 'DELETE',
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error deleting material: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting material. Please try again.');
            });
    }
}

window.onclick = function (event) {
    const modal = document.getElementById('uploadModal');
    if (event.target === modal) {
        closeUploadModal();
    }
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeUploadModal();
    }
});

console.log("Upload form exists:", !!document.getElementById('uploadForm'));
console.log("Upload button exists:", !!document.getElementById('uploadSubmitBtn'));

const uploadBtn = document.getElementById('uploadSubmitBtn');
if (uploadBtn) {
    uploadBtn.addEventListener('click', function (e) {
        console.log("Upload button clicked directly");
    });
}