<?php

require_once("../../global/library.php");

use FormTools\Emails;
use FormTools\Forms;
use FormTools\Modules;
use FormTools\Views;

$module = Modules::initModulePage("admin");
$L = $module->getLangStrings();

if (!isset($request["form_id"])) {
    header("location: index.php");
    exit;
}

$form_id = $_POST["form_id"];
$form_info = Forms::getForm($form_id);
$views = Views::getViews($form_id);
$emails = Emails::getEmailTemplates($form_id);

$page_vars = array(
    "form_id" => $form_id,
    "form_info" => $form_info,
    "views" => $views["results"],
    "emails" => $emails["results"],
    "head_title" => $L["module_name"]
);

$module->displayPage("templates/step2.tpl", $page_vars);
