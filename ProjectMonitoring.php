<?php

namespace Stanford\ProjectMonitoring;
/** @var \Stanford\ProjectMonitoring\ProjectMonitoring $module **/

require_once "emLoggerTrait.php";
require_once("src/ProjectMonitor.php");
require_once("util/ProjectMonitoringUtil.php");

use REDCap;


class ProjectMonitoring extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * This function is called from a cron job (currently not implemented). It will perform all actions
     * needed on a daily basis.
     */
    public function dailyCron() {

        // this cron will run once a day to update the REDCap project of projects (pid=15954)
        $this->emLog("Starting daily cron pMonitoring" . date("Y-m-d H:i:s"));

        // Retrieve the project we are updating and the event where we are storing the data
        list($pid, $event_id) = getMonitoringProjectID();
        if (empty($pid) || empty($event_id)) {
            $this->emError("Project ID and event ID are required for Cron processing: pid=" . $pid . ", and event_id=" . $event_id);
            return;
        }

        // Find new projects, update existing projects and mark projects as deleted.
        $pmonitor = new ProjectMonitor($pid, $event_id, $this);
        $pmonitor->checkStatusOfRedcapProjects();

        // Looks for new projects with new Designated Contacts and will send them an email to let
        // them know that have that role.  Only one email per person.
        $pmonitor->sendEmailToDesignatedContacts($pid, $event_id);

        $this->emLog("Finished daily cron pMonitoring" . date("Y-m-d H:i:s"));
    }


    /**
     * This function is called from a cron job (currently not implemented). It will perform all actions
     * needed on a monthly basis.
     */
    public function monthlyCron() {

        // this cron will run once a month to update the REDCap project of projects (pid=15954)
        $this->emLog("Starting monthly cron pMonitoring" . date("Y-m-d H:i:s"));

        list($pid, $event_id) = getMonitoringProjectID();
        if (empty($pid) || empty($event_id)) {
            $this->emError("Project ID and event ID are required for Cron processing: pid=" . $pid . ", and event_id=" . $event_id);
            return;
        }

        //$pmonitor = new ProjectMonitor($pid, $event_id, $this);
        //$pmonitor->updateProjectStatistics();
        //$pmonitor->checkIRBStatuses();
        //$pmonitor->changeStatusOfInactiveProjects();
        //$pmonitor->findDesignatedContact();
        //$pmonitor->updateUsers();
        //$pmonitor->updateProjectEnhancements();

        $this->emLog("Finished monthly cron pMonitoring" . date("Y-m-d H:i:s"));
    }

    /**
     * This function is called after each page is loaded and before it is rendered. There are 2 main reasons
     * we have it enabled.
     *
     *  1) On the User Rights page (and the Project Setup if a Designated Contact is not selected), users
     *     who have User Rights privaleges have the option to change the Designated Contact for their project.
     *  2) On the My Projects page, an icon will be placed to the left of the project title when this user
     *     is the designated contact for that project.
     *
     * @param null $project_id
     */
    function redcap_every_page_before_render($project_id=null) {

        $sunet_id = USERID;

        /*
         * This section will place the Designated Contact icon next to the project title on the My Projects page.
         */
        if (PAGE === 'redcap/index.php') {

            // Add the Show Archive Projects when there are some
            $newButton = '<button id="archiveBtn" onclick="showArchivedProjects();" class="btn btn-defaultrc btn-xs" style="margin:2px 0 0 20px;">';
            $newButton .= '</button>';

            // Find the number of archived projects
            $num_of_archived_projects = findNumArchivedProjects($sunet_id);

            // Find the projects that this user is the designated contact
            $projects = contactProjectList($this, $sunet_id);

            ?>

            <script type="text/javascript">

                window.onload = function() {

                    // Find the number of archived projects
                    var numArchived = '<?php echo $num_of_archived_projects; ?>';

                    if (numArchived > 0) {
                        // Create a new span and add the html to it
                        var newElement = document.createElement("span");
                        newElement.innerHTML = '<?php echo $newButton; ?>';

                        // Attach it the grandparent node of proj_search
                        var existingElement = document.getElementById("proj_search");
                        var parent = existingElement.parentNode;
                        parent.parentNode.insertBefore(newElement, parent);

                        // Find the label and set it on the button
                        var page = buttonLabel(numArchived);
                    }

                    // Add the icon next to the project name for designated contacts
                    designatedContactIcon();
                };


                function buttonLabel(numArchived) {
                    var show = "&show_archived";
                    var current_page = window.location.href;
                    var button = document.getElementById("archiveBtn");

                    var reloadPage;
                    if (current_page.includes(show)) {
                        button.innerHTML = '<i class="fa fa-archive"></i>  Hide Archived Projects';
                        reloadPage = current_page.substring(0, current_page.length - show.length);
                    } else {
                        button.innerHTML = '<i class="fa fa-archive"></i>  Show Archived Projects  ' +
                                '<span class="badge badge-dark">' + numArchived + '</span>';
                        reloadPage = current_page + show;
                    }

                    return reloadPage;
                }

                function showArchivedProjects() {

                    var numArchived = '<?php echo $num_of_archived_projects; ?>';
                    var page = buttonLabel(numArchived);
                    window.location.replace(page);
                }

                function designatedContactIcon() {

                    // Retrieve the list of projects this user is designated contact and make an array
                    var jsonProjectList = '<?php echo json_encode($projects); ?>';
                    var projectObject = JSON.parse(jsonProjectList.valueOf());
                    var projectList = Object.keys(projectObject);

                    // Find each project and insert the Designated Contact image
                    var nodes = document.querySelectorAll("a.aGrid");
                    nodes.forEach(function(node) {

                        // Find the project ID from the URL
                        var url = node.getAttribute("href");
                        var index = url.indexOf("pid=");
                        var project_id = url.substring(index+4, url.length);

                        // See if this project ID is in our list of Designated Contact projects
                        if (projectList.includes(project_id)) {

                            // Add the icon before the project link
                            var newIcon = document.createElement("span");
                            newIcon.classList.add("fas");
                            newIcon.classList.add("fa-address-book");
                            newIcon.setAttribute("title", "You are the Designated Contact for this project");
                            newIcon.setAttribute("style", "margin-right:7px");
                            node.prepend(newIcon);

                            // Move up the DOM and remove the padding-left 10 px instead of 30px
                            var parent = node.parentNode;
                            if (parent != null) {
                                var grandparent = parent.parentNode;
                                grandparent.setAttribute("style", "padding-left: 10px;");
                            }
                        }

                    });

                }

            </script>

            <?php
        }

        /*
         * If this is the User Rights page (or Project Setup page in some instances),
         * add the Designated Contact block which allows users to change the contact.
         *
         */
        if (PAGE === 'UserRights/index.php' || PAGE === 'ProjectSetup/index.php') {

            // See if this user has User Rights. If not, just exit
            $users = getUsersWithUserRights();
            if (!in_array($sunet_id, $users)) {
                $this->emDebug("User $sunet_id does not have User Rights");
                return;
            }

            // Display the designated contact fields since this user has User Rights
            $url = $this->getUrl("src/saveNewContact.php");
            list($pmon_pid, $pmon_event_id) = getMonitoringProjectID($this);

            // Retrieve the designated contact in the Project monitoring project
            $contact_fields = array('contact_sunetid', 'contact_firstname', 'contact_lastname', 'contact_timestamp');
            $data = REDCap::getData($pmon_pid, 'array', $project_id, $contact_fields, $pmon_event_id);
            $current_contact = $data[$project_id][$pmon_event_id]['contact_sunetid'];
            if (empty($current_contact)) {
                $color = "#ffcccc";
                $current_person = "No one has been selected yet!";
                $contact_timestamp = "";
                $new_contact = "Please setup a Designated Contact ";
            } else {
                $color = "#ccffcc";
                $current_person = $data[$project_id][$pmon_event_id]['contact_firstname'] . ' ' . $data[$project_id][$pmon_event_id]['contact_lastname'];
                $contact_timestamp = "(Last updated: " . $data[$project_id][$pmon_event_id]['contact_timestamp'] . ")";
                $new_contact = "";
            }
            $isMe = ($current_contact === $sunet_id);
            $current_contact_wording = "Designated Contact: ";

            // Set the max width based on which page we are on
            if (PAGE === 'ProjectSetup/index.php') {
                $max_width = 700;
            } else {
                $max_width = 630;
            }

            // Retrieve the list of other selectable contacts
            $availableContacts = retrieveUserInformation($users);
            $userList = null;

            // This is the Designated Contact modal which is used to change the person designated for that role.
            if ((PAGE === 'ProjectSetup/index.php') && (!empty($current_contact))) {
                // If there is already a designated contact, don't display anything on the Project Setup page
            } else {

                // Make a modal so users can change the Designated Contact
                $userList .= '<div id="contactDiv" style="margin:20px 0;font-weight:normal;padding:10px; border:1px solid #ccc; max-width:' . $max_width . 'px; background-color:' . $color . ';" > ';
                $userList .= '    <div id="contactmodal" class="modal" tabindex="-1" role="dialog">';
                $userList .= '       <div class="modal-dialog" role="document">';
                $userList .= '          <div class="modal-content">';
                $userList .= '              <div class="modal-header" style="background-color:maroon;color:white">';
                $userList .= '                  <h5 class="modal-title">Choose a new Designated Contact</h5>';
                $userList .= '                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">';
                $userList .= '                      <span style="color:white;" aria-hidden="true">&times;</span>';
                $userList .= '                  </button>';
                $userList .= '              </div>';

                $userList .= '              <div class="modal-body text-left">';
                $userList .= '                  <input id="url" type="text" value="' . $url . '" hidden>';
                $userList .= '                  <div style="margin: 10px 0; font-weight:bold;">' . $current_contact_wording . '<span style="font-weight:normal;">' . $current_person . '</span></div>';
                $userList .= '                  <div style="margin:20px 0 0 0;font-weight:bold;" > ';
                $userList .= '                      Select a new contact:';
                $userList .= '                        <select id="selected_contact" name="selected_contact">';

                // Add users that have User Rights to the selection list.
                foreach ($availableContacts as $username => $userInfo) {
                    $userList .= '<option value="' . $userInfo['contact_sunetid'] . '">' . $userInfo['contact_firstname'] . ' ' . $userInfo['contact_lastname'] . ' [' . $userInfo['contact_sunetid'] . ']' . '</option>';
                }

                $userList .= '                        </select>';
                $userList .= '                        <div style="font-size:10px;color:red;">* Only users with User Right privileges can be Designated Contacts.</div>';

                // Display to the user that the new designated contact will receive email that they were put in this role.
                $userList .= '                        <div style="font-weight:normal;margin-top:20px;">';
                $userList .= '                            <b>Note:</b><br>';
                $userList .= '                            <ul>';
                $userList .= '                                <li style="margin-top:5px;">An email will be sent to the new Designated contact to let them know they were added to this role.</li>';

                // Only send the current Designated Contact an email if they are not the person making the change
                if (!$isMe && !empty($current_contact)) {
                    $userList .= '                            <li style="margin-top:5px;">An email will be sent to the current Designated Contact to let them know they were removed from this role.</li>';
                }
                $userList .= '                            </ul>';
                $userList .= '                        </div>';
                $userList .= '                  </div>';
                $userList .= '                  <div style="margin-top:40px;text-align:right">';
                $userList .= '                        <input type="button" data-dismiss="modal" value="Close">';
                $userList .= '                        <input type="submit" onclick="saveNewContact()" value="Save">';
                $userList .= '                  </div>';
                $userList .= '              </div>';      // Modal body
                $userList .= '          </div>';         // Modal content
                $userList .= '       </div>';            // document
                $userList .= '    </div>';

                /*
                 * This is the block display on the User Rights page or on the Project Setup page
                 * (if a Designated Contact is not selected)
                 */
                if (empty($current_contact)) {

                    // If there is no currently selected contact, we will make the display bigger (2 lines) so it is prominent
                    $userList .= '<div style="color:#444;">';
                    $userList .= '    <div class="col-sm">';
                    $userList .= '        <span style="font-weight:bold;color:#000; ">';
                    $userList .= '            <i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i>';
                    $userList .=                $current_contact_wording;
                    $userList .= '        </span>';
                    $userList .= '        <span style="font-weight:normal; color:#000; margin-left:5px;">' . $current_person . '</span>';
                    $userList .= '    </div>';
                    $userList .= '    <div class="col-sm">';
                    $userList .= '        <span style="margin-left:20px;">' . $new_contact;
                    $userList .= '            <button type="button" class="btn btn-sm btn-secondary" style="font-size:12px" href="#" data-toggle="modal" data-target="#contactmodal">here!</button>';
                    $userList .= '        </span>';
                    $userList .= '    </div>';
                    $userList .= '</div>';
                } else {

                    // If a contact is already selected, we are making the box small (1 line)
                    $userList .= '<div style="color:#444;">';
                    $userList .= '    <span style="font-weight:bold;color:#000;">';
                    $userList .= '      <i class="fas fa-address-book" style="margin-right:5px;"></i>';
                    $userList .=            $current_contact_wording;
                    $userList .= '    </span>';
                    $userList .= '    <span style="font-weight:normal;color:#000;margin-right:5px;">' . $current_person . '</span>';
                    $userList .= '    <span style="font-weight:normal;font-size:10px;color:#000;margin-right:5px;">' . $contact_timestamp . '</span>';
                    $userList .= '    <span style="margin-left:10px;">' . $new_contact;
                    $userList .= '        <button type="button" class="btn btn-sm btn-secondary" style="font-size:12px" href="#" data-toggle="modal" data-target="#contactmodal">Change!</button>';
                    $userList .= '    </span>';
                    $userList .= '</div>';
                }
                $userList .= '</div>';
            }

            ?>

            <!-- Fill in the current designated contact and user will have a chance to change -->
            <script type="text/javascript">

            window.setTimeout(function() {

                // Find the page we are on
                var current_page = window.location.href;

                // Create a new div and add the html to it
                var newDiv = document.createElement("div");
                newDiv.innerHTML = '<?php echo $userList; ?>';

                // Insert this new element before the User Roles table
                var existingElement;
                if (current_page.includes('UserRights')) {
                    existingElement = document.getElementById("user_rights_roles_table_parent");
                } else if (current_page.includes('ProjectSetup')) {
                    existingElement = document.getElementById("setupChklist-modify_project");
                }
                existingElement.parentNode.insertBefore(newDiv, existingElement);

            }, 500);

            function saveNewContact() {

                var new_contact = document.getElementById("selected_contact").value;
                var current_page = window.location.href;
                var url = document.getElementById("url").value;

                $.ajax({
                    type: "POST",
                    url: url,
                    data: {"selected_contact": new_contact},
                    success: function(data, textStatus, jqXHR) {
                        window.location.replace(current_page);
                    },
                    error: function(hqXHR, textStatus, errorThrown) {
                        window.location.replace(current_page);
                    }
                });
            }

            </script>

            <?php

        }
    }

}
