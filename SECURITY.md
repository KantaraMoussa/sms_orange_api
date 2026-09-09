# Sécurité

## Authentification

Session PHP, table `utilisateurs` (`mot_de_passe` hashé avec `password_hash()`/`PASSWORD_DEFAULT`). **Aucune inscription publique** — cet outil envoie des SMS payants, les comptes sont provisionnés en ligne de commande :

```bash
php bin/create-user.php "Nom Complet" email@example.com "MotDePasseFort123" SUPER_ADMIN
```

Rôles (`ADMIN`/`OPERATOR`/`VIEWER`/`SUPER_ADMIN`) : `server/app.php` (toutes les mutations) exige `SUPER_ADMIN`, `ADMIN` ou `OPERATOR` — un `VIEWER` reçoit un `403`.

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

## Ce qui n'est pas encore fait

- Pas de limitation de tentatives de connexion (protection brute-force sur `app/login.php`).
- Pas de "mot de passe oublié".
- Pas de contrôle de rôle fin par action (ex. seul `ADMIN`+ peut annuler une campagne) — le contrôle actuel est au niveau fichier (`server/app.php` entier).
- Pas de validation stricte du type MIME/de la taille des CSV uploadés (extension `.csv` seulement, contrôlée côté navigateur via `accept`, pas revérifiée côté serveur).
- Upload CSV lu ligne à ligne sans limite explicite de nombre de lignes.

Voir `AUDIT.md` pour le détail de chaque trouvaille et sa priorité.
