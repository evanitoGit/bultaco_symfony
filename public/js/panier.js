// ---------- Popup récapitulative du panier ----------
const panierBouton = document.querySelector('.panier-bouton');
const miniPanier = document.getElementById('miniPanier');
const miniPanierContenu = miniPanier?.querySelector('[data-mini-panier]');

async function chargerApercu() {
    try {
        const reponse = await fetch(panierBouton.dataset.apercuUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!reponse.ok) throw new Error(reponse.status);
        miniPanierContenu.innerHTML = await reponse.text(); // HTML généré (et échappé) par Twig
    } catch {
        miniPanierContenu.innerHTML = `<p class="mini-panier-vide">${escapeHtml(window.I18N?.erreurPanier ?? 'Impossible de charger le panier.')}</p>`;
    }
}

function ouvrirPanier() {
    miniPanier.classList.add('ouvert');
    panierBouton.setAttribute('aria-expanded', 'true');
    chargerApercu();
}

function fermerPanier() {
    miniPanier?.classList.remove('ouvert');
    panierBouton?.setAttribute('aria-expanded', 'false');
}

panierBouton?.addEventListener('click', () => {
    miniPanier.classList.contains('ouvert') ? fermerPanier() : ouvrirPanier();
});
// un clic ailleurs (y compris sur le menu du compte) ferme la popup
document.addEventListener('click', e => { if (miniPanier && !e.target.closest('.panier-lien')) fermerPanier(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') fermerPanier(); });

// ---------- Ajout au panier sans recharger la page ----------
document.addEventListener('submit', async function (e) {
    const form = e.target.closest('.form-panier');
    if (!form) return;
    e.preventDefault();

    const bouton = form.querySelector('button');
    const libelle = bouton.textContent;
    bouton.disabled = true;

    try {
        const reponse = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        if (reponse.status === 400) {
            // session expirée : on prévient sans recharger
            afficherToast((await reponse.json()).message);
            return;
        }
        if (!reponse.ok) throw new Error(reponse.status);
        const data = await reponse.json();

        majBadge(data.nombre);
        afficherToast(data.message);
        if (miniPanier?.classList.contains('ouvert')) chargerApercu();
        if (data.ajoute) {
            bouton.textContent = window.I18N?.ajoute ?? '✓ Ajouté';
            setTimeout(() => { bouton.textContent = libelle; }, 1500);
        }
    } catch {
        form.submit(); // repli : envoi normal
    } finally {
        bouton.disabled = false;
    }
});

function majBadge(nombre) {
    const badge = document.querySelector('[data-panier-nombre]');
    if (!badge) return;
    badge.textContent = nombre;
    badge.hidden = nombre === 0;
    panierBouton.classList.remove('panier-pulse');
    void panierBouton.offsetWidth; // relance l'animation
    panierBouton.classList.add('panier-pulse');
}

function afficherToast(message) {
    const toast = document.createElement('div');
    toast.className = 'panier-toast';
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}
