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
 * \file    ecotaxe/admin/about.php
 * \ingroup ecotaxe
 * \brief   About page of module Ecotaxe.
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../admin/setup.php';

// Translations
$langs->loadLangs(array("errors", "admin", "ecotaxe@ecotaxe"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');
$mode = GETPOST('mode', 'aZ09');

/*
 * Actions
 */

/*
 * View
 */

$form = new Form($db);

$page_name = "EcotaxeAbout";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'object_ecotaxe@ecotaxe');

// Configuration header
$head = ecotaxeAdminPrepareHead();
print dol_get_fiche_head($head, 'about', '', 0, 'ecotaxe@ecotaxe');

if ($mode == 'dev') {
    // Developer mode
    print '<div class="fichecenter"><div class="fichethirdleft">';
    print '<span class="opacitymedium">' . $langs->trans("DevelopersInfos") . '</span><br><br>';
    
    $dirmodule = dol_buildpath('ecotaxe', 0);
    
    print '<table class="tableforfieldinfo centpercent">';
    
    // Module directory
    print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("ModuleDir") . '</td><td>' . $dirmodule . '</td></tr>';
    
    // Module descriptor file
    print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("ModuleDescriptorFile") . '</td><td>' . $dirmodule . '/core/modules/modEcotaxe.class.php</td></tr>';
    
    print '</table>';
    print '</div><div class="fichetwothirdright">';
    print '</div></div>';
} else {
    // Normal mode
    print '<div class="fichecenter"><div class="fichethirdleft">';
    print $langs->trans("Module created to handle ecotaxes");
    print '<br><br>';
    print $langs->trans("For more information about this module");
    print '<br><br>';
    
    print '</div><div class="fichetwothirdright">';
    
    $MAXLOGOS = 5;
    $width = 100;
    
    print '<div class="imgmd"><br>';
    print '<span class="fa fa-leaf fa-5x img imgheight="' . $width . 'px"></span>';
    print '</div>';
    
    print '</div></div>';
}

print dol_get_fiche_end();

// Page end
llxFooter();
$db->close();