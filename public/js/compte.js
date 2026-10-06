// ---------- Menu déroulant de l'icône compte ----------
const compteBouton = document.querySelector('.compte-bouton');
const menuCompte = document.getElementById('menuCompte');

function fermerMenuCompte() {
    menuCompte?.classList.remove('ouvert');
    compteBouton?.setAttribute('aria-expanded', 'false');
}

compteBouton?.addEventListener('click', e => {
    if (!menuCompte) return;
    e.preventDefault(); // le lien ne sert qu'en l'absence de JavaScript
    const ouvrir = !menuCompte.classList.contains('ouvert');
    menuCompte.classList.toggle('ouvert', ouvrir);
    compteBouton.setAttribute('aria-expanded', String(ouvrir));
    if (ouvrir) menuCompte.querySelector('a')?.focus({ preventScroll: true });
});

// un clic ailleurs (y compris sur le panier) ferme le menu
document.addEventListener('click', e => { if (!e.target.closest('.compte-lien')) fermerMenuCompte(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && menuCompte?.classList.contains('ouvert')) {
        fermerMenuCompte();
        compteBouton.focus();
    }
});
// la tabulation qui sort du menu le referme
menuCompte?.addEventListener('focusout', e => {
    if (e.relatedTarget && !e.relatedTarget.closest('.compte-lien')) fermerMenuCompte();
});
