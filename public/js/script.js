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
        toggle.setAttribute('aria-label', ouvert ? 'Fermer le menu' : 'Ouvrir le menu');
    };

    toggle.addEventListener('click', () => setMenu(!header.classList.contains('menu-open')));
    document.addEventListener('click', e => { if (!header.contains(e.target)) setMenu(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
})();
