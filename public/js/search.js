/*
 * Barre de recherche partagée.
 * Chaque page définit window.searchConfig avant de charger ce script :
 *   data        : tableau d'objets à filtrer
 *   fields      : champs dans lesquels chercher
 *   labelField  : champ affiché en titre (ou fonction item => texte)
 *   metaFields  : champs affichés sous le titre (ou fonction item => [textes])
 *   onSelect    : fonction appelée avec l'élément choisi
 *   emptyText   : message si aucun résultat (optionnel)
 */
(function () {
    const config = window.searchConfig;
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    if (!config || !searchInput || !searchResults) return;

    const getLabel = item => typeof config.labelField === 'function'
        ? config.labelField(item)
        : (item[config.labelField] || '');

    const getMeta = item => {
        const meta = typeof config.metaFields === 'function'
            ? config.metaFields(item)
            : (config.metaFields || []).map(field => item[field]);
        return meta.filter(value => value !== null && value !== undefined && value !== '');
    };

    const getSearchText = item => [getLabel(item)]
        .concat((config.fields || []).map(field => item[field]))
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    function highlightText(text, query) {
        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escapeHtml(text).replace(new RegExp(`(${escapeHtml(escapedQuery)})`, 'gi'), '<span class="highlight">$1</span>');
    }

    function hideResults() {
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
    }

    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        if (query === '') {
            hideResults();
            return;
        }

        const resultats = config.data.filter(item => getSearchText(item).includes(query));

        if (resultats.length === 0) {
            searchResults.innerHTML = `<div class="no-results">${escapeHtml(config.emptyText || 'Aucun résultat trouvé')}</div>`;
            searchResults.style.display = 'block';
            return;
        }

        searchResults.innerHTML = resultats.map((item, index) => `
            <div class="search-result-item" data-index="${index}">
                <div class="search-result-content">
                    <strong>${highlightText(getLabel(item), query)}</strong>
                    <div class="search-result-meta">
                        ${getMeta(item).map(value => `<span>${escapeHtml(value)}</span>`).join('')}
                    </div>
                </div>
            </div>
        `).join('');
        searchResults.style.display = 'block';

        searchResults.querySelectorAll('.search-result-item').forEach(element => {
            element.addEventListener('click', function () {
                const item = resultats[parseInt(this.dataset.index, 10)];
                hideResults();
                searchInput.value = '';
                if (config.onSelect) config.onSelect(item);
            });
        });
    });

    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
})();
