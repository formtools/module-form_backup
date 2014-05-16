{include file='modules_header.tpl'}

  <table cellpadding="0" cellspacing="0" class="margin_bottom_large">
  <tr>
    <td width="45"><a href="index.php"><img src="images/form_backup.gif" border="0" width="34" height="34" /></a></td>
    <td class="title">{$L.module_name|upper}</td>
  </tr>
  </table>

  <div id="form_copier_nav">
	<div>{$L.phrase_select_form}</div>
	<div class="unselected">{$LANG.word_settings}</div>
	<div class="unselected">{$LANG.word_complete}</div>
  </div>

  <form action="step2.php" method="post">
	
	<table cellspacing="0" cellpadding="0" class="form_backup_table">
	<tr>
	  <td width="140">{$L.phrase_select_form}</td>
	  <td>{forms_dropdown name_id="form_id"}</td>
	</tr>
	</table>
	
    <p>
      <input type="submit" value="{$L.word_continue}" />
    </p>
  </form>

{include file='modules_footer.tpl'}