<?php

require_once("../../global/library.php");
ft_init_module_page();

$folder = dirname(__FILE__);
require_once("$folder/library.php");

if (isset($_POST["form_id"]))
{
  $form_id = $_POST["form_id"];

  // create the new form
  $copy_submissions = ($_POST["copy_submissions"] == "yes") ? true : false;
  $form_disabled    = ($_POST["form_disabled"] == "yes") ? true : false;
  $settings = array(
    "form_name"        => $_POST["form_name"],
    "copy_submissions" => $copy_submissions,
    "form_disabled"    => $form_disabled,
    "form_permissions" => $_POST["form_permissions"]
  );
  list ($g_success, $g_message) = fb_duplicate_form($form_id, $settings);

  if ($g_success)
  {
    $new_form_id = $g_message;

    // if there are any Views specified, copy those over
    $view_map  = fb_duplicate_views($form_id, $new_form_id, $_POST["view_ids"], $settings);

    // duplicate any emails
    fb_duplicate_email_templates($new_form_id, $_POST["email_ids"], $view_map);

    $g_message = "The form has been created. <a href=\"../../admin/forms/edit.php?form_id=$new_form_id\">Click here</a> to edit the form.";
  }
}


$page_vars = array();
$page_vars["head_title"] = $L["module_name"];
$page_vars["head_string"] = "<link type=\"text/css\" rel=\"stylesheet\" href=\"$g_root_url/modules/form_backup/global/style.css\">";

ft_display_module_page("templates/complete.tpl", $page_vars);