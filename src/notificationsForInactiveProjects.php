<?php
namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

require_once("RepeatingForms.php");

use REDCap;

/*
 * When projects in Development or Production do not have any log entries for more than a year,
 * we are moving them to an inactive state: Development -> Archive and Production -> Inactive.
 * We will eventually setup notifications so when users of those projects login and navigate to
 * those projects, they will receive a notification that their project was moved to an inactive
 * state which they must acknowledge. To track who should receive the notifications and who has
 * acknowledged them, this repeating form is populated.
 *
 * We will only be called if the project status is moved to Inactive or Archived. This is an API
 * call since we need to be in project context in order to use REDCap::getUsers(). The logic is:
 *
 *      Close out any exisiting inactive notification forms by setting the [inactive_notification_old]
 *            Disregard flag to checked
 *      Create an instance in the Inactive User Notification form for each user of the project
 *
 */


$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$original_status = isset($_GET['status']) && !empty($_GET['status']) ? $_GET['status'] : null;

$current_timestamp = date("Y-m-d H:i:s");

// Retrieve the pid of the monitoring project
list($pmon_pid, $pmon_event_id) = getMonitoringProjectID();

// If this project was previously inactive, make sure the Inactive records are closed out before creating new ones
$rf = new RepeatingForms($pmon_pid, 'inactive_user_notifications');
$rf->loadData($pid, $pmon_event_id, null);
$data = $rf->getAllInstances($pid, $pmon_event_id);

if (!empty($data)) {
    foreach($data[$pid] as $instance_id => $instance_info) {
        if (($instance_info['inactive_status_user_notified'][1] == '0') and
            ($instance_info['inactive_notification_old'][1] == '0')) {

            // Set the inactive_notification_old flag to 1
            $save_flag = array('inactive_notification_old___1' => '1');
            $saved = $rf->saveInstance($pid, $save_flag, $instance_id, $pmon_event_id);
            if ($saved) {
                $module->emDebug('Set inactive_notification_old status to checked for user ' .
                    $instance_info['inactive_status_user'] . ', record ' . $pid . '/ instance ' . $instance_id);
            } else {
                $module->emError('Could not set inactive_notification_old to checked for user ' .
                    $instance_info['inactive_status_user'] . ', record ' . $pid . '/ instance ' . $instance_id);
            }
        }
    }
}

// Retrieve all users for this project
$users = REDCap::getUsers();

// Add a form instance in the project for each user that needs to be notified
$notifications_set = true;
foreach ($users as $ncnt => $user) {
    $notify_user["inactive_status_user"]        = $user;
    $notify_user['inactive_status_timestamp']   = $current_timestamp;
    $notify_user["inactive_previous_status"]    = $original_status;
    $notify_user["inactive_user_notifications_complete"] = 2;
    $next_instance = $rf->getNextInstanceId($pid);
    $saved = $rf->saveInstance($pid, $notify_user, $next_instance, $pmon_event_id);
    if ($saved) {
        $module->emDebug('Set new notification for user ' . $user . ' because project ' . $pid .
            ' went to Inactive/Archived status from ' . $original_status);
    } else {
        $module->emError('Could not set new notification for user ' . $user . ' because project ' . $pid .
            ' went to Inactive/Archived status from ' . $original_status);
        $notifications_set = false;
    }
}

// If all notifications were successfully set, send back true
if ($notifications_set) {
    print 1;
} else {
    print 0;
}

return;