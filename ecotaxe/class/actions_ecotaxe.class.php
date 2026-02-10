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
     * @var array Module configuration
     */
    public $conf;

    /**
     * @var array Language dictionary
     */
    public $langs;

    /**
     * @var User User object
     */
    public $user;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf, $langs, $user;

        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;
        $this->user = $user;
    }

    /**
     * Overloading the addMoreActionsButtons function : replacing the parent's function with the one below
     *
     * @param array   $parameters Hook metadata (context, etc...)
     * @param Commande $object     Current object
     * @return int                0 < on error, 0 on success, 1 to replace standard code
     */
    public function addMoreActionsButtons($parameters, &$object)
    {
        global $conf, $user, $langs;

        // Vérification des droits
        if (!$user->rights->commande->creer) {
            return 0;
        }

        // On vérifie que l'on est bien sur une commande
        if ($parameters['currentcontext'] !== 'ordercard') {
            return 0;
        }

        // On n'ajoute le bouton que si la commande est en brouillon
        if ($object->statut == 0) {
            print '<div class="inline-block divButAction">';
            print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=calculate_ecotaxe">' . $langs->trans("Calculer l'écotaxe") . '</a>';
            print '</div>';
        }

        return 0;
    }

    /**
     * Overloading the doActions function : replacing the parent's function with the one below
     *
     * @param array   $parameters Hook metadatas (context, etc...)
     * @param object  $object      Current object
     * @return int                 0 < on error, 0 on success, 1 to replace standard code
     */
    public function doActions($parameters, &$object)
    {
        global $conf, $user, $langs, $db;

        // On vérifie que l'on est bien sur une commande
        if ($parameters['currentcontext'] !== 'ordercard') {
            return 0;
        }

        // Récupération de l'action
        $action = GETPOST('action', 'aZ09');

        // Action pour calculer l'écotaxe
        if ($action == 'calculate_ecotaxe') {
            // Vérification des droits
            if (!$user->rights->commande->creer) {
                return 0;
            }

            // Chargement de la commande
            require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
            $commande = new Commande($db);
            $result = $commande->fetch(GETPOST('id', 'int'));
            if ($result <= 0) {
                setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
                return 0;
            }

            // On ne peut calculer l'écotaxe que sur une commande en brouillon
            if ($commande->statut != 0) {
                setEventMessages($langs->trans('ErrorOrderMustBeInDraftStatusToAddEcotaxe'), null, 'errors');
                return 0;
            }

            // CORRECTION 1 : Démarrer une transaction pour assurer la cohérence
            $this->db->begin();

            try {
                // Calcul du poids total et ajout de l'écotaxe
                $poidsTotal = 0;
                $montantEcotaxeTotal = 0;

                // Récupération de la valeur de l'écotaxe par tonne
                $ecotaxeValue = floatval(!empty($conf->global->ECOTAXE_VALUE) ? $conf->global->ECOTAXE_VALUE : 0.88);

                // Récupérer l'ID du service d'écotaxe
                $ecotaxeServiceId = intval(!empty($conf->global->ECOTAXE_SERVICE_ID) ? $conf->global->ECOTAXE_SERVICE_ID : 0);

                // CORRECTION 2 : Rechargement explicite des lignes pour avoir les données les plus récentes
                $commande->fetch_lines();

                // Vérifier si une ligne de service d'écotaxe existe déjà
                $ligneEcotaxeExistante = false;
                $ligneEcotaxeId = 0;
                $rangEcotaxeExistant = 0;

                // ÉTAPE 1 : Calculer les montants d'écotaxe et mettre à jour les extrafields des lignes
                if (!empty($commande->lines)) {
                    foreach ($commande->lines as $line) {
                        // Vérifier si c'est une ligne de service d'écotaxe existante
                        if ($line->fk_product == $ecotaxeServiceId && $line->product_type == 1) {
                            $ligneEcotaxeExistante = true;
                            $ligneEcotaxeId = $line->id;
                            $rangEcotaxeExistant = $line->rang;
                            continue; // Ne pas traiter cette ligne pour le calcul
                        }
                        
                        // On ne traite que les produits (pas les services) pour le calcul
                        if ($line->product_type == 0 && $line->fk_product > 0) {
                            // Chargement du produit pour récupérer son poids
                            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                            $product = new Product($db);
                            $result = $product->fetch($line->fk_product);

                            if ($result > 0 && $product->weight > 0) {
                                // Calculer le poids total en tonnes pour cette ligne
                                $weightUnit = $product->weight_units;
                                $weightInKg = 0;

                                // Conversion en kg selon l'unité
                                switch ($weightUnit) {
                                    case 0: // g
                                        $weightInKg = $product->weight / 1000;
                                        break;
                                    case 3: // kg
                                        $weightInKg = $product->weight;
                                        break;
                                    case 6: // t
                                        $weightInKg = $product->weight * 1000;
                                        break;
                                    case 98: // lb
                                        $weightInKg = $product->weight * 0.45359237;
                                        break;
                                    case 99: // oz
                                        $weightInKg = $product->weight * 0.0283495231;
                                        break;
                                    default:
                                        $weightInKg = $product->weight;
                                }

                                // Calculer le poids total de cette ligne
                                $quantite = $line->qty;
                                $poidsTotalLigne = ($weightInKg * $quantite) / 1000; // en tonnes pour le calcul de l'écotaxe
                                $poidsTotal += ($weightInKg * $quantite); // en kg pour l'extrafield

                                // Calcul de l'écotaxe pour cette ligne
                                $montantEcotaxeLigne = round($poidsTotalLigne * $ecotaxeValue, 3);
                                $montantEcotaxeTotal += $montantEcotaxeLigne;

                                // Mise à jour de l'extrafield de la ligne
                                if (!is_array($line->array_options)) {
                                    $line->array_options = array();
                                }
                                $line->array_options['options_montant_ecotaxe'] = $montantEcotaxeLigne;
                                
                                // Mise à jour de la ligne en préservant le rang
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
                                    throw new Exception($langs->trans('ErrorUpdatingLine') . ' : ' . $commande->error);
                                }
                            }
                        }
                    }
                }

                // CORRECTION 3 : Rechargement de la commande après mise à jour des extrafields
                $commande->fetch($commande->id);
                $commande->fetch_lines();

                // ÉTAPE 2 : Mise à jour des extrafields de la commande
                if ($poidsTotal > 0) {
                    $commande->array_options['options_poids_total'] = round($poidsTotal);
                    // CORRECTION 4 : Utiliser le format décimal standard sans formatage de locale
                    $montantEcotaxeTotalFormate = round($montantEcotaxeTotal, 3);
                    $commande->array_options['options_eco_taxe'] = $montantEcotaxeTotalFormate;
                    
                    // Mise à jour de la commande
                    $result = $commande->update($user);
                    if ($result <= 0) {
                        throw new Exception($langs->trans('ErrorUpdatingOrder') . ' : ' . $commande->error);
                    }
                    
                    // ÉTAPE 3 : Gestion de la ligne de service d'écotaxe
                    if ($ecotaxeServiceId > 0 && $montantEcotaxeTotalFormate > 0) {
                        // Charger le service
                        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                        $service = new Product($db);
                        $result = $service->fetch($ecotaxeServiceId);
                        
                        if ($result > 0) {
                            $descriptionService = $service->description ? $service->description : '';
                            $tva_tx = $service->tva_tx;
                            
                            if ($ligneEcotaxeExistante) {
                                // Mettre à jour la ligne existante
                                $result = $commande->updateline(
                                    $ligneEcotaxeId,
                                    $descriptionService,
                                    $montantEcotaxeTotalFormate, // CORRECTION 5 : Utiliser le montant non formaté
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
                                // Calculer le rang maximal et ajouter à la fin
                                $rang_max = 0;
                                foreach ($commande->lines as $line) {
                                    if ($line->rang > $rang_max) {
                                        $rang_max = $line->rang;
                                    }
                                }
                                
                                $result = $commande->addline(
                                    $descriptionService,
                                    $montantEcotaxeTotalFormate, // CORRECTION 6 : Utiliser le montant non formaté
                                    1,
                                    $tva_tx,
                                    0,
                                    0,
                                    $ecotaxeServiceId,
                                    0,
                                    '',
                                    '',
                                    0,
                                    0,
                                    0,
                                    0,
                                    0,
                                    0,
                                    $rang_max + 1,
                                    $service->fk_unit,
                                    0,
                                    'HT'
                                );
                            }
                            
                            if ($result <= 0) {
                                throw new Exception($langs->trans('ErrorAddingEcotaxeService') . ': ' . $commande->error);
                            }
                            
                            // CORRECTION 7 : Réorganiser les lignes après toutes les modifications
                            $this->reorganizeOrderLines($commande);
                        } else {
                            throw new Exception($langs->trans('ErrorLoadingEcotaxeService'));
                        }
                    }
                }

                // CORRECTION 8 : Commit de la transaction
                $this->db->commit();

                // Message de succès
                setEventMessages($langs->trans('EcotaxeCalculated') . ' : ' . price($montantEcotaxeTotal) . ' €', null);
                
            } catch (Exception $e) {
                // CORRECTION 9 : Rollback en cas d'erreur
                $this->db->rollback();
                setEventMessages($e->getMessage(), null, 'errors');
            }
            
            // Redirection vers la fiche commande
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $commande->id);
            exit;
        }

        return 0;
    }

    /**
     * Réorganiser les lignes de commande pour s'assurer que l'écotaxe est en dernier
     *
     * @param Commande $commande Objet commande
     * @return void
     */
    private function reorganizeOrderLines($commande)
    {
        global $conf;
        
        // Récupérer l'ID du service d'écotaxe
        $ecotaxeServiceId = intval(!empty($conf->global->ECOTAXE_SERVICE_ID) ? $conf->global->ECOTAXE_SERVICE_ID : 0);
        
        if ($ecotaxeServiceId <= 0) {
            return;
        }
        
        // CORRECTION 10 : Recharger les lignes de la commande pour avoir l'état le plus récent
        $commande->fetch_lines();
        
        $lignesNormales = array();
        $ligneEcotaxe = null;
        
        // Séparer les lignes normales de la ligne d'écotaxe
        foreach ($commande->lines as $line) {
            if ($line->fk_product == $ecotaxeServiceId && $line->product_type == 1) {
                $ligneEcotaxe = $line;
            } else {
                $lignesNormales[] = $line;
            }
        }
        
        // Réorganiser les rangs : lignes normales en premier, écotaxe à la fin
        $rang = 1;
        
        // Mettre à jour les rangs des lignes normales
        foreach ($lignesNormales as $line) {
            if ($line->rang != $rang) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "commandedet SET rang = " . intval($rang) . " WHERE rowid = " . intval($line->id);
                $this->db->query($sql);
            }
            $rang++;
        }
        
        // Mettre à jour le rang de la ligne d'écotaxe pour qu'elle soit en dernier
        if ($ligneEcotaxe && $ligneEcotaxe->rang != $rang) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "commandedet SET rang = " . intval($rang) . " WHERE rowid = " . intval($ligneEcotaxe->id);
            $this->db->query($sql);
        }
    }
}
