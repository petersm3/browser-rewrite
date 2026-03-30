/**
 * browser-rewrite standalone static frontend
 * Runs entirely in the browser using sql.js (SQLite compiled to WebAssembly).
 * No server infrastructure required.
 *
 * Replicates: Navigation.php, Display.php, DisplayDatabase.php,
 *             NavigationDatabase.php, Filter.php, and all controllers.
 */
(function () {
    'use strict';

    var db = null; // sql.js Database instance

    // -----------------------------------------------------------------------
    // Placeholder image generator (replaces external dummyimage.com)
    // -----------------------------------------------------------------------
    function generatePlaceholder(width, height, bgHex, fgHex, text) {
        var canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        var ctx = canvas.getContext('2d');

        // Fill background
        ctx.fillStyle = '#' + bgHex;
        ctx.fillRect(0, 0, width, height);

        // Render text
        if (text) {
            var fontSize = Math.max(Math.min(width / Math.max(text.length, 1) * 1.15, height * 0.5), 5);
            fontSize = Math.min(fontSize, 60);
            ctx.fillStyle = '#' + fgHex;
            ctx.font = fontSize + 'px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, width / 2, height / 2);
        }

        return canvas.toDataURL('image/png');
    }

    // -----------------------------------------------------------------------
    // Initialisation: load the SQLite database into memory
    // -----------------------------------------------------------------------
    function init() {
        // Determine base path (works with GitHub Pages subdirectory)
        var scripts = document.getElementsByTagName('script');
        var basePath = '';
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].getAttribute('src') || '';
            if (src.indexOf('js/app.js') !== -1) {
                basePath = src.replace('js/app.js', '');
                break;
            }
        }

        initSqlJs({
            locateFile: function (file) {
                return 'https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.10.3/' + file;
            }
        }).then(function (SQL) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', basePath + 'db/browser.db', true);
            xhr.responseType = 'arraybuffer';
            xhr.onload = function () {
                if (xhr.status === 200) {
                    var uInt8Array = new Uint8Array(xhr.response);
                    db = new SQL.Database(uInt8Array);
                    route();
                } else {
                    document.getElementById('app').innerHTML =
                        '<div class="container"><div class="alert alert-danger">' +
                        'Failed to load database (HTTP ' + xhr.status + '). ' +
                        'Run <code>npm install && npm run build</code> in the standalone/ directory first.' +
                        '</div></div>';
                }
            };
            xhr.onerror = function () {
                document.getElementById('app').innerHTML =
                    '<div class="container"><div class="alert alert-danger">' +
                    'Failed to fetch database file. Make sure db/browser.db exists.' +
                    '</div></div>';
            };
            xhr.send();
        });
    }

    // -----------------------------------------------------------------------
    // HTML escaping
    // -----------------------------------------------------------------------
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // -----------------------------------------------------------------------
    // Database helpers (replicate DisplayDatabase.php / NavigationDatabase.php)
    // -----------------------------------------------------------------------
    function dbAll(sql, params) {
        var stmt = db.prepare(sql);
        if (params) stmt.bind(params);
        var rows = [];
        while (stmt.step()) rows.push(stmt.getAsObject());
        stmt.free();
        return rows;
    }

    function dbGet(sql, params) {
        var rows = dbAll(sql, params);
        return rows.length > 0 ? rows[0] : null;
    }

    function getCategories() {
        return dbAll(
            'SELECT category, MIN(priority) as priority FROM categories GROUP BY category ORDER BY priority'
        );
    }

    function getSubCategories(category) {
        return dbAll(
            'SELECT subcategory FROM categories WHERE category = ?',
            [category]
        );
    }

    function getCategoriesId(category, subcategory) {
        var row = dbGet(
            'SELECT id FROM categories WHERE category = ? AND subcategory = ?',
            [category, subcategory]
        );
        return row ? row : { id: 0 };
    }

    function getFilterMatches(categoryIds, limit, offset) {
        if (categoryIds.length === 0) return [];
        var placeholders = categoryIds.map(function () { return '?'; }).join(',');
        var sql = 'SELECT fk_properties_id, COUNT(fk_properties_id) as count_fk_properties_id' +
            ' FROM filters WHERE fk_categories_id IN (' + placeholders + ')' +
            ' GROUP BY fk_properties_id HAVING count_fk_properties_id = ' + categoryIds.length;
        if (limit && limit > 0) sql += ' LIMIT ' + parseInt(limit);
        if (offset && offset > 0) sql += ' OFFSET ' + parseInt(offset);
        return dbAll(sql, categoryIds);
    }

    function getFilterMatchCount(categoryIds) {
        if (categoryIds.length === 0) return 0;
        var placeholders = categoryIds.map(function () { return '?'; }).join(',');
        var sql = 'SELECT COUNT(*) as total FROM (' +
            'SELECT fk_properties_id FROM filters WHERE fk_categories_id IN (' + placeholders + ')' +
            ' GROUP BY fk_properties_id HAVING COUNT(fk_properties_id) = ' + categoryIds.length +
            ')';
        var row = dbGet(sql, categoryIds);
        return row ? row.total : 0;
    }

    function getProperties(id) {
        var row = dbGet(
            'SELECT id, image, street_address, photographer, date FROM properties WHERE id = ?',
            [parseInt(id)]
        );
        return row ? row : { id: 0, image: '', street_address: '', photographer: '', date: '' };
    }

    function getAttributes(id) {
        return dbAll(
            'SELECT name, value FROM attributes WHERE fk_properties_id = ? ORDER BY name',
            [parseInt(id)]
        );
    }

    // -----------------------------------------------------------------------
    // URL / Hash state management
    // Uses hash routing: #/, #/about, #/display?id=N, #/?filter[]=X&limit=N
    // -----------------------------------------------------------------------
    function getHash() {
        var hash = window.location.hash || '#/';
        if (hash.charAt(0) === '#') hash = hash.substring(1);
        if (hash === '' || hash.charAt(0) !== '/') hash = '/' + hash;
        return hash;
    }

    function getHashPath() {
        var hash = getHash();
        var qIdx = hash.indexOf('?');
        return qIdx === -1 ? hash : hash.substring(0, qIdx);
    }

    function getHashParams() {
        var hash = getHash();
        var qIdx = hash.indexOf('?');
        if (qIdx === -1) return new URLSearchParams();
        return new URLSearchParams(hash.substring(qIdx + 1));
    }

    function getState() {
        var params = getHashParams();
        var filters = params.getAll('filter[]');
        if (filters.length === 0) filters = params.getAll('filter');
        var limit = parseInt(params.get('limit')) || 10;
        if (limit < 1 || limit > 500) limit = 10;
        var offset = parseInt(params.get('offset')) || 0;
        if (offset < 0) offset = 0;
        return { filters: filters, limit: limit, offset: offset };
    }

    function buildHash(filters, limit, offset) {
        var parts = [];
        for (var i = 0; i < filters.length; i++) {
            parts.push('filter[]=' + encodeURIComponent(filters[i]));
        }
        if (limit !== 10) parts.push('limit=' + limit);
        if (offset > 0) parts.push('offset=' + offset);
        return parts.length > 0 ? '#/?' + parts.join('&') : '#/';
    }

    // -----------------------------------------------------------------------
    // Focus management for SPA route changes
    // -----------------------------------------------------------------------
    function focusContent() {
        var el = document.getElementById('content');
        if (el) {
            el.setAttribute('tabindex', '-1');
            el.focus();
            el.removeAttribute('tabindex');
        }
    }

    // -----------------------------------------------------------------------
    // Routing
    // -----------------------------------------------------------------------
    function route() {
        var path = getHashPath();
        if (path === '/about') {
            renderAbout();
        } else if (path === '/display') {
            var params = getHashParams();
            renderDisplay(parseInt(params.get('id')));
        } else {
            renderMain();
        }
    }

    // -----------------------------------------------------------------------
    // Navigation bar (replicates Navigation.php)
    // -----------------------------------------------------------------------
    function buildNavbar(aboutMode, state) {
        var html = '';
        html += '<nav class="navbar navbar-default">';
        html += '<div class="container-fluid">';
        html += '<div class="navbar-header">';
        html += '<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">';
        html += '<span class="sr-only">Toggle navigation</span>';
        html += '<span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>';
        html += '</button>';
        html += '<a class="navbar-brand" href="#/">Browser <span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>';

        // Back button on display page
        if (aboutMode >= 1) {
            html += '<a class="navbar-brand" href="#/" onclick="window.history.back();return false;">';
            html += 'Back <span class="glyphicon glyphicon-menu-left" aria-hidden="true"></span></a>';
        }

        html += '</div>';
        html += '<div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">';
        html += '<ul class="nav navbar-nav">';

        if (!aboutMode) {
            var currentFilters = state.filters || [];
            var currentLimit = state.limit || 10;
            var colon = '%3A';

            // Resolve current filter category IDs (for badge counts)
            var currentCatIds = [];
            for (var fi = 0; fi < currentFilters.length; fi++) {
                var fParts = currentFilters[fi].split(':');
                var fCat = fParts[0].replace(/_/g, ' ');
                var fSub = decodeURIComponent(fParts[1].replace(/_/g, ' '));
                var fId = getCategoriesId(fCat, fSub);
                currentCatIds.push(fId.id);
            }

            var categories = getCategories();
            for (var c = 0; c < categories.length; c++) {
                var categoryRaw = categories[c].category;
                var categoryUnderscore = categoryRaw.replace(/ /g, '_');
                html += '<li class="dropdown">';
                html += '<a href="#" class="dropdown-toggle" data-toggle="dropdown"';
                html += ' role="button" aria-haspopup="true" aria-expanded="false">';
                html += esc(categoryRaw);
                html += '<span class="caret"></span></a><ul class="dropdown-menu">';
                html += '<fieldset><legend class="sr-only">' + esc(categoryRaw) + ' filters</legend>';

                var subs = getSubCategories(categoryRaw);
                for (var s = 0; s < subs.length; s++) {
                    var subRaw = subs[s].subcategory;
                    var subUnderscore = subRaw.replace(/ /g, '_');
                    var subEncode = encodeURIComponent(subUnderscore);
                    var filterValue = categoryUnderscore + ':' + subUnderscore;

                    // Check if this filter is currently active
                    var checked = false;
                    for (var k = 0; k < currentFilters.length; k++) {
                        if (encodeURIComponent(currentFilters[k]) === categoryUnderscore + colon + subEncode) {
                            checked = true;
                            break;
                        }
                    }

                    var checkId = categoryUnderscore + colon + subEncode;
                    html += '<li>&nbsp;<input type="checkbox"';
                    if (checked) html += ' checked';
                    html += ' class="filter-cb" data-filter="' + esc(filterValue) + '"';
                    html += ' id="' + esc(checkId) + '">';
                    html += ' <label class="dropdown-label" for="' + esc(checkId) + '">';
                    html += esc(subRaw);

                    // Badge count
                    if (!checked) {
                        var projectedIds = currentCatIds.slice();
                        var thisCatId = getCategoriesId(categoryRaw, subRaw);
                        projectedIds.push(thisCatId.id);
                        var count = getFilterMatchCount(projectedIds);
                        html += '&nbsp;&nbsp;<span class="badge">' + count;
                    } else {
                        html += '&nbsp;&nbsp;<span class="badge">0';
                    }
                    html += '</span></label></li>';
                }
                html += '</fieldset></ul></li>';
            }

            // Per page dropdown
            var limitOptions = [10, 50, 100, 250, 500];
            html += '<li class="dropdown">';
            html += '<a href="#" class="dropdown-toggle" data-toggle="dropdown"';
            html += ' role="button" aria-haspopup="true" aria-expanded="false">';
            html += 'Per page: ' + currentLimit;
            html += '<span class="caret"></span></a>';
            html += '<ul class="dropdown-menu">';
            html += '<fieldset><legend class="sr-only">Results per page</legend>';
            for (var li = 0; li < limitOptions.length; li++) {
                var opt = limitOptions[li];
                html += '<li>&nbsp;<input type="radio" name="limit" id="limit_' + opt;
                html += '" value="' + opt + '" class="limit-radio"';
                if (opt === currentLimit) html += ' checked';
                html += '> <label class="dropdown-label" for="limit_' + opt + '">';
                html += opt + '</label></li>';
            }
            html += '</fieldset></ul></li>';

            // Submit button for WCAG
            html += '<li><button type="button" class="btn btn-link submit-filters-btn">Submit</button></li>';
        }

        html += '</ul>';
        html += '<ul class="nav navbar-nav navbar-right">';
        html += '<li><a href="#/about">About</a></li>';
        html += '</ul>';
        html += '</div></div></nav>';

        return html;
    }

    // -----------------------------------------------------------------------
    // Breadcrumb bar (replicates breadcrumb section of Navigation.php)
    // -----------------------------------------------------------------------
    function buildBreadcrumbs(state) {
        var html = '<div class="container">';
        var filters = state.filters;

        if (filters.length > 0) {
            // Validate filters
            var error = false;
            var errorFilter = '';
            for (var i = 0; i < filters.length; i++) {
                var parts = filters[i].split(':');
                var cat = parts[0].replace(/_/g, ' ');
                var sub = decodeURIComponent(parts[1].replace(/_/g, ' '));
                var catId = getCategoriesId(cat, sub);
                if (catId.id < 1) {
                    error = true;
                    errorFilter = filters[i];
                    break;
                }
            }

            if (error) {
                html += '<div class="alert alert-danger" role="alert" aria-live="assertive">';
                html += '<span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span>';
                html += '<span class="sr-only">Error:</span>';
                html += ' Filter: "' + esc(errorFilter) + '" is not valid; please check your URL.';
                html += '</div>';
            } else {
                html += '<nav aria-label="Active filters">';
                html += '<ol class="breadcrumb">';
                for (var j = 0; j < filters.length; j++) {
                    html += '<li>' + esc(filters[j].replace(/_/g, ' ')) + '</li> ';
                }
                html += '<li><a href="#/">Clear all filters</a></li>';
                html += '</ol>';
                html += '</nav>';
            }
        } else {
            html += '<nav aria-label="Active filters">';
            html += '<ol class="breadcrumb">';
            html += '<li>Filters: <i>none</i></li>';
            html += '</ol>';
            html += '</nav>';
            html += '<div class="jumbotron">';
            html += 'Select filters from the dropdown categories above to begin your search.';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    // -----------------------------------------------------------------------
    // Results display (replicates Display::getResults)
    // -----------------------------------------------------------------------
    function buildResults(state) {
        var filters = state.filters;
        var limit = state.limit;
        var offset = state.offset;

        if (filters.length === 0) return '';

        // Resolve category IDs
        var categoryIds = [];
        for (var i = 0; i < filters.length; i++) {
            var parts = filters[i].split(':');
            var cat = parts[0].replace(/_/g, ' ');
            var sub = decodeURIComponent(parts[1].replace(/_/g, ' '));
            var catId = getCategoriesId(cat, sub);
            if (catId.id < 1) return '';
            categoryIds.push(catId.id);
        }

        var matches = getFilterMatches(categoryIds, limit, offset);
        var html = '<div class="container">';

        if (matches.length === 0) {
            html += '<div class="jumbotron">';
            html += 'No matches found satisfying an exact match to the above filter critera.';
            html += '</div>';
        } else {
            for (var m = 0; m < matches.length; m++) {
                var props = getProperties(matches[m].fk_properties_id);
                html += '<div class="jumbotron"><div class="row">';
                html += '<div class="col-sm-5">';
                html += '<a href="#/display?id=' + props.id + '" aria-label="View accession ' + props.id + '">';
                html += '<img class="img-responsive" src="' + generatePlaceholder(320, 240, '000', 'fff', props.image);
                html += '" alt="' + esc(props.image) + '"/></a>';
                html += '</div>';
                html += '<div class="col-sm-2"></div>';
                html += '<div class="col-sm-5">';
                html += '<table class="table">';
                html += '<tr><th scope="row">Accession:</th><td>';
                html += '<a href="#/display?id=' + props.id + '" aria-label="View accession ' + props.id + '">' + props.id + '</a></td></tr>';
                html += '<tr><th scope="row">Address:</th><td>' + esc(props.street_address) + '</td></tr>';
                html += '<tr><th scope="row">Photographer:</th><td>' + esc(props.photographer) + '</td></tr>';
                html += '<tr><th scope="row">Date:</th><td>' + esc(props.date) + '</td></tr>';
                html += '</table></div></div></div>';
            }
        }

        // Pagination
        var filterMatchCount = getFilterMatchCount(categoryIds);
        var totalPages = Math.ceil(filterMatchCount / limit);
        var startResult = offset + 1;
        var endResult = Math.min(offset + limit, filterMatchCount);

        html += '<div class="text-center">';
        html += '<p aria-live="polite">Showing ' + startResult + '-' + endResult + ' of ' + filterMatchCount + ' results</p>';
        html += '<nav aria-label="Pagination">';
        html += '<ul class="pagination">';

        for (var page = 1; page <= totalPages; page++) {
            var currentOffset = (page - 1) * limit;
            if (page === 1) currentOffset = 0;
            var active = (currentOffset === offset);
            html += '<li' + (active ? ' class="active"' : '') + '>';
            html += '<a href="' + buildHash(filters, limit, currentOffset) + '"';
            if (active) {
                html += ' aria-current="page"';
            } else {
                html += ' aria-label="Go to page ' + page + '"';
            }
            html += '>' + page;
            if (active) html += ' <span class="sr-only">(current)</span>';
            html += '</a></li>';
        }

        html += '</ul></nav></div></div>';
        return html;
    }

    // -----------------------------------------------------------------------
    // Main page render
    // -----------------------------------------------------------------------
    function renderMain() {
        var state = getState();
        document.title = 'Image and cultural properties browser';

        var html = buildNavbar(0, state);
        html += '<main id="content">';
        html += '<h1 class="sr-only">Image and cultural properties browser</h1>';
        html += buildBreadcrumbs(state);
        html += buildResults(state);
        html += '</main>';

        document.getElementById('app').innerHTML = html;
        bindEvents(state);
        window.scrollTo(0, 0);
    }

    // -----------------------------------------------------------------------
    // Display page (single accession, replicates Display::getAccession)
    // -----------------------------------------------------------------------
    function renderDisplay(id) {
        document.title = 'Display ' + id;

        var html = buildNavbar(2, {});

        if (!id || isNaN(id)) {
            html += '<div class="container"><p>Invalid accession ID.</p></div>';
            document.getElementById('app').innerHTML = html;
            return;
        }

        var props = getProperties(id);
        if (props.id !== id) {
            html += '<div class="container"><main id="content"><h1 class="sr-only">Accession Detail</h1>Accession ' + parseInt(id) + ' not found.</main></div>';
            document.getElementById('app').innerHTML = html;
            focusContent();
            return;
        }

        var attrs = getAttributes(id);

        html += '<div class="container"><main id="content">';
        html += '<h1 class="sr-only">Accession Detail</h1>';
        html += '<div class="jumbotron">';
        html += '<img class="img-responsive" src="' + generatePlaceholder(640, 480, '000', 'fff', props.image);
        html += '" alt="' + esc(props.image) + '"/>';
        html += '<table class="table">';
        html += '<tr><th scope="row">Accession:</th><td>' + props.id + '</td></tr>';
        html += '<tr><th scope="row">Address:</th><td>' + esc(props.street_address) + '</td></tr>';
        html += '<tr><th scope="row">Photographer:</th><td>' + esc(props.photographer) + '</td></tr>';
        html += '<tr><th scope="row">Date:</th><td>' + esc(props.date) + '</td></tr>';

        for (var a = 0; a < attrs.length; a++) {
            html += '<tr><th scope="row">' + esc(attrs[a].name) + '</th><td>' + esc(attrs[a].value) + '</td></tr>';
        }

        html += '</table></div></main></div>';
        document.getElementById('app').innerHTML = html;
        focusContent();
    }

    // -----------------------------------------------------------------------
    // About page
    // -----------------------------------------------------------------------
    function renderAbout() {
        document.title = 'About';

        var extIcon = ' <span class="sr-only">(opens in new window)</span>';
        var html = buildNavbar(1, {});
        html += '<div class="container">';
        html += '<main id="content">';
        html += '<h1>About</h1>';
        html += '<ul>';
        html += '  <li>Author: Matthew Peterson <a href="mailto:petersm3@oregonstate.edu">petersm3@oregonstate.edu</a></li>';
        html += '  <li>Project: Image and cultural properties browser';
        html += '      <ul>';
        html += '          <li>Emulating the functionality of <a target="_blank" rel="noopener" href="https://oregondigital.org/collections/building-or">Building Oregon' + extIcon + '</a> (circa 2015)</li>';
        html += '      </ul>';
        html += '  </li>';
        html += '  <li>GitHub: <a target="_blank" rel="noopener" href="https://github.com/petersm3/browser-rewrite">https://github.com/petersm3/browser-rewrite' + extIcon + '</a></li>';
        html += '  <li>Technologies used:';
        html += '      <ul>';
        html += '         <li><a target="_blank" rel="noopener" href="https://sql.js.org/">sql.js' + extIcon + '</a> (SQLite in WebAssembly)</li>';
        html += '         <li><a target="_blank" rel="noopener" href="https://getbootstrap.com/">Bootstrap' + extIcon + '</a></li>';
        html += '         <li><a target="_blank" rel="noopener" href="https://claude.ai">Claude Code' + extIcon + '</a></li>';
        html += '      </ul>';
        html += '   </li>';
        html += '</ul>';
        html += '</main></div>';

        document.getElementById('app').innerHTML = html;
        focusContent();
    }

    // -----------------------------------------------------------------------
    // Event binding (filter checkboxes, limit radios, submit button)
    // -----------------------------------------------------------------------
    function bindEvents(state) {
        $(document).off('.browserApp');

        $(document).on('change.browserApp', '.filter-cb', function () {
            submitCurrentFilters(state.limit);
        });

        $(document).on('change.browserApp', '.limit-radio', function () {
            submitCurrentFilters(parseInt($(this).val()));
        });

        $(document).on('click.browserApp', '.submit-filters-btn', function () {
            var limit = parseInt($('.limit-radio:checked').val()) || state.limit;
            submitCurrentFilters(limit);
        });
    }

    function submitCurrentFilters(limit) {
        var filters = [];
        $('.filter-cb:checked').each(function () {
            filters.push($(this).data('filter'));
        });
        window.location.hash = buildHash(filters, limit, 0);
    }

    // -----------------------------------------------------------------------
    // Hash change listener
    // -----------------------------------------------------------------------
    window.addEventListener('hashchange', function () {
        if (db) route();
    });

    // -----------------------------------------------------------------------
    // Start
    // -----------------------------------------------------------------------
    init();

})();
