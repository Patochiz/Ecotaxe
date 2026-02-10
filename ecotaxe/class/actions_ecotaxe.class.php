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
 * \file    ecotaxe/class/actions_ecotaxe.class.php
 * \ingroup ecotaxe
 * \brief   Fichier de la classe des actions du module Ecotaxe
 */

/**
 * Class ActionsEcotaxe
 */
class ActionsEcotaxe
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var bool Drapeau anti-récursion pour le calcul automatique
     */
    private static $isCalculating = false;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Indique si un calcul d'écotaxe est en cours (anti-récursion)
     *
     * @return bool
     */
    public static function isCalculating()
    {
        return self::$isCalculating;
    }

    /**
     * Overloading the addMoreActionsButtons function
     *
     * @param array    $parameters Hook metadata (context, etc...)
     * @param Commande $object     Current object
     * @return int                 0 < on error, 0 on success, 1 to replace standard code
     */
    public function addMoreActionsButtons($parameters, &$object)
    {
        global $user, $langs;

        if (!$user->rights->commande->creer) {
            return 0;
        }

        if ($parameters['currentcontext'] !== 'ordercard') {
            return 0;
        }

        // Bouton disponible uniquement sur les commandes en brouillon
        if ($object->statut == 0) {
            print '<div class="inline-block divButAction">';
            print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=calculate_ecotaxe">' . $langs->trans("Calculer l'écotaxe") . '</a>';
            print '</div>';
        }

        return 0;
    }

    /**
     * Overloading the doActions function
     *
     * @param array  $parameters Hook metadatas (context, etc...)
     * @param object $object     Current object
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function doActions($parameters, &$object)
    {
        global $user, $langs, $db;

        if ($parameters['currentcontext'] !== 'ordercard') {
            return 0;
        }

        $action = GETPOST('action', 'aZ09');

        if ($action == 'calculate_ecotaxe') {
            if (!$user->rights->commande->creer) {
                return 0;
            }

            $commande_id = GETPOST('id', 'int');
            $result = self::calculateEcotaxeForOrder($db, $commande_id);

            if ($result > 0) {
                require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
                $commande = new Commande($db);
                $commande->fetch($commande_id);
                $montant = isset($commande->array_options['options_eco_taxe']) ? $commande->array_options['options_eco_taxe'] : 0;
                setEventMessages($langs->trans('EcotaxeCalculated') . ' : ' . price($montant) . ' € HT', null);
            } elseif ($result < 0) {
                setEventMessages($langs->trans('ErrorCalculatingEcotaxe'), null, 'errors');
            } else {
                setEventMessages($langs->trans('EcotaxeNothingToCalculate'), null, 'warnings');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $commande_id);
            exit;
        }

        return 0;
    }

    /**
     * Calcule et met à jour l'écotaxe pour une commande.
     * Méthode statique réutilisable par les hooks et les triggers.
     *
     * @param DoliDB $db          Database handler
     * @param int    $commande_id ID de la commande
     * @return int                1 si OK, 0 si rien à faire, -1 si erreur
     */
    public static function calculateEcotaxeForOrder($db, $commande_id)
    {
        if (self::$isCalculating) {
            return 0;
        }

        self::$isCalculating = true;

        try {
            return self::doCalculateEcotaxe($db, $commande_id);
        } finally {
            self::$isCalculating = false;
        }
    }

    /**
     * Logique interne de calcul de l'écotaxe
     *
     * @param DoliDB $db          Database handler
     * @param int    $commande_id ID de la commande
     * @return int                1 si OK, 0 si rien à faire, -1 si erreur
     */
    private static function doCalculateEcotaxe($db, $commande_id)
    {
        global $conf, $user;

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        $ecotaxeValue = floatval(!empty($conf->global->ECOTAXE_VALUE) ? $conf->global->ECOTAXE_VALUE : 0.88);
        $ecotaxeServiceId = intval(!empty($conf->global->ECOTAXE_SERVICE_ID) ? $conf->global->ECOTAXE_SERVICE_ID : 0);

        // Charger la commande
        $commande = new Commande($db);
        $result = $commande->fetch($commande_id);
        if ($result <= 0) {
            return 0;
        }

        // Ne calculer que sur les commandes en brouillon
        if ($commande->statut != 0) {
            return 0;
        }

        $commande->fetch_lines();

        $db->begin();

        try {
            $poidsTotal = 0;
            $montantEcotaxeTotal = 0;

            // Identifier la ligne ecotaxe existante
            $ligneEcotaxeExistante = false;
            $ligneEcotaxeId = 0;
            $rangEcotaxeExistant = 0;

            // ÉTAPE 1 : Calculer les montants d'écotaxe et mettre à jour les extrafields des lignes
            if (!empty($commande->lines)) {
                foreach ($commande->lines as $line) {
                    // Identifier la ligne de service écotaxe existante
                    if ($ecotaxeServiceId > 0 && $line->fk_product == $ecotaxeServiceId && $line->product_type == 1) {
                        $ligneEcotaxeExistante = true;
                        $ligneEcotaxeId = $line->id;
                        $rangEcotaxeExistant = $line->rang;
                        continue;
                    }

                    // On ne traite que les produits (pas les services) pour le calcul
                    if ($line->product_type == 0 && $line->fk_product > 0) {
                        $product = new Product($db);
                        $result = $product->fetch($line->fk_product);

                        if ($result > 0 && $product->weight > 0) {
                            $weightInKg = self::convertWeightToKg($product->weight, $product->weight_units);
                            $quantite = $line->qty;
                            $poidsTotalLigne = ($weightInKg * $quantite) / 1000; // en tonnes
                            $poidsTotal += ($weightInKg * $quantite); // en kg

                            $montantEcotaxeLigne = round($poidsTotalLigne * $ecotaxeValue, 3);
                            $montantEcotaxeTotal += $montantEcotaxeLigne;

                            // Mise à jour de l'extrafield de la ligne
                            if (!is_array($line->array_options)) {
                                $line->array_options = array();
                            }
                            $line->array_options['options_montant_ecotaxe'] = $montantEcotaxeLigne;

                            $result = $commande->updateline(
                                $line->id,
                                $line->desc,
                                $line->subprice,
                                $line->qty,
                                $line->remise_percent,
                                $line->tva_tx,
                                $line->localtax1_tx,
                                $line->localtax2_tx,
                                'HT',
                                $line->info_bits,
                                $line->date_start,
                                $line->date_end,
                                $line->product_type,
                                $line->fk_parent_line,
                                0,
                                $line->fk_fournprice,
                                $line->pa_ht,
                                $line->label,
                                $line->special_code,
                                $line->array_options,
                                $line->fk_unit,
                                $line->multicurrency_subprice,
                                $line->rang
                            );

                            if ($result <= 0) {
                                throw new Exception('Error updating line: ' . $commande->error);
                            }
                        }
                    }
                }
            }

            // ÉTAPE 2 : Recharger la commande et mettre à jour les extrafields de l'en-tête
            $commande->fetch($commande_id);
            $commande->fetch_lines();

            $montantEcotaxeTotalFormate = round($montantEcotaxeTotal, 3);
            $commande->array_options['options_poids_total'] = round($poidsTotal);
            $commande->array_options['options_eco_taxe'] = $montantEcotaxeTotalFormate;

            $result = $commande->update($user);
            if ($result <= 0) {
                throw new Exception('Error updating order: ' . $commande->error);
            }

            // ÉTAPE 3 : Gestion de la ligne de service d'écotaxe
            if ($ecotaxeServiceId > 0) {
                $service = new Product($db);
                $result = $service->fetch($ecotaxeServiceId);

                if ($result > 0) {
                    $descriptionService = $service->description ? $service->description : '';
                    $tva_tx = $service->tva_tx;

                    if ($montantEcotaxeTotalFormate > 0) {
                        if ($ligneEcotaxeExistante) {
                            // Mettre à jour la ligne existante
                            $result = $commande->updateline(
                                $ligneEcotaxeId,
                                $descriptionService,
                                $montantEcotaxeTotalFormate,
                                1,
                                0,
                                $tva_tx,
                                0,
                                0,
                                'HT',
                                0,
                                '',
                                '',
                                1,
                                0,
                                0,
                                0,
                                0,
                                $service->label,
                                0,
                                array(),
                                $service->fk_unit,
                                0,
                                $rangEcotaxeExistant
                            );
                        } else {
                            // Ajouter une nouvelle ligne de service écotaxe
                            $rang_max = 0;
                            foreach ($commande->lines as $line) {
                                if ($line->rang > $rang_max) {
                                    $rang_max = $line->rang;
                                }
                            }

                            $result = $commande->addline(
                                $descriptionService,           // $desc
                                $montantEcotaxeTotalFormate,   // $pu_ht
                                1,                             // $qty
                                $tva_tx,                       // $txtva
                                0,                             // $txlocaltax1
                                0,                             // $txlocaltax2
                                $ecotaxeServiceId,             // $fk_product
                                0,                             // $remise_percent
                                0,                             // $info_bits
                                0,                             // $fk_remise_except
                                'HT',                          // $price_base_type
                                0,                             // $pu_ttc
                                '',                            // $date_start
                                '',                            // $date_end
                                1,                             // $type (1 = service)
                                $rang_max + 1,                 // $rang
                                0,                             // $special_code
                                0,                             // $fk_parent_line
                                null,                          // $fk_fournprice
                                0,                             // $pa_ht
                                $service->label,               // $label
                                array(),                       // $array_options
                                $service->fk_unit              // $fk_unit
                            );
                        }

                        if ($result <= 0) {
                            throw new Exception('Error adding/updating ecotaxe service line: ' . $commande->error);
                        }
                    } elseif ($ligneEcotaxeExistante) {
                        // Montant = 0, supprimer la ligne de service ecotaxe
                        $result = $commande->deleteline($user, $ligneEcotaxeId);
                        if ($result <= 0) {
                            throw new Exception('Error removing ecotaxe service line: ' . $commande->error);
                        }
                    }

                    // Réorganiser les lignes pour que l'écotaxe soit en dernier
                    self::reorganizeOrderLines($db, $commande);
                } else {
                    throw new Exception('Error loading ecotaxe service product');
                }
            }

            $db->commit();
            return 1;

        } catch (Exception $e) {
            $db->rollback();
            dol_syslog('ActionsEcotaxe::calculateEcotaxeForOrder error: ' . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Convertit un poids dans l'unité donnée en kilogrammes
     *
     * @param float $weight Poids
     * @param int   $unit   Code unité Dolibarr (0=g, 3=kg, 6=t, 98=lb, 99=oz)
     * @return float Poids en kg
     */
    private static function convertWeightToKg($weight, $unit)
    {
        switch ($unit) {
            case 0:  // grammes
                return $weight / 1000;
            case 3:  // kilogrammes
                return $weight;
            case 6:  // tonnes
                return $weight * 1000;
            case 98: // livres
                return $weight * 0.45359237;
            case 99: // onces
                return $weight * 0.0283495231;
            default:
                return $weight;
        }
    }

    /**
     * Réorganise les lignes de commande pour que l'écotaxe soit en dernier
     *
     * @param DoliDB   $db       Database handler
     * @param Commande $commande Objet commande
     * @return void
     */
    private static function reorganizeOrderLines($db, $commande)
    {
        global $conf;

        $ecotaxeServiceId = intval(!empty($conf->global->ECOTAXE_SERVICE_ID) ? $conf->global->ECOTAXE_SERVICE_ID : 0);

        if ($ecotaxeServiceId <= 0) {
            return;
        }

        $commande->fetch_lines();

        $lignesNormales = array();
        $ligneEcotaxe = null;

        foreach ($commande->lines as $line) {
            if ($line->fk_product == $ecotaxeServiceId && $line->product_type == 1) {
                $ligneEcotaxe = $line;
            } else {
                $lignesNormales[] = $line;
            }
        }

        $rang = 1;
        foreach ($lignesNormales as $line) {
            if ($line->rang != $rang) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "commandedet SET rang = " . intval($rang) . " WHERE rowid = " . intval($line->id);
                $db->query($sql);
            }
            $rang++;
        }

        if ($ligneEcotaxe && $ligneEcotaxe->rang != $rang) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "commandedet SET rang = " . intval($rang) . " WHERE rowid = " . intval($ligneEcotaxe->id);
            $db->query($sql);
        }
    }
}
