# Security Policy

Abuse AI handles abuse reports, customer data, evidence files, and infrastructure
credentials. Vulnerabilities in this codebase can lead to data exfiltration,
unauthorized customer suspensions, or evidence tampering. We take security
issues seriously.

## Supported Versions

Only the `main` branch receives security fixes. If you operate a fork or a
pinned release, you are responsible for backporting fixes yourself.

| Version       | Supported          |
|---------------|--------------------|
| `main`        | :white_check_mark: |
| Older tags    | :x:                |

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of these private channels instead:

1. **GitHub Security Advisories** (preferred) —
   <https://github.com/monovm/abuseai/security/advisories/new>
2. **Email** — `security@abuseai.io` (please include "ABUSEAI SECURITY" in
   the subject line so it's not filtered).

When reporting, include:

- A description of the vulnerability and its impact.
- Reproduction steps, ideally with a minimal proof-of-concept.
- The commit hash or release version you tested against.
- Any suggested mitigations you've already considered.

## Scope

In scope:

- The application code under `app/`, `routes/`, `resources/`, `config/`,
  `database/`.
- The default Horizon, queue, scheduler, and webhook configuration.
- The public abuse report form (`/report`) and reporter API (`/api/v1/*`).
- The admin Livewire UI (`/admin/*`) and customer portal (`/portal/*`).

Out of scope:

- Vulnerabilities in third-party services we integrate with (Mandrill,
  WHMCS, Virtualizor, AbuseIPDB, etc.) — please report to the vendor.
- Self-inflicted misconfigurations (e.g. running with `APP_DEBUG=true` in
  production, leaking your own `.env`, weak admin passwords).
- Issues that require operator-level access already (you are the admin).
- Denial-of-service against your own deployment.

## Disclosure Timeline

- We aim to acknowledge reports within **3 business days**.
- We aim to ship a fix or mitigation within **30 days** for high-severity
  issues, **90 days** for lower-severity issues.
- We coordinate public disclosure with reporters. By default, we will
  publish a GitHub Security Advisory once a fix is available, crediting
  the reporter unless they prefer anonymity.

## Safe Harbor

If you make a good-faith effort to comply with this policy, we will not
pursue legal action against you for security research that:

- Avoids privacy violations, destruction of data, and degradation of
  services for users other than your own test instance.
- Stops as soon as you have demonstrated the issue (no further exfiltration,
  no persistence, no lateral movement).
- Is reported to us privately as described above.

Thank you for helping keep Abuse AI and its users safe.
