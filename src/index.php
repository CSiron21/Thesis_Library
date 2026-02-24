<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thesis Library</title>
    <meta name="description" content="Thesis Library – search, browse, and manage academic theses.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<canvas id="bg-canvas"></canvas>

<!-- ===== Header ===== -->
<header class="header">
    <div class="container header-inner">
        <h1 class="logo">
            <img src="assets/soc-logo.png" alt="SOC Logo" class="logo-img"> 
            <span class="logo-text">Thesis Library</span>
        </h1>
        <button class="btn btn-primary" id="addBtn">+ Add Thesis</button>
    </div>
</header>

<!-- ===== Main ===== -->
<main class="container main">

    <!-- Search -->
    <section class="search-section">
        <div class="search-row">
            <div class="search-bar">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by title or abstract…" autocomplete="off">
            </div>
            <button class="btn btn-outline" id="toggleFilters">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filters
            </button>
        </div>

        <!-- Advanced Filters -->
        <div id="filtersPanel" class="filters-panel hidden">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="yearFrom">Year From</label>
                    <input type="number" id="yearFrom" min="1900" max="2099" placeholder="e.g. 2020">
                </div>
                <div class="filter-group">
                    <label for="yearTo">Year To</label>
                    <input type="number" id="yearTo" min="1900" max="2099" placeholder="e.g. 2025">
                </div>
                <div class="filter-group">
                    <label for="adviserFilter">Thesis Adviser</label>
                    <select id="adviserFilter"><option value="">All Advisers</option></select>
                </div>
                <div class="filter-group">
                    <label for="proponentFilter">Proponent</label>
                    <input type="text" id="proponentFilter" placeholder="Name…">
                </div>
                <div class="filter-group">
                    <label for="panelistFilter">Panelist</label>
                    <input type="text" id="panelistFilter" placeholder="Name…">
                </div>
            </div>
            <div class="filters-actions">
                <button class="btn btn-sm btn-primary" id="applyFilters">Apply Filters</button>
                <button class="btn btn-sm btn-ghost" id="clearFilters">Clear All</button>
            </div>
        </div>
    </section>

    <!-- Results Info -->
    <div class="results-bar">
        <span id="resultCount">Loading…</span>
    </div>

    <!-- Thesis Grid -->
    <section id="thesisGrid" class="thesis-grid"></section>

    <!-- Pagination -->
    <nav id="pagination" class="pagination"></nav>
</main>

<!-- ===== Add / Edit Modal ===== -->
<div id="formModal" class="modal hidden" role="dialog" aria-labelledby="modalTitle">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="modalTitle">Add Thesis</h2>
            <button class="modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="thesisForm" class="modal-body" enctype="multipart/form-data">
            <input type="hidden" id="thesisId">
            
            <div class="form-grid">
                <div class="form-col">
                    <div class="form-group">
                        <label for="titleInput">Thesis Title <span class="required">*</span></label>
                        <input type="text" id="titleInput" required>
                    </div>
                    <div class="form-group">
                        <label for="abstractInput">Abstract <span class="required">*</span></label>
                        <textarea id="abstractInput" rows="10" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="frontPageInput">Front Page Image</label>
                        <div class="file-upload-area" id="fileUploadArea">
                            <input type="file" id="frontPageInput" accept="image/*" class="file-input">
                            <div class="file-upload-label">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <span>Upload Image</span>
                            </div>
                        </div>
                        <div id="imagePreview" class="image-preview hidden">
                            <img id="previewImg" alt="Preview">
                            <button type="button" class="remove-img-btn" id="removeImgBtn" title="Remove image">&times;</button>
                        </div>
                    </div>
                </div>

                <div class="form-col">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="yearInput">Year <span class="required">*</span></label>
                            <input type="number" id="yearInput" min="1900" max="2099" required>
                        </div>
                        <div class="form-group">
                            <label for="adviserInput">Thesis Adviser <span class="required">*</span></label>
                            <input type="text" id="adviserInput" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="proponentsInput">Proponents <span class="required">*</span> <small>(one per line)</small></label>
                        <textarea id="proponentsInput" rows="5" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="panelistsInput">Panelists <span class="required">*</span> <small>(one per line)</small></label>
                        <textarea id="panelistsInput" rows="5" required></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Thesis</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== View Modal ===== -->
<div id="viewModal" class="modal hidden" role="dialog" aria-labelledby="viewTitle">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="viewTitle">Thesis Details</h2>
            <button class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="viewBody"></div>
        <div class="modal-footer">
            <button class="btn btn-ghost modal-cancel">Close</button>
            <button class="btn btn-outline" id="editFromView">Edit</button>
            <button class="btn btn-danger" id="deleteFromView">Delete</button>
        </div>
    </div>
</div>

<!-- ===== Delete Confirmation Modal ===== -->
<div id="deleteModal" class="modal hidden" role="alertdialog" aria-labelledby="deleteTitle">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2 id="deleteTitle">Confirm Delete</h2>
            <button class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteThesisName"></strong>? This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost modal-cancel">Cancel</button>
            <button class="btn btn-danger" id="confirmDelete">Delete</button>
        </div>
    </div>
</div>

<!-- ===== Toast ===== -->
<div id="toast" class="toast hidden"></div>

<script src="js/background.js"></script>
<script src="js/app.js"></script>
</body>
</html>
