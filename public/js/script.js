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
