<?php

$HOOKS = array();
$HOOKS["1.1.4"] = array(
  array(
    "hook_type"       => "template",
    "action_location" => "admin_forms_list_bottom",
    "function_name"   => "",
    "hook_function"   => "fb_display_create_form_backup_button",
    "priority"        => "50"
  )
);