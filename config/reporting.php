<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exchange routing
    |--------------------------------------------------------------------------
    | Every reporting message is published to a RabbitMQ topic exchange through
    | uzapoint/eventbus-core, with the contract's event name as routing key.
    |
    | `exchange` is the default for this service (a producer normally owns one
    | exchange, e.g. sales.events). `exchanges` overrides it per event name;
    | keys are exact names or trailing-wildcard patterns such as "invoice.*".
    | Publishing an event that resolves to no exchange throws
    | UnroutableReportException rather than guessing.
    */
    'exchange' => env('REPORTING_EXCHANGE'),

    'exchanges' => [
        // 'invoice.snapshot.synced' => 'sales.events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Envelope
    |--------------------------------------------------------------------------
    | eventbus-core delivers only the `data` part of its envelope to handlers,
    | so reporting metadata (kind, schema_version, aggregate, correlation id)
    | travels inside `data` under `metadata_key`. Payload fields are never
    | allowed to collide with that key.
    |
    | `format` controls how the contract payload is framed inside `data`:
    |
    |   flat     data = { ...payload, _reporting: {...} }
    |            Matches producers whose consumers read fields directly
    |            (sales, products, stocks, support, production).
    |
    |   wrapped  data = { auth_user_id: <actor>, payload: { ...payload, _reporting: {...} } }
    |            Matches producers whose consumers destructure
    |            (?int $auth_user_id, array $payload) — the accounts convention.
    |
    | `formats` overrides the format per event name (same matching rules as
    | `exchanges`), for services that publish both styles.
    */
    'envelope' => [
        'metadata_key' => '_reporting',
        'format' => env('REPORTING_ENVELOPE_FORMAT', 'flat'),
        'formats' => [
            // 'accounts.expense.*' => 'wrapped',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    | `after_commit`: when a publish is requested inside a database transaction
    | it is deferred until that transaction commits, and discarded if it rolls
    | back. Outside a transaction it publishes immediately. This prevents a
    | report describing data that never committed. It does not protect against
    | a broker failure after commit; that failure surfaces as a
    | ReportPublishFailedException to the caller of DB::transaction().
    |
    | `connection`: the database connection whose transaction state is
    | observed; null means the default connection.
    */
    'publish' => [
        'after_commit' => true,
        'connection' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    | Correlation ids are read from the incoming HTTP request header named by
    | eventbus-core's `eventbus.tracing.header` (X-Correlation-ID by default),
    | so the two packages share one tracing vocabulary. When no id is present
    | and `generate_if_missing` is true, a UUID is generated once per request
    | lifecycle and reused for every report published in it.
    */
    'tracing' => [
        'generate_if_missing' => true,
    ],

];
