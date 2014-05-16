{include file='modules_header.tpl'}

  <table cellpadding="0" cellspacing="0" class="margin_bottom_large">
  <tr>
    <td width="45"><a href="index.php"><img src="images/form_backup.gif" border="0" width="34" height="34" /></a></td>
    <td class="title">{$L.module_name|upper}</td>
  </tr>
  </table>

  <div id="form_copier_nav">
    <div><a href="index.php">{$L.phrase_select_form}</a></div>
    <div>{$LANG.word_settings}</div>
    <div>{$LANG.word_complete}</div>
  </div>

  {include file='messages.tpl'}

  <p>
    <input type="button" onclick="window.location='index.php'" value="{$L.phrase_backup_another_form}" />
  </p>

{include file='modules_footer.tpl'}