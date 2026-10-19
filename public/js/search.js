/*
 * Barre de recherche partagée.
 * Chaque page définit window.searchConfig avant de charger ce script :
 *   data        : tableau d'objets à filtrer
 *   fields      : champs dans lesquels chercher
 *   labelField  : champ affiché en titre (ou fonction item => texte)
 *   metaFields  : champs affichés sous le titre (ou fonction item => [textes])
 *   onSelect    : fonction appelée avec l'élément choisi
 *   emptyText   : message si aucun résultat (optionnel)
 *   emptyLink   : lien proposé si aucun résultat, { texte, url } (optionnel)
 */
(function () {
    const config = window.searchConfig;
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    if (!config || !searchInput || !searchResults) return;

    let resultats = [];
    let indexActif = -1;

    // Recherche insensible à la casse et aux accents ("piece" trouve "pièce")
    const normaliser = texte => String(texte || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    const getLabel = item => typeof config.labelField === 'function'
        ? config.labelField(item)
        : (item[config.labelField] || '');

    const getMeta = item => {
        const meta = typeof config.metaFields === 'function'
            ? config.metaFields(item)
            : (config.metaFields || []).map(field => item[field]);
        return meta.filter(value => value !== null && value !== undefined && value !== '');
    };

    const index = config.data.map(item => normaliser(
        [getLabel(item)].concat((config.fields || []).map(field => item[field])).filter(Boolean).join(' ')
    ));

    // Surligne la partie du libellé qui correspond à la recherche
    function highlightText(text, query) {
        const position = normaliser(text).indexOf(query);
        if (position === -1) return escapeHtml(text);
        return escapeHtml(text.slice(0, position))
            + `<span class="highlight">${escapeHtml(text.slice(position, position + query.length))}</span>`
            + escapeHtml(text.slice(position + query.length));
    }

    function hideResults() {
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
        searchInput.setAttribute('aria-expanded', 'false');
        indexActif = -1;
    }

    function select(item) {
        hideResults();
        searchInput.value = '';
        if (config.onSelect) config.onSelect(item);
    }

    function setActif(nouvelIndex) {
        const elements = searchResults.querySelectorAll('.search-result-item');
        if (elements.length === 0) return;
        indexActif = (nouvelIndex + elements.length) % elements.length;
        elements.forEach((el, i) => el.classList.toggle('active', i === indexActif));
        elements[indexActif].scrollIntoView({ block: 'nearest' });
    }

    searchInput.setAttribute('role', 'combobox');
    searchInput.setAttribute('aria-autocomplete', 'list');
    searchInput.setAttribute('aria-expanded', 'false');
    searchInput.setAttribute('aria-controls', 'searchResults');
    searchResults.setAttribute('role', 'listbox');

    searchInput.addEventListener('input', function () {
        const query = normaliser(this.value.trim());
        indexActif = -1;
        if (query === '') {
            hideResults();
            return;
        }

        resultats = config.data.filter((item, i) => index[i].includes(query));
        searchResults.style.display = 'block';
        searchInput.setAttribute('aria-expanded', 'true');

        if (resultats.length === 0) {
            const lien = config.emptyLink ? `<a href="${escapeHtml(config.emptyLink.url)}" class="no-results-lien">${escapeHtml(config.emptyLink.texte)}</a>` : '';
            searchResults.innerHTML = `<div class="no-results">${escapeHtml(config.emptyText || window.I18N?.aucunResultat || 'Aucun résultat trouvé')}${lien}</div>`;
            return;
        }

        searchResults.innerHTML = resultats.map((item, i) => `
            <div class="search-result-item" role="option" data-index="${i}">
                <strong>${highlightText(getLabel(item), query)}</strong>
                <div class="search-result-meta">
                    ${getMeta(item).map(value => `<span>${escapeHtml(value)}</span>`).join('')}
                </div>
            </div>
        `).join('');
    });

    searchResults.addEventListener('click', function (e) {
        const element = e.target.closest('.search-result-item');
        if (element) select(resultats[parseInt(element.dataset.index, 10)]);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (searchResults.style.display !== 'block') return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActif(indexActif + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActif(indexActif - 1); }
        else if (e.key === 'Enter' && resultats.length > 0) {
            e.preventDefault();
            select(resultats[Math.max(indexActif, 0)]);
        }
        else if (e.key === 'Escape') { e.stopPropagation(); hideResults(); }
    });

    searchInput.addEventListener('focus', function () {
        if (this.value.trim() !== '') this.dispatchEvent(new Event('input'));
    });

    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
})();
