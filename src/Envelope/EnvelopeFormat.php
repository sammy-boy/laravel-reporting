<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Envelope;

/**
 * How a report payload is framed inside eventbus-core's `data`.
 */
enum EnvelopeFormat: string
{
    /** data = { ...payload, <metadata_key>: {...} } */
    case Flat = 'flat';

    /** data = { auth_user_id: actor, payload: { ...payload, <metadata_key>: {...} } } */
    case Wrapped = 'wrapped';
}
