# Intégration Orange SMS API

SDK : `mediumart/orange-sms` (^2.0), pays `GIN` (Guinée). Toute l'application dépend de `App\Services\OrangeSmsService` (`src/Services/OrangeSmsService.php`) — **jamais du SDK directement** (cahier des charges §28), pour pouvoir changer de fournisseur ou de politique de retry sans toucher au reste du code.

## Configuration

`.env` :
```
ORANGE_CLIENT_ID=...
ORANGE_CLIENT_SECRET=...
ORANGE_SENDER_NAME=+224620000000
ORANGE_COUNTRY_CODE=GIN
```

## Cache du token

Chaque appel `SMSClient::getInstance($id, $secret)` du SDK ré-authentifie intégralement (nouvelle requête OAuth) — coûteux et inutile si répété à chaque SMS. `OrangeSmsService::authenticate()` cache le token dans `storage/cache/orange_token.json` avec sa date d'expiration, et ne redemande un token que s'il est expiré (marge de sécurité de 60s). **Ce fichier n'est pas versionné** (`.gitignore`) car il contient un jeton d'accès valide.

## Méthodes exposées

| Méthode | Usage |
|---|---|
| `sendSms($to, $message, $from = null)` | Envoie un SMS ; lève une `Exception` préfixée `API_ERROR:` en cas d'échec. |
| `getBalance()` | Solde, statut du contrat, date d'expiration. |
| `getStatistics()` / `getHistory()` | Statistiques et historique des achats. |
| `getTotalSmsSent()` | Total d'usage toutes périodes confondues (agrégé depuis `getStatistics()`). |

## ⚠️ Constat opérationnel (trouvé en testant la Phase 4, toujours vrai)

Au moment de la refonte, l'appel réel à `getBalance()` retournait :
```
"status": "EXPIRED", "expirationDate": "2025-12-11", "availableUnits": 906
```
**Le contrat SMS Orange est expiré** alors que l'API affiche encore un solde disponible. À vérifier/renouveler auprès d'Orange avant toute campagne réelle — indépendant du code, aucune correction logicielle ne peut compenser un contrat expiré.

## Classification des erreurs (`CampaignQueueService::classifyError`)

| Code | Retry ? | Détection |
|---|---|---|
| `INVALID_PHONE` | Non | message contient "invalid" + "phone" |
| `AUTH_ERROR` | Non | "unauthorized", "401" |
| `INSUFFICIENT_BALANCE` | Non | "insufficient", "balance", "quota" |
| `TIMEOUT` | Oui | "timeout" |
| `RATE_LIMIT` | Oui | "rate limit", "429" |
| `API_ERROR` / `UNKNOWN_ERROR` | Oui | tout le reste |

Cette classification est basée sur le texte du message d'exception renvoyé par le SDK — elle a été relue mais **pas exercée contre l'API réelle en situation d'échec**, pour ne pas risquer de vrais envois pendant que le contrat est expiré. À valider avec de vrais cas d'erreur une fois le contrat renouvelé.

## Normalisation des numéros (`PhoneNumberService`)

Accepte `622xxxxxx`, `+224622xxxxxx`, `00224622xxxxxx` → normalise en `+224622xxxxxx`. Rejette tout numéro qui n'est pas un mobile guinéen (préfixe `6`, 9 chiffres après l'indicatif).
