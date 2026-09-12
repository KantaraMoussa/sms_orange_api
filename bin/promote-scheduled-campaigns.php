<?php

/**
 * Promeut les campagnes SCHEDULED dont l'heure est arrivée en QUEUED (§30)
 * et génère la prochaine occurrence des campagnes récurrentes dues
 * (automatisations, Phase 2). Destiné à une tâche planifiée classique (cron
 * toutes les minutes, ou Windows Task Scheduler) dans un déploiement qui
 * n'utilise pas le démon de bin/process-campaign.php --daemon (celui-ci fait
 * déjà les deux à chaque tour de boucle et n'a pas besoin de ce script en plus).
 *
 * Usage : php bin/promote-scheduled-campaigns.php
 * Cron  : * * * * * php /chemin/vers/bin/promote-scheduled-campaigns.php
 */

require_once __DIR__ . '/../server/config.php';

$promoted = campaignQueue()->promoteDueCampaigns();
echo "$promoted campagne(s) planifiée(s) promue(s) en QUEUED.\n";

// Automatisations : même logique que dans le démon de process-campaign.php
// (voir CampaignQueueService::processRecurringCampaigns()) — nécessaire ici
// aussi puisque ce script est justement destiné aux déploiements qui
// n'utilisent pas ce démon.
$spawned = campaignQueue()->processRecurringCampaigns();
echo count($spawned) . " occurrence(s) automatique(s) générée(s).\n";
