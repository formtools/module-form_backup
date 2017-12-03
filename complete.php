<?php

require_once("../../global/library.php");

use FormTools\Modules;
use FormTools\Modules\FormBackup\General;

$module = Modules::initModulePage("admin");
$L = $module->getLangStrings();

$success = true;
$message = "";
if (isset($_POST["form_id"])) {
    $form_id = $_POST["form_id"];

    // create the new form
    $copy_submissions = ($_POST["copy_submissions"] == "yes") ? true : false;
    $form_disabled = ($_POST["form_disabled"] == "yes") ? true : false;
    $settings = array(
        "form_name" => $_POST["form_name"],
        "copy_submissions" => $copy_submissions,
        "form_disabled" => $form_disabled,
        "form_permissions" => $_POST["form_permissions"]
    );
    list ($success, $message, $field_map) = General::duplicateForm($form_id, $settings);

    if ($success) {
        $new_form_id = $message;

        // if there are any Views specified, copy those over
        $view_ids = isset($_POST["view_ids"]) ? $_POST["view_ids"] : array();
        $view_map = General::duplicateViews($form_id, $new_form_id, $view_ids, $field_map, $settings);

        // duplicate any emails
        $email_ids = isset($_POST["email_ids"]) ? $_POST["email_ids"] : array();
        General::duplicateEmailTemplates($new_form_id, $email_ids, $view_map);

        $message = "The form has been created. <a href=\"../../admin/forms/edit/?form_id=$new_form_id\">Click here</a> to edit the form.";
    }
}

$page_vars = array(
    "g_success" => $success,
    "g_message" => $message,
    "head_title" => $L["module_name"]
);

$module->displayPage("templates/complete.tpl", $page_vars);
