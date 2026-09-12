# Sécurité

## Authentification

Session PHP, table `utilisateurs` (`mot_de_passe` hashé avec `password_hash()`/`PASSWORD_DEFAULT`). **Aucune inscription publique** — cet outil envoie des SMS payants, les comptes sont provisionnés en ligne de commande :

```bash
php bin/create-user.php "Nom Complet" email@example.com "MotDePasseFort123" SUPER_ADMIN
```

Rôles (`ADMIN`/`OPERATOR`/`VIEWER`/`SUPER_ADMIN`) : `server/app.php` (toutes les mutations) exige `SUPER_ADMIN`, `ADMIN` ou `OPERATOR` — un `VIEWER` reçoit un `403`.

**Verrouillage anti brute-force** : après plusieurs échecs de connexion consécutifs, le compte est verrouillé temporairement (`utilisateurs.failed_attempts`/`locked_until`, migration `005_login_lockout.sql`) — voir `AuthService::attempt()`/`lockedForSeconds()`.

**Compte de test livré pendant la refonte, à changer immédiatement** : `admin@test.local` / `TempPass1234`. Créer ton vrai compte puis supprimer celui-ci :
```sql
DELETE FROM utilisateurs WHERE email = 'admin@test.local';
```

## Routes protégées

- `app/index.php` : `auth()->requireLogin()` avant tout rendu.
- `server/app.php` : session + rôle + CSRF (voir plus bas), sur toute requête.
- `server/campaign_worker.php` : session requise (401 JSON sinon) — sans ça, n'importe qui connaissant un `campagne_id` aurait pu déclencher l'envoi réel des lots depuis l'extérieur.

## CSRF

`config/csrf.php` — un jeton par session, vérifié (`hash_equals`) sur toute requête POST vers `server/app.php`. Les 15 formulaires de l'application incluent `<?= csrf_field() ?>`. Tout nouveau formulaire POST vers `app.php` doit faire de même, sinon il sera rejeté avec un code `419`.

## Secrets

Tout est en `.env` (non versionné) — `DB_PASSWORD`, `ORANGE_CLIENT_ID`/`ORANGE_CLIENT_SECRET`. **Ces valeurs ont été trouvées en clair dans le code au début de la refonte et sont donc potentiellement compromises** (présentes dans l'historique git) : régénérer le secret Orange et le mot de passe PostgreSQL est recommandé indépendamment du reste.

Le cache de token Orange (`storage/cache/orange_token.json`) contient un jeton d'accès temporaire — non versionné également.

## XSS

Échappement systématique côté serveur (`htmlspecialchars()`) sur toutes les valeurs affichées dans les vues PHP. Côté client, deux failles XSS réelles ont été trouvées et corrigées le 2026-09-11 (voir AUDIT.md, session 3 finale) :
- `resultats.php` construisait le tableau d'étudiants via `innerHTML` + concaténation de chaînes avec des données issues d'un fichier importé (nom/prénom/matricule non échappés) — **XSS stocké** exploitable par n'importe quel fichier importé contenant du HTML/JS dans un champ texte. Corrigé en reconstruisant chaque ligne via le DOM (`createElement`/`textContent`), qui élimine la classe de bug plutôt que d'ajouter un échappement ponctuel.
- `modeles.php` embarquait un `json_encode()` non échappé dans un attribut `onclick='...'` — un modèle contenant une apostrophe cassait l'attribut. Corrigé avec `htmlspecialchars(json_encode(...), ENT_QUOTES)`.

Règle à respecter pour tout nouveau code JS qui affiche des données venant de la base ou d'un import utilisateur : construire le DOM via `createElement`/`textContent`/`setAttribute`, jamais via `innerHTML` + concaténation de chaînes.

## Redirections (`redirectBack()`)

Bug réel trouvé le 2026-09-12 : la comparaison d'hôte de `redirectBack()` (protection contre l'open-redirect, §5.6 de l'audit initial) utilisait `parse_url($referer, PHP_URL_HOST)`, qui ne renvoie jamais le port — alors que `$_SERVER['HTTP_HOST']` l'inclut dès qu'il n'est pas 80/443. Sur tout déploiement avec un port non standard, la comparaison échouait donc systématiquement et renvoyait vers le fallback au lieu de la bonne page (perte du `?page=...`). Corrigé en comparant l'autorité complète (hôte + port). La protection contre les Referer forgés vers un domaine externe reste intacte (voir `tests/Unit/RedirectBackTest.php`).

## Ce qui n'est pas encore fait

- Pas de "mot de passe oublié".
- Pas de contrôle de rôle fin par action (ex. seul `ADMIN`+ peut annuler une campagne) — le contrôle actuel est au niveau fichier (`server/app.php` entier).
- Pas de validation stricte du type MIME/de la taille des CSV uploadés (extension `.csv` seulement, contrôlée côté navigateur via `accept`, pas revérifiée côté serveur).
- Upload CSV lu ligne à ligne sans limite explicite de nombre de lignes.

Voir `AUDIT.md` pour le détail de chaque trouvaille et sa priorité.
