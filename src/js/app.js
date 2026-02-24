/* ===== State ===== */
const state = {
    page: 1,
    perPage: 12,
    search: '',
    filters: { yearFrom: '', yearTo: '', adviser: '', proponent: '', panelist: '' },
    viewingId: null,
    deletingId: null,
    removeImage: false
};

const API = 'api/thesis.php';

/* ===== DOM Refs ===== */
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const searchInput = $('#searchInput');
const thesisGrid = $('#thesisGrid');
const pagination = $('#pagination');
const resultCount = $('#resultCount');
const filtersPanel = $('#filtersPanel');
const toggleFiltersBtn = $('#toggleFilters');

const formModal = $('#formModal');
const viewModal = $('#viewModal');
const deleteModal = $('#deleteModal');

const thesisForm = $('#thesisForm');
const modalTitle = $('#modalTitle');
const thesisIdInput = $('#thesisId');
const frontPageInput = $('#frontPageInput');
const imagePreview = $('#imagePreview');
const previewImg = $('#previewImg');
const fileUploadArea = $('#fileUploadArea');
const removeImgBtn = $('#removeImgBtn');

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', () => {
    fetchTheses();
    loadFilterOptions();
    bindEvents();
});

/* ===== Event Binding ===== */
function bindEvents() {
    searchInput.addEventListener('input', debounce(() => {
        state.search = searchInput.value;
        state.page = 1;
        fetchTheses();
    }, 300));

    toggleFiltersBtn.addEventListener('click', () => {
        filtersPanel.classList.toggle('hidden');
    });

    $('#applyFilters').addEventListener('click', applyFilters);
    $('#clearFilters').addEventListener('click', clearFilters);
    $('#addBtn').addEventListener('click', openAddModal);
    thesisForm.addEventListener('submit', handleFormSubmit);

    frontPageInput.addEventListener('change', handleFilePreview);
    removeImgBtn.addEventListener('click', handleRemoveImage);

    /* Modal close buttons */
    $$('.modal-close, .modal-cancel').forEach(btn => {
        btn.addEventListener('click', () => closeAllModals());
    });
    $$('.modal-backdrop').forEach(el => {
        el.addEventListener('click', () => closeAllModals());
    });

    /* View modal actions */
    $('#editFromView').addEventListener('click', () => {
        closeAllModals();
        openEditModal(state.viewingId);
    });
    $('#deleteFromView').addEventListener('click', () => {
        closeModal(viewModal);
        openDeleteModal(state.viewingId);
    });

    /* Delete confirm */
    $('#confirmDelete').addEventListener('click', async () => {
        if (!state.deletingId) return;
        await doDelete(state.deletingId);
    });

    /* Escape key */
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAllModals();
    });
}

/* ===== API Calls ===== */
async function fetchTheses() {
    showLoading();
    const params = new URLSearchParams({
        page: state.page,
        per_page: state.perPage,
        search: state.search
    });
    const f = state.filters;
    if (f.yearFrom) params.set('year_from', f.yearFrom);
    if (f.yearTo) params.set('year_to', f.yearTo);
    if (f.adviser) params.set('adviser', f.adviser);
    if (f.proponent) params.set('proponent', f.proponent);
    if (f.panelist) params.set('panelist', f.panelist);

    try {
        const res = await fetch(`${API}?${params}`);
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Failed to load');
        renderGrid(json.data);
        renderPagination(json.pagination);
        resultCount.textContent = `${json.pagination.total} ${json.pagination.total === 1 ? 'thesis' : 'theses'} found`;
    } catch (err) {
        thesisGrid.innerHTML = `<div class="empty-state"><div class="empty-state-icon">⚠️</div><h3>Error</h3><p>${escapeHtml(err.message)}</p></div>`;
        pagination.innerHTML = '';
        resultCount.textContent = 'Error loading theses';
    }
}

async function fetchThesis(id) {
    const res = await fetch(`${API}?id=${id}`);
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Not found');
    return json.data;
}

async function loadFilterOptions() {
    try {
        const res = await fetch(`${API}?action=filters`);
        const json = await res.json();
        const select = $('#adviserFilter');
        select.innerHTML = '<option value="">All Advisers</option>';
        (json.advisers || []).forEach(a => {
            const opt = document.createElement('option');
            opt.value = a;
            opt.textContent = a;
            select.appendChild(opt);
        });
    } catch { /* silent */ }
}

/* ===== Rendering ===== */
function showLoading() {
    thesisGrid.innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
}

function renderGrid(theses) {
    if (!theses.length) {
        thesisGrid.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📄</div>
                <h3>No theses found</h3>
                <p>${state.search || hasActiveFilters() ? 'Try adjusting your search or filters.' : 'Click "+ Add Thesis" to get started.'}</p>
            </div>`;
        return;
    }
    thesisGrid.innerHTML = theses.map(t => cardHtml(t)).join('');

    /* Bind card clicks */
    thesisGrid.querySelectorAll('.thesis-card').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.closest('.card-actions')) return;
            openViewModal(parseInt(card.dataset.id));
        });
    });
    thesisGrid.querySelectorAll('.btn-view').forEach(btn => {
        btn.addEventListener('click', () => openViewModal(parseInt(btn.dataset.id)));
    });
    thesisGrid.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => openEditModal(parseInt(btn.dataset.id)));
    });
    thesisGrid.querySelectorAll('.btn-del').forEach(btn => {
        btn.addEventListener('click', () => openDeleteModal(parseInt(btn.dataset.id)));
    });
}

function cardHtml(t) {
    const proponents = (t.proponents || '').split('\n').filter(Boolean);
    const thumbHtml = t.front_page
        ? `<img class="card-thumb" src="${escapeHtml(t.front_page)}" alt="Front page" loading="lazy">`
        : `<div class="card-thumb-placeholder">📄</div>`;
    return `
    <article class="thesis-card" data-id="${t.id}">
        ${thumbHtml}
        <div class="card-body">
            <div class="card-title" title="${escapeHtml(t.title)}">${escapeHtml(t.title)}</div>
            <div class="card-meta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                ${escapeHtml(t.year)}
            </div>
            <div class="card-meta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                ${escapeHtml(t.thesis_adviser)}
            </div>
            <div class="card-spacer"></div>
            <div class="card-actions">
                <button class="btn btn-sm btn-outline btn-view" data-id="${t.id}">View</button>
                <button class="btn btn-sm btn-ghost btn-edit" data-id="${t.id}">Edit</button>
                <button class="btn btn-sm btn-ghost btn-del" data-id="${t.id}" style="color:var(--danger)">Del</button>
            </div>
        </div>
    </article>`;
}

function renderPagination(p) {
    if (p.total_pages <= 1) { pagination.innerHTML = ''; return; }

    let html = '';
    html += `<button class="page-btn" data-page="${p.current_page - 1}" ${p.current_page <= 1 ? 'disabled' : ''}>&laquo;</button>`;

    const pages = getPageRange(p.current_page, p.total_pages);
    pages.forEach(pg => {
        if (pg === '...') {
            html += '<span class="page-ellipsis">…</span>';
        } else {
            html += `<button class="page-btn ${pg === p.current_page ? 'active' : ''}" data-page="${pg}">${pg}</button>`;
        }
    });

    html += `<button class="page-btn" data-page="${p.current_page + 1}" ${p.current_page >= p.total_pages ? 'disabled' : ''}>&raquo;</button>`;
    pagination.innerHTML = html;

    pagination.querySelectorAll('.page-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const pg = parseInt(btn.dataset.page);
            if (!isNaN(pg) && pg >= 1) {
                state.page = pg;
                fetchTheses();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
}

function getPageRange(current, total) {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    const pages = [];
    pages.push(1);
    if (current > 3) pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
        pages.push(i);
    }
    if (current < total - 2) pages.push('...');
    pages.push(total);
    return pages;
}

/* ===== Filters ===== */
function applyFilters() {
    state.filters.yearFrom = $('#yearFrom').value;
    state.filters.yearTo = $('#yearTo').value;
    state.filters.adviser = $('#adviserFilter').value;
    state.filters.proponent = $('#proponentFilter').value;
    state.filters.panelist = $('#panelistFilter').value;
    state.page = 1;
    fetchTheses();
}

function clearFilters() {
    $('#yearFrom').value = '';
    $('#yearTo').value = '';
    $('#adviserFilter').value = '';
    $('#proponentFilter').value = '';
    $('#panelistFilter').value = '';
    state.filters = { yearFrom: '', yearTo: '', adviser: '', proponent: '', panelist: '' };
    state.page = 1;
    fetchTheses();
}

function hasActiveFilters() {
    return Object.values(state.filters).some(v => v !== '');
}

/* ===== Add / Edit Modal ===== */
function openAddModal() {
    thesisForm.reset();
    thesisIdInput.value = '';
    modalTitle.textContent = 'Add Thesis';
    imagePreview.classList.add('hidden');
    fileUploadArea.classList.remove('hidden');
    state.removeImage = false;
    openModal(formModal);
}

async function openEditModal(id) {
    try {
        const t = await fetchThesis(id);
        thesisIdInput.value = t.id;
        modalTitle.textContent = 'Edit Thesis';
        $('#titleInput').value = t.title;
        $('#abstractInput').value = t.abstract;
        $('#yearInput').value = t.year;
        $('#proponentsInput').value = t.proponents;
        $('#panelistsInput').value = t.panelists;
        $('#adviserInput').value = t.thesis_adviser;

        state.removeImage = false;
        frontPageInput.value = '';
        if (t.front_page) {
            previewImg.src = t.front_page;
            imagePreview.classList.remove('hidden');
            fileUploadArea.classList.add('hidden');
        } else {
            imagePreview.classList.add('hidden');
            fileUploadArea.classList.remove('hidden');
        }
        openModal(formModal);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function handleFormSubmit(e) {
    e.preventDefault();
    const id = thesisIdInput.value;
    const formData = new FormData();
    formData.append('title', $('#titleInput').value);
    formData.append('abstract', $('#abstractInput').value);
    formData.append('year', $('#yearInput').value);
    formData.append('proponents', $('#proponentsInput').value);
    formData.append('panelists', $('#panelistsInput').value);
    formData.append('thesis_adviser', $('#adviserInput').value);

    if (frontPageInput.files.length > 0) {
        formData.append('front_page', frontPageInput.files[0]);
    }

    if (id) {
        formData.append('id', id);
        if (state.removeImage) formData.append('remove_image', '1');
    }

    try {
        const url = id ? `${API}?action=update` : API;
        const res = await fetch(url, { method: 'POST', body: formData });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Save failed');
        closeAllModals();
        showToast(id ? 'Thesis updated' : 'Thesis added', 'success');
        fetchTheses();
        loadFilterOptions();
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/* ===== View Modal ===== */
async function openViewModal(id) {
    try {
        const t = await fetchThesis(id);
        state.viewingId = id;
        $('#viewTitle').textContent = 'Thesis Details';

        const imgHtml = t.front_page
            ? `<div class="view-image"><img src="${escapeHtml(t.front_page)}" alt="Front page"></div>`
            : `<div class="view-image-placeholder">📄</div>`;

        const proponents = (t.proponents || '').split('\n').filter(Boolean).map(p => escapeHtml(p)).join('\n');
        const panelists = (t.panelists || '').split('\n').filter(Boolean).map(p => escapeHtml(p)).join('\n');

        $('#viewBody').innerHTML = `
            <div class="view-layout">
                ${imgHtml}
                <div class="view-info">
                    <h3>${escapeHtml(t.title)}</h3>
                    <div class="view-field">
                        <div class="view-field-label">Year</div>
                        <div class="view-field-value">${escapeHtml(t.year)}</div>
                    </div>
                    <div class="view-field">
                        <div class="view-field-label">Thesis Adviser</div>
                        <div class="view-field-value">${escapeHtml(t.thesis_adviser)}</div>
                    </div>
                    <div class="view-field">
                        <div class="view-field-label">Proponents</div>
                        <div class="view-field-value">${proponents}</div>
                    </div>
                    <div class="view-field">
                        <div class="view-field-label">Panelists</div>
                        <div class="view-field-value">${panelists}</div>
                    </div>
                </div>
            </div>
            <div class="view-abstract">
                <div class="view-field">
                    <div class="view-field-label">Abstract</div>
                    <div class="view-field-value">${escapeHtml(t.abstract)}</div>
                </div>
            </div>`;
        openModal(viewModal);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/* ===== Delete ===== */
function openDeleteModal(id) {
    state.deletingId = id;
    const card = thesisGrid.querySelector(`[data-id="${id}"] .card-title`);
    $('#deleteThesisName').textContent = card ? card.textContent : `Thesis #${id}`;
    openModal(deleteModal);
}

async function doDelete(id) {
    try {
        const res = await fetch(`${API}?id=${id}`, { method: 'DELETE' });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Delete failed');
        closeAllModals();
        showToast('Thesis deleted', 'success');
        fetchTheses();
        loadFilterOptions();
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/* ===== File Preview ===== */
function handleFilePreview() {
    const file = frontPageInput.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        previewImg.src = e.target.result;
        imagePreview.classList.remove('hidden');
        fileUploadArea.classList.add('hidden');
        state.removeImage = false;
    };
    reader.readAsDataURL(file);
}

function handleRemoveImage() {
    previewImg.src = '';
    frontPageInput.value = '';
    imagePreview.classList.add('hidden');
    fileUploadArea.classList.remove('hidden');
    state.removeImage = true;
}

/* ===== Modal Helpers ===== */
function openModal(modal) {
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal(modal) {
    modal.classList.add('hidden');
}

function closeAllModals() {
    [formModal, viewModal, deleteModal].forEach(m => m.classList.add('hidden'));
    document.body.style.overflow = '';
}

/* ===== Toast ===== */
function showToast(message, type = 'info') {
    const toast = $('#toast');
    toast.textContent = message;
    toast.className = `toast ${type}`;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast.classList.add('hidden'), 3000);
}

/* ===== Utilities ===== */
function debounce(fn, delay) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
