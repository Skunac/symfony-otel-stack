# Symfony Observability POC

A small Symfony application wired end-to-end for monitoring and observability.

## Goal

Build a simple Symfony app that emits **logs, metrics, and traces**, ships them through
**OpenTelemetry**, and surfaces them in **Grafana** — with dashboards that can be filtered
**per company**. The app deliberately includes broken endpoints so there are real errors
to observe.

## Why

- Learn and demonstrate a realistic, production-style observability setup around Symfony.
- Apply best practices: clean OpenTelemetry instrumentation, request identity resolved
  once and attached everywhere, and privacy-conscious (RGPD) telemetry.
- Having intentionally failing endpoints (500s, slow responses, warnings) provides a
  steady, realistic stream of signals to monitor instead of contrived test data.

## How

The app authenticates users (Symfony Security); the authenticated user determines the
`user_id` and `company_id` that get attached to telemetry. These IDs are resolved **once**
early in the request and stamped onto **every** signal by a custom OpenTelemetry
processor, so application code stays clean.

Signals flow through the OpenTelemetry Collector, which routes and redacts before storage:

```
Symfony app ──OTLP──▶ OTel Collector ──┬──▶ Quickwit    (logs, searchable)
                                        └──▶ Prometheus  (metrics, time-series)

                       Grafana ◀── query ── Quickwit + Prometheus
```

- **OpenTelemetry** — instrumentation and standardization layer (logs, metrics, traces).
- **OTel Collector** — central router; also strips automatically captured PII.
- **Quickwit** — log storage and search.
- **Prometheus** — metrics store.
- **Grafana** — visualization with a per-company (`$company`) dashboard filter.

For privacy, only stable `user_id` and `company_id` are attached deliberately; anything
PII-shaped that instrumentation captures automatically (IPs, query params, headers) is
stripped at the Collector.

See [`PLAN.md`](./docs/PLAN.md) for the phased implementation roadmap.