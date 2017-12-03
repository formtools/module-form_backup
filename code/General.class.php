<?php

namespace FormTools\Modules\FormBackup;

use FormTools\Core;
use PDO, Exception;


class General
{

    /**
     * This duplicates a form, their fields.
     */
    public static function duplicateForm($form_id, $settings)
    {
        $db = Core::$db;

        $new_form_id = self::addFormTableRecord($form_id, $settings);

        $copy_submissions = $settings["copy_submissions"];
        $form_permissions = $settings["form_permissions"];

        // everything in this function from now on is rolled back pending a problem
        $field_map = self::addFormFieldsTableRecords($form_id, $new_form_id);
        if (!empty($field_map)) {
            self::addFormFieldValidationAndSettings($new_form_id, $field_map);
        }
        self::addFormEmailFields($form_id, $new_form_id, $field_map);

        // multi_page_form_urls
        $db->query("SELECT * FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
        $fields = $db->fetchAll();

        foreach ($fields as $row) {
            $db->query("
                INSERT INTO {PREFIX}multi_page_form_urls (form_id, form_url, page_num)
                VALUES (:form_id, :form_url, :page_num)
            ");
            $db->bindAll(array(
                "form_id" => $new_form_id,
                "form_url" => $row["form_url"],
                "page_num" => $row["page_num"]
            ));
            $db->execute();
        }

        // client_forms
        if ($form_permissions == "same_permissions") {
            $db->query("SELECT account_id FROM {PREFIX}client_forms WHERE form_id = :form_id");
            $db->bind("form_id", $form_id);
            $db->execute();
            $fields = $db->fetchAll();

            foreach ($fields as $row) {
                $db->query("
                    INSERT INTO {PREFIX}client_forms (account_id, form_id)
                    VALUES (:account_id, :form_id)
                ");
                $db->bindAll(array(
                    "account_id" => $row["account_id"],
                    "form_id" => $new_form_id
                ));
                $db->execute();
            }

            // ft_public_form_omit_list
            $db->query("SELECT account_id FROM {PREFIX}public_form_omit_list WHERE form_id = :form_id");
            $db->bind("form_id", $form_id);
            $db->execute();
            $fields = $db->fetchAll();

            foreach ($fields as $row) {
                $db->query("
                    INSERT INTO {PREFIX}public_form_omit_list (account_id, form_id)
                    VALUES (:account_id, :form_id)
                ");
                $db->bindAll(array(
                    "account_id" => $row["account_id"],
                    "form_id" => $new_form_id
                ));
                $db->execute();
            }
        }

        // if a history table exists, created by the Submission History module, make a copy of that too
        $db->query("SHOW TABLES");
        $db->execute();

        $history_table_exists = false;
        foreach ($db->fetchAll(PDO::FETCH_COLUMN) as $table_name) {
            if ($table_name == "{PREFIX}form_{$form_id}_history") {
                $history_table_exists = true;
                break;
            }
        }

        // create the actual form with or without the submission info
        if ($copy_submissions) {
            $result = mysql_query("CREATE TABLE {PREFIX}form_{$new_form_id} SELECT * FROM {PREFIX}form_{$form_id}");
            if ($history_table_exists) {
                $result2 = mysql_query("CREATE TABLE {PREFIX}form_{$new_form_id}_history SELECT * FROM {PREFIX}form_{$form_id}_history");
            }
        } else {
            $result = mysql_query("CREATE TABLE {PREFIX}form_{$new_form_id} LIKE {PREFIX}form_{$form_id}");
            if ($history_table_exists) {
                $result2 = mysql_query("CREATE TABLE {PREFIX}form_{$new_form_id}_history LIKE {PREFIX}form_{$form_id}_history");
            }
        }

        if (!$result) {
            $error = mysql_error();
            self::rollbackForm($new_form_id);
            return array(false, "There was a problem creating the new table. Please report this error in Form Tools forums: " . $error, "");
        } else {
            // now add the auto-increment, primary key
            @mysql_query("ALTER TABLE {PREFIX}form_{$new_form_id} ADD PRIMARY KEY (submission_id)");
            @mysql_query("ALTER TABLE {PREFIX}form_{$new_form_id} CHANGE submission_id submission_id MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT");
            @mysql_query("ALTER TABLE {PREFIX}form_{$new_form_id} DEFAULT CHARSET=utf8");
        }

        return array(true, $new_form_id, $field_map);
    }


    /**
     * Copies a list of Views and returns a hash of old View ID => new View ID.
     */
    public static function duplicateViews($form_id, $new_form_id, $view_ids, $field_map, $settings)
    {
        global $g_table_prefix;

        if (empty($view_ids))
            return array();

        // View groups for the form
        $query = mysql_query("SELECT * FROM {PREFIX}list_groups WHERE group_type = 'form_{$form_id}_view_group'");
        $new_group_type = "form_{$new_form_id}_view_group";
        $view_group_id_map = array();
        while ($row = mysql_fetch_assoc($query)) {
            $group_name  = ft_sanitize($row["group_name"]);
            $custom_data = ft_sanitize($row["custom_data"]);
            $list_order = $row["list_order"];
            $old_group_id = $row["group_id"];

            $result = mysql_query("
                INSERT INTO {PREFIX}list_groups (group_type, group_name, custom_data, list_order)
                VALUES ('$new_group_type', '$group_name', '$custom_data', $list_order)
            ");

            if (!$result) {
                return array(false, "Sorry, there was a problem duplicating the View group information. Please report this error in the Form Tools forums: " . mysql_error());
            } else {
                $view_group_id_map[$old_group_id] = mysql_insert_id();
            }
        }

        $view_ids_str = join(",", $view_ids);
        $query = mysql_query("SELECT * FROM {PREFIX}views WHERE view_id IN ($view_ids_str)");

        // views table
        $view_map = array();
        while ($row = mysql_fetch_assoc($query)) {
            $view_id = $row["view_id"];
            $row["form_id"] = $new_form_id;
            $old_group_id = $row["group_id"];
            $row["group_id"] = $view_group_id_map[$old_group_id];
            unset($row["view_id"]);

            if ($settings["form_permissions"] == "admin") {
                $row["access_type"] = "admin";
            }

            list($cols_str, $vals_str) = fb_hash_split($row);

            $result = mysql_query("
                INSERT INTO {PREFIX}views ($cols_str)
                VALUES ($vals_str)
            ");

            if (!$result) {
                return array(false, "Sorry, there was a problem duplicating a View. Please report this error in the Form Tools forums: " . mysql_error());
            }

            $new_view_id = mysql_insert_id();
            $view_map[$view_id] = $new_view_id;


            // view field groups
            $view_field_group_id_map = array();
            $view_field_group_query = mysql_query("SELECT * FROM {PREFIX}list_groups WHERE group_type = 'view_fields_$view_id'");
            $new_group_type = "view_fields_{$new_view_id}";
            while ($row2 = mysql_fetch_assoc($view_field_group_query)) {
                $group_name   = ft_sanitize($row2["group_name"]);
                $custom_data  = ft_sanitize($row2["custom_data"]);
                $list_order   = $row2["list_order"];
                $old_group_id = $row2["group_id"];

                $result = mysql_query("
                    INSERT INTO {PREFIX}list_groups (group_type, group_name, custom_data, list_order)
                    VALUES ('$new_group_type', '$group_name', '$custom_data', $list_order)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the View field group information. Please report this error in the Form Tools forums: " . mysql_error());
                } else {
                    $new_group_id = mysql_insert_id();
                    $view_field_group_id_map[$old_group_id] = $new_group_id;
                }
            }

            // view_fields
            $view_fields_query = mysql_query("SELECT * FROM {PREFIX}view_fields WHERE view_id = $view_id");
            while ($row2 = mysql_fetch_assoc($view_fields_query)) {
                $row2["view_id"]  = $view_map[$view_id];
                $old_group_id = $row2["group_id"];
                $row2["group_id"] = $view_field_group_id_map[$old_group_id];

                $old_field_id = $row2["field_id"];

                // not strictly necessary, since it should never happen. But just in case some data got orphaned
                if (!array_key_exists($old_field_id, $field_map)) {
                    continue;
                }

                $row2["field_id"] = $field_map[$old_field_id];
                list($cols_str, $vals_str) = fb_hash_split($row2);

                $result = mysql_query("
                    INSERT INTO {PREFIX}view_fields ($cols_str)
                    VALUES ($vals_str)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the View fields information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }


            // view columns
            $view_cols_query = mysql_query("SELECT * FROM {PREFIX}view_columns WHERE view_id = $view_id");
            while ($row2 = mysql_fetch_assoc($view_cols_query)) {
                $row2["view_id"]  = $view_map[$view_id];
                $row2["field_id"] = $field_map[$row2["field_id"]];
                list($cols_str, $vals_str) = fb_hash_split($row2);

                $result = mysql_query("
                    INSERT INTO {PREFIX}view_columns ($cols_str)
                    VALUES ($vals_str)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the View fields information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }


            // view_filters
            $view_filters_query = mysql_query("SELECT * FROM {PREFIX}view_filters WHERE view_id = $view_id");
            while ($row2 = mysql_fetch_assoc($view_filters_query)) {
                $row2["view_id"] = $view_map[$view_id];
                $row2["field_id"] = $field_map[$row2["field_id"]];

                unset($row2["filter_id"]);
                list($cols_str, $vals_str) = fb_hash_split($row2);

                $result = mysql_query("
                    INSERT INTO {PREFIX}view_filters ($cols_str)
                    VALUES ($vals_str)
                ") or die(mysql_error());

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the View filter information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }

            // view_tabs
            $view_tabs_query = mysql_query("SELECT * FROM {PREFIX}view_tabs WHERE view_id = $view_id");
            while ($row2 = mysql_fetch_assoc($view_tabs_query)) {
                $row2["view_id"] = $view_map[$view_id];
                list($cols_str, $vals_str) = fb_hash_split($row2);

                $result = mysql_query("
                    INSERT INTO {PREFIX}view_tabs ($cols_str)
                    VALUES ($vals_str)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the View tab information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }

            // new_view_submission_defaults
            $new_view_defaults_query = mysql_query("SELECT * FROM {PREFIX}new_view_submission_defaults WHERE view_id = $view_id");
            while ($row2 = mysql_fetch_assoc($new_view_defaults_query)) {
                $new_field_id = $field_map[$row2["field_id"]];
                $default_value = ft_sanitize($row2["default_value"]);
                $list_order = $row2["list_order"];

                $result = mysql_query("
                    INSERT INTO {PREFIX}new_view_submission_defaults (view_id, field_id, default_value, list_order)
                    VALUES ($new_view_id, $new_field_id, '$default_value', $list_order)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the new View submission default information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }

            // if the user is also copying over the form permissions, duplicate the relevant entries in client_views and public_view_omit_list
            if ($settings["form_permissions"] == "same_permissions") {
                $result = mysql_query("SELECT account_id FROM {PREFIX}client_views WHERE view_id = $view_id");
                while ($row2 = mysql_fetch_array($result)) {
                    $account_id = $row2["account_id"];
                    mysql_query("INSERT INTO {PREFIX}client_views (account_id, view_id) VALUES ($account_id, $new_view_id)");
                }

                // public_view_omit_list
                $result = mysql_query("SELECT account_id FROM {PREFIX}public_view_omit_list WHERE view_id = $view_id");
                while ($row = mysql_fetch_array($result)) {
                    $account_id = $row2["account_id"];
                    mysql_query("INSERT INTO {PREFIX}public_view_omit_list (account_id, view_id) VALUES ($account_id, $new_view_id)");
                }
            }
        }

        return $view_map;
    }


    /**
     * Copies a list of email templates and returns a hash of old email template ID => new email template ID.
     */
    public static function duplicateEmailTemplates($new_form_id, $email_ids, $view_map)
    {
        if (empty($email_ids)) {
            return array();
        }

        $email_id_str = join(", ", $email_ids);
        $query = mysql_query("SELECT * FROM {PREFIX}email_templates WHERE email_id IN ($email_id_str)");

        $email_id_map = array();
        while ($row = mysql_fetch_assoc($query)) {
            $row["form_id"] = $new_form_id;
            $curr_email_id = $row["email_id"];
            $row["limit_email_content_to_fields_in_view"] = isset($view_map[$row["limit_email_content_to_fields_in_view"]]) ? $view_map[$row["limit_email_content_to_fields_in_view"]] : "";

            unset($row["email_id"]);
            list($cols_str, $vals_str) = fb_hash_split($row);

            $result = mysql_query("
                INSERT INTO {PREFIX}email_templates ($cols_str)
                VALUES ($vals_str)
            ");

            if (!$result) {
                return array(false, "Sorry, there was a problem duplicating the email template information. Please report this error in the Form Tools forums: " . mysql_error());
            }

            $new_email_id = mysql_insert_id();
            $email_id_map[$curr_email_id] = $new_email_id;

            $query2 = mysql_query("SELECT * FROM {PREFIX}email_template_recipients WHERE email_template_id = $curr_email_id");
            while ($row2 = mysql_fetch_assoc($query2)) {
                $row2["email_template_id"] = $email_id_map[$curr_email_id];
                unset($row2["recipient_id"]);

                list($cols_str, $vals_str) = fb_hash_split($row2);
                $result = mysql_query("
                    INSERT INTO {PREFIX}email_template_recipients ($cols_str)
                    VALUES ($vals_str)
                ");

                if (!$result) {
                    return array(false, "Sorry, there was a problem duplicating the email template recipient information. Please report this error in the Form Tools forums: " . mysql_error());
                }
            }

            // if a View Map has been supplied, duplicate any entries in the email_template_edit_submission_views and
            // email_template_when_sent_views tables
            if (!empty($view_map)) {
                while (list($original_view_id, $new_view_id) = each($view_map)) {
                    $query2 = mysql_query("
                        SELECT count(*) as c
                        FROM   {PREFIX}email_template_edit_submission_views
                        WHERE  email_id = $curr_email_id AND
                               view_id = $original_view_id
                    ");
                    if (mysql_num_rows($query2) == 1) {
                        @mysql_query("
                            INSERT INTO {PREFIX}email_template_edit_submission_views (email_id, view_id)
                            VALUES ($new_email_id, $new_view_id)
                        ");
                    }
                    $query3 = mysql_query("
                        SELECT count(*) as c
                        FROM   {PREFIX}email_template_when_sent_views
                        WHERE  email_id = $curr_email_id AND
                               view_id = $original_view_id
                    ");
                    if (mysql_num_rows($query3) == 1) {
                        @mysql_query("
                            INSERT INTO {PREFIX}email_template_when_sent_views (email_id, view_id)
                            VALUES ($new_email_id, $new_view_id)
                        ");
                    }
                }
            }
        }
    }


//    public static function fb_hash_split($row)
//    {
//        $cols   = array();
//        $values = array();
//        while (list($key, $value) = each($row))
//        {
//            $cols[]   = $key;
//            $values[] = "'" . ft_sanitize($value) . "'";
//        }
//
//        $cols_str = join(", ", $cols);
//        $vals_str = join(", ", $values);
//
//        return array($cols_str, $vals_str);
//    }


    public static function rollbackForm($form_id)
    {
        $db = Core::$db;

        $db->query("DELETE FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}form_email_fields WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}form_fields WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
    }


    private static function addFormTableRecord($form_id, $settings)
    {
        $db = Core::$db;

        $new_form_name    = $settings["form_name"];
        $form_disabled    = $settings["form_disabled"];
        $form_permissions = $settings["form_permissions"];

        // add a new record to the forms table
        $db->query("
            SELECT *
            FROM {PREFIX}forms
            WHERE form_id = :form_id
        ");
        $db->bind("form_id", $form_id);
        $db->execute();

        $form_data = $db->fetch();

        unset($form_data["form_id"]);
        $form_data["form_name"] = $new_form_name;
        $form_data["is_active"] = ($form_disabled == "yes") ? "no" : "yes"; // invert!

        // if the user wanted to set the permissions as admin only, do it!
        if ($form_permissions == "admin") {
            $form_data["access_type"] = "admin";
        }

        $col_names = array();
        $placeholders = array();
        foreach ($form_data as $col_name => $value) {
            $col_names[] = $col_name;
            $placeholders[] = ":{$col_name}";
        }

        $cols_str = join(", ", $col_names);
        $placeholders = join(", ", $placeholders);

        try {
            $db->query("
                INSERT INTO {PREFIX}forms ($cols_str)
                VALUES ($placeholders)
            ");
            $db->bindAll($form_data);
            $db->execute();
        } catch (Exception $e) {
            return array(false, "Sorry, there was a problem creating the new record in the forms table. Please report this error in the Form Tools forums: " . mysql_error(), "");
        }

        return $db->getInsertId();
    }


    /**
     * @param $old_form_id
     * @param $new_form_id
     * @return array hash of old_field_id => new_field_id
     */
    private static function addFormFieldsTableRecords($old_form_id, $new_form_id)
    {
        $db = Core::$db;

        $db->query("
            SELECT *
            FROM {PREFIX}form_fields
            WHERE form_id = :form_id
        ");
        $db->bind("form_id", $old_form_id);
        $db->execute();

        $field_map = array();
        $fields = $db->fetchAll();
        foreach ($fields as $field_info) {
            $field_info["form_id"] = $new_form_id;
            $field_id = $field_info["field_id"];
            unset($field_info["field_id"]);

            $col_names = array();
            $placeholders = array();
            foreach ($field_info as $col_name => $value) {
                $col_names[] = $col_name;
                $placeholders[] = ":{$col_name}";
            }
            $cols_str = join(", ", $col_names);
            $placeholders = join(", ", $placeholders);

            try {
                $db->query("
                    INSERT INTO {PREFIX}form_fields ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($field_info);
                $db->execute();

                $field_map[$field_id] = $db->getInsertId();
            } catch (Exception $e) {
                $error = $e->getMessage();
                self::rollbackForm($new_form_id);
                return array(false, "There was a problem inserting the new form's field info into the form_fields table. Please report this error in Form Tools forums: " . $error, "");
            }
        }

        return $field_map;
    }


    private static function addFormFieldValidationAndSettings($new_form_id, $field_map)
    {
        $db = Core::$db;

        $original_field_ids = array_keys($field_map);
        $field_ids = join(",", $original_field_ids);

        // copy over any field validation rules
        $db->query("
            SELECT *
            FROM {PREFIX}field_validation
            WHERE field_id IN ($field_ids)
        ");
        $db->execute();
        $fields = $db->fetchAll();

        foreach ($fields as $field_info) {
            $row["field_id"] = $field_map[$field_info["field_id"]];

            $col_names = array();
            $placeholders = array();
            foreach ($field_info as $col_name => $value) {
                $col_names[] = $col_name;
                $placeholders[] = ":{$col_name}";
            }
            $cols_str = join(", ", $col_names);
            $placeholders = join(", ", $placeholders);

            try {
                $db->query("
                    INSERT INTO {PREFIX}field_validation ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($field_info);
                $db->execute();
            } catch (Exception $e) {
                $error = $e->getMessage();
                self::rollbackForm($new_form_id);
                return array(false, "There was a problem inserting the new form's validation rules into the field_validation table. Please report this error in Form Tools forums: " . $error, "");
            }
        }

        // now copy any field_settings
        $db->query("
            SELECT *
            FROM {PREFIX}field_settings
            WHERE field_id IN ($field_ids)
        ");
        $db->execute();
        $fields = $db->fetchAll();

        foreach ($fields as $field_info) {
            $field_info["field_id"] = $field_map[$field_info["field_id"]];

            $col_names = array();
            $placeholders = array();
            foreach ($field_info as $col_name => $value) {
                $col_names[] = $col_name;
                $placeholders[] = ":{$col_name}";
            }
            $cols_str = join(", ", $col_names);
            $placeholders = join(", ", $placeholders);

            try {
                $db->query("
                    INSERT INTO {PREFIX}field_settings ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($field_info);
                $db->execute();
            } catch (Exception $e) {
                $error = $e->getMessage();
                self::rollbackForm($new_form_id);
                return array(false, "There was a problem inserting the new form's field info into the field_settings table. Please report this error in Form Tools forums: " . $error, "");
            }
        }
    }

    private static function addFormEmailFields($old_form_id, $new_form_id, $field_map)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}form_email_fields WHERE form_id = :form_id");
        $db->bind("form_id", $old_form_id);
        $db->execute();
        $rows = $db->fetchAll();

        foreach ($rows as $row) {
            try {
                $db->query("
                    INSERT INTO {PREFIX}form_email_fields (form_id, email_field_id, first_name_field_id, last_name_field_id)
                    VALUES (:form_id, :email_field_id, :first_name_field_id, :last_name_field_id)
                ");
                $db->bindAll(array(
                    "form_id" => $new_form_id,
                    "email_field_id" => $field_map[$row["email_field_id"]],
                    "first_name_field_id" => !empty($row["first_name_field_id"]) ? $field_map[$row["first_name_field_id"]] : "",
                    "last_name_field_id" => !empty($row["last_name_field_id"]) ? $field_map[$row["last_name_field_id"]] : ""
                ));
                $db->execute();
            } catch (Exception $e) {
                $error = $e->getMessage();
                self::rollbackForm($new_form_id);
                return array(false, "There was a problem inserting the new form's email field info into the form_email_fields table. Please report this error in Form Tools forums: " . $error, "");
            }
        }
    }
}
