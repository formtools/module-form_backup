<?php

require_once("../../global/library.php");

use FormTools\Modules;

$module = Modules::initModulePage("admin");
$L = $module->getLangStrings();

$page_vars = array(
    "head_title" => $L["module_name"]
);

$module->displayPage("templates/help.tpl", $page_vars);
