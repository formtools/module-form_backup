<?php


namespace FormTools\Modules\FormBackup;

use FormTools\Core;
use FormTools\Hooks;
use FormTools\Module as FormToolsModule;
use FormTools\Settings;


class Module extends FormToolsModule
{
    protected $moduleName = "Form Backup";
    protected $moduleDesc = "This module lets you backup an entire form, including individual components like Views, email templates and submission data. It's also handy for making copies of forms if you want multiple, similar forms without having to add each separately.";
    protected $author = "Ben Keen";
    protected $authorEmail = "ben.keen@gmail.com";
    protected $authorLink = "https://formtools.org";
    protected $version = "2.0.2";
    protected $date = "2017-12-17";
    protected $originLanguage = "en_us";
    protected $cssFiles = array("css/style.css");

    protected $nav = array(
        "module_name"   => array("index.php", false),
        "word_settings" => array("settings.php", true),
        "word_help"     => array("help.php", true)
    );

    public function install($module_id)
    {
        Hooks::registerHook("template", "form_backup", "admin_forms_list_bottom", "", "displayCreateFormBackupButton", 50, true);
        Settings::set(array("show_backup_form_button" => "yes"), "form_backup");
        return array(true, "");
    }

    public function displayCreateFormBackupButton()
    {
        $root_url = Core::getRootUrl();
        $L = $this->getLangStrings();

        echo <<< END
<div style="border-top: 1px solid #cccccc; margin: 10px 0"></div>
<form action="$root_url/modules/form_backup/" method="post">
  <input type="submit" value="{$L["phrase_back_up_form"]}" />
</form>
END;
    }

    /**
     * Called on the settings page.
     *
     * @param array $info
     */
    public function updateSettings($info, $L)
    {
        $show_backup_form_button = isset($info["show_backup_form_button"]) ? $info["show_backup_form_button"] : "no";

        $this->setSettings(array(
            "show_backup_form_button" => $show_backup_form_button
        ));

        return array(true, $L["notify_settings_updated"]);
    }
}
