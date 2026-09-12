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
    $sql = "SELECT id,nom,description,date_creation,date_debut,date_fin,statut
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

function getGlobalSmsStats(int $organizationId)
{
    $sql = "SELECT
                COUNT(*) FILTER (WHERE statut = 'envoye') AS envoyes,
                COUNT(*) FILTER (WHERE statut = 'echec') AS echecs,
                COUNT(*) FILTER (WHERE statut IN ('en_attente','en_cours')) AS en_attente
            FROM messages
            WHERE organization_id = :organization_id";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
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

function getCampaignsReport(int $organizationId)
{
    $sql = "SELECT id, nom, type, statut, total_destinataires, nombre_envoyes, nombre_echecs, date_creation, date_completion
            FROM campagne
            WHERE organization_id = :organization_id
            ORDER BY date_creation DESC";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopErrors(int $organizationId, int $limit = 10)
{
    $sql = "SELECT error_code, COUNT(*) AS total
            FROM messages
            WHERE organization_id = :organization_id AND statut = 'echec' AND error_code IS NOT NULL
            GROUP BY error_code
            ORDER BY total DESC
            LIMIT :limit";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// function sms infos
