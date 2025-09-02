$(document).ready(function () {
  $("#formGroupe").submit(function (e) {
    e.preventDefault();
    alertify.confirm(
      "Etes vous sure de créer cet groupe.",
      function () {
        $.ajax({
          url: "../server/app.php", // ton fichier backend PHP
          type: "POST",
           data: $(this).serialize(), // envoie toutes les données du formulaire // données envoyées
           success: function (response) {
            // Notification succès
            console.log( $(this).serialize());
            alertify.success("✅ Succès : " + response);
          },
          error: function (xhr, status, error) {
            // Notification erreur
            alertify.error("❌ Erreur : " + error);
          },
        });
         
      },
      function () {
        alertify.error("Cancel");
      }
    );
  });
});
