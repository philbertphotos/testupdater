User Manager 3.0 Workflow Engine Starter
========================================

Purpose
-------
This package starts the instruction-matrix / workflow-engine concept for User Manager.

Instead of writing a custom PHP function for every administrative task, this creates:

1. A small reusable workflow engine.
2. A registry of approved actions.
3. Database-driven workflows.
4. Two starter workflows:
   - Report selected logs to an administrator.
   - Clean up old sessions.

Important Design Idea
---------------------
You still write PHP actions, but each action is reusable.

For example:
- report_logs can be reused for daily logs, security logs, failed login logs, or weekly logs.
- cleanup_old_sessions can be reused for 1-hour, 8-hour, 24-hour, or 7-day session cleanup.
- email_context can be reused by any workflow that needs to send results.

Where You Modify Things
-----------------------
Most future changes should be made in the database workflow rows, not by creating new PHP functions.

Modify these areas first:

1. database/install_workflow_tables.sql
   - Use this once to create the workflow tables.

2. database/seed_workflows.sql
   - Change table names, field names, admin email address, and interval settings.

3. config/workflow_actions.php
   - Register new reusable PHP actions here.

4. actions/*.php
   - Add new reusable actions only when the existing actions cannot do the job.

5. examples/run_workflow.php
   - Testing entry point. Use this to manually run a workflow by key.

Suggested File Placement
------------------------
Copy the folders into your User Manager project like this:

/classes/workflow/WorkflowActionInterface.php
/classes/workflow/WorkflowEngine.php
/actions/Action_ReportLogs.php
/actions/Action_EmailContext.php
/actions/Action_CleanupOldSessions.php
/config/workflow_actions.php
/database/install_workflow_tables.sql
/database/seed_workflows.sql
/examples/run_workflow.php

Assumptions
-----------
This starter assumes your UM project has:

- env.php
- a database helper loaded as $d
- $d->rows($sql)
- $d->row($sql)
- $d->query($sql)
- $d->insert($table, $array)

Those match the style already used in the existing User Manager code.

Testing Order
-------------
1. Back up the database.
2. Run database/install_workflow_tables.sql.
3. Update database/seed_workflows.sql for your real table and field names.
4. Run database/seed_workflows.sql.
5. Open /examples/run_workflow.php?key=report.admin.logs.
6. Open /examples/run_workflow.php?key=cleanup.old.sessions.
7. Check um_workflow_runs and um_workflow_run_logs.

Security Notes
--------------
- Only actions registered in config/workflow_actions.php can run.
- Table and field names are validated before use.
- Parameters come from JSON, but actions still sanitize/validate values.
- You should protect examples/run_workflow.php with the same manager/admin security used elsewhere.

Next Improvements
-----------------
- Add a UI for workflow management.
- Add schedule integration so Events can run workflow keys.
- Add conditions such as if no logs found then skip email.
- Add action permissions.
- Add workflow versioning.
