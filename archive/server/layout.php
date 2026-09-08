<?php
require_once('config.php');
function ListGroupe()
{
    $groupes = getGroupes();
    if (count($groupes) != 0) {
        foreach ($groupes as $groupe) {
            $date=new DateTime($groupe['date_creation']);
            echo ' <tr>
                 <td>'.$groupe['libelle'].'</td>
                 <td>'.$groupe['description'].'</td>
                 <td>'. $date->format("d/m/Y H:i:s") .'</td>
                 <td>
                   <a href="?page=Groupes&details='.$groupe['id'].'"> <i class="fa fa-eye"></i> Voir plus </a>
                 </td>
               </tr>';
        }
    }
}
