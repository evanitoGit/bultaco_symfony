// Administration : changer l'ordre d'affichage d'une liste par glisser-déposer.
//  - à la souris ou au doigt : attraper la ligne par sa poignée et la glisser à sa place ;
//  - au clavier ou sur téléphone : les flèches ↑ ↓ de la ligne (ou les touches haut/bas sur la poignée).
// Les sous-catégories ne se déplacent qu'à l'intérieur de leur catégorie (data-groupe).
// L'ordre est enregistré au lâcher ; le serveur renumérote 10, 20, 30…
(function () {
    const table = document.querySelector('[data-reordonner]');
    if (!table) return;
    const corps = table.tBodies[0];
    let filtreActif = false;
    let envoi = Promise.resolve();

    const lignes = () => [...corps.rows];
    const memeGroupe = (a, b) => a.dataset.groupe === b.dataset.groupe;
    const ordreActuel = ligne => lignes().filter(l => memeGroupe(l, ligne)).map(l => l.dataset.id).join();

    function message(texte, erreur = false) {
        const toast = document.createElement('div');
        toast.className = 'admin-toast' + (erreur ? ' admin-toast--erreur' : '');
        toast.setAttribute('role', 'status');
        toast.textContent = texte;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), erreur ? 5000 : 2200);
    }

    // trait de séparation entre deux catégories (sous-catégories)
    function majSeparations() {
        lignes().forEach((ligne, i, toutes) => {
            ligne.classList.toggle('admin-groupe-debut', i > 0 && !memeGroupe(ligne, toutes[i - 1]));
        });
    }

    // les autres lignes glissent jusqu'à leur nouvelle place au lieu de sauter (technique « FLIP »)
    function animer(deplacement) {
        const avant = new Map(lignes().map(l => [l, l.getBoundingClientRect().top]));
        deplacement();
        lignes().forEach(l => {
            const ecart = avant.get(l) - l.getBoundingClientRect().top;
            if (!ecart || l.classList.contains('en-deplacement')) return;
            l.style.transition = 'none';
            l.style.transform = `translateY(${ecart}px)`;
            requestAnimationFrame(() => {
                l.style.transition = 'transform 0.18s ease';
                l.style.transform = '';
            });
        });
    }

    // enregistrement : les envois partent l'un après l'autre, dans l'ordre des déplacements
    function enregistrer(ligne) {
        const ids = lignes().filter(l => memeGroupe(l, ligne)).map(l => Number(l.dataset.id));
        envoi = envoi.then(async () => {
            try {
                const reponse = await fetch(table.dataset.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ ids, _token: table.dataset.token }),
                });
                const donnees = await reponse.json().catch(() => ({}));
                if (!reponse.ok) throw new Error(donnees.message || 'Enregistrement impossible : rechargez la page puis recommencez.');
                // nouvelle valeur de la colonne « Ordre »
                for (const [id, ordre] of Object.entries(donnees.ordres)) {
                    const cellule = corps.querySelector(`tr[data-id="${id}"] [data-valeur-ordre]`);
                    if (cellule) cellule.textContent = ordre;
                }
                message('✓ ' + donnees.message);
            } catch (e) {
                message(e.message === 'Failed to fetch' ? 'Connexion perdue : l\'ordre n\'a pas été enregistré.' : e.message, true);
            }
        });
    }

    // ---------- flèches ↑ ↓ (et clavier sur la poignée) ----------
    function deplacer(ligne, sens) {
        const voisine = sens < 0 ? ligne.previousElementSibling : ligne.nextElementSibling;
        if (!voisine || !memeGroupe(voisine, ligne) || filtreActif) return false;
        animer(() => (sens < 0 ? voisine.before(ligne) : voisine.after(ligne)));
        majSeparations();
        enregistrer(ligne);
        return true;
    }

    table.addEventListener('click', e => {
        const fleche = e.target.closest('[data-deplacer]');
        if (!fleche) return;
        const ligne = fleche.closest('tr');
        if (deplacer(ligne, Number(fleche.dataset.deplacer))) {
            // le focus reste sur la même flèche, qui a suivi la ligne
            fleche.focus();
        }
    });

    table.addEventListener('keydown', e => {
        const poignee = e.target.closest('[data-poignee]');
        if (!poignee || !['ArrowUp', 'ArrowDown'].includes(e.key)) return;
        e.preventDefault();
        if (deplacer(poignee.closest('tr'), e.key === 'ArrowUp' ? -1 : 1)) poignee.focus();
    });

    // ---------- glisser-déposer (souris et doigt) ----------
    let glisse = null; // { ligne, avant, decalage, defilement }

    table.addEventListener('pointerdown', e => {
        const poignee = e.target.closest('[data-poignee]');
        if (!poignee || filtreActif || (e.pointerType === 'mouse' && e.button !== 0)) return;
        e.preventDefault();
        const ligne = poignee.closest('tr');
        poignee.setPointerCapture(e.pointerId);
        glisse = { ligne, avant: ordreActuel(ligne), y: e.clientY, defilement: null };
        ligne.classList.add('en-deplacement');
        table.classList.add('glisse-en-cours');
    });

    table.addEventListener('pointermove', e => {
        if (!glisse) return;
        glisse.y = e.clientY;
        suivre();
        // près du bord de l'écran : la page défile pour atteindre les lignes cachées
        const bord = 60;
        const vitesse = e.clientY < bord ? -12 : e.clientY > innerHeight - bord ? 12 : 0;
        clearInterval(glisse.defilement);
        glisse.defilement = vitesse ? setInterval(() => { scrollBy(0, vitesse); suivre(); }, 16) : null;
    });

    // la ligne prend la place de la voisine dès que le pointeur dépasse le milieu de celle-ci
    // (plusieurs fois d'affilée si le pointeur est allé vite)
    function suivre() {
        for (let i = 0; i < 100 && unPas(); i++);
    }

    // position réelle d'une ligne, sans le décalage de l'animation en cours
    function milieu(ligne) {
        return table.getBoundingClientRect().top + ligne.offsetTop + ligne.offsetHeight / 2;
    }

    function unPas() {
        const { ligne, y } = glisse;
        const precedente = ligne.previousElementSibling;
        const suivante = ligne.nextElementSibling;
        if (precedente && memeGroupe(precedente, ligne)) {
            if (y < milieu(precedente)) {
                animer(() => ligne.after(precedente)); // la voisine bouge : la ligne tenue garde la prise du pointeur
                return true;
            }
        }
        if (suivante && memeGroupe(suivante, ligne)) {
            if (y > milieu(suivante)) {
                animer(() => ligne.before(suivante));
                return true;
            }
        }
        return false;
    }

    function lacher() {
        if (!glisse) return;
        const { ligne, avant, defilement } = glisse;
        clearInterval(defilement);
        glisse = null;
        ligne.classList.remove('en-deplacement');
        table.classList.remove('glisse-en-cours');
        majSeparations();
        if (ordreActuel(ligne) !== avant) enregistrer(ligne);
    }
    table.addEventListener('pointerup', lacher);
    table.addEventListener('pointercancel', lacher);
    table.addEventListener('lostpointercapture', lacher);

    // ---------- filtre de la liste : ordre partiel, déplacement mis en pause ----------
    document.addEventListener('admin-filtre', e => {
        filtreActif = e.detail;
        table.classList.toggle('ordre-en-pause', filtreActif);
        document.querySelector('[data-ordre-aide]').hidden = filtreActif;
        document.querySelector('[data-ordre-pause]').hidden = !filtreActif;
        table.querySelectorAll('[data-poignee], [data-deplacer]').forEach(b => { b.disabled = filtreActif; });
    });
})();
