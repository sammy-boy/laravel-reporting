<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Contracts;

use DateTimeInterface;
use SammyBoy\Reporting\Envelope\ReportKind;

/**
 * A reportable data object: something a producer hands to the Reporting
 * publisher. The implementation decides WHAT is reported (fields, event name,
 * schema version); this package decides HOW it travels.
 *
 * Instance methods throughout so one class can drive several lifecycle routing
 * keys (e.g. accounts.expense.{created,approved,voided}) from its own state.
 */
interface Reportable
{
    /**
     * Routing key, e.g. "sale.item.synced". Lower-case dotted segments.
     */
    public function eventName(): string;

    /**
     * Snapshot (current reportable state) or Event (something that happened).
     */
    public function kind(): ReportKind;

    /**
     * Positive integer. Bumped whenever the payload shape changes incompatibly.
     */
    public function schemaVersion(): int;

    /**
     * Aggregate the report is about, e.g. "sale", "expense".
     */
    public function aggregateType(): string;

    /**
     * Stable identifier of the aggregate instance within its owning service.
     */
    public function aggregateId(): string|int;

    /**
     * When the reported fact happened. Null means "at publication time".
     */
    public function occurredAt(): ?DateTimeInterface;

    /**
     * Deterministic key for eventbus-core's consumer-side duplicate guard.
     * Null lets the publisher mint a unique id per publication, which is the
     * right choice for snapshots (every re-sync must be processed).
     */
    public function idempotencyKey(): ?string;

    /**
     * The user who caused the reported fact, when known. Carried as
     * `auth_user_id` in the wrapped envelope format and as actor_id in metadata.
     */
    public function actorId(): int|string|null;

    /**
     * The wire payload. Must be JSON-serialisable and must not contain the
     * configured metadata key.
     *
     * @return array<string, mixed>
     */
    public function toReportPayload(): array;
}
