<?php

/**
 * Task Scheduler
 *
 * Uses the event nextrun timestamp to determine whether a task may execute.
 *
 * Column naming used by this version:
 * - schedule = schedule rule/expression used by strtotime().
 * - nextrun  = next execution timestamp.
 * - lastrun  = last execution timestamp.
 *
 * Main behavior:
 * - Uses nextrun instead of schedule to determine if an event is due.
 * - Uses schedule instead of format to calculate the next run time.
 * - Prevents disabled events from running.
 * - Prevents unscheduled events from running when nextrun is empty or 0.
 * - Uses the event ID loaded in the constructor instead of requiring a second ID.
 * - Keeps allowAction(?int $id = null) backward compatible with older calls.
 * - Updates lastrun and nextrun after a successful allowed execution.
 * - Stores lastExec, nextExec, and secToExec for debugging/status output.
 *
 * Example:
 *
 * $cron = new pssmcron(10, $eventId);
 *
 * if ($cron->allowAction()) {
 *     // Run task
 * }
 *
 * Backward compatible call still works:
 *
 * if ($cron->allowAction($eventId)) {
 *     // Run task
 * }
 */
class pssmcron
{
    private int $minDelay;
    private int $lastExec = 0;
    private int $nextExec = 0;
    private int $secToExec = 0;
    private bool $check = false;

    /**
     * Event record.
     */
    private array $event = [];

    public function __construct(int $minDelay, int $id)
    {
        global $db_database;

        $this->minDelay = max(1, $minDelay);

        $sql = sprintf(
            "SELECT
                id,
                nextrun,
                lastrun,
                schedule,
                state,
                debug,
                updated
             FROM `%s`.`events`
             WHERE id = %d
             LIMIT 1",
            $db_database,
            $id
        );

        $result = _dbquery($sql, MYSQLI_ASSOC, false);

        if (is_array($result) && !empty($result[0])) {
            $this->event = $result[0];
            $this->check = true;

            $this->lastExec = (int)($this->event['lastrun'] ?? 0);
            $this->nextExec = (int)($this->event['nextrun'] ?? 0);

            if ($this->nextExec > time()) {
                $this->secToExec = $this->nextExec - time();
            }
        }
    }

    /**
     * Determine whether a task may execute.
     *
     * The optional $id parameter is kept only for backward compatibility.
     * New code should call allowAction() without passing the ID again.
     */
    public function allowAction(?int $id = null): bool
    {
        if (!$this->check) {
            return false;
        }

        if ((int)($this->event['state'] ?? 0) !== 1) {
            return false;
        }

        $eventId = (int)($this->event['id'] ?? 0);

        if ($eventId <= 0) {
            return false;
        }

        /*
            Safety check for old code that still passes an ID.
            This prevents loading one event and accidentally updating another.
        */
        if ($id !== null && $id !== $eventId) {
            return false;
        }

        $now = time();
        $nextrun = (int)($this->event['nextrun'] ?? 0);

        /*
            If nextrun is empty or 0, the event is considered not scheduled.
            It should not execute automatically.
        */
        if ($nextrun <= 0) {
            $this->lastExec = (int)($this->event['lastrun'] ?? 0);
            $this->nextExec = 0;
            $this->secToExec = 0;

            return false;
        }

        /*
            Not due yet.
        */
        if ($nextrun > $now) {
            $this->lastExec = (int)($this->event['lastrun'] ?? 0);
            $this->nextExec = $nextrun;
            $this->secToExec = $nextrun - $now;

            return false;
        }

        /*
            Due now. Update lastrun and next schedule window.
        */
        return $this->updateExecution($eventId);
    }

    /**
     * Calculate the next scheduled run time.
     *
     * Uses the event schedule value to generate the next execution
     * timestamp using strtotime(), matching the behavior of the
     * original scheduler implementation.
     *
     * If the schedule value cannot be parsed, the scheduler falls back
     * to the configured minute interval.
     */
    private function calculateSchedule(): int
    {
        $scheduleRule = trim((string)($this->event['schedule'] ?? ''));

        if ($scheduleRule !== '') {
            $timestamp = strtotime($scheduleRule);

            if ($timestamp !== false && $timestamp > 0) {
                return $timestamp;
            }
        }

        return time() + ($this->minDelay * 60);
    }

    /**
     * Update both lastrun and nextrun in a single query.
     */
    private function updateExecution(int $id): bool
    {
        global $db_database;

        $now = time();
        $nextrun = $this->calculateSchedule();

        $sql = sprintf(
            "UPDATE `%s`.`events`
             SET
                 `lastrun` = %d,
                 `nextrun` = %d
             WHERE
                 `id` = %d
                 AND `state` = 1",
            $db_database,
            $now,
            $nextrun,
            $id
        );

        $updated = (bool)_dbupdate($sql);

        if ($updated) {
            $this->lastExec = $now;
            $this->nextExec = $nextrun;
            $this->secToExec = max(0, $nextrun - $now);

            $this->event['lastrun'] = $now;
            $this->event['nextrun'] = $nextrun;
        }

        return $updated;
    }

    /**
     * Manually touch/update the next run time.
     *
     * This recalculates nextrun from the event schedule rule and stores
     * the updated timestamp in the events table.
     */
    public function touchSchedule(?int $id = null): int
    {
        global $db_database;

        if (!$this->check) {
            return 0;
        }

        $eventId = (int)($this->event['id'] ?? 0);

        if ($eventId <= 0) {
            return 0;
        }

        if ($id !== null && $id !== $eventId) {
            return 0;
        }

        $nextrun = $this->calculateSchedule();

        $sql = sprintf(
            "UPDATE `%s`.`events`
             SET `nextrun` = %d
             WHERE `id` = %d
             AND `state` = 1",
            $db_database,
            $nextrun,
            $eventId
        );

        $updated = (bool)_dbupdate($sql);

        if (!$updated) {
            return 0;
        }

        $this->nextExec = $nextrun;
        $this->secToExec = max(0, $nextrun - time());
        $this->event['nextrun'] = $nextrun;

        return $nextrun;
    }

    /**
     * Force the event to be due now.
     *
     * Useful when creating a new event or when you want the scheduler to pick it up
     * during the next cron cycle.
     */
    public function markDueNow(?int $id = null): bool
    {
        global $db_database;

        if (!$this->check) {
            return false;
        }

        $eventId = (int)($this->event['id'] ?? 0);

        if ($eventId <= 0) {
            return false;
        }

        if ($id !== null && $id !== $eventId) {
            return false;
        }

        $now = time();

        $sql = sprintf(
            "UPDATE `%s`.`events`
             SET `nextrun` = %d
             WHERE `id` = %d
             AND `state` = 1",
            $db_database,
            $now,
            $eventId
        );

        $updated = (bool)_dbupdate($sql);

        if ($updated) {
            $this->nextExec = $now;
            $this->secToExec = 0;
            $this->event['nextrun'] = $now;
        }

        return $updated;
    }

    /**
     * Getters.
     */
    public function getLastExec(): int
    {
        return $this->lastExec;
    }

    public function getNextExec(): int
    {
        return $this->nextExec;
    }

    public function getSecondsToExec(): int
    {
        return $this->secToExec;
    }

    public function exists(): bool
    {
        return $this->check;
    }

    public function isEnabled(): bool
    {
        return $this->check && (int)($this->event['state'] ?? 0) === 1;
    }

    public function isScheduled(): bool
    {
        return $this->check && (int)($this->event['nextrun'] ?? 0) > 0;
    }

    public function isDue(): bool
    {
        return $this->isEnabled()
            && $this->isScheduled()
            && (int)$this->event['nextrun'] <= time();
    }

    public function getEvent(): array
    {
        return $this->event;
    }
}
