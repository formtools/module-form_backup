<?php

require_once("../../global/library.php");

use FormTools\Modules;

$module = Modules::initModulePage("admin");
$L = $module->getLangStrings();

$success = true;
$message = "";
if (isset($_POST["update"])) {
    list($success, $message) = $module->updateSettings($_POST, $L);
}

$page_vars = array(
    "g_success" => $success,
    "g_message" => $message,
    "head_title" => $L["module_name"],
    "module_settings" => $module->getSettings()
);

$module->displayPage("templates/settings.tpl", $page_vars);
