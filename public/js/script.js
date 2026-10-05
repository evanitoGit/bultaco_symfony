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

// --- Modale partagée (voir templates/_partials/_modal.html.twig) ---
function openModal(html) {
    const modal = document.getElementById('modal');
    if (!modal) return;
    document.getElementById('modalContent').innerHTML = html;
    modal.classList.add('active');
    document.getElementById('modalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const modal = document.getElementById('modal');
    if (!modal) return;
    modal.classList.remove('active');
    document.getElementById('modalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});

// --- Mise en évidence temporaire d'un élément de la page ---
function scrollAndHighlight(element) {
    if (!element) return;
    document.querySelectorAll('.highlighted').forEach(el => el.classList.remove('highlighted'));
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element.classList.add('highlighted');
    setTimeout(() => element.classList.remove('highlighted'), 3000);
}

// --- Header : changement de style au défilement ---
window.addEventListener('scroll', () => {
    const nav = document.querySelector('.nav');
    if (!nav) return;
    nav.classList.toggle('scrolled', window.scrollY > 600);
});
