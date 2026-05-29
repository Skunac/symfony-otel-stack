# Implementation Plan

Phased roadmap for the Symfony Observability POC. This covers the **development track**;
the **devops track** (Kubernetes via Terraform + Ansible, no ArgoCD) is planned after the
dev work is validated locally.

See [`README.md`](../README.md) for the project goal and overview.

---

## Core pattern — identity tagging

The principle is **set once, attach everywhere**:

1. A `kernel.request` listener resolves the authenticated user and their company once,
   early, and stores the IDs in a request-scoped holder (and OTel baggage).
2. A custom OTel processor (span + log-record) reads that context and stamps `enduser.id`
   and `company.id` onto every signal automatically.
3. Controllers never pass identity around manually.
4. Logs (Quickwit) and metrics (Prometheus) carry the same `company` value, so Grafana's
   `$company` dropdown filters both consistently.

Notes:
- Resolve identity at `kernel.request` (early), not `kernel.terminate` (too late).
- Normal 500s (thrown exceptions) are logged after identity is set, so they are tagged.
- Edge cases degrade to `unknown` instead of disappearing: hard PHP fatals (captured as
  raw container stderr), pre-auth errors, and non-HTTP contexts (CLI / workers — set
  identity at job start from the payload).
- Span attributes do not inherit to children → identity lives in baggage, re-read per
  signal by the processor.

---

## RGPD posture

- Attach deliberately and minimally: only `user_id` and `company_id`.
  - A user ID is pseudonymised personal data (re-linkable via DB) → RGPD still applies:
    enforce retention limits and access control.
  - A company ID is generally not personal data (except sole traders).
- The real risk is auto-captured data: client IP, full URLs with query params, headers,
  bodies, and PII inside exception messages / stack traces.
- Two controls:
  1. Conservative instrumentation (no header/body capture; strip query strings, keep the
     route template like `/users/{id}`).
  2. Collector-side redaction as the central enforcement point (drop/hash IP and
     PII-shaped fields before backends). Config-driven, no app rebuild.

> Not legal advice — engineering guidance for a privacy-conscious POC.

---

## Phases

### Phase 1 — App, domain & authentication
- Minimal Symfony app.
- Entities: `Company`, `User` and `Task` (a user belongs to a company; a task is
  linked to a user and a company). `User` implements the Symfony Security user
  interface.
- Symfony Security: a simple login — best-practice auth, no custom crypto.
- Fixtures: seed a couple of companies and a few users with stable IDs.
- Endpoints: some working, plus several broken on purpose:
  - throws an uncaught exception (clean 500),
  - deliberately slow,
  - triggers a PHP warning/notice,
  - returns a 4xx.
- **Done when**: you can log in as different users (in different companies) and hit each
  endpoint to get the intended behavior.

### Phase 2 — Request context
- `kernel.request` listener resolves the authenticated user and company into a
  request-scoped holder.
- Defensive fallback to `unknown` for unauthenticated / pre-auth requests.
- **Done when**: the holder reliably carries the right IDs and degrades gracefully.

### Phase 3 — OpenTelemetry instrumentation
- Install the OTel PHP SDK / Symfony bundle.
- Resource attributes: `service.name`, `service.version`, `deployment.environment`.
- Conservative auto-instrumentation: strip query strings, no header/body capture.
- Custom span + log-record processors stamping `enduser.id` and `company.id` from context.
- Bridge Monolog → OTel so app logs export as OTLP.
- **Done when**: a request to a broken endpoint produces a log and a trace carrying both
  IDs, exported over OTLP.

### Phase 4 — Log / traffic generation
- Use the broken endpoints plus a generator (Symfony console command, or k6 / Artillery)
  to drive varied traffic: mixed endpoints, status codes, latencies, log levels.
- **Done when**: you can produce a steady, varied stream of logs and errors on demand.

### Phase 5 — Local observability stack (docker-compose)
- Spin up locally: OTel Collector, Quickwit, Prometheus, Grafana.
- Collector pipelines: logs → Quickwit, metrics → Prometheus.
- Grafana datasources + per-company dashboard with a `$company` template variable
  (populated via `label_values(company)` or the Quickwit equivalent).
- Local only — not the kube cluster — but the Collector config and Grafana dashboards
  carry over almost unchanged to the devops phase.
- **Done when**: generated traffic appears in Grafana, filtered by company.

### Phase 6 — Validation & RGPD check
- End-to-end pass: tagged logs in Quickwit, metrics in Prometheus, dashboard filters per
  company, `unknown` fallback behaves.
- Add Collector redaction (strip IP, hash/drop PII-shaped fields); confirm no IPs,
  URLs-with-params, or PII-in-stack-traces reach the backends.
- Set retention windows in Quickwit and Prometheus.
- **Done when**: the pipeline is clean and demonstrably RGPD-conscious.

---

## Then: devops track (planned later)

- Provision the Kubernetes control plane with Terraform + Ansible.
- Deploy the known-good Collector / Prometheus / Grafana configs proven locally.
- No ArgoCD (the app is not permanently online).

---

## Decisions to lock early

- **Canonical company identifier**: pick a stable slug or ID (names change); use it
  everywhere.
- **Attribute naming**: OTel semantic conventions where they exist (`enduser.id`);
  namespace custom ones (`company.id` / `company.slug`).
- **Identity timing**: resolve at `kernel.request`; optional per-request summary log at
  `kernel.terminate`.