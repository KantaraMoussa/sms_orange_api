<?php
require_once('./config.php');
session_start();
$_SESSION['class'] = "";
$_SESSION['message'] = "";
if (isset($_POST['create_group'])) {
    $libelle = trim($_POST['group_name']);
    $description = trim($_POST['group_description']);
    if ($libelle != "") {
        try {
            $sql = "INSERT INTO groupes (libelle, description)  VALUES (:libelle, :description)";
            $stmt = PDO()->prepare($sql);
            $stmt->execute([":libelle" => $libelle, ":description" => $description]);
            $_SESSION['class'] = "alert alert-success";
            $_SESSION['message'] = "✅ Groupe créer  avec succès.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        } catch (PDOException $e) {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "❌ Erreur lors de la création : " . $e->getMessage() . "";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "Veuillez s'aisir le nom du groupe";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}
// Importer contacts via CSV
if (isset($_POST['import_csv']) && isset($_FILES['csvFile'])) {
    $group_id = $_POST['group_id'];
    if (!empty($group_id)) {
        $file = $_FILES['csvFile']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            // Lire la première ligne = en-têtes
            $headers = fgetcsv($handle, 1000, ",");

            // Vérifier que le format est correct
            if (strtolower(trim($headers[0])) !== "nom" || strtolower(trim($headers[1])) !== "telephone") {
                $_SESSION['class'] = "alert alert-warning";
                $_SESSION['message'] = "❌ Le fichier CSV doit contenir les colonnes : <b>Nom,Telephone</b>";
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            } else {
                // Lire chaque ligne et insérer
                $count = 0;
                $contact = [];
                $ret = [];
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $name = trim($data[0]);
                    $phone = trim($data[1]);
                    $email = trim($data[2]);
                    $phone = '+224' . $phone;
                    // ajouter dans un tableaux
                    if ($name != "" && $phone != "") {
                        $contact['nom'] = $name;
                        $contact['telephone'] = $phone;
                        $contact['email'] = $email;
                        $contact['id'] = random_int(10000000, 99999999);
                        array_push($ret, $contact);
                    } else {
                        $_SESSION['class'] = "alert alert-danger";
                        $_SESSION['message'] = "Echec lors du traitement du fichier la ligne du numéro de téléphone  $phone à un probléme .";
                        header("Location: " . $_SERVER['HTTP_REFERER']);
                        exit();
                    }
                }
                foreach ($ret as $data) {
                    try {
                        if (!phoneExiste($data['telephone'])) {
                            $sql = "INSERT INTO contacts (nom, telephone, email,contact_id) VALUES (:nom, :telephone, :email,:id)";
                            $stmt = PDO()->prepare($sql);
                            $stmt->execute([
                                ":nom" => $data['nom'],
                                ":telephone" => $data['telephone'],
                                ":email" => $data['email'],
                                ":id" => $data['id'],
                            ]);
                        }
                        $sql2 = "INSERT INTO groupe_contacts (groupe_id, contact_id) VALUES (:group, :id)";
                        $req = PDO()->prepare($sql2);
                        $req->execute([":group" => 1, ":id" => $data['id']]);

                        $count++;
                        // echo "<div class='alert alert-success'>✅ Contact ajouté avec succès !</div>";
                        // echo "<a href='javascript:history.back()' class='btn btn-primary'>⬅ Retour</a>";
                    } catch (PDOException $e) {
                        if ($e->getCode() == "23505") { // violation contrainte UNIQUE
                            continue;
                        } else {
                            echo "<div class='alert alert-danger'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
                        }
                    }
                }
                if ($sql2) {
                    $_SESSION['class'] = "alert alert-success";
                    $_SESSION['message'] = "✅ $count contact(s) importé(s) avec succès.";
                    header("Location: " . $_SERVER['HTTP_REFERER']);
                    exit();
                }
            }
            fclose($handle);
        }
    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ veuillez selectionner un groupe";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
}
if (isset($_POST['add_contact'])) {
    $group_id = $_POST['group_id'];
    $name = trim($_POST['contact_name']);
    $phone = trim('+224' . $_POST['contact_phone']);
    $email = trim($_POST['contact_email']);

    if (!empty($group_id)) {

        if ($name != "" && $phone != "") {

            if (!phoneExiste($phone)) {
                $data['id'] = random_int(10000000, 99999999);
                $sql = "INSERT INTO contacts (nom, telephone, email,contact_id) VALUES (:nom, :telephone, :email,:id)";
                $stmt = PDO()->prepare($sql);
                $stmt->execute([
                    ":nom" => $name,
                    ":telephone" => $phone,
                    ":email" => $email,
                    ":id" => $data['id'],
                ]);
                $sql2 = "INSERT INTO groupe_contacts (groupe_id, contact_id) VALUES (:group, :id)";
                $req = PDO()->prepare($sql2);
                $req->execute([":group" => 1, ":id" => $data['id']]);
                $_SESSION['class'] = "alert alert-success";
                $_SESSION['message'] = "✅ Contact ajouté au groupe avec succès.";
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            }
        } else {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "Veuillez s'aisir le numéro et le nom";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    } else {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Veuillez selectionnez un groupe";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

if (isset($_POST['single-sender'])) {
    $pattern = "/^(\+224|00224)6\d{8}$/";
    $numero = trim($_POST['number']);
    $message = trim($_POST['message']);
    if ($numero != "" && $message != "") {

        if (preg_match($pattern, $numero)) {
            $response = $sms->message($message)
                ->from('+224620000000') // Numéro expéditeur
                ->to($numero)
                ->send();

            $_SESSION['class'] = "alert alert-success";
            $_SESSION['message'] = "✅ Message envoyé au {$numero} avec succéess .";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        } else {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "❌ Le numéro {$numero} est invalide  .";
            header("Location: " . $_SERVER['HTTP_REFERER']);
        }

    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "Veuillez entrer le numéro de téléphone et le message .";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}
/*
if (isset($_POST['single-sender'])) {

    $message = " 📢 ALERT UGLCS-SCOLARITE : La biométrie démarre le 19/11/2025 et se termine le 27/11/2025.
        Seuls les étudiants de L1 sont concernés.
        Sans biométrie, vous perdrez votre statut d’étudiant.";
            // Tableau des numéros
            $numbers = [];
            // Boucle d’envoi
            foreach ($numbers as $numero) {
                try {
                    $sms->message($message)
                        ->from('+224620000000') // Numéro expéditeur
                        ->to($numero)
                        ->send();

                    echo "✅ SMS envoyé à : $numero\n";
                } catch (Exception $e) {
                    echo "❌ Erreur avec $numero : " . $e->getMessage() . "\n";
                }
            }
}*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_campagne'])) {
    $nom         = trim($_POST['campagne_name']);
    $description = trim($_POST['campagne_description']);
    // Générer automatiquement date_debut et date_fin
    $date_debut = date("Y-m-d H:i:s");                        // maintenant
    $date_fin   = date("Y-m-d H:i:s", strtotime("+1 month")); // +1 mois

    if (!empty($nom)) {
        // Requête préparée pour insérer la campagne
        $sql = "INSERT INTO campagne (nom, description, date_debut, date_fin)
                VALUES (:nom, :description, :date_debut, :date_fin)";
        $stmt = PDO()->prepare($sql);
        $stmt->bindParam(':nom', $nom);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':date_debut', $date_debut);
        $stmt->bindParam(':date_fin', $date_fin);
        if ($stmt->execute()) {
            $_SESSION['class'] = "alert alert-success";
            $_SESSION['message'] = "✅ Campagne ajoutée avec succès ";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        } else {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "Erreur lors de l'ajout de la campagne.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    } else {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom de la campagne est obligatoire ";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}
if (isset($_POST['importMessage_csv']) && isset($_FILES['csvFile']) && isset($_POST['campagne'])) {
    $campagneId = (int) $_POST['campagne'];
    $ret = [];

    $fileTmp = $_FILES['csvFile']['tmp_name'];

    if (($handle = fopen($fileTmp, "r")) !== false) {
        $row = 0;
        $contact = [];
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            $row++;
            if ($row == 1) continue; // Ignorer la première ligne (entêtes CSV)
            $destinataire = trim('+224' . $data[0]);
            $contenu = trim($data[1]);
            $matricule = trim($data[2]);
            $notes = trim($data[3]);
            $level = trim($data[4]);
            if (!empty($destinataire) && !empty($contenu)) {

                /* $contact['destinataire'] = '+224'.$destinataire;
                $contact['contenu'] = $contenu;
                $contact['campagne_id'] = $campagneId;
                 $contact['matricule'] = $matricule;
                $contact['notes'] = $notes;
                array_push($ret, $contact);*/
                $sql = "INSERT INTO messages (destinataire, contenu, date_envoi, statut, campagne_id,matricule,notes,niveaux) 
                        VALUES (:destinataire, :contenu, NOW(), 'en_attente', :campagne_id, :matricule, :notes, :niveaux)";
                $stmt = PDO()->prepare($sql);
                $stmt->execute([
                    ':destinataire' =>   $destinataire,
                    ':contenu' =>   $contenu,
                    ':campagne_id' => $campagneId,
                    ':matricule' =>  $matricule,
                    ':notes' =>  $notes,
                    ':niveaux' =>  $level,
                ]);
            } else {
                continue;
                $_SESSION['class'] = "alert alert-danger";
                $_SESSION['message'] = "Echec lors du traitement du fichier la ligne du numéro de téléphone  $destinataire à un probléme .";
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit();
            }
        }
        /* foreach ($ret as $data) {
            $sql = "INSERT INTO messages (destinataire, contenu, date_envoi, statut, campagne_id,matricule,notes) 
                        VALUES (:destinataire, :contenu, NOW(), 'en_attente', :campagne_id, :matricule, :notes)";
            $stmt = PDO()->prepare($sql);
            $stmt->execute([
                ':destinataire' =>  $data['destinataire'],
                ':contenu' =>  $data['contenu'],
                ':campagne_id' => $campagneId,
                ':matricule' =>  $data['matricule'],
                ':notes' =>  $data['notes'],
            ]);
        }*/
        fclose($handle);
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Importation réussie !";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Impossible de lire le fichier.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
}
/*

// Envoi d’un SMS à un groupe
if (isset($_POST['groupe_id']) && isset($_POST['message'])) {
    $groupeId = (int) $_POST['groupe_id'];
    $message = trim($_POST['message']);

    try {
        // 1. Insertion du SMS global
        $stmt = $pdo->prepare("INSERT INTO sms (contenu) VALUES (:contenu) RETURNING id");
        $stmt->execute([':contenu' => $message]);
        $smsId = $stmt->fetchColumn();

        // 2. Récupérer les contacts du groupe
        $stmt = $pdo->prepare("SELECT id, numero FROM contacts WHERE groupe_id = :gid");
        $stmt->execute([':gid' => $groupeId]);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contacts as $contact) {
            $numero = $contact['numero'];

            // 3. Envoi SMS via Orange
            $result = sendSmsOrange($numero, $message);

            $statut = $result['success'] ? 'envoye' : 'echec';

            // 4. Insertion dans sms_destinataires
            $stmtInsert = $pdo->prepare(
                "INSERT INTO sms_destinataires (sms_id, contact_id, numero, statut)
                 VALUES (:sms_id, :contact_id, :numero, :statut)"
            );
            $stmtInsert->execute([
                ':sms_id' => $smsId,
                ':contact_id' => $contact['id'],
                ':numero' => $numero,
                ':statut' => $statut
            ]);
        }

        echo "<div class='alert alert-success'>SMS envoyé au groupe (ID=$groupeId) avec gestion des statuts.</div>";

    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Erreur : " . $e->getMessage() . "</div>";
    }
}
?>




*/