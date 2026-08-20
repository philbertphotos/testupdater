<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');
include(DOCROOT . '/header.php');

/*************************************************************************
 * Security
 *************************************************************************/

$m->requirePageAccess('Managers');

function h($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function workflow_status_label($enabled)
{
	return ((int)$enabled === 1) ? array('Enabled', 'success', 'glyphicon-ok-circle') : array('Disabled', 'default', 'glyphicon-ban-circle');
}

function workflow_date($value)
{
	$value = trim((string)$value);
	return ($value !== '' && $value !== '0000-00-00 00:00:00') ? date('F j, Y, g:i a', strtotime($value)) : 'Never';
}

function workflow_key_class($key)
{
	$key = strtolower((string)$key);
	$key = preg_replace('/[^a-z0-9]+/', '-', $key);
	$key = trim($key, '-');
	return 'workflow-key-' . ($key ?: 'general');
}

$workflows = $d->rows('
	SELECT
		wm.id,
		wm.`key`,
		wm.name,
		wm.description,
		wm.enabled,
		wm.created,
		wm.updated,
		COUNT(ws.id) AS step_count,
		MAX(wr.started) AS last_run,
		MAX(wr.finished) AS last_finished
	FROM workflows_main wm
	LEFT JOIN workflows_steps ws
		ON ws.workflow_id = wm.id
	LEFT JOIN workflows_runs wr
		ON wr.workflow_id = wm.id
	GROUP BY wm.id
	ORDER BY wm.enabled DESC, wm.name ASC
');

$totalWorkflows = count($workflows);
$enabledWorkflows = 0;
$disabledWorkflows = 0;
$totalSteps = 0;

foreach ($workflows as $workflow) {
	((int)$workflow->enabled === 1) ? $enabledWorkflows++ : $disabledWorkflows++;
	$totalSteps += (int)$workflow->step_count;
}

$canCreate  = $acl->isAllowed($acl->getRole(), 'create', 'events');
$canEdit    = $acl->isAllowed($acl->getRole(), 'edit', 'events');
$canDelete  = $acl->isAllowed($acl->getRole(), 'delete', 'events');
$canExecute = $acl->isAllowed($acl->getRole(), 'execute', 'events');
?>
<style>
	.workflows-page { margin-top: 15px; }
	.workflows-page .page-header { margin-top: 0; border-bottom: 0; }
	.workflows-page .page-title { margin-top: 0; margin-bottom: 5px; font-weight: 600; }
	.workflows-page .page-subtitle { color: #6c757d; margin-bottom: 0; }
	.workflow-summary-card {
		border: 1px solid #e5e5e5;
		border-radius: 8px;
		background: #fff;
		padding: 16px;
		min-height: 96px;
		cursor: pointer;
		transition: all .15s ease-in-out;
		margin-bottom: 15px;
		box-shadow: 0 1px 2px rgba(0,0,0,.04);
	}
	.workflow-summary-card:hover,
	.workflow-summary-card.active-filter {
		border-color: #337ab7;
		box-shadow: 0 3px 10px rgba(51,122,183,.18);
		transform: translateY(-1px);
	}
	.workflow-summary-card .summary-number { font-size: 30px; font-weight: 700; line-height: 1; }
	.workflow-summary-card .summary-label { color: #777; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-top: 7px; }
	.workflow-summary-card .summary-icon { float: right; font-size: 28px; opacity: .25; margin-top: 5px; }
	.workflows-toolbar { margin-bottom: 15px; }
	.workflows-toolbar .form-control { margin-bottom: 8px; }
	.workflow-row { cursor: pointer; }
	.workflow-row.selected-row td { background: #eef7ff !important; }
	.workflow-disabled td { color: #777; background: #f9f9f9 !important; }
	.workflow-title { font-weight: 600; display: inline-block; max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
	.workflow-meta { display: block; color: #777; font-size: 11px; margin-top: 3px; word-break: break-word; }
	.workflow-badge-line { margin-top: 8px; }
	.workflow-badge-line .label,
	.workflow-badge { display: inline-block; margin-right: 5px; margin-bottom: 5px; padding: 6px 11px; border-radius: 14px; font-size: 11px; font-weight: 600; line-height: 1.35; letter-spacing: .25px; vertical-align: middle; white-space: nowrap; }
	.workflow-tools { white-space: nowrap; text-align: center; }
	.workflow-tools .dropdown-menu { right: 0; left: auto; text-align: left; }
	.workflow-tools .btn { margin-bottom: 3px; }
	.workflow-step-item { border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 10px; background: #fff; }
	.workflow-step-item .step-heading { margin-bottom: 10px; font-weight: 600; }
	.workflow-step-item textarea { font-family: Menlo, Monaco, Consolas, 'Courier New', monospace; }
	.empty-state { display: none; text-align: center; padding: 35px; color: #777; }
	.empty-state .glyphicon { font-size: 32px; margin-bottom: 10px; opacity: .35; }
	@media (max-width: 991px) {
		.workflow-title { max-width: 100%; white-space: normal; }
		.workflow-tools { text-align: left; margin-top: 8px; }
	}
</style>
<div class="container-fluid workflows-page">
	<div class="page-header clearfix">
		<div class="pull-left">
			<h2 class="page-title">Workflow Builder</h2>
			<p class="page-subtitle">Create and manage workflow definitions without writing SQL.</p>
		</div>
		<div class="pull-right">
			<?php if ($canCreate) { ?>
				<button type="button" class="btn btn-primary" id="createworkflow">
					<i class="glyphicon glyphicon-plus"></i> Create Workflow
				</button>
			<?php } ?>
			<a href="/admin/events.php" class="btn btn-default">
				<i class="glyphicon glyphicon-calendar"></i> Scheduled Events
			</a>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-3">
			<div class="workflow-summary-card active-filter" data-filter="all">
				<span class="glyphicon glyphicon-random summary-icon"></span>
				<div class="summary-number"><?php echo (int)$totalWorkflows; ?></div>
				<div class="summary-label">All Workflows</div>
			</div>
		</div>
		<div class="col-sm-3">
			<div class="workflow-summary-card" data-filter="enabled">
				<span class="glyphicon glyphicon-ok-circle summary-icon"></span>
				<div class="summary-number"><?php echo (int)$enabledWorkflows; ?></div>
				<div class="summary-label">Enabled</div>
			</div>
		</div>
		<div class="col-sm-3">
			<div class="workflow-summary-card" data-filter="disabled">
				<span class="glyphicon glyphicon-ban-circle summary-icon"></span>
				<div class="summary-number"><?php echo (int)$disabledWorkflows; ?></div>
				<div class="summary-label">Disabled</div>
			</div>
		</div>
		<div class="col-sm-3">
			<div class="workflow-summary-card" data-filter="steps">
				<span class="glyphicon glyphicon-list summary-icon"></span>
				<div class="summary-number"><?php echo (int)$totalSteps; ?></div>
				<div class="summary-label">Total Steps</div>
			</div>
		</div>
	</div>
	<div class="panel panel-default panel-table">
		<div class="panel-body">
			<div class="row workflows-toolbar">
				<div class="col-sm-6">
					<input type="text" class="form-control" id="workflowSearch" placeholder="Search workflows..." />
				</div>
				<div class="col-sm-3">
					<select class="form-control" id="workflowStatusFilter">
						<option value="all">All Statuses</option>
						<option value="enabled">Enabled</option>
						<option value="disabled">Disabled</option>
					</select>
				</div>
				<div class="col-sm-3 text-right">
					<button type="button" class="btn btn-default" id="clearWorkflowFilters">Clear Filters</button>
				</div>
			</div>
			<div class="table-responsive">
				<table class="table table-hover" id="workflowsTable">
					<thead>
						<tr>
							<th>Workflow</th>
							<th>Status</th>
							<th>Steps</th>
							<th>Last Run</th>
							<th class="text-center">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($workflows as $workflow) { ?>
						<?php $status = workflow_status_label($workflow->enabled); ?>
						<tr id="workflow_<?php echo (int)$workflow->id; ?>" class="workflow-row <?php echo ((int)$workflow->enabled === 1) ? 'workflow-enabled' : 'workflow-disabled'; ?>" data-workflow-id="<?php echo (int)$workflow->id; ?>" data-status="<?php echo ((int)$workflow->enabled === 1) ? 'enabled' : 'disabled'; ?>" data-key="<?php echo h($workflow->key); ?>">
							<td>
								<span class="workflow-title" id="workflow_title_<?php echo (int)$workflow->id; ?>"><?php echo h($workflow->name); ?></span>
								<span class="workflow-meta"><?php echo h($workflow->key); ?></span>
								<div class="workflow-badge-line">
									<span class="workflow-badge label label-default <?php echo h(workflow_key_class($workflow->key)); ?>"><?php echo h($workflow->key); ?></span>
								</div>
								<?php if (trim((string)$workflow->description) !== '') { ?>
									<span class="workflow-meta"><?php echo h($workflow->description); ?></span>
								<?php } ?>
							</td>
							<td>
								<span class="label label-<?php echo h($status[1]); ?> workflow-badge">
									<span class="glyphicon <?php echo h($status[2]); ?>"></span> <?php echo h($status[0]); ?>
								</span>
							</td>
							<td><?php echo (int)$workflow->step_count; ?></td>
							<td><?php echo h(workflow_date($workflow->last_run)); ?></td>
							<td class="workflow-tools">
								<div class="btn-group">
									<?php if ($canExecute) { ?>
										<button type="button" class="btn btn-xs btn-success runworkflow" data-target="<?php echo (int)$workflow->id; ?>"><i class="glyphicon glyphicon-play"></i> Run</button>
									<?php } ?>
									<button type="button" class="btn btn-xs btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">More <span class="caret"></span></button>
									<ul class="dropdown-menu">
										<?php if ($canEdit) { ?>
											<li><a href="#" class="editworkflow" data-target="<?php echo (int)$workflow->id; ?>"><i class="glyphicon glyphicon-pencil"></i> Edit</a></li>
										<?php } ?>
										<?php if ($canCreate) { ?>
											<li><a href="#" class="cloneworkflow" data-target="<?php echo (int)$workflow->id; ?>"><i class="glyphicon glyphicon-duplicate"></i> Clone</a></li>
										<?php } ?>
										<?php if ($canDelete) { ?>
											<li role="separator" class="divider"></li>
											<li><a href="#" class="deleteworkflow text-danger" data-target="<?php echo (int)$workflow->id; ?>"><i class="glyphicon glyphicon-trash"></i> Delete</a></li>
										<?php } ?>
									</ul>
								</div>
							</td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
			<div class="empty-state" id="workflowEmptyState">
				<span class="glyphicon glyphicon-search"></span>
				<p>No workflows match the current filters.</p>
			</div>
		</div>
	</div>
</div>
<?php include(DOCROOT . '/footer.php'); ?>
