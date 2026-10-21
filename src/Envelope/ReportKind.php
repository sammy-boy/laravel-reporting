<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Envelope;

enum ReportKind: string
{
    /** Current reportable state of an aggregate; consumers upsert. */
    case Snapshot = 'snapshot';

    /** Something that happened; consumers apply a delta or append. */
    case Event = 'event';
}
