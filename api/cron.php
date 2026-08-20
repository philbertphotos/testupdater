<?php
/**
 * Handles incoming requests to fire regularly-scheduled tasks (cron jobs).
 *
 * Column naming used by this version:
 * - schedule = schedule rule/expression used by strtotime().
 * - nextrun  = next execution timestamp.
 * - lastrun  = last execution timestamp.
 */

if (!defined('DOCROOT')) {
    define('DOCROOT', dirname(__DIR__));
}

require_once(DOCROOT . '/env.php');
require_once(DOCROOT . '/api/api_cron.php');

/*
 * Debug Mode
 */
$debug = filter_var(
    $m->get('pssm_debug'),
    FILTER_VALIDATE_BOOLEAN
);

$file = DOCROOT . '/api/croncheck.txt';

if ($debug) {
    $time = date('Y-m-d H:i:s');

    $croncheck = array(
        'time' => $time,
        'root' => DOCROOT,
        'CLI'  => CLI,
        'sapi' => php_sapi_name()
    );

    // file_put_contents($file, json_encode($croncheck) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/*
 * Load only active events.
 *
 * nextrun is the timestamp used to determine whether the event is due.
 * schedule is the schedule rule used by pssmcron::calculateSchedule().
 */
$events = $d->rows("
    SELECT
        id,
        title,
        name,
        argument,
        state,
        debug,
        updated,
        lastrun,
        nextrun,
        schedule
    FROM events
    WHERE state = 1
");

foreach ($events as $event) {

    /*
     * Calculate the delay between the last run and the next run.
     *
     * This value is only a fallback interval for the pssmcron class.
     * The actual next run time is calculated from the schedule rule.
     */
    $cronTime = 1;

    if ((int)$event->nextrun > 0 && (int)$event->lastrun > 0) {
        $cronTime = millisecsBetween(
            strftime('%Y-%m-%d %H:%M', (int)$event->nextrun),
            strftime('%Y-%m-%d %H:%M', (int)$event->lastrun),
            false
        ) / 1000 / 60;
    }

    $cronTime = max(1, (int)$cronTime);

    /*
     * Create scheduler instance.
     */
    $cron = new pssmcron(
        $cronTime,
        (int)$event->id
    );

    /*
     * Skip until event is due.
     *
     * allowAction() checks nextrun and updates lastrun/nextrun
     * when the event is allowed to execute.
     */
    if (!$cron->allowAction()) {
        continue;
    }

    $result = null;

    /*
     * Execute task.
     */
    try {

        if (isEmpty($event->argument)) {

            $script =
                DOCROOT .
                '/cron/' .
                $event->name .
                '.php';

            if (!is_file($script)) {
                _save_log(
                    'cron',
                    'error',
                    'Cron script not found: ' . $script
                );

                continue;
            }

            $result = include_once($script);

            if ($debug) {
                file_put_contents(
                    $file,
                    'script: ' . $event->name . '.php' . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }

        } else {

            if (is_callable($event->argument)) {

                $result = call_user_func(
                    $event->argument
                );

                if ($debug) {
                    file_put_contents(
                        $file,
                        'call_user_func: ' .
                        $event->argument .
                        PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                }
            } else {

                _save_log(
                    'cron',
                    'error',
                    'Invalid callback: ' .
                    $event->argument
                );

                continue;
            }
        }

        /*
         * Event Debug Logging
         */
        if ((int)$event->debug === 1) {

            $cronEvent = $cron->getEvent();

            _save_log(
                'cron',
                'info',
                $cronEvent['title']
                . ' executed at ('
                . date(
                    'F d Y H:i:s',
                    $cron->getLastExec()
                )
                . ') next execution ('
                . date(
                    'F d Y H:i:s',
                    $cron->getNextExec()
                )
                . ')'
            );
        }

        /*
         * Debug Result Logging
         */
        if ((int)$event->debug === 1 && $debug) {

            $cronEvent = $cron->getEvent();
            $resultInfo = '';

            if (
                is_array($result) &&
                isset($result['info'])
            ) {
                $resultInfo = json_encode(
                    $result['info']
                );
            } else {
                $resultInfo = json_encode($result);
            }

            _save_log(
                'cron',
                'debug',
                $cronEvent['title']
                . ' Result: '
                . $resultInfo
            );
        }

    } catch (Throwable $e) {

        _save_log(
            'cron',
            'error',
            'Cron Event [' .
            $event->id .
            '] ' .
            $event->title .
            ' failed: ' .
            $e->getMessage()
        );

        if ($debug) {
            file_put_contents(
                $file,
                'ERROR: ' .
                $e->getMessage() .
                PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
