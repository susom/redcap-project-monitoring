# Project Monitoring EM
This EM will monitor the state of all REDCap projects.  The status of each project can be: Development, Production, Inactive, Archived, Deleted, Permanently Deleted.  

This EM will also monitor and update Designated Contacts.  If a Designated Contact is not specified, the Project Setup page will display a red box to inform all users with User Rights that a designated contact needs to be set. If a designated contact is set, users with User Rights will see a green box with the option to change the Designated Contact.

When viewing REDCap projects on the My Projects page, a icon will be displayed to the left of the project name when you are the Designated Contact for that project.

This EM is designed to work from a cron job.  Updating project statuses will occur nightly but other monitoring tasks may occur monthly.  Other tasks could be: 

1) Checking IRB statuses to ensure they are still approved
2) Checking EM, plugin, DET usage
3) Verifying all users are current
4) Retrieving and storing project statistics
5) Generating project summaries