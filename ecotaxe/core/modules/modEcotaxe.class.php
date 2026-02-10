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
 * Description du module Écotaxe
 */

// Le module est chargé sous un autre nom dans les paramètres système
// Charge l'environnement et les bibliothèques Dolibarr
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Ecotaxe
 */
class modEcotaxe extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        // Id for module (must be unique).
        $this->numero = 750000; // TODO: Replace with a free id

        // Key text used to identify module (for permissions, menus, etc...)
        $this->rights_class = 'ecotaxe';

        // Family can be 'base' (core modules),'crm','financial','hr','projects','products','marketing','interface',etc
        $this->family = "products";

        // Module label (no space allowed), used if translation string 'ModuleXXXName' not found (where XXX is value of numeric property 'numero' of module)
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        // Module description, used if translation string 'ModuleXXXDesc' not found (where XXX is value of numeric property 'numero' of module)
        $this->description = "Module d'ajout d'écotaxe sur les lignes de commandes clients";

        // Used only if file README.md and README-LL.md not found.
        $this->descriptionlong = "Module pour ajouter automatiquement une écotaxe aux commandes clients en fonction du poids des produits";

        $this->editor_name = 'Editor name';
        $this->editor_url = 'https://www.example.com';

        // Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
        $this->version = '1.0';

        // Url to the file with your last numberversion of this module
        $this->url_last_version = 'https://www.example.com/versionmodule.txt';

        // Key used in llx_const table to save module status enabled/disabled (where MYMODULE is value of property name of module in uppercase)
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

        // Name of image file used for this module.
        $this->picto = 'generic';

        // Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
        $this->module_parts = array(
            // Set this to 1 if module has its own trigger directory (core/triggers)
            'triggers' => 1,
            // Set this to 1 if module has its own models directory (core/modules/xxx)
            'models' => 0,
            // Set this to 1 if module overwrite template dir (core/tpl)
            'tpl' => 0,
            // Set this to 1 if module has its own barcode directory (core/modules/barcode)
            'barcode' => 0,
            // Set this to 1 if module has its own printing directory (core/modules/printing)
            'printing' => 0,
            // Set this to 1 if module has its own export directory (core/modules/export)
            'export' => 0,
            // Set this to 1 if module has its own import directory (core/modules/import)
            'import' => 0,
            // Set this to relative path of css file if module has its own css file
            'css' => array(),
            // Set this to relative path of js file if module has its own js file
            'js' => array(),
            // Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code.
            'hooks' => array('ordercard', 'ordersuppliercard', 'commandes', 'commandecard'),
            // Set this to 1 if features of module are opened to external users
            'moduleforexternal' => 0,
        );

        // Data directories to create when module is enabled.
        $this->dirs = array('/ecotaxe/temp');

        // Config pages. Put here list of php page, stored into ecotaxe/admin directory, to use to setup module.
        $this->config_page_url = array("setup.php@ecotaxe");

        // Dependencies
        // A condition to hide module
        $this->hidden = false;
        // List of module class names as string that must be enabled if this module is enabled
        $this->depends = array('modCommande');
        // List of module class names as string to disable if this module is disabled
        $this->requiredby = array();
        // List of module class names as string this module is in conflict with
        $this->conflictwith = array();
        // Minimum PHP version required by this module
        $this->phpmin = array(7, 0);
        // Minimum version of Dolibarr required by this module
        $this->need_dolibarr_version = array(16, 0);

        // Constants
        $this->const = array(
            // CONST ECOTAXE
            1 => array(
                'name' => 'ECOTAXE_TEXT',
                'description' => 'Texte à ajouter dans la description du produit',
                'type' => 'chaine',
                'val' => 'Éco participation 070100500 (plafonds métalliques) VALOBAT : FR317236_04AURA',
                'visible' => 1,
                'enabled' => 1,
                'position' => 10
            ),
            2 => array(
                'name' => 'ECOTAXE_VALUE',
                'description' => 'Valeur de l\'écotaxe en € HT par tonne',
                'type' => 'chaine',
                'val' => '0.88',
                'visible' => 1,
                'enabled' => 1,
                'position' => 20
            ),
            3 => array(
                'name' => 'ECOTAXE_SERVICE_ID',
                'description' => 'ID du service à utiliser pour l\'écotaxe',
                'type' => 'chaine',
                'val' => '',
                'visible' => 1,
                'enabled' => 1,
                'position' => 30
            )
        );

        // Cronjobs (List of cron jobs entries to add when module is enabled)
        $this->cronjobs = array();

        // Permissions provided by this module
        $this->rights = array();

        // Main menu entries to add
        $this->menu = array();
    }

    /**
     * Function called when module is enabled.
     * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
     * It also creates data directories
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        global $conf, $langs;

        $sql = array();

        // Create extrafields
        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);

        // Ajout extrafield poids_total
        $result = $extrafields->addExtraField(
            'poids_total',             // attrname
            'Poids total (kg)',        // label modifié pour afficher kg au lieu de tonnes
            'double',                  // type
            100,                       // pos
            '',                        // size
            'commande',                // elementtype
            0,                         // unique
            0,                         // required
            '',                        // default_value
            array('enabled' => 1)      // param
        );

        // Ajout extrafield eco_taxe
        $result = $extrafields->addExtraField(
            'eco_taxe',                // attrname
            'Montant écotaxe (€ HT)',  // label
            'price',                   // type
            110,                       // pos
            '',                        // size
            'commande',                // elementtype
            0,                         // unique
            0,                         // required
            '',                        // default_value
            array('enabled' => 1)      // param
        );
        
        // Ajout extrafield montant_ecotaxe pour les lignes de commande
        $result = $extrafields->addExtraField(
            'montant_ecotaxe',           // attrname
            'Écotaxe (€ HT)',            // label
            'price',                     // type
            120,                         // pos
            '',                          // size
            'commandedet',               // elementtype - pour les lignes de commande
            0,                           // unique
            0,                           // required
            '',                          // default_value
            array('enabled' => 1)        // param
        );

        $result = $this->_load_tables('/ecotaxe/sql/');
        if ($result < 0) {
            return -1; // Do not activate if tables not created
        }

        dolibarr_set_const($this->db, "ECOTAXE_TEXT", 'Éco participation 070100500 (plafonds métalliques) VALOBAT : FR317236_04AURA', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, "ECOTAXE_VALUE", '0.88', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, "ECOTAXE_SERVICE_ID", '', 'chaine', 0, '', $conf->entity);

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Remove from database constants, boxes and permissions from Dolibarr database.
     * Data directories are not deleted
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();

        return $this->_remove($sql, $options);
    }
}