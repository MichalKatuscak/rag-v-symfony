<?php

declare(strict_types=1);

namespace App\Indexing;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Pro měření: worker skončí, jakmile je fronta prázdná (messenger:consume v Symfony 8.1 nemá --stop-when-empty).
 * Zapíná se proměnnou prostředí WORKER_STOP_WHEN_IDLE=1, v provozu worker běží dál.
 */
#[AsEventListener(priority: 10)] // dřív než PostgreSqlNotifyOnIdleListener, který na NOTIFY čeká až minutu
final class StopWorkerWhenIdleListener
{
    public function __invoke(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && '1' === getenv('WORKER_STOP_WHEN_IDLE')) {
            $event->getWorker()->stop();
        }
    }
}
