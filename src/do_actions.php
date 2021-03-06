#!/usr/bin/php -q
<?php
/*
   $Id: do_actions.php,v 1.0 2013/04/19 13:40:32 axel Exp $
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
   Original Author of file: Axel Roger
   Purpose of file: Do planed actions on files/directories.
   ----------------------------------------------------------------------
 */
/**
 * This script check the MySQL DB for actions to do, and do them one by one.
 *
 * @copyright AlternC-Team 2002-2013 http://alternc.org/
 */


////////////////////////////////// 
/*
Fixme

 - handle error correctly
 - check all those cases


*/
///////////////////////////////////

// Put this var to 1 if you want to enable debug prints
$debug=1;
$error_raise='';

// Debug function that print infos
function d($mess){
  global $debug;
  if ($debug == 1)
    echo "$mess\n";
}

// Function to mail the panel's administrator if something failed
function mail_it(){
  global $error_raise,$L_FQDN;
  mail("alterncpanel@$L_FQDN",'Script do_actions.php issues',"\n Errors reporting mail:\n\n$error_raise");
}

/*
 * $command: the command
 * $parameters: of the command (they are going to be protected)
 * return array('output'=>'output of exec', 'return_var'=>'returned integer of exec') 
 */
function execute_cmd($command, $parameters=array()) {
  $cmd_line = "$command ";
  if (!empty($parameters)) {
    if (is_array($parameters)) {
      foreach($parameters as $pp) {
        $cmd_line.= " ".escapeshellarg($pp)." ";
      }
    } else {
      $cmd_line.= " ".escapeshellarg($parameters)." " ;
    }
  }
  $cmd_line.= " 2>&1";
  exec($cmd_line, $output, $code);
  return array('executed' => $cmd_line, 'output'=>$output, 'return_var'=>$code);
}

require_once("/usr/share/alternc/panel/class/config_nochk.php");

$admin->stop_if_jobs_locked();

$LOCK_FILE='/var/run/alternc/do_actions_cron.lock';
$SCRIPT='/usr/bin/php do_actions.php';
$MY_PID=getmypid();
$FIXPERM='/usr/lib/alternc/fixperms.sh';

// Check if script isn't already running
if (file_exists($LOCK_FILE) !== false){
    d("Lock file already exists. ");
    // Check if file is in process list
    $PID=file_get_contents($LOCK_FILE);
    d("My PID is $MY_PID, PID in the lock file is $PID");
    if ($PID == exec("pidof $SCRIPT | tr ' ' '\n' | grep -v $MY_PID")){
      // Previous cron is not finished yet, just exit
      d("Previous cron is already running, we just exit and let it finish :-)");
      exit(0);
    }else{
      // Previous cron failed!
      $error_raise.="Lock file already exists. No process with PID $PID found! Previous cron failed...\n";

      // No need to remove anything, we're going to recreate it
      //d("Removing lock file and trying to process the failed action...");
      // Delete the lock and continue to the next action
      //unlink($LOCK_FILE);

      // Lock with the current script's PID
      if (file_put_contents($LOCK_FILE,$MY_PID) === false){
        $error_raise.="Cannot open/write $LOCK_FILE\n";
        mail_it();
        exit(1);
      }

      // Get the action(s) that was processing when previous script failed
      // (Normally, there will be at most 1 job pending... but who know?)
      while($cc=$action->get_job()){
        $c=$cc[0];
        $params=unserialize($c["parameters"]);
        // We can resume these types of action, so we reset the job to process it later
        d("Previous job was the n°".$c["id"]." : '".$c["type"]."'");
        if($c["type"] == "CREATE_FILE" && is_dir(dirname($params["file"])) || $c["type"] == "CREATE_DIR" || $c["type"] == "DELETE" || $c["type"] == "FIX_DIR" || $c["type"] == "FIX_FILE"){
          d("Reset of the job! So it will be resumed...");
          $action->reset_job($c["id"]);
        }else{
          // We can't resume the others types, notify the fail and finish this action
          $error_raise.="Can't resume the job n°".$c["id"]." action '".$c["type"]."', finishing it with a fail status.\n";
          if(!$action->finish($c["id"],"Fail: Previous script crashed while processing this action, cannot resume it.")){
            $error_raise.="Cannot finish the action! Error while inserting the error value in the DB for action n°".$c["id"]." : action '".$c["type"]."'\n";
            break; // Else we go into an infinite loop... AAAAHHHHHH
          }
        }
      }
    }
}else{
  // Lock with the current script's PID
  if (file_put_contents($LOCK_FILE,$MY_PID) === false){
    $error_raise.="Cannot open/write $LOCK_FILE\n";
    mail_it();
    exit(1);
  }
}

//We get the next action to do
while ($rr=$action->get_action()){
  $r=$rr[0];
  $return="OK";
  // Do we have to do this action with a specific user?
  if($r["user"] != "root")
    $SU="su ".$r["user"]." 2>&1 ;";
  else
    $SU="";
  unset($output);
  // We lock the action
  d("-----------\nBeginning action n°".$r["id"]);
  $action->begin($r["id"]);
  // We process it
  $params=@unserialize($r["parameters"]);
  // We exec with the specified user
  d("Executing action '".$r["type"]."' with user '".$r["user"]."'");
  switch ($r["type"]){
    case "FIX_USER" :
      // Create the directory and make parent directories as needed
      #@exec("$FIXPERM -u ".$params["uid"]." 2>&1", $trash, $code);
      $returned = execute_cmd("$FIXPERM -u", $params["uid"]);
      break;
    case "CREATE_FILE" :
      if(!file_exists($params["file"])) {
        #@exec("$SU touch ".$params["file"]." 2>&1 ; echo '".$params["content"]."' > '".$params["file"]."' 2>&1", $output);
        if ( file_put_contents($params["file"], $params["content"]) === false ) {
          $log_error=array("Fail: can't write into file ".$params["file"]);
        } else {
          if (!chown($params["file"], $r["user"])) {
            $log_error=array("Fail: cannot chown ".$params["file"]);
          }
        }
      } else {
        $log_error=array("Fail: file already exists ".$params["file"]);
      }
      break;
    case "CREATE_DIR" :
      // Create the directory and make parent directories as needed
      #@exec("$SU mkdir -p ".$params["dir"]." 2>&1",$output);
      $returned = execute_cmd("$SU mkdir", array('-p', $params["dir"]));
      break;
    case "DELETE" :
      // Delete file/directory and its contents recursively
      #@exec("$SU rm -rf ".$params["dir"]." 2>&1", $output);
      $returned = execute_cmd("$SU rm", array('-rf', $params["dir"]));
      break;
    case "MOVE" :
      // If destination dir does not exists, create it
      if(!is_dir($params["dst"]))
        if ( @mkdir($params["dst"], 0777, true)) {
          if ( @chown($params["dst"], $r["user"]) ) {
            $returned = execute_cmd("$SU mv -f", array($params["src"], $params["dst"])); 
          }
        } else { //is_dir false
          $log_error=array("Fail: cannot create ".$params["dst"]);
        } // is_dir
        
      break;
    case "FIX_DIR" :
      $returned = execute_cmd($FIXPERM, array('-d', $params["dir"]));
      if($returned['return_val'] != 0) $log_error=array("Fixperms.sh failed, returned error code : ".$returned['return_val']);
      break;
    case "FIX_FILE" :
      #@exec("$FIXPERM -f ".$params["file"]." 2>&1", $trash, $code);
      $returned = execute_cmd($FIXPERM, array('-f', $params["file"]));
      if($returned['return_val'] != 0) $log_error=array("Fixperms.sh failed, returned error code : ".$returned['return_val']);
      break;
    default :
      $output=array("Fail: Sorry dude, i do not know this type of action");
      break;
  }
  // Get the error (if exists).
  if(isset($output[0])){
    $return=$output[0];
    $error_raise.="\nAction n°".$r["id"]." '".$r["type"]."' failed! With user: ".$r["user"]."\nHere is the complete output:\n".print_r($output);
  }
  // We finished the action, notify the DB.
  d("Finishing... return value is : $return\n");
  if(!$action->finish($r["id"],addslashes($return))){
    $error_raise.="Cannot finish the action! Error while inserting the error value in the DB for action n°".$r["id"]." : action '".$c["type"]."'\nReturn value: ".addslashes($return)."\n";
    break; // Else we go into an infinite loop... AAAAHHHHHH
  }
}

// If something have failed, notify it to the admin
if($error_raise !== '') {
  mail_it(); 
}

// Unlock the script
unlink($LOCK_FILE);

// Exit this script
exit(0);
?>
