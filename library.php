<?php

/**
 * This file defines all functions relating to the Form Backup module.
 *
 * @copyright Encore Web Studios 2010
 * @author Encore Web Studios <formtools@encorewebstudios.com>
 * @package 2-0-0
 * @subpackage FormBackup
 */


/**
 * This duplicates a form, their fields
 */
function fb_duplicate_form($form_id, $settings)
{
  global $g_table_prefix;

  $new_form_name    = $settings["form_name"];
  $copy_submissions = $settings["copy_submissions"];
  $form_disabled    = $settings["form_disabled"];
  $form_permissions = $settings["form_permissions"];

  // add a new record to the forms table
  $query = mysql_query("SELECT * FROM {$g_table_prefix}forms WHERE form_id = $form_id");
  $form_col_data = mysql_fetch_assoc($query);

  unset($form_col_data["form_id"]);
  $form_col_data["form_name"] = ft_sanitize($new_form_name);
  $form_col_data["is_active"] = ($form_disabled == "yes") ? "no" : "yes"; // invert!

  // if the user wanted to set the permissions as admin only, do it!
  if ($form_permissions == "admin")
  {
    $form_col_data["access_type"] = "admin";
  }

  // create the new form row
  $cols   = array();
  $values = array();
  while (list($key, $value) = each($form_col_data))
  {
    $cols[]   = $key;
    $values[] = "'" . ft_sanitize($value) . "'";
  }
  $cols_str = join(", ", $cols);
  $vals_str = join(", ", $values);
  $query = @mysql_query("
    INSERT INTO {$g_table_prefix}forms ($cols_str)
    VALUES ($vals_str)
      ");

  if (!$query)
  {
    return array(false, "Sorry, there was a problem creating the new record in the forms table. Please report this error in the Form Tools forums: " . mysql_error(), "");
  }

  $new_form_id = mysql_insert_id();

  // everything in this function from now on is rolled back pending a problem

  // copy over the field data
  $field_map = array(); // a hash of old_field_id => new_field_id
  $query = mysql_query("SELECT * FROM {$g_table_prefix}form_fields WHERE form_id = $form_id");
  while ($row = mysql_fetch_assoc($query))
  {
    $row["form_id"] = $new_form_id;
    $field_id = $row["field_id"];
    unset($row["field_id"]);

    $cols   = array();
    $values = array();
    while (list($key, $value) = each($row))
    {
      $cols[]   = $key;
      $values[] = "'" . ft_sanitize($value) . "'";
    }
    $cols_str = join(", ", $cols);
    $vals_str = join(", ", $values);
    $insert_query = @mysql_query("
      INSERT INTO {$g_table_prefix}form_fields ($cols_str)
      VALUES ($vals_str)
        ");

    if (!$insert_query)
    {
      $error = mysql_error();
      fb_rollback_form($new_form_id);
      return array(false, "There was a problem inserting the new form's field info into the form_fields table. Please report this error in Form Tools forums: " . $error, "");
    }
    else
    {
      $field_map[$field_id] = mysql_insert_id();
    }
  }

  // now update any field_settings
  if (!empty($field_map))
  {
    $original_field_ids = array_keys($field_map);
    $field_ids = join(",", $original_field_ids);
    $query = mysql_query("SELECT * FROM {$g_table_prefix}field_settings WHERE field_id IN ($field_ids)");

    // now duplicate all those rows
    while ($row = mysql_fetch_assoc($query))
    {
      $row["field_id"] = $field_map[$row["field_id"]];
      $field_id = $row["field_id"];

      list($cols_str, $vals_str) = fb_hash_split($row);

      $insert_query = @mysql_query("
        INSERT INTO {$g_table_prefix}field_settings ($cols_str)
        VALUES ($vals_str)
          ");

      if (!$insert_query)
      {
        $error = mysql_error();
        fb_rollback_form($new_form_id);
        return array(false, "There was a problem inserting the new form's field info into the field_settings table. Please report this error in Form Tools forums: " . $error, "");
      }
    }
  }

  // multi_page_form_urls
  $query = mysql_query("SELECT * FROM {$g_table_prefix}multi_page_form_urls WHERE form_id = $form_id");
  while ($row = mysql_fetch_array($query))
  {
    $form_url = ft_sanitize($row["form_url"]);
    $page_num = $row["page_num"];
    mysql_query("INSERT INTO {$g_table_prefix}multi_page_form_urls (form_id, form_url, page_num) VALUES ($new_form_id, '$form_url', '$page_num')");
  }

  // client_forms
  if ($form_permissions == "same_permissions")
  {
    $query = mysql_query("SELECT account_id FROM {$g_table_prefix}client_forms WHERE form_id = $form_id");
    while ($row = mysql_fetch_array($query))
    {
      $account_id = $row["account_id"];
      mysql_query("INSERT INTO {$g_table_prefix}client_forms (account_id, form_id) VALUES ($account_id, $new_form_id)");
    }

    // ft_public_form_omit_list
    $query = mysql_query("SELECT account_id FROM {$g_table_prefix}public_form_omit_list WHERE form_id = $form_id");
    while ($row = mysql_fetch_array($query))
    {
      $account_id = $row["account_id"];
      mysql_query("INSERT INTO {$g_table_prefix}public_form_omit_list (account_id, form_id) VALUES ($account_id, $new_form_id)");
    }
  }


  // create the actual form with or without the submission info
  if ($copy_submissions)
  {
    $result = mysql_query("CREATE TABLE {$g_table_prefix}form_{$new_form_id} SELECT * FROM {$g_table_prefix}form_{$form_id}");
  }
  else
  {
    $result = mysql_query("CREATE TABLE {$g_table_prefix}form_{$new_form_id} LIKE {$g_table_prefix}form_{$form_id}");
  }

  if (!$result)
  {
    $error = mysql_error();
    fb_rollback_form($new_form_id);
    return array(false, "There was a problem creating the new table. Please report this error in Form Tools forums: " . $error, "");
  }
  else
  {
    // now add the auto-increment, primary key
    @mysql_query("ALTER TABLE {$g_table_prefix}form_{$new_form_id} ADD PRIMARY KEY (submission_id)");
    @mysql_query("ALTER TABLE {$g_table_prefix}form_{$new_form_id} CHANGE submission_id submission_id MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT");
    @mysql_query("ALTER TABLE {$g_table_prefix}form_{$new_form_id} TYPE=MyISAM DEFAULT CHARSET=utf8");
  }

  return array(true, $new_form_id, $field_map);
}


/**
 * Copies a list of Views and returns a hash of old View ID => new View ID.
 */
function fb_duplicate_views($form_id, $new_form_id, $view_ids, $field_map, $settings)
{
  global $g_table_prefix;

  if (empty($view_ids))
    return array();

  $view_ids_str = join(",", $view_ids);
  $query = mysql_query("SELECT * FROM {$g_table_prefix}views WHERE view_id IN ($view_ids_str)");

  $view_map = array();

  // views table
  while ($row = mysql_fetch_assoc($query))
  {
    $view_id = $row["view_id"];
    $row["form_id"] = $new_form_id;
    unset($row["view_id"]);

    if ($settings["form_permissions"] == "admin")
    {
      $row["access_type"] = "admin";
    }

    list($cols_str, $vals_str) = fb_hash_split($row);

    $result = mysql_query("
      INSERT INTO {$g_table_prefix}views ($cols_str)
      VALUES ($vals_str)
        ");

    if (!$result)
    {
      return array(false, "Sorry, there was a problem duplicating a View. Please report this error in the Form Tools forums: " . mysql_error());
    }

    $new_view_id = mysql_insert_id();
    $view_map[$view_id] = $new_view_id;

    // view_fields
    $view_fields_query = mysql_query("SELECT * FROM {$g_table_prefix}view_fields WHERE view_id = $view_id");
    while ($row2 = mysql_fetch_assoc($view_fields_query))
    {
      $row2["view_id"]  = $view_map[$view_id];
      $row2["field_id"] = $field_map[$row2["field_id"]];
      list($cols_str, $vals_str) = fb_hash_split($row2);

      $result = mysql_query("
        INSERT INTO {$g_table_prefix}view_fields ($cols_str)
        VALUES ($vals_str)
          ");

      if (!$result)
      {
        return array(false, "Sorry, there was a problem duplicating the View fields information. Please report this error in the Form Tools forums: " . mysql_error());
      }
    }

    // view_filters
    $view_filters_query = mysql_query("SELECT * FROM {$g_table_prefix}view_filters WHERE view_id = $view_id");
    while ($row2 = mysql_fetch_assoc($view_filters_query))
    {
      $row2["view_id"] = $view_map[$view_id];
      unset($row2["filter_id"]);
      list($cols_str, $vals_str) = fb_hash_split($row2);

      $result = mysql_query("
        INSERT INTO {$g_table_prefix}view_filters ($cols_str)
        VALUES ($vals_str)
          ") or die(mysql_error());

      if (!$result)
      {
        return array(false, "Sorry, there was a problem duplicating the View filter information. Please report this error in the Form Tools forums: " . mysql_error());
      }
    }

    // view_tabs
    $view_tabs_query = mysql_query("SELECT * FROM {$g_table_prefix}view_tabs WHERE view_id = $view_id");
    while ($row2 = mysql_fetch_assoc($view_tabs_query))
    {
      $row2["view_id"] = $view_map[$view_id];
      list($cols_str, $vals_str) = fb_hash_split($row2);

      $result = mysql_query("
        INSERT INTO {$g_table_prefix}view_tabs ($cols_str)
        VALUES ($vals_str)
          ");

      if (!$result)
      {
        return array(false, "Sorry, there was a problem duplicating the View tab information. Please report this error in the Form Tools forums: " . mysql_error());
      }
    }

    // if the user is also copying over the form permissions, duplicate the relevant entries in client_views and public_view_omit_list
    if ($settings["form_permissions"] == "same_permissions")
    {
      $result = mysql_query("SELECT account_id FROM {$g_table_prefix}client_views WHERE view_id = $view_id");
      while ($row2 = mysql_fetch_array($result))
      {
        $account_id = $row2["account_id"];
        mysql_query("INSERT INTO {$g_table_prefix}client_views (account_id, view_id) VALUES ($account_id, $new_view_id)");
      }

      // public_view_omit_list
      $result = mysql_query("SELECT account_id FROM {$g_table_prefix}public_view_omit_list WHERE view_id = $view_id");
      while ($row = mysql_fetch_array($result))
      {
        $account_id = $row2["account_id"];
        mysql_query("INSERT INTO {$g_table_prefix}public_view_omit_list (account_id, view_id) VALUES ($account_id, $new_view_id)");
      }
    }
  }

  return $view_map;
}


/**
 * Copies a list of email templates and returns a hash of old email template ID => new email template ID.
 */
function fb_duplicate_email_templates($new_form_id, $email_ids, $view_map)
{
  global $g_table_prefix;

  if (empty($email_ids))
    return array();

  $email_id_str = join(", ", $email_ids);
  $query = mysql_query("SELECT * FROM {$g_table_prefix}email_templates WHERE email_id IN ($email_id_str)");

  $email_id_map = array();
  while ($row = mysql_fetch_assoc($query))
  {
    $row["form_id"] = $new_form_id;
    $curr_email_id = $row["email_id"];
    $row["view_mapping_view_id"] = $view_map[$row["view_mapping_view_id"]];
    $row["limit_email_content_to_fields_in_view"] = $view_map[$row["limit_email_content_to_fields_in_view"]];

    unset($row["email_id"]);
    list($cols_str, $vals_str) = fb_hash_split($row);

    $result = mysql_query("
      INSERT INTO {$g_table_prefix}email_templates ($cols_str)
      VALUES ($vals_str)
        ");

    if (!$result)
    {
      return array(false, "Sorry, there was a problem duplicating the email template information. Please report this error in the Form Tools forums: " . mysql_error());
    }

    $email_id_map[$curr_email_id] = mysql_insert_id();

    $query2 = mysql_query("SELECT * FROM {$g_table_prefix}email_template_recipients WHERE email_template_id = $curr_email_id");
    while ($row2 = mysql_fetch_assoc($query2))
    {
      $row2["email_template_id"] = $email_id_map[$curr_email_id];
      unset($row2["recipient_id"]);

      list($cols_str, $vals_str) = fb_hash_split($row2);
      $result = mysql_query("
        INSERT INTO {$g_table_prefix}email_template_recipients ($cols_str)
        VALUES ($vals_str)
          ");

      if (!$result)
      {
        return array(false, "Sorry, there was a problem duplicating the email template recipient information. Please report this error in the Form Tools forums: " . mysql_error());
      }
    }

    // if a View Map has been supplied, duplicate any entries in the email_template_edit_submission_views table
    if (!empty($view_map))
    {
      while (list($original_view_id, $new_view_id) = each($view_map))
      {
        $query2 = mysql_query("
          SELECT count(*) as c
          FROM   {$g_table_prefix}email_template_edit_submission_views
          WHERE  email_id = $curr_email_id AND
                 view_id = $original_view_id
            ");
        $result = mysql_fetch_assoc($query2);
        if ($result2["c"] == 1)
        {
          @mysql_query("
            INSERT INTO {$g_table_prefix}email_template_edit_submission_views (email_id, view_id)
            VALUES ($curr_email_id, $new_view_id)
              ");
        }
      }
    }
  }
}


function fb_hash_split($row)
{
  $cols   = array();
  $values = array();
  while (list($key, $value) = each($row))
  {
    $cols[]   = $key;
    $values[] = "'" . ft_sanitize($value) . "'";
  }

  $cols_str = join(", ", $cols);
  $vals_str = join(", ", $values);

  return array($cols_str, $vals_str);
}


function fb_rollback_form($form_id)
{
  global $g_table_prefix;

  @mysql_query("DELETE FROM {$g_table_prefix}forms WHERE form_id = $form_id");
  @mysql_query("DELETE FROM {$g_table_prefix}form_fields WHERE form_id = $form_id");
}
