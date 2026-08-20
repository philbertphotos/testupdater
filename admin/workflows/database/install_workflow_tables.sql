-- User Manager 3.0 Workflow Engine Tables
-- Run this once before using the workflow engine.

CREATE TABLE IF NOT EXISTS um_workflows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_key VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS um_workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    action_key VARCHAR(120) NOT NULL,
    parameters_json LONGTEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    INDEX workflow_id_idx (workflow_id),
    INDEX action_key_idx (action_key)
);

CREATE TABLE IF NOT EXISTS um_workflow_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT NOT NULL,
    workflow_key VARCHAR(120) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'running',
    message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NULL,
    INDEX workflow_id_idx (workflow_id),
    INDEX workflow_key_idx (workflow_key),
    INDEX status_idx (status)
);

CREATE TABLE IF NOT EXISTS um_workflow_run_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    step_id INT NULL,
    action_key VARCHAR(120) NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT NULL,
    data_json LONGTEXT NULL,
    created_at DATETIME NULL,
    INDEX run_id_idx (run_id),
    INDEX status_idx (status)
);
