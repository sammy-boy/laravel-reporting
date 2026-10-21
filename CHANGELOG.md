# Changelog

All notable changes to `sammy-boy/laravel-reporting` are documented here. The format follows Keep a Changelog; versions follow SemVer.

## [1.0.0] - 2026-10-13

### Added
- `Reporting` publisher with `publish()`, `snapshot()` and `event()`, returning the built `ReportEnvelope`.
- `Reportable` contract interface and `ReportKind` enum (snapshot / event).
- `ReportEnvelope` with `flat` and `wrapped` transport framing and `fromTransportData()` decoding; `IncomingReport` for consumers, covering contracted and legacy (schema 0) messages.
- `EventBusTransport` adapter over `uzapoint/eventbus-core` `RabbitMQService`, mapping the report `event_id` to the bus `idempotency_key`.
- Exchange and envelope-format resolution from configuration with exact and trailing-wildcard event-name matching.
- `CorrelationId` scoped service reading `eventbus.tracing.header`, generating when missing.
- After-commit deferral of publication via the connection's transaction manager; rollback discards.
- `ReportPublishing`, `ReportPublished`, `ReportPublishFailed` events.
- `InvalidReportException`, `UnroutableReportException`, `ReportPublishFailedException` (carries envelope and exchange).
- `Reporting::fake()` with `FakeTransport` assertions and `failWith()`.
- Test suite: unit, Laravel feature, and integration through the real `RabbitMQService::publish()` and `EventProcessor::process()`.
