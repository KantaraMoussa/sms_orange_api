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
require_once '../vendor/autoload.php';

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;
$clientId = "VDnMeAPmoenbvOD2BTtWDTe0ILdQ4SLC";
$clientSecret = "HQckwZtQNOGFXKb2tdUjG0ZZQSO4UFPpFueKU2l8GyFk";
$client = SMSClient::getInstance($clientId, $clientSecret);
$sms = new SMS($client);

function PDO()
{
    $host = "localhost";     // ou l'adresse IP du serveur
    $port = "5432";          // port par défaut PostgreSQL
    $dbname = "apiSms";   // nom de ta base
    $user = "postgres";      // ton utilisateur
    $password = "stratus05@1993"; // ton mot de passe
    try {
        // Connexion avec PDO
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Active les erreurs
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // Récupère les données en tableau associatif
        ]);
        return $pdo;
    } catch (PDOException $e) {
        echo "❌ Erreur de connexion : " . $e->getMessage();
    }
}
function getGroupes()
{
    $sql = "SELECT id, libelle, description, date_creation FROM groupes ORDER BY date_creation DESC";
    $stmt = PDO()->query($sql);
    return  $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getGroupe($idGroupe)
{
    $sql = "SELECT * FROM groupes WHERE id = :id";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([":id" => $idGroupe]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function getContactByGroupe($idGroupe)
{
    $sql = "SELECT *
                FROM contacts 
                INNER JOIN groupe_contacts  ON contacts.contact_id = groupe_contacts.contact_id
                WHERE groupe_contacts.groupe_id = :id";

    $stmt = PDO()->prepare($sql);
    $stmt->execute([":id" => $idGroupe]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function detailGroupe($idGroupe)
{
    $data = [];
    $ret = [];
    $ret_ = [];
    $groupe = getGroupe($idGroupe);
    $data['id'] = $groupe['id'];
    $data['libelle'] = $groupe['libelle'];
    $data['description'] = $groupe['description'];
    $contactgroupes = getContactByGroupe($idGroupe);
    if (count($contactgroupes) != 0) {
        foreach ($contactgroupes as $contact) {
            $ret['nom'] = $contact['nom'];
            $ret['email'] = $contact['email'];
            $ret['telephone'] = $contact['telephone'];
            array_push($ret_, $ret);
        }
    }
    $data['contacts'] = $ret_;

    return $data;
}
function getPhoneContact($telephone)
{
    $stmt = PDO()->prepare("SELECT * FROM contacts WHERE telephone = :telephone");
    $stmt->bindParam(':telephone', $telephone, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function phoneExiste($telephone)
{
    if (!empty(getPhoneContact($telephone))) {
        return true;
    } else {
        return false;
    }
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
    $sql = "SELECT id, nom, description, date_creation, date_debut, date_fin, statut 
            FROM campagne 
            WHERE id = :id";
    $stmt = PDO()->prepare($sql);
    $stmt->bindParam(':id', $campagneId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function getMessageCampagne($campagneId)
{
    $sql = "SELECT id, contenu, destinataire, date_envoi, statut 
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

function getMessageSenderMarksheet()
{
    $sql = "SELECT 
    messages.destinataire,messages.matricule,niveaux,
    STRING_AGG(messages.contenu || ' = ' || messages.notes || '', ' | ') AS messages
    FROM messages 
    GROUP BY messages.destinataire, messages.matricule, messages.niveaux
    ORDER BY messages.destinataire desc;";
    $stmt = PDO()->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getSingleStudentSendMarksheet($matricule){
    $sql = "
    SELECT 
    messages.destinataire,messages.matricule,
    STRING_AGG(messages.contenu || ' = ' || messages.notes || '', ' | ') AS messages
    FROM messages 
    where matricule=:matricule
    GROUP BY messages.destinataire, messages.matricule
    ORDER BY messages.destinataire desc";
    $stmt = PDO()->prepare($sql);
    $stmt->execute([":matricule" => $matricule]);
    return $stmt->fetch(PDO::FETCH_ASSOC); 
}


//-- var_dump(detailGroupe($_GET['details']));exit();





// function sms infos
