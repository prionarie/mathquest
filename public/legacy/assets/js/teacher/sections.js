function openModal(id){ document.getElementById(id).style.display = 'flex'; }
function closeModal(id){ document.getElementById(id).style.display = 'none'; }

function openEditSectionModal(id, name, year){
    document.getElementById('editSectionIdField').value = id;
    document.getElementById('editSectionName').value = name;
    document.getElementById('editSectionYear').value = year;
    openModal('editSectionModal');
}

function openEditStudentModal(id, first, middle, last, lrn, section_id, category){
    document.getElementById('editStudentId').value = id;
    document.getElementById('editFirstName').value = first;
    document.getElementById('editMiddleName').value = middle;
    document.getElementById('editLastName').value = last;
    document.getElementById('editLRN').value = lrn;
    document.getElementById('editSectionId').value = section_id;
    document.getElementById('editLearnerCategory').value = category;
    openModal('editStudentModal');
}

function confirmArchiveStudent(studentId, studentName){
    if (!confirm('Archive student: ' + studentName + ' ?')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    const inp = document.createElement('input'); inp.name='student_id'; inp.value = studentId; f.appendChild(inp);
    const btn = document.createElement('input'); btn.type='hidden'; btn.name = 'delete_student'; btn.value = '1'; f.appendChild(btn);
    document.body.appendChild(f);
    f.submit();
}

function confirmArchiveStudentList(sectionId, sectionName){
    const label = sectionName ? 'Archive student list for ' + sectionName + ' ?' : 'Archive student list for this section?';
    if (!confirm(label)) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    const inp = document.createElement('input'); inp.name='section_id'; inp.value = sectionId; f.appendChild(inp);
    const btn = document.createElement('input'); btn.type='hidden'; btn.name = 'delete_student_list'; btn.value = '1'; f.appendChild(btn);
    document.body.appendChild(f);
    f.submit();
}

function filterSections() {
    const input = document.getElementById('searchSections').value.toLowerCase();
    const rows = document.querySelectorAll('#sectionsTableBody tr');
    rows.forEach(row => {
        const sectionNameCell = row.cells[1];
        if (!sectionNameCell) return;
        const sectionName = sectionNameCell.textContent.toLowerCase();
        row.style.display = sectionName.includes(input) ? '' : 'none';
    });
}

function clearSectionSearch() {
    document.getElementById('searchSections').value = '';
    filterSections();
}

document.addEventListener('DOMContentLoaded', filterSections);

function filterByCategory(val){
    const rows = document.querySelectorAll('#studentsTableBody tr');
    rows.forEach(r => {
        if (!val) r.style.display = '';
        else r.style.display = r.dataset.category === val ? '' : 'none';
    });
}

function searchStudents(){
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#studentsTableBody tr');
    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = text.indexOf(q) !== -1 ? '' : 'none';
    });
}

function confirmResetPasscode(studentId) {
    if (confirm("Are you sure you want to reset this student's passcode? This action cannot be undone.")) {
        window.location.href = `reset_passcode.php?student_id=${studentId}`;
    }
}

function viewStudentProfile(studentId) {
    window.location.href = `student_profile.php?student_id=${studentId}`;
}

function confirmArchiveSection(sectionId, sectionName) {
    document.getElementById('archiveSectionId').value = sectionId;
    document.querySelector('#archiveSectionModal .modal-title').textContent = `Archive Section "${sectionName}"`;
    openModal('archiveSectionModal');
}
