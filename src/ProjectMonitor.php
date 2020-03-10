<?php
namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

use \REDCap;
use \ExternalModules;

/**
 * This class is a monitor for all projects together. Each time the cron is called there are some bulk updates
 * that will happen together.  If any of the following has changed, these fields will get updated and the last_update_date
 * will be updated:
 *   1) last_log_event
 *   2) number of log events in last x months
 *   3) any project information (pi, irb, project status, etc)
 *
 * In addition, once the projects have been updated, if any projects in Development does not have a log event in the last
 * year, it will be put into Archived mode.  If any projects in Production does not have a log event in the last year, it
 * will be put into Inactive mode.
 *
 * If new projects are found, a new record will be created with a call to the NewProject class
 *
 * Class ProjectMonitoring
 * @package Stanford\ProjectMonitoring
 */
class ProjectMonitor {

    private $pid;
    private $event_id;
    private $config;
    private $projFields;

    // project status options
    const DEVELOPMENT = 0;
    const PRODUCTION = 1;
    const INACTIVE = 2;
    const ARCHIVED = 3;
    const MARKED_TO_BE_DELETED = 98;
    const PERMANENTLY_DELETED = 99;
    private $status_labels;

    function __construct($pid, $event_id, $config) {

        $this->config = $config;
        $this->pid = $pid;
        $this->event_id = $event_id;
        $this->projFields = array("project_id","proj_create_date", "proj_title", "proj_purpose",
                                  "proj_irb", "proj_pi", "proj_status", "proj_last_log_entry");
        $this->status_labels = array(self::DEVELOPMENT          => 'Development',
                                     self::PRODUCTION           => 'Production',
                                     self::INACTIVE             => 'Inactive',
                                     self::ARCHIVED             => 'Archived',
                                     self::MARKED_TO_BE_DELETED => 'Marked to be Deleted',
                                     self::PERMANENTLY_DELETED  => 'Permanently Deleted');
   }

    /**
     * Retrieve the list of all projects in the redcap_projects table.
     *   1) If a project is new, save the project in the REDCap projects pid 15954.  Check to see
     *      if we can find an initial designated contact.
     *   2) If a project is already in pid 15954, see if there are changes to the project metadata
     *      and should be saved. If the project doesn't have a log entry within the last year, move
     *      to inactive status. Place a notification on the user to display a message if they try to
     *      access the project.
     *   3) Check for missing project ids.  If they are missing, the project was permanently deleted
     *      so make sure the status in pid 15954 reflects that.
     */
    public function checkStatusOfRedcapProjects() {

        $projectData = array();
        $today = date("Y-m-d");
        $one_year_ago = date('Y-m-d', strtotime("$today - 1 YEAR"));

        // Retrieve the list of current projects in the REDCap project
        $currentProjects = REDCap::getData($this->pid, 'array', null, $this->projFields, $this->event_id);
        if (!empty($currentProjects)) {

            // Put into a usable format [{record_id1: {field1:value1, field2:value2, ...}}, {record_id2: {field1:value1, field2:value2, ...}}]
            foreach ($currentProjects as $proj_id => $thisRecord) {

                $oneRecord = $thisRecord[$this->event_id];
                $record_id = $oneRecord['project_id'];
                $projectData[$record_id] = $oneRecord;
            }
        }

        // if a project is marked to be deleted, date_deleted will be set, otherwise look at status (0=development, 1=production, 2=inactive, 3=archived, 4=deleted)
        // created_by is a number (link with redcap_user_information). If a project is really deleted, there will not be any data in redcap.
        // purpose is 0, 1, 2, 3, 4 (when Research (2), there will be an IRB number and project_pi_alias)
        $sql = "select project_id as project_id, creation_time as proj_create_date, app_title as proj_title, purpose as proj_purpose, " .
            "project_irb_number as proj_irb, project_pi_alias as proj_pi, last_logged_event as proj_last_log_entry, " .
            "case when date_deleted is NULL then status else " . self::MARKED_TO_BE_DELETED . " end as proj_status " .
            "    from redcap_projects " .
            "    order by project_id asc";
        $q = db_query($sql);
        $updateData = array();
        $dbRecordList = array();
        while ($current_db_row = db_fetch_assoc($q)) {

            // These are all the current projects from the database, make sure they match the status of the project in project 47
            // If not update the status or insert the record if it currently does not exist.
            $current_record = array();
            $record_id = $current_db_row['project_id'];
            $dbRecordList[] = $record_id;
            $current_proj_row = $projectData[$record_id];

            if (empty($current_proj_row)) {
                $differences = $current_db_row;
            } else {
                $differences = array_diff($current_db_row, $current_proj_row);
            }

            if (!empty($differences)) {

                // Save the differences and add the date stamp so we know the last time the project info was updated
                $current_record = $differences;
                $current_record['proj_status_update'] = $today;
                $current_record['project_overview_complete'] = 2;

                // If this is a new project, find a designated contact
                if (empty($current_proj_row)) {
                    $contact = $this->designatedContact($record_id);
                    if (!empty($contact)) {
                        $current_record = array_merge($current_record, $contact);
                    }
                }

                // If the last log entry is over a year old and the project is in Dev(0) or Prod(1), move to Inactive or Archived
                if (isset($current_db_row['proj_last_log_entry']) && ($current_db_row['proj_last_log_entry'] < $one_year_ago)
                    && (($current_db_row['proj_status'] == self::DEVELOPMENT) || ($current_db_row['proj_status'] == self::PRODUCTION))) {

                    //$this->config->emLog("This is the last log entry for project $record_id: " . $current_db_row['proj_last_log_entry']);
                    $inactiveStatus = $this->moveProjectInactive($record_id, $current_db_row['proj_status']);
                    if (!empty($inactiveStatus)) {
                        $current_record = array_merge($current_record, $inactiveStatus);
                    }
                } else if (isset($current_db_row['proj_last_log_entry']) &&
                    (($current_db_row['proj_status'] == self::DEVELOPMENT) || ($current_db_row['proj_status'] == self::PRODUCTION))) {

                    // If the last long entry is different than last time, retrieve the number of log entries in the last 3 months
                    $lastLogCount = $this->lastLogCount($record_id, $current_db_row['proj_last_log_entry']);
                    if (!empty($lastLogCount)) {
                        $current_record = array_merge($current_record, $lastLogCount);
                    }
                }
            }

            // If there is data to save, add it to the save list.
            if (!empty($current_record)) {
                if (empty($current_record['project_id'])) {
                    $current_record['project_id'] = $record_id;
                }
                $updateData[$record_id] = $current_record;
            }
        }

        // Update all project information that was accumlated
        if (!empty($updateData)) {
            $this->config->emDebug("Data before saveData: " . json_encode($updateData));
            $return_status = REDCap::saveData($this->pid, 'json', json_encode($updateData));
            $this->config->emDebug("Return status from saveData: " . json_encode($return_status));
        }

        // Now check for missing records and make sure they are marked as permanently deleted in our REDCap project
        $deletedRecords = $this->checkForDeletedProjects($dbRecordList, $projectData, $today);
        if (!empty($deletedRecords)) {
            $this->config->emDebug("All records to save for delete: " . json_encode($deletedRecords));
            $return_status = REDCap::saveData($this->pid, 'json', json_encode($deletedRecords));
            $this->config->emDebug("Return status from saveData for delete: " . json_encode($return_status));
        }

    }

    /**
     * This function will look through the records retrieved from redcap_projects and look for missing
     * entries.  If the entry is missing, the project is permanently deleted.
     *
     * @param $dbRecordList - array of projects retrieved from redcap_projects
     * @param $projectData - Data already saved in the project monitoring database
     * @param $today - today's date used to save in redcap monitoring project
     * @return $updateRecords - Data to save when project is permanently deleted from redcap database
     */
    private function checkForDeletedProjects($dbRecordList, $projectData, $today) {

        $updateRecords = array();
        $lastProjectID = max($dbRecordList);
        for ($ncnt=1; $ncnt <= $lastProjectID; $ncnt++) {
            if (!in_array($ncnt, $dbRecordList)) {
                $currentStatus = $projectData[$ncnt]['proj_status'];
                if (strcmp($currentStatus, self::PERMANENTLY_DELETED) !== 0) {
                    $updateRecords[$ncnt]['project_id'] = $ncnt;
                    $updateRecords[$ncnt]['proj_status'] = self::PERMANENTLY_DELETED;
                    $updateRecords[$ncnt]['proj_status_update'] = $today;
                    $updateRecords[$ncnt]['project_overview_complete'] = 2;
                }
            }
        }

        $this->config->emDebug("Permanently deleted status: " . json_encode($updateRecords));

        return $updateRecords;
    }

    /**
     * A new project has been found, find a person to designate as the Designated Contact.  We
     * need to post so that we will be in project contact to retrieve the list of project users.
     *
     * @param $record_id - (is same as project id)
     * @return $contact - array containing new Designated Contact for this project
     */
    private function designatedContact($record_id) {

        // We are not in project context but to use the REDCap functions
        $url = $this->config->getUrl("src/findDesignatedContact.php") . "&pid=" . $record_id;

        $response = http_post($url, null);

        $contact = json_decode($response, true);
        return $contact;
    }

    /**
     * This function will make a post to notificationsForInactiveProjects.php to be in project context.
     * It will make the changes to turn this project inactive.
     *
     * @param $record_id - no log event activity in over a year so moving to an inactive status
     * @param $current_status - save the status that the project currently is in (Development or Production)
     * @return $update_data -
     */
    private function moveProjectInactive($record_id, $current_status) {

        $this->config->emDebug("Moving to Inactive status: project " . $record_id . " which is in $current_status");
        $current_timestamp = date("Y-m-d H:i:s");
        if ($current_status == self::DEVELOPMENT) {

            // If the project is in DEV, move to Archive
            $status = self::ARCHIVED;
            $label = "Archive project by Cron";
            $update_timestamp = '';

        } else {

            // If the project is in Prod, move to Inactive
            $status = self::INACTIVE;
            $label = "Set project as Inactive by Cron";
            $update_timestamp = $current_timestamp;
        }

        // Update the project status in the database
        $sql = 'update redcap_projects 
                 set status=' . $status . ',
                     inactive_time = "' . $update_timestamp . '"
                     where project_id = ' . $record_id;
        $q = db_query($sql);

        // Now update the project status to show that we put the project in inactive mode
        $updateData['proj_status'] = $status;
        $updateData['proj_inactive_timestamp'] = $current_timestamp;

        // Now log the fact that it went Inactive or Archived in the project log
        REDCap::logEvent("Manage/Design", $label, null, null, null, $record_id);

        // Make a notification so that each user will receive a pop-up when they try to access this project
        // To find the users of this project, we need to be project context
        $url = $this->config->getUrl("src/notificationsForInactiveProjects.php") . "&pid=" . $record_id .
            '&status=' . $this->status_labels[$current_status];
        $response = http_post($url, null);
        $return = json_decode($response, true);
        if ($return) {
            $this->config->emDebug("Successfully set notifications for project $record_id when turning project inactive from $current_status by cron");
        } else {
            $this->config->emError("Setting notifications had an error for project $record_id when turning project inactive");
        }

        return $updateData;
    }

    /**
     * This function will make a count of the number of log events in the last 3 months. Should this be
     * configurable instead of just using 3 months?
     *
     * @param $record_id - project to look at log events
     * @param $timestamp - ending timestamp
     * @return $updateRecord - array of data to save
     */
    private function lastLogCount($record_id, $timestamp) {

        // Count how many log entries there were in the last 3 months that there was activity
        $updateRecord = array();
        $sql = "select count(*) as ncount from redcap_log_event " .
            "where project_id = '" . $record_id . "' " .
            "and date_format(str_to_date(ts, '%Y%m%d%H%i%s'), '%Y-%m-%d') " .
            "between ('" . $timestamp . "' - INTERVAL 3 month) and '" . $timestamp . "' " .
            "group by project_id";
        $q = db_query($sql);
        $count = db_fetch_row($q);

        $updateRecord['proj_num_logs_in_3mos'] = $count[0];
        return $updateRecord;
    }

    /**
     * This function will look at all Designated Contacts who have not been notified and that they are
     * the designated contact and send one email with a list of their project.
     *
     * @param $pid - Project Monitoring project to look for Designated Contact data
     * @param $event_id - Event ID of Project Monitoring project
     */
    public function sendEmailToDesignatedContacts($pid, $event_id) {

        $from = "noreply@redcap.com";
        $subject = "You are a REDCap Designated Contact!";
        $bodyPre = "You are the Designated Contact for the following project(s): ";
        $bodyPost = "The Designated Contact will be the point person who will be contacted<br>
                     by the REDCap team for important announcements or if there are issues<br>
                     with your project.<br><br>
                     
                     To change the Designated Contact, please login to REDCap, go to the project<br>
                     and navigate to the User Rights page.  You will be able to select another user<br>
                     with User Rights privileges.<br><br>
                     
                     If you have questions about this new role, please contact the REDCap team by<br>
                     logging into your project and selecting the 'Contact REDCap Administrator' button<br>
                     on the bottom of the left hand sidebar.<br><br>
                     
                     REDCap Team";
        $urlLink = APP_PATH_WEBROOT . 'index.php?pid=';

        $fields = array('project_id', 'proj_title', 'contact_firstname', 'contact_lastname', 'contact_email');
        $filter = '[contact_notified(1)] = "0" and [designated_contact_complete] = "2"';

        $data = REDCap::getData($this->pid, 'array', null, $fields, $this->event_id, null, null, null, null, $filter);

        // Create an array based on user so we can send out emails once to each user
        $userEmail = array();
        foreach($data as $record_id => $record_data) {
            foreach($record_data as $event_id => $record) {
                $oneProject = array();
                $email                          = $record["contact_email"];
                $project_id                     = $record["project_id"];
                $oneProject['project_id']       = $project_id;
                $oneProject['title']            = $record['proj_title'];
                $oneProject['firstname']        = $record['contact_firstname'];
                $oneProject['lastname']         = $record['contact_lastname'];
            }

            $userEmail[$email][] = $oneProject;
        }

        // Now send email to the Designated Contact for all their projects
        foreach($userEmail as $emailAddr => $emailInfo) {

            $body = '';

            $save_notification_status = array();
            foreach($emailInfo as $proj) {
                if (empty($body)) {
                    $body = "Hello " . $proj['firstname'] . ' ' . $proj['lastname'] . ',<br><br>';
                    $body .= $bodyPre . '<ul>';
                }

                $project_id = $proj['project_id'];
                $update_status['project_id'] = $project_id;
                $update_status['contact_notified___1'] = "1";
                $save_notification_status[$project_id] = $update_status;

                $body .= "<li> [" . $proj['project_id'] . "] " . "<a href='" . $urlLink . $proj['project_id'] . "'>" . $proj['title'] . "</a></li>";
            }

            $body .= "</ul>";
            $body .= $bodyPost;

            $this->config->emDebug("TO: $emailAddr, FROM: $from, SUBJECT: $subject, BODY: $body");
            //echo("TO: $emailAddr, FROM: $from, SUBJECT: $subject, BODY: <br><br>$body<br><br>");
            $status = 1;
            $status = REDCap::email($emailAddr, $from, $subject, $body);
            if ($status) {
                // The email was sent, now set the checkbox in the Monitoring project to
                // so we don't include this project again.
                $this->config->emDebug("Save data: " . json_encode($save_notification_status));
                $status = REDCap::saveData($pid, 'json', json_encode($save_notification_status));
                $this->config->emDebug("Return status: " . json_encode($status));
            }
        }

    }

}