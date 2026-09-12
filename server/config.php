<?php
function checkInternet() // verifier que internet existe
{
    $connected = @fsockopen("www.google.com", 80);
    if ($connected) {
        fclose($connected);
        return true;
    }
    return false;
}
if (!checkInternet()) {
    echo ('<div class="alert alert-danger" role="alert">
                    ❌ Pas de connexion Internet. Vérifiez votre réseau.
                </div>');
    exit();
}
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/orange.php';
require_once __DIR__ . '/../config/services.php';

// Kept for backward compatibility with code that still calls PDO() directly.
function PDO()
{
    return db();
}
function getCampagne(int $organizationId)
{
    $sql = "SELECT id,nom,description,date_creation,date_debut,date_fin,statut,scheduled_at
        FROM campagne
        WHERE organization_id = :organization_id
        ORDER BY date_creation DESC";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    $stmt->execute();
    return  $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * Volontairement non scopée par organisation : utilisée par le worker CLI
 * (server/campaign_worker.php, bin/process-campaign.php), qui n'a pas de
 * session HTTP et doit pouvoir traiter les campagnes de toutes les
 * organisations. Les pages HTTP qui l'appellent avec un id fourni par
 * l'utilisateur (ex. detail-campagne.php) DOIVENT vérifier elles-mêmes que
 * `organization_id` correspond à `auth()->organizationId()` — voir
 * assertOwnsCampagne() ci-dessous — sous peine de fuite inter-organisation
 * (IDOR, §59).
 */
function getSingleCampagne($campagneId)
{
    $sql = "SELECT * FROM campagne WHERE id = :id";
    $stmt = PDO()->prepare($sql);
    $stmt->bindParam(':id', $campagneId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Garde-fou anti-IDOR (§59) : à appeler par toute route HTTP mutante ou de
 * consultation qui reçoit un campagne_id fourni par le client, avant tout
 * accès à campaignQueue() ou aux tables messages/campagne. Termine la requête
 * en 404 si la campagne n'existe pas ou appartient à une autre organisation
 * (même réponse dans les deux cas : ne pas révéler qu'un id existe ailleurs).
 */
function assertOwnsCampagne(int $campagneId): array
{
    $campagne = getSingleCampagne($campagneId);
    if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
        http_response_code(404);
        exit('Campagne introuvable.');
    }

    return $campagne;
}

/**
 * Nombre réel de SMS nécessaires pour terminer une campagne (§18, §20) —
 * somme des segments de chaque message encore en attente, jamais une simple
 * estimation "1 destinataire = 1 SMS" qui sous-estimerait les messages
 * longs (§18 : ne jamais sous-estimer). Utilisé avant de bloquer un
 * lancement pour solde insuffisant.
 */
function estimateSmsNeeded(int $campagneId): int
{
    $stmt = PDO()->prepare("SELECT contenu FROM messages WHERE campagne_id = :id AND statut = 'en_attente'");
    $stmt->execute([':id' => $campagneId]);

    $total = 0;
    while (($contenu = $stmt->fetchColumn()) !== false) {
        $total += \App\Services\SmsCounterService::analyze((string) $contenu)['segments'];
    }

    return $total;
}

/**
 * §20 : message d'erreur si le solde Orange est insuffisant pour couvrir
 * estimateSmsNeeded(), sinon null. Partagé par le lancement immédiat et la
 * planification (server/app.php) — les deux engagent la campagne à être
 * envoyée, donc les deux doivent être bloqués si le solde ne suit pas.
 */
function insufficientBalanceMessage(int $campagneId): ?string
{
    $needed = estimateSmsNeeded($campagneId);
    $available = (int) (orangeSms()->getBalance()['availableUnits'] ?? 0);

    if ($needed > $available) {
        return "❌ Solde SMS insuffisant pour cette campagne : $needed SMS nécessaires, $available disponible(s).";
    }

    return null;
}

/**
 * Affiche un timestamp stocké en UTC (ex. campagne.scheduled_at, voir
 * CampaignQueueService::schedule()) dans le fuseau de l'organisation
 * courante — sans ce passage explicite par UTC, `new DateTime($valeur)`
 * l'interpréterait dans le fuseau par défaut de PHP (Europe/Berlin dans cet
 * environnement), pas celui, potentiellement différent, de l'organisation.
 */
function formatOrgDateTime(string $utcTimestamp, string $format = 'd/m/Y H:i'): string
{
    $orgTimezone = organizations()->find(auth()->organizationId())['fuseau_horaire'] ?? 'Africa/Conakry';
    $dt = new DateTime($utcTimestamp, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($orgTimezone));

    return $dt->format($format);
}

function getMessageCampagne($campagneId)
{
    $sql = "SELECT id, contenu, destinataire, date_envoi, statut, nom, prenom, error_code, error_message, tentative_count, date_traitement
                FROM messages
                WHERE campagne_id = :campagne_id
                ORDER BY date_envoi DESC";
    $stmt = PDO()->prepare($sql);
    $stmt->bindParam(':campagne_id', $campagneId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function detailCampagne($campagneId)
{
    $data = [];
    $ret = [];
    $ret_ = [];
    $campagne = getSingleCampagne($campagneId);
    $data['id'] = $campagne['id'];
    $data['libelle'] = $campagne['nom'];
    $data['description'] = $campagne['description'];
    $data['statut'] = $campagne['statut'];
    $messages = getMessageCampagne($campagneId);
    if (count($messages) != 0) {
        foreach ($messages as $message) {
            $ret['destinataire'] = $message['destinataire'];
            $ret['contenu'] = $message['contenu'];
            $ret['statut'] = $message['statut'];
            array_push($ret_, $ret);
        }
    }
    $data['messages'] = $ret_;

    return $data;
}

/**
 * Remplace `header("Location: " . $_SERVER['HTTP_REFERER'])` utilisé partout
 * dans app.php : évite le warning PHP quand HTTP_REFERER est absent (courant
 * avec certains navigateurs/proxys) et l'open-redirect si un Referer forgé
 * pointait vers un domaine externe (audit §5.6).
 */
/**
 * Logique pure de redirectBack(), extraite pour être testable sans dépendre
 * de header() (silencieusement ignoré en CLI, donc invérifiable autrement).
 *
 * Bug réel trouvé le 2026-09-12 en testant sur le serveur de dev PHP (port
 * non standard, ex. :8899) : parse_url(..., PHP_URL_HOST) ne renvoie JAMAIS
 * le port, alors que HTTP_HOST l'inclut dès que ce n'est pas le port par
 * défaut (80/443). La comparaison échouait donc systématiquement hors
 * Apache:80, renvoyant toujours vers le fallback (perte du ?page=...).
 */
function resolveRedirectTarget(string $referer, string $host, string $fallback): string
{
    $refererHost = parse_url($referer, PHP_URL_HOST);
    $refererPort = parse_url($referer, PHP_URL_PORT);
    $refererAuthority = $refererPort !== null ? "$refererHost:$refererPort" : $refererHost;

    if ($referer !== '' && $host !== '' && $refererAuthority === $host) {
        return $referer;
    }

    return $fallback;
}

function redirectBack(string $fallback = '../app/index.php'): void
{
    $target = resolveRedirectTarget($_SERVER['HTTP_REFERER'] ?? '', $_SERVER['HTTP_HOST'] ?? '', $fallback);
    header("Location: $target");
}

// -- Statistiques réelles pour le tableau de bord (remplacent les KPI/graphiques
// factices de la Phase 1, cf. audit §8) --

/**
 * Fragment WHERE + paramètres partagés par les fonctions d'analytics (§33) :
 * période (date_from/date_to, sur $dateColumn) et campagne, tous optionnels.
 * $dateColumn diffère selon la table interrogée (`messages.date_traitement`
 * pour les stats/erreurs, `campagne.date_creation` pour la liste de
 * campagnes elle-même). $paramSuffix évite les collisions de nom de
 * paramètre PDO quand la même requête appelle cette fonction plusieurs fois
 * (ex. getGlobalSmsStats : un filtre avec dates pour envoyés/échecs, un
 * filtre sans dates pour en_attente).
 *
 * @return array{0: string, 1: array<string,mixed>}
 */
function buildAnalyticsFilter(?string $dateFrom, ?string $dateTo, ?int $campagneId, string $dateColumn, string $paramSuffix = ''): array
{
    $sql = '';
    $params = [];

    if ($dateFrom) {
        $sql .= " AND $dateColumn >= :date_from$paramSuffix";
        $params[":date_from$paramSuffix"] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND $dateColumn < (:date_to$paramSuffix)::date + INTERVAL '1 day'";
        $params[":date_to$paramSuffix"] = $dateTo;
    }
    if ($campagneId) {
        $column = $dateColumn === 'date_creation' ? 'id' : 'campagne_id';
        $sql .= " AND $column = :campagne_id$paramSuffix";
        $params[":campagne_id$paramSuffix"] = $campagneId;
    }

    return [$sql, $params];
}

function getGlobalSmsStats(int $organizationId, ?string $dateFrom = null, ?string $dateTo = null, ?int $campagneId = null)
{
    [$filterSql, $filterParams] = buildAnalyticsFilter($dateFrom, $dateTo, $campagneId, 'date_traitement');
    // "en_attente" est un état présent, pas un évènement daté (date_traitement
    // est NULL tant qu'un message n'a pas été traité) : le filtrer par la
    // période désactiverait complètement le compteur (NULL >= date est
    // toujours faux en SQL) alors qu'un message en attente l'est "maintenant",
    // pas "pendant" une période passée — donc jamais filtré par date, mais
    // toujours filtré par campagne si demandé.
    [$campaignOnlySql, $campaignOnlyParams] = buildAnalyticsFilter(null, null, $campagneId, 'date_traitement', '_pending');
    $sql = "SELECT
                COUNT(*) FILTER (WHERE statut = 'envoye' $filterSql) AS envoyes,
                COUNT(*) FILTER (WHERE statut = 'echec' $filterSql) AS echecs,
                COUNT(*) FILTER (WHERE statut IN ('en_attente','en_cours') $campaignOnlySql) AS en_attente
            FROM messages
            WHERE organization_id = :organization_id";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    foreach (array_merge($filterParams, $campaignOnlyParams) as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $envoyes = (int) ($row['envoyes'] ?? 0);
    $echecs = (int) ($row['echecs'] ?? 0);
    $traites = $envoyes + $echecs;

    return [
        'envoyes' => $envoyes,
        'echecs' => $echecs,
        'en_attente' => (int) ($row['en_attente'] ?? 0),
        'taux_reussite' => $traites > 0 ? round(($envoyes / $traites) * 100, 1) : 0,
    ];
}

function getSmsEvolution(int $organizationId, int $days = 14)
{
    $sql = "SELECT DATE(date_traitement) AS jour, COUNT(*) AS total
            FROM messages
            WHERE organization_id = :organization_id AND statut = 'envoye' AND date_traitement >= NOW() - (:days || ' days')::interval
            GROUP BY DATE(date_traitement)
            ORDER BY jour";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([':organization_id' => $organizationId, ':days' => $days]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $series[$day] = (int) ($rows[$day] ?? 0);
    }

    return $series;
}

/**
 * Série temporelle du taux de réussite (cahier des charges V2.0 §12,
 * 4ᵉ graphique du dashboard). Un jour sans aucun SMS traité vaut `null`
 * (pas 0%) pour ne pas laisser croire à un échec total un jour d'inactivité.
 */
function getSuccessRateEvolution(int $organizationId, int $days = 14)
{
    $sql = "SELECT DATE(date_traitement) AS jour,
                COUNT(*) FILTER (WHERE statut = 'envoye') AS envoyes,
                COUNT(*) FILTER (WHERE statut IN ('envoye', 'echec')) AS traites
            FROM messages
            WHERE organization_id = :organization_id AND date_traitement >= NOW() - (:days || ' days')::interval
            GROUP BY DATE(date_traitement)
            ORDER BY jour";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([':organization_id' => $organizationId, ':days' => $days]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $traites = (int) $row['traites'];
        $rows[$row['jour']] = $traites > 0 ? round(((int) $row['envoyes'] / $traites) * 100, 1) : null;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $series[$day] = $rows[$day] ?? null;
    }

    return $series;
}

/**
 * KPI dashboard manquants identifiés en auditant l'existant (session 7) :
 * "SMS envoyés aujourd'hui/ce mois" et "campagnes actives" n'étaient nulle
 * part, seule une évolution 14 jours et un cumul all-time existaient.
 */
function getSmsSentToday(int $organizationId): int
{
    $stmt = PDO()->prepare(
        "SELECT COUNT(*) FROM messages WHERE organization_id = :org AND statut = 'envoye' AND date_traitement::date = CURRENT_DATE"
    );
    $stmt->execute([':org' => $organizationId]);

    return (int) $stmt->fetchColumn();
}

function getSmsSentThisMonth(int $organizationId): int
{
    $stmt = PDO()->prepare(
        "SELECT COUNT(*) FROM messages WHERE organization_id = :org AND statut = 'envoye'
         AND date_trunc('month', date_traitement) = date_trunc('month', CURRENT_DATE)"
    );
    $stmt->execute([':org' => $organizationId]);

    return (int) $stmt->fetchColumn();
}

function getActiveCampaignsCount(int $organizationId): int
{
    $stmt = PDO()->prepare(
        "SELECT COUNT(*) FROM campagne WHERE organization_id = :org AND statut IN ('QUEUED', 'RUNNING', 'PAUSED')"
    );
    $stmt->execute([':org' => $organizationId]);

    return (int) $stmt->fetchColumn();
}

function getCampaignPerformance(int $organizationId, int $limit = 6)
{
    $sql = "SELECT nom, nombre_envoyes, nombre_echecs
            FROM campagne
            WHERE organization_id = :organization_id AND total_destinataires > 0
            ORDER BY date_creation DESC
            LIMIT :limit";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Traduit un préréglage de période (§33 : aujourd'hui/7 jours/30 jours/ce
 * mois/personnalisée) en bornes date_from/date_to (Y-m-d, inclusives) pour
 * buildAnalyticsFilter(). 'custom' passe $from/$to tels quels (saisie libre) ;
 * tout préréglage inconnu ou vide ne filtre rien (comportement historique).
 *
 * @return array{from: ?string, to: ?string}
 */
function resolveDateRangePreset(string $preset, ?string $from, ?string $to): array
{
    // Les timestamps comparés (messages.date_traitement, campagne.date_creation)
    // sont écrits en UTC par PostgreSQL (NOW()), mais PHP tourne par défaut
    // sur un autre fuseau dans cet environnement (Europe/Berlin) — date()
    // calculerait "aujourd'hui" avec l'heure murale locale, en avance de 1 à
    // 2h sur l'UTC, ce qui bascule au jour suivant plusieurs heures avant
    // minuit UTC (bug reproduit : "aujourd'hui" à 01h du matin heure de
    // Berlin excluait un envoi fait quelques minutes plus tôt, toujours "hier"
    // en UTC). Même piège que celui déjà documenté pour locked_until
    // (AuthService) et scheduled_at (CampaignQueueService::schedule) — utcNow()
    // ci-dessous centralise le correctif pour éviter une 4e occurrence.
    $today = utcToday();

    return match ($preset) {
        'today' => ['from' => $today, 'to' => $today],
        '7d' => ['from' => utcDateOffset(-6), 'to' => $today],
        '30d' => ['from' => utcDateOffset(-29), 'to' => $today],
        'month' => [
            'from' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-01'),
            'to' => $today,
        ],
        'custom' => ['from' => $from ?: null, 'to' => $to ?: null],
        default => ['from' => null, 'to' => null],
    };
}

function utcToday(): string
{
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
}

function utcDateOffset(int $days): string
{
    return (new DateTime('now', new DateTimeZone('UTC')))->modify("$days days")->format('Y-m-d');
}

function getCampaignsReport(int $organizationId, ?string $dateFrom = null, ?string $dateTo = null, ?int $campagneId = null)
{
    [$filterSql, $filterParams] = buildAnalyticsFilter($dateFrom, $dateTo, $campagneId, 'date_creation');
    $sql = "SELECT id, nom, type, statut, total_destinataires, nombre_envoyes, nombre_echecs, date_creation, date_completion
            FROM campagne
            WHERE organization_id = :organization_id $filterSql
            ORDER BY date_creation DESC";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    foreach ($filterParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopErrors(int $organizationId, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null, ?int $campagneId = null)
{
    [$filterSql, $filterParams] = buildAnalyticsFilter($dateFrom, $dateTo, $campagneId, 'date_traitement');
    $sql = "SELECT error_code, COUNT(*) AS total
            FROM messages
            WHERE organization_id = :organization_id AND statut = 'echec' AND error_code IS NOT NULL $filterSql
            GROUP BY error_code
            ORDER BY total DESC
            LIMIT :limit";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    foreach ($filterParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// function sms infos
