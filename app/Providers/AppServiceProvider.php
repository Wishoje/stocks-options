<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->subject('Reset your GexOptions password')
                ->greeting('Hey!')
                ->line('We received a request to reset your password.')
                ->action('Reset Password', $url)
                ->line("If you didn't request this, you can ignore this email.");
        });

        $this->registerSchedulerMonitoring();
        $this->registerQueueMonitoring();
    }

    protected function registerSchedulerMonitoring(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            Log::channel('scheduler')->info('scheduler.task.starting', $this->scheduledTaskContext($event->task));
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            Log::channel('scheduler')->info('scheduler.task.finished', array_merge(
                $this->scheduledTaskContext($event->task),
                [
                    'runtime_seconds' => round((float) $event->runtime, 2),
                    'exit_code' => $event->task->exitCode,
                ]
            ));
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            Log::channel('scheduler')->debug('scheduler.task.skipped', $this->scheduledTaskContext($event->task));
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            Log::channel('scheduler')->error('scheduler.task.failed', array_merge(
                $this->scheduledTaskContext($event->task),
                [
                    'exception' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ]
            ));
        });
    }

    protected function registerQueueMonitoring(): void
    {
        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::channel('queue_monitor')->warning('queue.busy', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            Log::channel('queue_monitor')->warning('queue.job.exception', array_merge(
                $this->queueJobContext($event->job, $event->connectionName),
                [
                    'exception' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ]
            ));
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::channel('queue_monitor')->error('queue.job.failed', array_merge(
                $this->queueJobContext($event->job, $event->connectionName),
                [
                    'exception' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                ]
            ));
        });
    }

    protected function scheduledTaskContext(object $task): array
    {
        return [
            'name' => $task->description,
            'summary' => $task->getSummaryForDisplay(),
            'expression' => $task->getExpression(),
            'timezone' => (string) ($task->timezone ?: config('app.timezone')),
            'mutex_name' => $task->mutexName(),
        ];
    }

    protected function queueJobContext(object $job, ?string $connectionName = null): array
    {
        $context = [
            'connection' => $connectionName,
            'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
            'job_name' => method_exists($job, 'resolveName') ? $job->resolveName() : null,
            'job_id' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
            'attempt' => method_exists($job, 'attempts') ? $job->attempts() : null,
        ];

        if (method_exists($job, 'uuid')) {
            $context['uuid'] = $job->uuid();
        }

        return array_filter($context, fn ($value) => ! is_null($value));
    }
}
