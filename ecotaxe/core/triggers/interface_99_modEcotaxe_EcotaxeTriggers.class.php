<?php
/* Copyright (C) 2023 Nom de l'auteur <email@domaine.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ecotaxe/core/triggers/interface_99_modEcotaxe_EcotaxeTriggers.class.php
 * \ingroup ecotaxe
 * \brief   Triggers du module Ecotaxe pour le recalcul automatique
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceEcotaxeTriggers
 *
 * Recalcule automatiquement l'écotaxe lorsqu'une ligne de commande
 * est ajoutée, modifiée ou supprimée.
 */
class InterfaceEcotaxeTriggers extends DolibarrTriggers
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "products";
        $this->description = "Recalcul automatique de l'écotaxe sur les commandes";
        $this->version = '1.0';
        $this->picto = 'generic';
    }

    /**
     * Fonction appelée lors du déclenchement d'un évènement Dolibarr.
     *
     * @param string    $action Event action code
     * @param Object    $object Object
     * @param User      $user   User
     * @param Translate $langs  Langs
     * @param Conf      $conf   Conf
     * @return int               0 < on error, 0 on success
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        // Ne traiter que les événements de ligne de commande client
        if (!in_array($action, array('LINEORDER_INSERT', 'LINEORDER_MODIFY', 'LINEORDER_UPDATE', 'LINEORDER_DELETE'))) {
            return 0;
        }

        // Charger la classe d'actions
        dol_include_once('/ecotaxe/class/actions_ecotaxe.class.php');

        // Anti-récursion : si un calcul est déjà en cours, ne rien faire
        if (ActionsEcotaxe::isCalculating()) {
            return 0;
        }

        // Identifier le produit de la ligne concernée (selon le type d'objet reçu)
        // Dans Dolibarr, $object peut être un Commande ou un OrderLine selon la version
        $ecotaxeServiceId = intval(!empty($conf->global->ECOTAXE_SERVICE_ID) ? $conf->global->ECOTAXE_SERVICE_ID : 0);

        $fk_product_line = 0;
        if (isset($object->fk_product)) {
            // $object est un OrderLine
            $fk_product_line = $object->fk_product;
        } elseif (isset($object->line) && isset($object->line->fk_product)) {
            // $object est un Commande, la ligne est dans $object->line
            $fk_product_line = $object->line->fk_product;
        }

        // Ne pas recalculer si la ligne concernée est la ligne écotaxe elle-même
        if ($ecotaxeServiceId > 0 && $fk_product_line == $ecotaxeServiceId) {
            return 0;
        }

        // Récupérer l'ID de la commande parente (selon le type d'objet reçu)
        $commande_id = 0;
        if (isset($object->fk_commande) && $object->fk_commande > 0) {
            // $object est un OrderLine
            $commande_id = $object->fk_commande;
        } elseif (isset($object->element) && $object->element == 'commande' && $object->id > 0) {
            // $object est un Commande
            $commande_id = $object->id;
        }

        if ($commande_id <= 0) {
            return 0;
        }

        // Lancer le recalcul automatique
        $result = ActionsEcotaxe::calculateEcotaxeForOrder($this->db, $commande_id);

        if ($result < 0) {
            dol_syslog('EcotaxeTriggers::runTrigger error on ' . $action . ' for order ' . $commande_id, LOG_ERR);
        }

        return 0;
    }
}
