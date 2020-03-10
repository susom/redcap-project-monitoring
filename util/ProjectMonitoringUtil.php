<?php
namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

use REDCap;

/**
 * This function can be called from a cron job or in project context.  If this function
 * is called outside of the module context, give it access to the system settings.
 *
 *  @param $sys - if not in module context, send the handle to access system settings
 *  @return array
 *          - $pid - REDCap Monitoring Project pid (on Prod 15954)
 *          - $event_id - This REDCap project should only have one event, but set it anyways
 */

function getMonitoringProjectID($sys = null) {

    global $module;

    if (empty($sys)) {
        $pid = $module->getSystemSetting('monitor-project-id');
        $event_id = $module->getSystemSetting('monitor-project-event-id');
    } else {
        $pid = $sys->getSystemSetting('monitor-project-id');
        $event_id = $sys->getSystemSetting('monitor-project-event-id');
    }

    return array($pid, $event_id);

}

/**
 * NOTE: This must be called in Project Context since REDCap functions need to know what project it is in.
 * This function retrieves the list of users who have User Rights privileges for this project.
 *
 * @return array
 */
function getUsersWithUserRights() {

    $userRightsUsers = array();
    $allUsers = REDCap::getUsers();
    foreach($allUsers as $cnt => $user) {
        $rights = REDCap::getUserRights($user);
        if ($rights[$user]["user_rights"] == 1) {
            $userRightsUsers[] = $user;
        }
    }

    return $userRightsUsers;

}

/**
 * Given an array of sunetids, this function will return the user name and contact info
 * for each sunetid.
 *
 * @param $sunetids - array of sunetIds
 * @return array - user information associated with the sunetIds
 */
function retrieveUserInformation($sunetids) {

    $contact = array();

    // Retrieve the rest of the data for this contact
    $sql = "select user_email, user_phone, user_firstname, user_lastname, username " .
        "    from redcap_user_information " .
        "    where username in ('" . implode("','",$sunetids) . "')";
    $q = db_query($sql);
    while ($current_db_row = db_fetch_assoc($q)) {
        $sunetid = $current_db_row['username'];
        $contact[$sunetid]['contact_sunetid']   = $sunetid;
        $contact[$sunetid]["contact_firstname"] = $current_db_row["user_firstname"];
        $contact[$sunetid]["contact_lastname"]  = $current_db_row["user_lastname"];
        $contact[$sunetid]["contact_email"]     = $current_db_row["user_email"];
        $contact[$sunetid]["contact_phone"]     = $current_db_row["user_phone"];
    }

    return $contact;
}

/**
 * This function will retrieve a list of projects that this person is the Designated Contact for.  This list
 * is used to place on icon next to the project name on the 'My Projects' page.
 *
 * @param $sys
 * @param $sunetid
 * @return array
 */

function contactProjectList($sys, $sunetid) {

    list($pmon_pid, $pmon_event_id) = getMonitoringProjectID($sys);

    $filter = '[contact_sunetid] = "' . $sunetid . '"';
    $data = REDCap::getData($pmon_pid, 'array', null, array('project_id'), $pmon_event_id, null, null, null, null, $filter);
    $records = array();
    foreach ($data as $record_id => $record_info) {
        $records[$record_id] = $record_id;
    }

    return $records;
}

/**
 * This function is used to retrieve the number of archived projects this user has. The number will be used to
 * make a badge on the new 'Show Archived Projects' button on the 'My Projects' page.
 *
 * @param $sunetid
 * @return $num_archived - number of archived projects associated with this user
 */

function findNumArchivedProjects($sunetid)
{
    // Find the number of archived projects this person has
    $sql = "select count(*) as num_archived
                from redcap_projects rp, redcap_user_rights ur
                where rp.status = 3
                and rp.project_id = ur.project_id
                and ur.username = '" . $sunetid . "'
                and ur.expiration is null";
    $q = db_query($sql);
    while ($current_db_row = db_fetch_assoc($q)) {
        $num_archived = $current_db_row['num_archived'];
    }

    return $num_archived;
}

