<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Queue\Events\JobFailed;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Laravel\Facade as Sentry;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\State\Scope;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

final class SentryTelemetry
{
    private static bool $queueJobActive = false;

    private static bool $capturingTerminalQueueFailure = false;

    public static function sampleTrace(SamplingContext $context): float
    {
        $request = app()->bound('request') ? request() : null;
        if ($request !== null && $request->is('up', 'health/*', 'api/v1/webhooks', 'api/v1/webhooks/*')) {
            return 0.0;
        }

        $rate = config('sentry.traces_sample_rate', 0.05);
        if (! is_numeric($rate) || ! is_finite((float) $rate)) {
            return 0.0;
        }

        // Deliberately do not inherit public caller sampling flags. Each root
        // request is bounded by this deployment's configured local rate.
        return max(0.0, min(1.0, (float) $rate));
    }

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        if (self::$queueJobActive
            && ! self::$capturingTerminalQueueFailure
            && $event->getType() === EventType::event()
            && $event->getExceptions() !== []) {
            // Laravel reports a failed attempt before deciding whether the
            // worker will retry it. Only the terminal JobFailed event below
            // owns queue exception capture.
            return null;
        }

        return self::sanitizeEvent($event);
    }

    public static function sanitizeEvent(Event $event): ?Event
    {
        if (! in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
            return null;
        }

        $isTransaction = $event->getType() === EventType::transaction();
        $sanitized = $isTransaction
            ? Event::createTransaction($event->getId())
            : Event::createEvent($event->getId());

        $sanitized
            ->setTimestamp($event->getTimestamp())
            ->setStartTimestamp($event->getStartTimestamp())
            ->setLevel($event->getLevel())
            ->setRelease($event->getRelease())
            ->setEnvironment($event->getEnvironment())
            ->setTransaction(self::safeTransactionName())
            ->setTags(self::safeTags($event))
            ->setRequest(self::safeRequest($event))
            ->setBreadcrumb([])
            ->setUser(null)
            ->setExtra([])
            ->setExceptions(self::sanitizeExceptions($event->getExceptions()))
            ->setStacktrace(self::sanitizeStacktrace($event->getStacktrace()))
            ->setSpans(self::sanitizeSpans($event->getSpans()));

        if (! $isTransaction) {
            $sanitized->setMessage('Application error');
        }

        $trace = self::safeTraceContext($event->getContexts()['trace'] ?? []);
        if ($trace !== []) {
            $sanitized->setContext('trace', $trace);
        }

        $sanitized->setAttachments([]);

        return $sanitized;
    }

    public static function beginQueueJob(): void
    {
        self::$queueJobActive = true;
        self::$capturingTerminalQueueFailure = false;
    }

    public static function clearQueueJob(): void
    {
        self::$queueJobActive = false;
        self::$capturingTerminalQueueFailure = false;
    }

    public static function recordTerminalFailure(JobFailed $event): void
    {
        self::$capturingTerminalQueueFailure = true;

        try {
            Sentry::withScope(static function (Scope $scope) use ($event): void {
                $scope->setTag('service', self::serviceName());
                $scope->setTag('failure.kind', 'queue.terminal');

                $jobType = class_basename($event->job->resolveName());
                $scope->setTag('job.type', preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,79}$/', $jobType) === 1 ? $jobType : 'UnknownJob');

                Sentry::captureException($event->exception);
            });
        } finally {
            self::clearQueueJob();
        }
    }

    public static function captureDatabaseUnavailable(string $sqlstate): void
    {
        if (preg_match('/^[A-Z0-9]{5}$/', $sqlstate) !== 1) {
            return;
        }

        Sentry::withScope(static function (Scope $scope) use ($sqlstate): void {
            $scope->setTag('service', self::serviceName());
            $scope->setTag('alert.kind', 'db.unavailable');
            $scope->setTag('db.sqlstate', $sqlstate);

            Sentry::captureMessage(
                'Database operation unavailable.',
                $sqlstate === '57014' ? Severity::warning() : Severity::error(),
            );
        });
    }

    private static function serviceName(): string
    {
        $service = config('services.sentry.service', 'api');
        $allowed = ['api', 'queue', 'scheduler', 'ai', 'whatsapp-worker'];

        return is_string($service) && in_array($service, $allowed, true) ? $service : 'api';
    }

    /** @return array<string, string> */
    private static function safeTags(Event $event): array
    {
        $tags = ['service' => self::serviceName()];

        foreach ($event->getTags() as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if ($key === 'failure.kind' && $value === 'queue.terminal') {
                $tags[$key] = $value;
            } elseif ($key === 'alert.kind' && $value === 'db.unavailable') {
                $tags[$key] = $value;
            } elseif ($key === 'db.sqlstate' && preg_match('/^[A-Z0-9]{5}$/', $value) === 1) {
                $tags[$key] = $value;
            } elseif ($key === 'job.type' && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,79}$/', $value) === 1) {
                $tags[$key] = $value;
            }
        }

        return $tags;
    }

    /** @return array<string, string> */
    private static function safeRequest(Event $event): array
    {
        $source = $event->getRequest();
        $method = strtoupper((string) ($source['method'] ?? (app()->bound('request') ? request()->method() : '')));
        $safe = [];

        if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $safe['method'] = $method;
        }

        $route = self::routeTemplate();
        if ($route !== null) {
            $safe['url'] = $route;
        }

        return $safe;
    }

    private static function safeTransactionName(): string
    {
        $route = self::routeTemplate();
        if ($route !== null) {
            $method = app()->bound('request') ? strtoupper(request()->method()) : 'REQUEST';
            if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
                $method = 'REQUEST';
            }

            return $method.' '.$route;
        }

        return match (self::serviceName()) {
            'queue', 'whatsapp-worker' => 'queue.job',
            'scheduler' => 'scheduler.run',
            'ai' => 'ai.stream',
            default => 'background',
        };
    }

    private static function routeTemplate(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $route = request()->route();
        if ($route === null || ! method_exists($route, 'uri')) {
            return null;
        }

        $uri = trim((string) $route->uri(), '/');
        if ($uri === '' || preg_match('/^[A-Za-z0-9_./:{}-]{1,180}$/', $uri) !== 1) {
            return null;
        }

        return '/'.$uri;
    }

    /** @param array<string, mixed> $context
     *  @return array<string, string>
     */
    private static function safeTraceContext(array $context): array
    {
        $safe = [];
        foreach (['trace_id' => 32, 'span_id' => 16, 'parent_span_id' => 16] as $key => $length) {
            $value = $context[$key] ?? null;
            if (is_string($value) && strlen($value) === $length && ctype_xdigit($value)) {
                $safe[$key] = strtolower($value);
            }
        }

        return $safe;
    }

    /** @param ExceptionDataBag[] $exceptions
     *  @return ExceptionDataBag[]
     */
    private static function sanitizeExceptions(array $exceptions): array
    {
        $safe = [];
        foreach ($exceptions as $exception) {
            $type = preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $exception->getType()) === 1
                ? $exception->getType()
                : 'Throwable';
            $sanitized = new ExceptionDataBag(new \RuntimeException());
            $sanitized->setType($type)->setValue($type);

            $stacktrace = self::sanitizeStacktrace($exception->getStacktrace());
            if ($stacktrace !== null) {
                $sanitized->setStacktrace($stacktrace);
            }

            $safe[] = $sanitized;
        }

        return $safe;
    }

    private static function sanitizeStacktrace(?Stacktrace $stacktrace): ?Stacktrace
    {
        if ($stacktrace === null || $stacktrace->getFrames() === []) {
            return null;
        }

        $frames = [];
        foreach ($stacktrace->getFrames() as $frame) {
            $path = str_replace('\\', '/', $frame->getFile());
            $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
            $file = str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
            $frames[] = new Frame($frame->getFunctionName(), $file, $frame->getLine(), null, null, [], $frame->isInApp());
        }

        return new Stacktrace($frames);
    }

    /** @param Span[] $spans
     *  @return Span[]
     */
    private static function sanitizeSpans(array $spans): array
    {
        $safe = [];
        foreach ($spans as $span) {
            $op = $span->getOp();
            $safeOp = is_string($op) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $op) === 1 ? $op : 'span';
            $context = SpanContext::make()
                ->setTraceId($span->getTraceId())
                ->setSpanId($span->getSpanId())
                ->setParentSpanId($span->getParentSpanId())
                ->setStartTimestamp($span->getStartTimestamp())
                ->setEndTimestamp($span->getEndTimestamp())
                ->setSampled($span->getSampled())
                ->setStatus($span->getStatus())
                ->setOp($safeOp)
                ->setDescription($safeOp);

            $safe[] = new Span($context);
        }

        return $safe;
    }
}
