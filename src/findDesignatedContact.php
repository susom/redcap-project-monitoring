<?php
namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

use REDCap;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;

/*
 * This file will be called when a new REDCap project is detected from the nightly Cron job and an initial
 * Designated Contact is needed.  The person who created the project will be used as long as they have
 * User Rights privileges.  If they don't have User Rights privileges, we will check for the last log entry
 * from a person who does have User Rights privileges.
 *
 * Since this person maybe have created several new projects, we only want to notify them once so another process
 * will go through and check for Designated Contacts for new projects and send one email listing all their new
 * projects.
 *
 */

$found_designated_contact = array();

// Retrieve all users with User Rights
$users = getUsersWithUserRights();

//Retrieve info of the person who created the project. If they have UserRighs,
$sql = "select ui.username, ui.user_email, ui.user_phone, ui.user_firstname, ui.user_lastname
            from redcap_projects rp, redcap_user_information ui
            where rp.project_id = " . $pid . "
            and rp.created_by = ui.ui_id";
$q = db_query($sql);
while ($current_db_row = db_fetch_assoc($q)) {
    if (in_array($current_db_row['username'], $users)) {
        $found_designated_contact["project_id"] = $pid;
        $found_designated_contact['contact_sunetid']   = $current_db_row['username'];
        $found_designated_contact["contact_firstname"] = $current_db_row["user_firstname"];
        $found_designated_contact["contact_lastname"]  = $current_db_row["user_lastname"];
        $found_designated_contact["contact_email"]     = $current_db_row["user_email"];
        $found_designated_contact["contact_phone"]     = $current_db_row["user_phone"];
        $found_designated_contact['contact_timestamp'] = date("Y-m-d H:i:s");
        $found_designated_contact['designated_contact_complete'] = '2';
    }
}

/*
 * Find the user who has the last log entry who has User Rights privileges? We will designate
 * them as the Contact.
 */

if (empty($found_designated_contact)) {
    $sql = "select username, user_email, user_phone, user_firstname, user_lastname " .
        "     from redcap_user_information ui, redcap_log_event le " .
        "     where le.user in ('" . implode("','", $users) . "')" .
        "     and le.project_id = " . $pid .
        "     and le.user = ui.username" .
        "     order by ts desc " .
        "     limit 1";
    $q = db_query($sql);
    while ($current_db_row = db_fetch_assoc($q)) {
        $found_designated_contact["project_id"] = $pid;
        $found_designated_contact['contact_sunetid']   = $current_db_row['username'];
        $found_designated_contact["contact_firstname"] = $current_db_row["user_firstname"];
        $found_designated_contact["contact_lastname"]  = $current_db_row["user_lastname"];
        $found_designated_contact["contact_email"]     = $current_db_row["user_email"];
        $found_designated_contact["contact_phone"]     = $current_db_row["user_phone"];
        $found_designated_contact['contact_timestamp'] = date("Y-m-d H:i:s");
        $found_designated_contact['designated_contact_complete'] = '2';
    }
}

if (!empty($found_designated_contact)) {
    REDCap::logEvent("New Contact", "Designated contact initially set to " . $found_designated_contact['contact_sunetid'],
        null, null, null, $pid);
}

print json_encode($found_designated_contact);
return;