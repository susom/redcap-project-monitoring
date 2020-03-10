<?php
namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

$module_path = $module->getModulePath();
require_once($module_path . "util/ProjectMonitoringUtil.php");
use REDCap;

/*
 * This page is called when users are on the User Rights page (or Project Setup when a designated contact
 * is not yet selected) and a user selects the Change Designated Contact link. Users will be presented with
 * a list of project users who have User Rights privileges.  When a new contact is saved, the new and old
 * Designated Contact will be sent email to let them know the contact has been changed.
 *
 * The Designated Contact will be changed in the Project Monitoring project and a log entry will be created.
 */


$user = USERID;
$des_contact = array();

$from_email = "noreply@stanford.edu";
$oldSubject = "You have been removed as REDCap Designated Contact";
$body = "The Designated Contact for your REDCap project has changed. Please see below for details:";
$newSubject = "You have been added as REDCap Designated Contact";

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$contact = isset($_POST['selected_contact']) && !empty($_POST['selected_contact']) ? $_POST['selected_contact'] : null;

// Retrieve the user information from the sunetid
$users = retrieveUserInformation(array($contact));
foreach($users as $sunetid => $userInfo) {
    $des_contact[$pid]["project_id"] = $pid;
    $des_contact[$pid]['contact_sunetid']   = $sunetid;
    $des_contact[$pid]["contact_firstname"] = $userInfo["contact_firstname"];
    $des_contact[$pid]["contact_lastname"]  = $userInfo["contact_lastname"];
    $des_contact[$pid]["contact_email"]     = $userInfo["contact_email"];
    $des_contact[$pid]["contact_phone"]     = $userInfo["contact_phone"];
    $des_contact[$pid]['contact_timestamp'] = date("Y-m-d H:i:s");
    $des_contact[$pid]['designated_contact_complete'] = '2';
    $des_contact[$pid]['contact_notified___1'] = '1';
}

if (!empty($des_contact)) {

    // Retrieve the project monitoring project from the system parameters
    list($pmon_pid, $pmon_event_id) = getMonitoringProjectID();

    // New contact
    $new_email = $des_contact[$pid]["contact_email"];
    $new_name = $des_contact[$pid]["contact_firstname"] . ' ' . $des_contact[$pid]["contact_lastname"];

    // Old contact
    $fields = array('contact_sunetid', 'contact_email', 'contact_firstname', 'contact_lastname');
    $old_contact = REDCap::getData($pmon_pid, 'array', $pid, $fields, $pmon_event_id);
    $old_sunetid = $old_contact[$pid][$pmon_event_id]['contact_sunetid'];
    $old_email = $old_contact[$pid][$pmon_event_id]['contact_email'];
    $old_name = $old_contact[$pid][$pmon_event_id]['contact_firstname'] . ' ' . $old_contact[$pid][$pmon_event_id]['contact_lastname'];

    // Save the new contact
    if (empty($status['errors'])) {
        $status = REDCap::saveData($pmon_pid, 'json', json_encode($des_contact));
        REDCap::logEvent('New Contact', "Designated Contact changed to $contact by $user", null, null, null, $pid);

        // Person who made the change
        $change = retrieveUserInformation(array($user));
        $changer = $change[$user]["contact_firstname"] . ' ' . $change[$user]["contact_lastname"];

        $emailBody = $body;
        $emailBody .= "<br>Project ID:                 " . $pid;
        $emailBody .= "<br>Person who made the change: " . $changer;
        if (!empty($old_sunetid)) {
            $emailBody .= "<br>Designated Contact Removed: " . $old_name;
        }
        $emailBody .= "<br>Designated Contact Added:   " . $new_name;

        // Check to see if we need to send email to the one being removed
        if (!empty($old_sunetid)) {
            if ($old_sunetid !== $user) {

                // Send email to the current contact
                if (!empty($old_email)) {
                    $module->emDebug("TO: $old_email, SUBJECT: $oldSubject, BODY: $emailBody");
                    REDCap::email($old_email, $from_email, $oldSubject, $emailBody);
                } else {
                    $module->emError("Cannot send email to $old_sunetid that $user has removed them from Designated Contact for project $pid");
                }
            }
        }

        // Check to see if we need to send email to the one being added
        if ($contact !== $user) {
            // Send email to the new contact
            if (!empty($new_email)) {
                $module->emDebug("TO: $new_email, SUBJECT: $newSubject, BODY: $emailBody");
                $status = REDCap::email($new_email, $from_email, $newSubject, $emailBody);
                if (!$status) {
                    $module->emError("Email did not get sent to $new_email for project $pid");
                } else {
                    $module->emLog("Successfully sent email to $new_email for project $pid");
                }
            } else {
                $module->emError("Cannot send email to $new_email that $user has removed them from Designated Contact for project $pid");
            }
        }

        print 1;
    } else {
        print 0;
        $module->emError("Cannot update Designated Contact $contact for project $pid by $user", $status);
    }
} else {
    $module->emError("Could not find new Designated Contact $contact in DB for project $pid by $user");
    print 0;
}

return;