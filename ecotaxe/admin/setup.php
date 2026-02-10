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
 * \file    ecotaxe/admin/setup.php
 * \ingroup ecotaxe
 * \brief   Page de configuration du module Ecotaxe
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";

// Translations
$langs->loadLangs(array("admin", "ecotaxe@ecotaxe"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$arrayofparameters = array(
    'ECOTAXE_TEXT' => array('css' => 'width500', 'enabled' => 1),
    'ECOTAXE_VALUE' => array('css' => 'minwidth100', 'enabled' => 1),
    'ECOTAXE_SERVICE_ID' => array('css' => 'minwidth100', 'enabled' => 1)
);

/*
 * Actions
 */
if ($action == 'update' && !empty($user->admin)) {
    $error = 0;
    $ecotaxe_text = GETPOST('ECOTAXE_TEXT', 'alpha');
    $ecotaxe_value = price2num(GETPOST('ECOTAXE_VALUE', 'alpha'));
    $ecotaxe_service_id = GETPOST('ECOTAXE_SERVICE_ID', 'int');

    if (!$error) {
        if (!dolibarr_set_const($db, 'ECOTAXE_TEXT', $ecotaxe_text, 'chaine', 0, '', $conf->entity)) {
            $error++;
        }
        
        if (!dolibarr_set_const($db, 'ECOTAXE_VALUE', $ecotaxe_value, 'chaine', 0, '', $conf->entity)) {
            $error++;
        }
        
        if (!dolibarr_set_const($db, 'ECOTAXE_SERVICE_ID', $ecotaxe_service_id, 'chaine', 0, '', $conf->entity)) {
            $error++;
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "EcotaxeSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'object_ecotaxe@ecotaxe');

// Configuration header
$head = ecotaxeAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', '', -1, "ecotaxe@ecotaxe");

// Setup page goes here
echo '<span class="opacitymedium">' . $langs->trans("EcotaxeSetupPage") . '</span><br><br>';

if (!isset($form) && method_exists('Form', '__construct')) {
    $form = new Form($db);
}

print '<form action="' . $_SERVER["PHP_SELF"] . '" method="post">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans("Parameter") . '</td><td>' . $langs->trans("Value") . '</td></tr>';

print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans("EcotaxeText"), $langs->trans("EcotaxeTextDescription"));
print '</td><td><input name="ECOTAXE_TEXT" type="text" class="flat minwidth500" value="' . $conf->global->ECOTAXE_TEXT . '"></td></tr>';

print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans("EcotaxeValue"), $langs->trans("EcotaxeValueDescription"));
print '</td><td><input name="ECOTAXE_VALUE" type="text" class="flat minwidth100" value="' . $conf->global->ECOTAXE_VALUE . '"> € HT/tonne</td></tr>';

print '<tr class="oddeven"><td>';
print $form->textwithpicto($langs->trans("EcotaxeServiceID"), $langs->trans("EcotaxeServiceIDDescription"));
print '</td><td>';
// Créer un sélecteur de produit/service
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
$product = new Product($db);
$filter = array();
$filter['t.fk_product_type'] = 1; // 1 = Service
print $form->select_produits($conf->global->ECOTAXE_SERVICE_ID, 'ECOTAXE_SERVICE_ID', '', 0, 0, 1, 2, '', 0, $filter);
print '</td></tr>';

print '</table>';

print '<br><div class="center">';
print '<input class="button" type="submit" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

// Page end
llxFooter();
$db->close();

/**
 * Prepare admin pages header
 *
 * @return array
 */
function ecotaxeAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("ecotaxe@ecotaxe");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ecotaxe/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;

    $head[$h][0] = dol_buildpath("/ecotaxe/admin/about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Complete with extra dev info
    if (empty($conf->global->MAIN_DISABLEPROFILELINKINGDEVELOPERTAB)) {
        $head[$h][0] = dol_buildpath("/ecotaxe/admin/about.php", 1) . '?mode=dev';
        $head[$h][1] = $langs->trans("Developers");
        $head[$h][2] = 'dev';
        $h++;
    }

    return $head;
}