<?php
/*
 $Id: piwik_userlist.php, author: François Serman <fser>
 ----------------------------------------------------------------------
 AlternC - Web Hosting System
 Copyright (C) 2002 by the AlternC Development Team.
 http://alternc.org/
 ----------------------------------------------------------------------
 Based on:
 Valentin Lacambre's web hosting softwares: http://altern.org/
 ----------------------------------------------------------------------
 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html
 ----------------------------------------------------------------------
 Purpose of file: listing of piwik site, and manage associated credentials
 ----------------------------------------------------------------------
*/

require_once("../class/config.php");
include_once("head.php");
include_once("piwik_utils.php");

$fields = array (
        "site_id"             => array ("request", "integer", -1),  // alternc ID of the piwik site
	"right"               => array ("post",    "array", FALSE), // array of rights associated foreach user of $site_id
);
getFields($fields);

/* Get once alternc users and sites */
$piwik_alternc_users = $piwik->get_alternc_users();
$piwik_alternc_sites = $piwik->get_alternc_sites();

/* Form was submitted, need to deal with work to do. */
if ($right !== FALSE) {
	// Should this stay here, or in the API?
	if (!in_array($site_id, $piwik_alternc_sites))
		$error = _("You don't own this piwik site!");
	else {
		/* Foreach row of right, extract user, and selected credential */
		foreach ($right AS $user => $cred)
		{
			/* Ensures that the user is legitimate for that user */
			/* If not, we just break the loop, and set error message */
			if (!in_array($user, $piwik_alternc_users)) {
				$error = sprintf('%s "%s"', _('You dont own user'), $user);
				break;
			}

			/* Ok, current user has right to manage this piwik user. Update rights. */
			printf ("%s -> %s<br />\n", $user, $cred);
			if (!$piwik->site_set_user_right($site_id, $user, $cred)) {
				$error = $err->errstr();
				break;
			}
		}
	}
}

/* If something went wrong, display error message, but continue with the page rendering */
if (isset($error) && $error) {
  	echo "<p class=\"alert alert-danger\">$error</p>";
}

/* Does current user still has quota ? */
if ($quota->cancreate("piwik")) {
  $quotapiwik=$quota->getquota('piwik');

  /* If quota are still available, display form to let user add a new site */
  if ($quotapiwik['u']>0) {
  
?>
<h3><?php __("Add a new website");?></h3>
<form method="post" action="piwik_addsites.php" id="main" name="addsites" >
	<input type="text" class="int" name="site_urls" size="50" id="site_name" maxlength="255" value="" placeholder="<?php __("URL of the website")?>"/>
	<input type="submit" name="submit" class="inb" value="<?php __("Create"); ?>" />
</form>

<br/>
<hr/>
<?php
  } // quotapiwik > 0
} // cancreate piwik



/* In that part, we'll manage the rights associated to a selected piwik site. */
/* The output is the following: */ 
/*   [ site [v]] */
/*     - user1  no access ()   view ()     admin () */
/*     - user2  no access ()   view ()     admin () */
/*   [ submit ] */

?>

<h3><?php __("Existing Piwik monitored websites"); ?></h3>
<?php 

/* Get the list of piwik sites for current user */
$sitelist = $piwik->site_list();

/* If user didn't add a website, just do nothing but display there's no site */
if (empty($sitelist)){
	__("No existing Piwik websites");
} else {
/* Otherwize, display the html form, [ sitename, url, javascript code ] */
?>

<table class="tlist">
    <tr><th/><th><?php __("Site name");?></th><th align=center><?php __("Site url"); ?></th><th>Javascript Code</th></tr>
<?php

$col=1;
foreach ($sitelist as $site ){
	$col=3-$col;
	?>
	<tr class="lst_clic<?php echo $col; ?>">
	   <td><div class="ina"><a href="/piwik_site_dodel.php?siteid=<?php echo $site->id; ?>"><img src="images/delete.png" alt="<?php __("Delete"); ?>" /><?php __("Delete"); ?></a></div></td>
  	   <td align=right><?php echo $site->name ?></td>
           <td><?php echo $site->main_url ?></td>
	   <td><textarea><?php echo $piwik->site_js_tag($site->id); ?></textarea></td>
	</tr>
	<?php
} // foreach sitelist



/* We'll now manage credentials for piwik sites */
/* We first create a select item to choose the piwik site to administrate */
/* Then we display a list of users, and associated rights. */
/* To achieve this, we select all piwik users available for current alternc user */
/* If a piwik user has no rights on that site, its rights are set to "noaccess" */
?>
</table>


<h3><?php __("Credentials management"); ?></h3>

<form method="get">
<select name="site_id">

<?php
	foreach ($sitelist as $site)
		printf ('<option value="%d"%s>%s</option>', $site->id, ($site->id == $site_id) ? ' selected ' : '', $site->name);
?>

</select>&nbsp;
<input type="submit" class="inb" value="ok" />	
</form>

<?php
	// If a site was selected
	if ($site_id != -1 && in_array($site_id, $piwik_alternc_sites)) {
		echo '<form method="post">';
		echo '<dl>';
		foreach ($piwik->get_users_access_from_site($site_id) AS $piwik_user => $cred) {
			printf("<dt>%s:</dt>\n\t<dd>%s</dd>\n", $piwik_user, piwik_right_widget('right', $piwik_user, $cred));
		}
		echo '</dl>';
		echo '<input type="submit" name="valid" class="inb" value="' , _("submit"), '" />';
		echo '</form>';
	}
} // empty userlist
?>

<?php include_once("foot.php"); ?>
