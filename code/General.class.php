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
        try {
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

            // this copies the table + indexes
            $db->query("CREATE TABLE {PREFIX}form_{$new_form_id} LIKE {PREFIX}form_{$form_id}");
            $db->execute();

            // create the actual form with or without the submission info
            if ($copy_submissions) {
                $db->query("INSERT {PREFIX}form_{$new_form_id} SELECT * FROM {PREFIX}form_{$form_id}");
                $db->execute();
            }

            if ($history_table_exists) {
                $db->query("CREATE TABLE {PREFIX}form_{$new_form_id}_history LIKE {PREFIX}form_{$form_id}_history");
                $db->execute();

                if ($copy_submissions) {
                    $db->query("INSERT {PREFIX}form_{$new_form_id}_history SELECT * FROM {PREFIX}form_{$form_id}_history");
                    $db->execute();
                }
            }

        } catch (Exception $e) {
            self::rollbackForm($new_form_id);
            return array(false, "There was a problem creating the new table. Please report this error in Form Tools forums: " . $e->getMessage(), "");
        }

        return array(true, $new_form_id, $field_map);
    }


    /**
     * Copies a list of Views and returns a hash of old View ID => new View ID.
     */
    public static function duplicateViews($form_id, $new_form_id, $view_ids, $field_map, $settings)
    {
        $db = Core::$db;

        if (empty($view_ids)) {
            return array();
        }

        // View groups for the form
        $db->query("SELECT * FROM {PREFIX}list_groups WHERE group_type = :group_type");
        $db->bind("group_type", "form_{$form_id}_view_group");
        $db->execute();
        $groups = $db->fetchAll();

        $new_group_type = "form_{$new_form_id}_view_group";
        $view_group_id_map = array();
        foreach ($groups as $row) {
            $old_group_id = $row["group_id"];

            try {
                $db->query("
                    INSERT INTO {PREFIX}list_groups (group_type, group_name, custom_data, list_order)
                    VALUES (:group_type, :group_name, :custom_data, :list_order)
                ");
                $db->bindAll(array(
                    "group_type" => $new_group_type,
                    "group_name" => $row["group_name"],
                    "custom_data" => $row["custom_data"],
                    "list_order" => $row["list_order"]
                ));
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View group information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }

            $view_group_id_map[$old_group_id] = $db->getInsertId();
        }

        $view_ids_str = join(",", $view_ids);
        $db->query("SELECT * FROM {PREFIX}views WHERE view_id IN ($view_ids_str)");
        $db->execute();
        $views = $db->fetchAll();

        // views table
        $view_map = array();
        foreach ($views as $row) {
            $view_id = $row["view_id"];
            $row["form_id"] = $new_form_id;
            $old_group_id = $row["group_id"];
            $row["group_id"] = $view_group_id_map[$old_group_id];
            unset($row["view_id"]);

            if ($settings["form_permissions"] == "admin") {
                $row["access_type"] = "admin";
            }

            list ($cols_str, $placeholders) = self::getInsertStatementParams($row);
            try {
                $db->query("
                    INSERT INTO {PREFIX}views ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($row);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating a View. Please report this error in the Form Tools forums: " . $e->getMessage());
            }

            $new_view_id = $db->getInsertId();
            $view_map[$view_id] = $new_view_id;

            $view_field_group_id_map = self::copyViewFieldGroups($view_id, $new_view_id);

            self::copyViewFields($view_id, $view_map, $view_field_group_id_map, $field_map);
            self::copyViewColumns($view_id, $view_map, $field_map);
            self::copyViewFilters($view_id, $view_map, $field_map);
            self::copyViewTabs($view_id, $view_map);
            self::copyNewViewSubmissionDefaults($view_id, $new_view_id, $field_map);

            // if the user is also copying over the form permissions, duplicate the relevant entries in client_views
            // and public_view_omit_list
            if ($settings["form_permissions"] == "same_permissions") {

                $db->query("SELECT account_id FROM {PREFIX}client_views WHERE view_id = :view_id");
                $db->bind("view_id", $view_id);
                $db->execute();
                $account_ids = $db->fetchAll(PDO::FETCH_COLUMN);

                foreach ($account_ids as $account_id) {
                    $db->query("INSERT INTO {PREFIX}client_views (account_id, view_id) VALUES (:account_id, :view_id)");
                    $db->bindAll(array(
                        "account_id" => $account_id,
                        "view_id" => $new_view_id
                    ));
                    $db->execute();
                }

                // public_view_omit_list
                $db->query("SELECT account_id FROM {PREFIX}public_view_omit_list WHERE view_id = :view_id");
                $db->bind("view_id", $view_id);
                $db->execute();
                $account_ids = $db->fetchAll(PDO::FETCH_COLUMN);

                foreach ($account_ids as $account_id) {
                    $db->query("INSERT INTO {PREFIX}public_view_omit_list (account_id, view_id) VALUES (:account_id, :view_id)");
                    $db->bindAll(array(
                        "account_id" => $account_id,
                        "view_id" => $new_view_id
                    ));
                    $db->execute();
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
        $db = Core::$db;

        if (empty($email_ids)) {
            return array();
        }

        $email_id_str = join(", ", $email_ids);
        $db->query("SELECT * FROM {PREFIX}email_templates WHERE email_id IN ($email_id_str)");
        $db->execute();
        $rows = $db->fetchAll();

        $email_id_map = array();
        foreach ($rows as $row) {
            $curr_email_id = $row["email_id"];

            $row["form_id"] = $new_form_id;
            $row["limit_email_content_to_fields_in_view"] = isset($view_map[$row["limit_email_content_to_fields_in_view"]]) ? $view_map[$row["limit_email_content_to_fields_in_view"]] : "";
            unset($row["email_id"]);

            list($cols_str, $placeholders) = self::getInsertStatementParams($row);

            try {
                $db->query("
                    INSERT INTO {PREFIX}email_templates ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($row);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the email template information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }

            $new_email_id = $db->getInsertId();
            $email_id_map[$curr_email_id] = $new_email_id;

            $db->query("SELECT * FROM {PREFIX}email_template_recipients WHERE email_template_id = :email_template_id");
            $db->bind("email_template_id", $curr_email_id);
            $db->execute();
            $rows = $db->fetchAll();

            foreach ($rows as $row2) {
                $row2["email_template_id"] = $email_id_map[$curr_email_id];
                unset($row2["recipient_id"]);

                list($cols_str, $placeholders) = self::getInsertStatementParams($row2);
                try {
                    $db->query("
                        INSERT INTO {PREFIX}email_template_recipients ($cols_str)
                        VALUES ($placeholders)
                    ");
                    $db->bindAll($row2);
                    $db->execute();
                } catch (Exception $e) {
                    return array(false, "Sorry, there was a problem duplicating the email template recipient information. Please report this error in the Form Tools forums: " . $e->getMessage());
                }
            }

            // if a View Map has been supplied, duplicate any entries in the email_template_edit_submission_views and
            // email_template_when_sent_views tables
            if (!empty($view_map)) {
                foreach ($view_map as $original_view_id => $new_view_id) {
                    $db->query("
                        SELECT count(*)
                        FROM   {PREFIX}email_template_edit_submission_views
                        WHERE  email_id = :email_id AND
                               view_id = :view_id
                    ");
                    $db->bindAll(array(
                        "email_id" => $curr_email_id,
                        "view_id" => $original_view_id
                    ));
                    $db->execute();

                    if ($db->numRows() === 1) {
                        $db->query("
                            INSERT INTO {PREFIX}email_template_edit_submission_views (email_id, view_id)
                            VALUES (:email_id, :view_id)
                        ");
                        $db->bindAll(array(
                            "email_id" => $new_email_id,
                            "view_id" => $new_view_id
                        ));
                        $db->execute();
                    }

                    $db->query("
                        SELECT count(*)
                        FROM   {PREFIX}email_template_when_sent_views
                        WHERE  email_id = :email_id AND
                               view_id = :view_id
                    ");
                    $db->bindAll(array(
                        "email_id" => $curr_email_id,
                        "view_id" => $original_view_id
                    ));
                    $db->execute();

                    if ($db->numRows() === 1) {
                        $db->query("
                            INSERT INTO {PREFIX}email_template_when_sent_views (email_id, view_id)
                            VALUES (:email_id, :view_id)
                        ");
                        $db->bindAll(array(
                            "email_id" => $new_email_id,
                            "view_id" => $new_view_id
                        ));
                        $db->execute();
                    }
                }
            }
        }
    }


    /**
     * @param $hash array of columns => values
     * @return array
     */
    public static function getInsertStatementParams($hash)
    {
        $col_names = array();
        $placeholders = array();
        foreach ($hash as $col_name => $value) {
            $col_names[] = $col_name;
            $placeholders[] = ":{$col_name}";
        }

        $cols_str = join(", ", $col_names);
        $placeholders = join(", ", $placeholders);

        return array($cols_str, $placeholders);
    }


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
        $form_data["is_active"] = ($form_disabled == "yes") ? "no" : "yes";

        // if the user wanted to set the permissions as admin only, do it!
        if ($form_permissions == "admin") {
            $form_data["access_type"] = "admin";
        }

        list($cols_str, $placeholders) = self::getInsertStatementParams($form_data);

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


    private static function copyViewFieldGroups($view_id, $new_view_id)
    {
        $db = Core::$db;
        $db->query("SELECT * FROM {PREFIX}list_groups WHERE group_type = :group_type");
        $db->bind("group_type", "view_fields_$view_id");
        $db->execute();
        $groups = $db->fetchAll();

        $view_field_group_id_map = array();
        foreach ($groups as $row) {
            $old_group_id = $row["group_id"];

            try {
                $db->query("
                    INSERT INTO {PREFIX}list_groups (group_type, group_name, custom_data, list_order)
                    VALUES (:group_type, :group_name, :custom_data, :list_order)
                ");
                $db->bindAll(array(
                    "group_type" => "view_fields_{$new_view_id}",
                    "group_name" => $row["group_name"],
                    "custom_data" => $row["custom_data"],
                    "list_order" => $row["list_order"]
                ));
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View field group information. Please report this error in the Form Tools forums: " . mysql_error());
            }

            $view_field_group_id_map[$old_group_id] = $db->getInsertId();
        }

        return $view_field_group_id_map;
    }


    private static function copyViewFields($view_id, $view_map, $view_field_group_id_map, $field_map)
    {
        $db = Core::$db;
        $db->query("SELECT * FROM {PREFIX}view_fields WHERE view_id = :view_id");
        $db->bind("view_id", $view_id);
        $db->execute();
        $view_fields = $db->fetchAll();

        foreach ($view_fields as $row) {
            $old_group_id = $row["group_id"];
            $old_field_id = $row["field_id"];

            // This should never happen. But just in case some data got orphaned let's be safe
            if (!array_key_exists($old_field_id, $field_map)) {
                continue;
            }

            // overwrite the unique values in the old data row for the new View
            $row["view_id"] = $view_map[$view_id];
            $row["group_id"] = $view_field_group_id_map[$old_group_id];
            $row["field_id"] = $field_map[$old_field_id];

            list($cols_str, $placeholders) = self::getInsertStatementParams($row);

            try {
                $db->query("
                    INSERT INTO {PREFIX}view_fields ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($row);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View fields information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }
        }
    }


    private static function copyViewColumns($view_id, $view_map, $field_map)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}view_columns WHERE view_id = :view_id");
        $db->bind("view_id", $view_id);
        $db->execute();
        $columns = $db->fetchAll();

        foreach ($columns as $column) {
            $column["view_id"]  = $view_map[$view_id];
            $column["field_id"] = $field_map[$column["field_id"]];

            list($cols_str, $placeholders) = self::getInsertStatementParams($column);

            try {
                $db->query("
                    INSERT INTO {PREFIX}view_columns ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($column);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View fields information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }
        }
    }


    private static function copyViewFilters($view_id, $view_map, $field_map)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}view_filters WHERE view_id = :view_id");
        $db->bind("view_id", $view_id);
        $db->execute();
        $filters = $db->fetchAll();

        foreach ($filters as $filter) {
            $filter["view_id"] = $view_map[$view_id];
            $filter["field_id"] = $field_map[$filter["field_id"]];

            unset($filter["filter_id"]);
            list($cols_str, $placeholders) = self::getInsertStatementParams($filter);

            try {
                $db->query("
                    INSERT INTO {PREFIX}view_filters ($cols_str)
                    VALUES ($placeholders)
                ");
                $db->bindAll($filter);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View filter information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }
        }
    }


    private static function copyViewTabs($view_id, $view_map)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}view_tabs WHERE view_id = :view_id");
        $db->bind("view_id", $view_id);
        $db->execute();
        $tabs = $db->fetchAll();

        foreach ($tabs as $tab) {
            $tab["view_id"] = $view_map[$view_id];
            list($cols_str, $vals_str) = self::getInsertStatementParams($tab);

            try {
                $db->query("
                    INSERT INTO {PREFIX}view_tabs ($cols_str)
                    VALUES ($vals_str)
                ");
                $db->bindAll($tab);
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the View tab information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }
        }
    }


    private static function copyNewViewSubmissionDefaults($view_id, $new_view_id, $field_map)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}new_view_submission_defaults WHERE view_id = :view_id");
        $db->bind("view_id", $view_id);
        $db->execute();
        $rows = $db->fetchAll();

        foreach ($rows as $row) {
            $new_field_id = $field_map[$row["field_id"]];

            try {
                $db->query("
                    INSERT INTO {PREFIX}new_view_submission_defaults (view_id, field_id, default_value, list_order)
                    VALUES (:view_id, :field_id, :default_value, :list_order)
                ");
                $db->bindAll(array(
                    "view_id" => $new_view_id,
                    "field_id" => $new_field_id,
                    "default_value" => $row["default_value"],
                    "list_order" => $row["list_order"]
                ));
                $db->execute();
            } catch (Exception $e) {
                return array(false, "Sorry, there was a problem duplicating the new View submission default information. Please report this error in the Form Tools forums: " . $e->getMessage());
            }
        }
    }
}
