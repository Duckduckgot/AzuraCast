<?php

namespace App\Sync;

use App\Entity;
use App\Entity\Repository\SettingsTableRepository;
use App\Environment;
use App\Event\GetSyncTasks;
use App\EventDispatcher;
use App\LockFactory;
use Monolog\Logger;

/**
 * The runner of scheduled synchronization tasks.
 */
class Runner
{
    protected Logger $logger;

    protected Entity\Settings $settings;

    protected SettingsTableRepository $settingsTableRepo;

    protected LockFactory $lockFactory;

    protected EventDispatcher $eventDispatcher;

    public function __construct(
        SettingsTableRepository $settingsRepo,
        Entity\Settings $settings,
        Logger $logger,
        LockFactory $lockFactory,
        EventDispatcher $eventDispatcher
    ) {
        $this->settingsTableRepo = $settingsRepo;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->lockFactory = $lockFactory;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function runSyncTask(string $type, bool $force = false): void
    {
        // Immediately halt if setup is not complete.
        if (!$this->settings->isSetupComplete()) {
            die('Setup not complete; halting synchronized task.');
        }

        $allSyncInfo = $this->getSyncTimes();

        if (!isset($allSyncInfo[$type])) {
            throw new \InvalidArgumentException(sprintf('Invalid sync task: %s', $type));
        }

        $syncInfo = $allSyncInfo[$type];

        set_time_limit($syncInfo['timeout']);

        if (Environment::getInstance()->isCli()) {
            error_reporting(E_ALL & ~E_STRICT & ~E_NOTICE);
            ini_set('display_errors', '1');
            ini_set('log_errors', '1');
        }

        $this->logger->notice(sprintf('Running sync task: %s', $syncInfo['name']));

        $lock = $this->lockFactory->createLock('sync_' . $type, $syncInfo['timeout']);

        if ($force) {
            $this->lockFactory->clearQueue('sync_' . $type);
            try {
                $lock->acquire($force);
            } catch (\Exception $e) {
                // Noop
            }
        } elseif (!$lock->acquire()) {
            return;
        }

        try {
            $event = new GetSyncTasks($type);
            $this->eventDispatcher->dispatch($event);

            $tasks = $event->getTasks();

            foreach ($tasks as $taskClass => $task) {
                if (!$lock->isAcquired()) {
                    return;
                }

                $start_time = microtime(true);

                $task->run($force);

                $end_time = microtime(true);
                $time_diff = $end_time - $start_time;

                $this->logger->debug(sprintf(
                    'Timer "%s" completed in %01.3f second(s).',
                    $taskClass,
                    round($time_diff, 3)
                ));
            }

            $this->settings->updateSyncLastRunTime($type);
            $this->settingsTableRepo->writeSettings($this->settings);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return mixed[]
     */
    public function getSyncTimes(): array
    {
        $shortTaskTimeout = $_ENV['SYNC_SHORT_EXECUTION_TIME'] ?? 600;
        $longTaskTimeout = $_ENV['SYNC_LONG_EXECUTION_TIME'] ?? 1800;

        $syncs = [
            GetSyncTasks::SYNC_NOWPLAYING => [
                'name' => __('Now Playing Data'),
                'contents' => [
                    __('Now Playing Data'),
                ],
                'timeout' => $shortTaskTimeout,
                'interval' => 15,
            ],
            GetSyncTasks::SYNC_SHORT => [
                'name' => __('1-Minute Sync'),
                'contents' => [
                    __('Song Requests Queue'),
                ],
                'timeout' => $shortTaskTimeout,
                'interval' => 60,
            ],
            GetSyncTasks::SYNC_MEDIUM => [
                'name' => __('5-Minute Sync'),
                'contents' => [
                    __('Check Media Folders'),
                ],
                'timeout' => $shortTaskTimeout,
                'interval' => 300,
            ],
            GetSyncTasks::SYNC_LONG => [
                'name' => __('1-Hour Sync'),
                'contents' => [
                    __('Analytics/Statistics'),
                    __('Cleanup'),
                ],
                'timeout' => $longTaskTimeout,
                'interval' => 3600,
            ],
        ];

        foreach ($syncs as $task => &$sync_info) {
            $sync_info['latest'] = $this->settings->getSyncLastRunTime($task);
            $sync_info['diff'] = time() - $sync_info['latest'];
        }

        return $syncs;
    }
}
