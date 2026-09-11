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
function getCampagne()
{
    $sql = "SELECT id,nom,description,date_creation,date_debut,date_fin,statut 
        FROM campagne 
        ORDER BY date_creation DESC";
    $stmt = PDO()->query($sql);
    return  $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getSingleCampagne($campagneId)
{
    $sql = "SELECT * FROM campagne WHERE id = :id";
    $stmt = PDO()->prepare($sql);
    $stmt->bindParam(':id', $campagneId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function getMessageCampagne($campagneId)
{
    $sql = "SELECT id, contenu, destinataire, date_envoi, statut, matricule, nom, prenom, error_code, error_message, tentative_count, date_traitement
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
function redirectBack(string $fallback = '../app/index.php'): void
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($referer !== '' && $host !== '' && parse_url($referer, PHP_URL_HOST) === $host) {
        header("Location: $referer");
    } else {
        header("Location: $fallback");
    }
}

// -- Statistiques réelles pour le tableau de bord (remplacent les KPI/graphiques
// factices de la Phase 1, cf. audit §8) --

function getGlobalSmsStats()
{
    $sql = "SELECT
                COUNT(*) FILTER (WHERE statut = 'envoye') AS envoyes,
                COUNT(*) FILTER (WHERE statut = 'echec') AS echecs,
                COUNT(*) FILTER (WHERE statut IN ('en_attente','en_cours')) AS en_attente
            FROM messages";
    $row = PDO()->query($sql)->fetch(PDO::FETCH_ASSOC);
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

function getSmsEvolution(int $days = 14)
{
    $sql = "SELECT DATE(date_traitement) AS jour, COUNT(*) AS total
            FROM messages
            WHERE statut = 'envoye' AND date_traitement >= NOW() - (:days || ' days')::interval
            GROUP BY DATE(date_traitement)
            ORDER BY jour";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([':days' => $days]);
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
function getSuccessRateEvolution(int $days = 14)
{
    $sql = "SELECT DATE(date_traitement) AS jour,
                COUNT(*) FILTER (WHERE statut = 'envoye') AS envoyes,
                COUNT(*) FILTER (WHERE statut IN ('envoye', 'echec')) AS traites
            FROM messages
            WHERE date_traitement >= NOW() - (:days || ' days')::interval
            GROUP BY DATE(date_traitement)
            ORDER BY jour";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([':days' => $days]);
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

function getCampaignPerformance(int $limit = 6)
{
    $sql = "SELECT nom, nombre_envoyes, nombre_echecs
            FROM campagne
            WHERE total_destinataires > 0
            ORDER BY date_creation DESC
            LIMIT :limit";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getCampaignsReport()
{
    $sql = "SELECT id, nom, type, statut, total_destinataires, nombre_envoyes, nombre_echecs, date_creation, date_completion
            FROM campagne
            ORDER BY date_creation DESC";
    return PDO()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getTopErrors(int $limit = 10)
{
    $sql = "SELECT error_code, COUNT(*) AS total
            FROM messages
            WHERE statut = 'echec' AND error_code IS NOT NULL
            GROUP BY error_code
            ORDER BY total DESC
            LIMIT :limit";
    $stmt = PDO()->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// function sms infos
