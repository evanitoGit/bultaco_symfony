// Utilitaires communs à toutes les pages

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function nl2br(value) {
    return escapeHtml(value).replace(/\r?\n/g, '<br>');
}

// Chemin d'image enregistré en base (« uploads/... ») -> URL utilisable depuis n'importe quelle page
function mediaUrl(path) {
    if (!path) return '';
    if (/^(https?:)?\/\//.test(path) || path.startsWith('/') || path.startsWith('data:')) return path;
    return (document.body.dataset.basePath || '') + '/' + path;
}

// Petit message en bas de l'écran (ajout au panier, copie…)
function afficherToast(message) {
    const toast = document.createElement('div');
    toast.className = 'panier-toast';
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

// Copie dans le presse-papiers ; repli pour les sites sans HTTPS, où navigator.clipboard est absent
async function copier(texte) {
    try {
        await navigator.clipboard.writeText(texte);
        return true;
    } catch {
        const zone = Object.assign(document.createElement('textarea'), { value: texte, readOnly: true });
        zone.style.cssText = 'position: fixed; top: 0; opacity: 0;';
        document.body.appendChild(zone);
        zone.select();
        let copie = false;
        try { copie = document.execCommand('copy'); } catch { /* navigateur trop ancien */ }
        zone.remove();
        return copie;
    }
}

function pluriel(nombre, singulier, pluriel) {
    return `${nombre} ${nombre > 1 ? (pluriel || singulier + 's') : singulier}`;
}

// --- Modale partagée (voir templates/_partials/_modal.html.twig) ---
let elementAvantModale = null;

function openModal(html) {
    const modal = document.getElementById('modal');
    if (!modal) return;
    elementAvantModale = document.activeElement;
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modalContent').scrollTop = 0;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('modalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    modal.querySelector('.close-btn').focus({ preventScroll: true });
}

function closeModal() {
    const modal = document.getElementById('modal');
    if (!modal || !modal.classList.contains('active')) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('modalOverlay').classList.remove('active');
    document.body.style.overflow = '';
    if (elementAvantModale) elementAvantModale.focus({ preventScroll: true });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();

    // Les cartes cliquables (role="button") s'ouvrent aussi au clavier
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[role="button"]:not(button)')) {
        e.preventDefault();
        e.target.click();
    }
});

// --- Mise en évidence temporaire d'un élément de la page ---
function scrollAndHighlight(element) {
    if (!element) return;
    document.querySelectorAll('.highlighted').forEach(el => el.classList.remove('highlighted'));
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element.classList.add('highlighted');
    setTimeout(() => element.classList.remove('highlighted'), 3000);
}

// --- Header : ombre au défilement + menu mobile ---
(function () {
    const header = document.querySelector('header.nav');
    if (!header) return;

    const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 20);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const toggle = header.querySelector('.nav-toggle');
    if (!toggle) return;

    const setMenu = ouvert => {
        header.classList.toggle('menu-open', ouvert);
        toggle.setAttribute('aria-expanded', ouvert);
        toggle.setAttribute('aria-label', ouvert ? (window.I18N?.fermerMenu ?? 'Fermer le menu') : (window.I18N?.ouvrirMenu ?? 'Ouvrir le menu'));
    };

    toggle.addEventListener('click', () => setMenu(!header.classList.contains('menu-open')));
    document.addEventListener('click', e => { if (!header.contains(e.target)) setMenu(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
})();

// Adresse du pied de page : carte dans une fenêtre (Ctrl+clic ou sans JavaScript : nouvel onglet)
document.querySelectorAll('[data-carte]').forEach(lien => {
    const fenetre = document.getElementById(lien.dataset.carte);
    if (!fenetre?.showModal) return;

    lien.addEventListener('click', e => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        const iframe = fenetre.querySelector('iframe');
        if (!iframe.src) iframe.src = iframe.dataset.src; // Google n'est contacté qu'ici
        fenetre.showModal();
    });

    // fermeture : bouton × ou clic à côté de la fenêtre (Échap est géré par le navigateur)
    fenetre.addEventListener('click', e => {
        if (e.target === fenetre || e.target.closest('[data-fermer]')) fenetre.close();
    });
});

// Boutons « copier » : <button data-copier="texte à copier" data-copie="message affiché">
// (icônes copier/coche : la coche remplace l'icône pendant 2 secondes)
document.addEventListener('click', async e => {
    const bouton = e.target.closest('[data-copier]');
    if (!bouton) return;
    e.preventDefault();
    if (!(await copier(bouton.dataset.copier))) {
        window.prompt('', bouton.dataset.copier); // dernier recours : texte sélectionné à copier à la main
        return;
    }
    bouton.classList.add('copie-ok');
    setTimeout(() => bouton.classList.remove('copie-ok'), 2000);
    if (bouton.dataset.copie) afficherToast(bouton.dataset.copie);
});

// Bouton « retour en haut » : visible après avoir fait défiler, masqué quand le logo du pied de page est à l'écran
(function () {
    const bouton = document.querySelector('[data-haut-de-page]');
    if (!bouton) return;
    bouton.hidden = false;

    let logoVisible = false;
    const maj = () => bouton.classList.toggle('visible', window.scrollY > window.innerHeight * 1.5 && !logoVisible);
    const logo = document.querySelector('.logorond');
    if (logo && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entree]) => { logoVisible = entree.isIntersecting; maj(); }).observe(logo);
    }
    window.addEventListener('scroll', maj, { passive: true });
    maj();

    bouton.addEventListener('click', () => {
        const animations = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: animations ? 'smooth' : 'instant' });
        // le clavier repart aussi du haut de la page
        document.querySelector('header a')?.focus({ preventScroll: true });
    });
})();

// Comparateur avant / après (_partials/_comparateur.html.twig) : le curseur déplace la séparation
function placerComparateur(comparateur, valeur) {
    const curseur = comparateur.querySelector('.comparateur-curseur');
    curseur.value = valeur;
    curseur.setAttribute('aria-valuetext', valeur + ' %');
    comparateur.style.setProperty('--position', valeur + '%');
}
document.addEventListener('input', e => {
    if (e.target.classList.contains('comparateur-curseur')) placerComparateur(e.target.closest('[data-comparateur]'), e.target.value);
});

// Galerie plein écran (fiche pièce, restaurations, pressbook) :
// ouvrirGalerie([{ src, legende }, …], position) ; flèches, clavier ← →, glissement du doigt
// Une entrée { avant, apres, legende } s'affiche avec le comparateur avant / après
const ouvrirGalerie = (function () {
    const fenetre = document.getElementById('galerie');
    if (!fenetre?.showModal) {
        // navigateur sans <dialog> : la photo s'ouvre simplement dans un nouvel onglet
        return (photos, position = 0) => window.open(photos[position]?.src, '_blank', 'noopener');
    }
    const image = fenetre.querySelector('.galerie-image');
    const comparateur = fenetre.querySelector('.galerie-comparateur');
    const legende = fenetre.querySelector('.galerie-legende');
    const compteur = fenetre.querySelector('.galerie-compteur');
    let photos = [];
    let position = 0;

    function afficher(nouvelle) {
        position = (nouvelle + photos.length) % photos.length; // on boucle d'une extrémité à l'autre
        const photo = photos[position];
        const texte = (photo.legende || '').trim();
        const comparer = Boolean(photo.avant && photo.apres);
        image.hidden = comparer;
        comparateur.hidden = !comparer;
        if (comparer) {
            comparateur.querySelector('.comparateur-avant').src = photo.avant;
            comparateur.querySelector('.comparateur-apres').src = photo.apres;
            comparateur.querySelector('.comparateur-avant').alt = texte;
            comparateur.querySelector('.comparateur-apres').alt = texte;
            comparateur.querySelector('.comparateur-curseur').setAttribute('aria-label', comparateur.dataset.libelle.replace('__TITRE__', texte));
            placerComparateur(comparateur, 50);
        } else {
            image.src = photo.src;
            image.alt = texte;
        }
        legende.textContent = texte;
        legende.hidden = texte === '';
        compteur.textContent = fenetre.dataset.compteur.replace('__N__', position + 1).replace('__TOTAL__', photos.length);
        // photos voisines préchargées : le passage à la suivante est immédiat
        [position - 1, position + 1].forEach(i => {
            const voisine = photos[(i + photos.length) % photos.length];
            [voisine.src, voisine.avant, voisine.apres].filter(Boolean).forEach(src => { new Image().src = src; });
        });
    }

    fenetre.addEventListener('click', e => {
        const nav = e.target.closest('[data-galerie]');
        if (nav) afficher(position + Number(nav.dataset.galerie));
        else if (e.target === fenetre || e.target.closest('[data-fermer]')) fenetre.close();
    });

    fenetre.addEventListener('keydown', e => {
        // sur le comparateur, ← → déplacent la poignée (comportement normal du curseur)
        const surCurseur = e.target.classList.contains('comparateur-curseur');
        if (e.key === 'ArrowLeft' && !surCurseur) { e.preventDefault(); afficher(position - 1); }
        if (e.key === 'ArrowRight' && !surCurseur) { e.preventDefault(); afficher(position + 1); }
        // Échap : ferme la galerie seulement (pas la fenêtre ouverte en dessous, ex. pressbook)
        if (e.key === 'Escape') e.stopPropagation();
    });

    // glissement du doigt vers la gauche ou la droite
    let depart = null;
    image.addEventListener('pointerdown', e => { depart = { x: e.clientX, y: e.clientY }; });
    image.addEventListener('pointerup', e => {
        if (!depart || photos.length < 2) return;
        const dx = e.clientX - depart.x;
        const dy = e.clientY - depart.y;
        depart = null;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) afficher(position + (dx < 0 ? 1 : -1));
    });
    image.addEventListener('pointercancel', () => { depart = null; });

    return function (liste, depuis = 0) {
        photos = liste.filter(p => p && (p.src || (p.avant && p.apres)));
        if (photos.length === 0) return;
        fenetre.classList.toggle('galerie--unique', photos.length === 1);
        afficher(Math.max(0, Math.min(depuis, photos.length - 1)));
        fenetre.showModal();
        fenetre.querySelector(photos.length > 1 ? '.galerie-suivante' : '.galerie-fermer').focus();
    };
})();

// « Vous avez consulté récemment » (_partials/_vues_recentes.html.twig) : les dernières pièces vues
// sont mémorisées uniquement dans le navigateur du visiteur (localStorage), sans cookie ni compte
const PiecesVues = {
    cle: 'bultaco.pieces_vues',
    max: 6,
    lire() {
        try {
            const ids = JSON.parse(localStorage.getItem(this.cle));
            return Array.isArray(ids) ? ids.filter(Number.isInteger) : [];
        } catch {
            return []; // navigation privée, stockage désactivé…
        }
    },
    ecrire(ids) {
        try {
            if (ids.length) localStorage.setItem(this.cle, JSON.stringify(ids.slice(0, this.max + 1)));
            else localStorage.removeItem(this.cle);
        } catch { /* stockage indisponible : tant pis */ }
    },
};

document.querySelectorAll('[data-vues-recentes]').forEach(async bloc => {
    const courante = Number(bloc.dataset.pieceCourante) || 0;
    // page d'une pièce : elle passe en tête de l'historique (mais n'est pas affichée)
    if (courante) PiecesVues.ecrire([courante, ...PiecesVues.lire().filter(id => id !== courante)]);

    const ids = PiecesVues.lire().filter(id => id !== courante).slice(0, PiecesVues.max);
    if (ids.length === 0) return;
    try {
        const reponse = await fetch(`${bloc.dataset.url}?ids=${ids.join(',')}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!reponse.ok) return;
        const liste = bloc.querySelector('[data-vues-liste]');
        liste.innerHTML = await reponse.text();
        // pièces supprimées entre-temps : retirées de l'historique
        const trouvees = [...liste.querySelectorAll(':scope > [data-id]')].map(li => Number(li.dataset.id));
        PiecesVues.ecrire(PiecesVues.lire().filter(id => id === courante || trouvees.includes(id)));
        bloc.hidden = trouvees.length === 0;
    } catch { /* hors ligne : le bloc reste caché */ }
});

document.addEventListener('click', e => {
    const bouton = e.target.closest('[data-vues-effacer]');
    if (!bouton) return;
    PiecesVues.ecrire([]);
    bouton.closest('[data-vues-recentes]').hidden = true;
});

// ---------- Ma sélection : pièces gardées de côté avec le cœur ----------
// Mémorisée dans le navigateur (sans compte), comme les vues récentes.
const Selection = {
    cle: 'bultaco.selection',
    max: 50, // même limite que BoutiqueController::SELECTION_MAX
    lire() {
        try {
            const ids = JSON.parse(localStorage.getItem(this.cle));
            return Array.isArray(ids) ? ids.filter(Number.isInteger) : [];
        } catch {
            return [];
        }
    },
    ecrire(ids) {
        try {
            if (ids.length) localStorage.setItem(this.cle, JSON.stringify(ids.slice(0, this.max)));
            else localStorage.removeItem(this.cle);
        } catch { /* stockage indisponible : tant pis */ }
        majSelection();
    },
};

// Cœurs, compteur de l'en-tête et page « Ma sélection » mis à jour d'après la liste mémorisée
function majSelection() {
    const ids = Selection.lire();
    document.querySelectorAll('[data-selection-lien]').forEach(li => { li.hidden = ids.length === 0; });
    document.querySelectorAll('[data-selection-nombre]').forEach(badge => { badge.textContent = ids.length; });
    document.querySelectorAll('[data-selection-coeur]').forEach(bouton => {
        const dedans = ids.includes(Number(bouton.dataset.id));
        const action = dedans ? window.I18N?.selectionRetirer : window.I18N?.selectionAjouter;
        bouton.setAttribute('aria-pressed', String(dedans));
        bouton.title = action;
        const nom = bouton.getAttribute('aria-label').split(' : ').slice(1).join(' : ');
        bouton.setAttribute('aria-label', nom ? `${action} : ${nom}` : action);
        const texte = bouton.querySelector('[data-selection-texte]');
        if (texte) texte.textContent = dedans ? window.I18N?.selectionDans : window.I18N?.selectionAjouter;
    });
    PageSelection.etat();
}

document.addEventListener('click', e => {
    const bouton = e.target.closest('[data-selection-coeur]');
    if (!bouton) return;
    e.preventDefault();
    const id = Number(bouton.dataset.id);
    const ids = Selection.lire();
    const dedans = ids.includes(id);
    if (!dedans && ids.length >= Selection.max) {
        afficherToast(window.I18N?.selectionPleine);
        return;
    }
    Selection.ecrire(dedans ? ids.filter(i => i !== id) : [id, ...ids]);
    afficherToast(dedans ? window.I18N?.selectionRetiree : window.I18N?.selectionAjoutee);

    bouton.classList.remove('coeur-pulse');
    void bouton.offsetWidth; // relance l'animation
    bouton.classList.add('coeur-pulse');
    document.querySelectorAll('[data-selection-nombre]').forEach(badge => {
        badge.classList.remove('panier-pulse');
        void badge.offsetWidth;
        badge.classList.add('panier-pulse');
    });
    // page « Ma sélection » : la carte retirée disparaît
    if (dedans) PageSelection.retirer(id);
});

// Page « Ma sélection » : cartes chargées depuis le serveur d'après la liste mémorisée
const PageSelection = {
    page: document.querySelector('[data-selection-page]'),
    async charger() {
        if (!this.page) return;
        const liste = this.page.querySelector('[data-selection-liste]');
        const ids = Selection.lire();
        if (ids.length) {
            try {
                const reponse = await fetch(`${this.page.dataset.url}?ids=${ids.join(',')}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (reponse.ok) {
                    liste.innerHTML = await reponse.text();
                    // pièces supprimées entre-temps : retirées de la sélection
                    const trouvees = [...liste.querySelectorAll(':scope > [data-id]')].map(carte => Number(carte.dataset.id));
                    if (trouvees.length !== ids.length) {
                        Selection.ecrire(ids.filter(id => trouvees.includes(id)));
                        return; // majSelection() a tout mis à jour
                    }
                }
            } catch { /* hors ligne : la liste reste vide */ }
        } else {
            liste.innerHTML = '';
        }
        majSelection();
    },
    retirer(id) {
        this.page?.querySelector(`[data-selection-liste] > [data-id="${id}"]`)?.remove();
        this.etat();
    },
    // vide / actions / liens d'après les cartes affichées
    etat() {
        if (!this.page) return;
        const cartes = [...this.page.querySelectorAll('[data-selection-liste] > [data-id]')].map(carte => carte.dataset.id);
        this.page.querySelector('[data-selection-chargement]').hidden = true;
        this.page.querySelector('[data-selection-vide]').hidden = cartes.length > 0;
        this.page.querySelector('[data-selection-actions]').hidden = cartes.length === 0;
        const demande = this.page.querySelector('[data-selection-demande]');
        const url = new URL(demande.href, location.href);
        url.searchParams.set('pieces', cartes.join(','));
        demande.href = url.pathname + url.search;
        const champ = this.page.querySelector('[data-selection-ids]');
        if (champ) champ.value = cartes.join(',');
    },
};

document.addEventListener('click', e => {
    const bouton = e.target.closest('[data-selection-vider]');
    if (!bouton || !confirm(bouton.dataset.confirmer)) return;
    PageSelection.page.querySelector('[data-selection-liste]').innerHTML = '';
    Selection.ecrire([]);
});

// sélection modifiée dans un autre onglet
window.addEventListener('storage', e => {
    if (e.key === Selection.cle || e.key === null) PageSelection.page ? PageSelection.charger() : majSelection();
});

PageSelection.page ? PageSelection.charger() : majSelection();

// Ouvre un élément de la page désigné par l'adresse (#modele-12, #pilote-3…), à l'arrivée sur la page
// et quand l'ancre change (résultat de la recherche globale qui pointe vers la page affichée)
function ouvrirDepuisAncre(motif, ouvrir) {
    const essayer = () => {
        const trouve = location.hash.match(motif);
        if (trouve) ouvrir(trouve[1]);
    };
    essayer();
    window.addEventListener('hashchange', essayer);
}

// Recherche globale dans l'en-tête : la loupe (ou Ctrl+K / ⌘K, ou « / ») déploie le champ,
// les résultats s'affichent dessous pendant la frappe, ↑ ↓ pour les parcourir.
// Entrée, ou la loupe une fois le champ rempli : page de tous les résultats (/recherche).
(function () {
    const zone = document.querySelector('[data-recherche]');
    if (!zone) return;
    const champ = zone.querySelector('input[name=q]');
    const bouton = zone.querySelector('[data-recherche-bouton]');
    const panneau = zone.querySelector('.recherche-panneau');
    const resultats = zone.querySelector('[data-recherche-resultats]');
    const aide = resultats.innerHTML;
    let minuterie = null;
    let requete = null;
    let derniere = null;

    const ouverte = () => zone.classList.contains('ouverte');

    function ouvrir() {
        if (!ouverte()) {
            zone.classList.add('ouverte');
            document.querySelector('header.nav')?.classList.add('recherche-active');
            champ.tabIndex = 0;
            champ.setAttribute('aria-expanded', 'true');
            panneau.hidden = false;
        }
        champ.focus();
    }

    function fermer(rendreLaMain = true) {
        if (!ouverte()) return;
        zone.classList.remove('ouverte');
        document.querySelector('header.nav')?.classList.remove('recherche-active');
        champ.tabIndex = -1;
        champ.setAttribute('aria-expanded', 'false');
        panneau.hidden = true;
        if (rendreLaMain && zone.contains(document.activeElement)) bouton.focus();
    }

    async function chercher() {
        const texte = champ.value.trim();
        if (texte === derniere) return;
        derniere = texte;
        requete?.abort();
        if (texte.length < 2) {
            resultats.innerHTML = aide;
            return;
        }
        requete = new AbortController();
        try {
            const reponse = await fetch(`${zone.dataset.url}?q=${encodeURIComponent(texte)}`, {
                signal: requete.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (reponse.ok && texte === champ.value.trim()) resultats.innerHTML = await reponse.text();
        } catch { /* requête remplacée par une plus récente, ou hors ligne */ }
    }

    // la loupe : déploie le champ ; une fois du texte tapé, lance la page de résultats (envoi normal du formulaire)
    bouton.addEventListener('click', e => {
        if (!ouverte() || champ.value.trim() === '') {
            e.preventDefault();
            ouvrir();
        }
    });
    zone.querySelector('[data-recherche-fermer]').addEventListener('click', () => fermer());

    champ.addEventListener('input', () => {
        clearTimeout(minuterie);
        minuterie = setTimeout(chercher, 200); // pas une requête à chaque lettre
    });

    document.addEventListener('keydown', e => {
        const enSaisie = e.target.closest('input, textarea, select, [contenteditable="true"]');
        if (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            ouvrir();
        } else if (e.key === '/' && !enSaisie && !document.querySelector('dialog[open]')) {
            e.preventDefault();
            ouvrir();
        }
    });

    zone.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            fermer();
            return;
        }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        const liens = [...resultats.querySelectorAll('a')];
        if (liens.length === 0) return;
        e.preventDefault();
        const position = liens.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') (liens[position + 1] || liens[0]).focus();
        else if (position <= 0) champ.focus();
        else liens[position - 1].focus();
    });

    // clic ailleurs sur la page : la recherche se replie (le texte tapé est gardé)
    document.addEventListener('click', e => {
        if (ouverte() && !zone.contains(e.target)) fermer(false);
    });

    resultats.addEventListener('click', e => {
        const lien = e.target.closest('a');
        if (!lien) return;
        fermer(false);
        // résultat sur la page affichée avec la même ancre : le navigateur ne signale rien, on le fait
        const cible = new URL(lien.href, location.href);
        if (cible.pathname === location.pathname && cible.search === location.search && cible.hash === location.hash) {
            e.preventDefault();
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        }
    });
})();
