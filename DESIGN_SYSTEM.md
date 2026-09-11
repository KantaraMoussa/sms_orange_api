# Design System — SMS_ORANGE

Référence des composants et conventions visuelles réellement utilisés dans l'application (cahier des charges V2.0 §14). Ce n'est pas une bibliothèque de composants séparée : SMS_ORANGE reste basé sur le thème Bootstrap 5 "Gradient Able" (voir AUDIT.md §67 pour l'historique du rebranding), avec un jeu de conventions cohérentes documentées ici pour que tout nouvel écran reste visuellement homogène.

## Couleurs

Le thème utilise le preset `preset-6` (`app/index.php`, `data-pc-preset="preset-6"`), qui définit la couleur de marque :

| Rôle | Valeur | Usage |
|---|---|---|
| Primaire (orange SMS_ORANGE) | `#fd7e14` | Boutons primaires, liens, sidebar active, en-tête |
| Primaire clair | `#fff2e8` | Fonds `bg-light-primary` |
| Succès | `#2ca87f` | Envoyés, KPI positifs |
| Danger | `#e63757` | Échecs |
| Warning | `#f0ad4e` | En attente, alertes |
| Info (graphique) | `#1e88e5` | Courbe "taux de réussite" (`dashboard.php`) |

Dégradé d'en-tête (`assets/css/sms-orange-overrides.css`) : `linear-gradient(to right, #ff7900, #ff9e40)` — override nécessaire car non couvert par le système de preset standard (voir commentaire dans le fichier).

Ne jamais coder une couleur de marque en dur dans une vue : utiliser les classes Bootstrap existantes (`btn-primary`, `bg-grd-success`, `text-danger`, `badge bg-warning`, ...) qui héritent automatiquement du preset.

## Typographie

Police : **Poppins** (Google Fonts, chargée dans `app/index.php`), poids 400/500/600. Taille de base héritée de Bootstrap 5 (`1rem` = 16px). Titres de carte en `<h4 class="mb-0">`, emoji discrets en préfixe pour les repérer visuellement dans le menu (📥 Import, 🎯 Sélection, ✉️ Message, 📋 Liste, 📝 Modèles, 🕒 Journal) — convention déjà en place sur toutes les pages métier récentes, à conserver pour les prochaines.

## Icônes

Trois jeux d'icônes chargés globalement : **Phosphor** (`ph ph-*`, préféré pour tout nouvel élément — sidebar, actions), Feather (`feather icon-*`, cartes KPI historiques) et Font Awesome (`fa fa-*`, boutons hérités du thème). Pour un nouvel écran, utiliser Phosphor par défaut afin de limiter la prolifération de jeux d'icônes.

## Composants

### Boutons
`btn btn-primary` (action principale), `btn btn-success` (confirmer/créer), `btn btn-outline-*` (action secondaire), `btn btn-outline-danger` (destructif, toujours avec confirmation JS — voir ci-dessous). Taille réduite : `btn-sm`.

### Cartes
Conteneur universel de contenu : `<div class="card"><div class="card-header">...</div><div class="card-body">...</div></div>`. Cartes KPI du dashboard : `card bg-grd-{primary|success|warning|danger} order-card` (fond dégradé, texte blanc).

### Badges
Statuts de campagne (`detail-campagne.php`) : `bg-secondary` (DRAFT), `bg-info` (QUEUED), `bg-primary` (RUNNING), `bg-warning` (PAUSED/PARTIAL), `bg-success` (COMPLETED), `bg-danger` (FAILED), `bg-dark` (CANCELLED). Statut "déjà envoyé" (`resultats.php`) : `badge bg-warning text-dark`.

### Formulaires (inputs/selects/textareas)
- Toujours `class="form-control"` / `form-select` / `form-check-input`.
- **Chaque champ visible a un `<label for="...">` associé** — utiliser `class="visually-hidden"` sur le label si l'espace ne permet pas de l'afficher (ex. `dashboard.php`, `sms-sender.php`), jamais l'omettre. Un contrôle sans label visible ni `aria-label` est un bug d'accessibilité, pas un détail de style.
- Case à cocher générée dynamiquement en JS (ex. sélection d'étudiants) : toujours poser un `aria-label` explicite au moment de la création du `<input>`, jamais construire la ligne via `innerHTML` avec des données utilisateur non échappées (risque XSS stocké — voir `resultats.php`, fonction `renderStudentRow`, qui construit chaque cellule via `createElement`/`textContent`).

### Tableaux
`table table-striped` (+ `table-bordered` pour les tableaux denses), toujours enveloppé dans `<div class="table-responsive">` pour le défilement horizontal contrôlé sur mobile (§69). DataTables (recherche/tri/pagination/export CSV-Excel-PDF-Print) sur les tableaux globaux via `id="groupesTable"` (initialisation centralisée dans `app/index.php`) ; pour un tableau alimenté en AJAX avec sa propre pagination serveur (ex. liste d'étudiants de `resultats.php`), ne pas utiliser DataTables — implémenter une pagination légère dédiée pour rester performant à 10 000+ lignes.

### Modales
`modal fade` + `modal-dialog` (+ `modal-lg` pour les formulaires), ouverture via `data-bs-toggle="modal" data-bs-target="#idModal"`. Toujours un `<button class="btn-close" data-bs-dismiss="modal">` dans le header.

### Notifications
Deux mécanismes, volontairement distincts :
- **Flash message post-action** (`$_SESSION['class']`/`$_SESSION['message']`, affiché en haut de `app/index.php`) : `alert alert-{success|danger|warning} alert-dismissible fade show`. C'est le mécanisme principal après une mutation (import, création, envoi de test...).
- **Confirmation avant action critique** (§39) : `onsubmit="return confirm('...')"` sur le formulaire, jamais d'exécution silencieuse pour lancer/annuler une campagne, réessayer les échecs, ou archiver un modèle.

### Barres de progression / suivi temps réel
`progress` + `progress-bar progress-bar-striped progress-bar-animated`, mise à jour par polling JS (`fetch` toutes les ~1,2s) sur un endpoint JSON dédié (`campaign_worker.php`, `resultats_preview.php`, `resultats_list.php`) — jamais de rechargement de page complet pour un suivi en direct.

### Graphiques
**ApexCharts** exclusivement (déjà chargé globalement, aucune autre librairie de graphique à ajouter). Toujours initialiser dans un `document.addEventListener('DOMContentLoaded', ...)` — les `<script src>` du thème sont chargés en pied de page, après le contenu (voir bug historique documenté dans AUDIT.md, section "Correctif DataTables").

### États vides / chargement / erreur
- **Vide** : ligne de tableau unique, centrée, texte gris (`text-center text-muted`), jamais un tableau silencieusement vide. Exemple : `<tr><td colspan="N" class="text-center text-muted p-3">Aucun étudiant ne correspond à ces critères.</td></tr>`.
- **Chargement** : le préchargeur global (`loader-bg`) couvre le chargement de page ; pour une zone qui se met à jour en AJAX (aperçu, liste), pas de spinner dédié actuellement — le texte affiche le dernier état connu jusqu'à la réponse suivante (acceptable vu la latence courte, à revoir si un écran AJAX plus lent est ajouté).
- **Erreur** : jamais une erreur PHP brute à l'écran — capturer, journaliser si pertinent, afficher un message utilisateur via le mécanisme de flash message.

## Accessibilité (rappel)

- Tout `<input>`/`<select>`/`<textarea>` visible a un label associé (voir Formulaires ci-dessus).
- Toute action destructive ou irréversible (annuler, archiver, réessayer) demande confirmation.
- Le contraste des couleurs de statut (succès/danger/warning) suit les valeurs par défaut de Bootstrap 5, déjà conformes AA sur fond blanc.
- Navigation clavier : héritée de Bootstrap (modales, dropdowns) — pas de piège au clavier connu.

## Responsive

Le thème (Bootstrap 5 grid + sidebar collapsible) gère nativement le passage mobile : sidebar réduite en icône hamburger sous le seuil `lg`, cartes KPI empilées en une colonne, tableaux dans `table-responsive`. Vérifié à 390px de large (iPhone standard) sur les écrans Dashboard/Résultats/Campagnes/Modèles/Journal : aucun débordement horizontal constaté. Pour tout nouvel écran : toujours tester à une largeur ≤ 400px avant de considérer l'écran terminé.
