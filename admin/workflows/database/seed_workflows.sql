-- User Manager 3.0 Starter Workflows
-- Review and modify table names / field names before running.

-- Workflow 1: Report certain logs to an admin.
INSERT INTO um_workflows
(workflow_key, name, description, enabled, created_at, updated_at)
VALUES
('report.admin.logs', 'Report Admin Logs', 'Collect selected logs and email them to an administrator.', 1, NOW(), NOW());

SET @report_workflow_id = LAST_INSERT_ID();

INSERT INTO um_workflow_steps
(workflow_id, position, action_key, parameters_json, enabled, created_at, updated_at)
VALUES
(@report_workflow_id, 1, 'report_logs', '{
  "table":"logs",
  "date_field":"created_at",
  "date_type":"datetime",
  "since_hours":24,
  "level_field":"level",
  "levels":["error","warning","security"],
  "limit":100
}', 1, NOW(), NOW()),
(@report_workflow_id, 2, 'email_context', '{
  "to":"admin@example.local",
  "subject":"User Manager Daily Log Report",
  "include_context":true
}', 1, NOW(), NOW());

-- Workflow 2: Clean up old sessions.
-- Start with dry_run true. Change to false after confirming matched count.
INSERT INTO um_workflows
(workflow_key, name, description, enabled, created_at, updated_at)
VALUES
('cleanup.old.sessions', 'Clean Up Old Sessions', 'Remove or report old session records.', 1, NOW(), NOW());

SET @cleanup_workflow_id = LAST_INSERT_ID();

INSERT INTO um_workflow_steps
(workflow_id, position, action_key, parameters_json, enabled, created_at, updated_at)
VALUES
(@cleanup_workflow_id, 1, 'cleanup_old_sessions', '{
  "table":"sessions",
  "last_activity_field":"last_activity",
  "date_type":"unix",
  "max_idle_minutes":1440,
  "dry_run":true
}', 1, NOW(), NOW()),
(@cleanup_workflow_id, 2, 'email_context', '{
  "to":"admin@example.local",
  "subject":"User Manager Session Cleanup Report",
  "include_context":true
}', 1, NOW(), NOW());
