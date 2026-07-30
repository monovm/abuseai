# REST API Reference

## Authentication

The API supports two authentication methods via the `ApiKeyAuth` middleware:

### X-API-Key Header

Send your API key in the `X-API-Key` header. Keys are assigned to `Reporter` records and stored as bcrypt hashes in the database.

```
X-API-Key: your-api-key-here
```

### Bearer Token (Sanctum)

Use a Laravel Sanctum personal access token as a Bearer token. The token's user is automatically linked to a `Reporter` record (created if needed).

```
Authorization: Bearer your-sanctum-token
```

If a reporter is blocked (`is_blocked = true`), both authentication methods return `403`.

---

## Rate Limiting

All API endpoints are rate-limited to **60 requests per minute** per API key (or per IP if unauthenticated). The limit uses a Redis sliding window.

When the limit is exceeded, the API returns:

```json
HTTP 429 Too Many Requests
Retry-After: 42

{
    "error": "Rate limit exceeded",
    "retry_after": 42
}
```

---

## Endpoints

### POST /api/v1/reports

Submit a single abuse report. Supports JSON body or ARF (RFC 5965) format.

**Content-Type Detection:**
- `application/json` -> JSON processing
- `message/feedback-report`, `message/rfc822`, or any type containing `report` -> ARF parsing

#### JSON Request

```
POST /api/v1/reports
Content-Type: application/json
X-API-Key: your-api-key
```

```json
{
    "abuse_type": "spam",
    "target_ip": "192.0.2.10",
    "target_domain": "example.com",
    "target_url": "https://example.com/phishing-page",
    "description": "Received unsolicited bulk email from this IP",
    "evidence": "Full email headers and body here...",
    "reported_at": "2026-04-06T12:00:00Z",
    "headers": {
        "X-Mailer": "PHPMailer",
        "Received": "from 192.0.2.10 ..."
    }
}
```

**Validation Rules:**

| Field | Rules |
|---|---|
| `abuse_type` | Required. One of: `spam`, `phishing`, `malware`, `ddos`, `csam`, `copyright`, `fraud`, `law_enforcement`, `brute_force`, `intrusion`, `botnet`, `other` |
| `target_ip` | Optional. Valid IP address |
| `target_domain` | Optional. String, max 255 chars |
| `target_url` | Optional. Valid URL, max 2048 chars |
| `description` | Required. String, max 10,000 chars |
| `evidence` | Optional. String, max 50,000 chars |
| `reported_at` | Optional. ISO 8601 date |
| `headers` | Optional. JSON object |

**JSON Response (201 Created):**

```json
{
    "id": "9e1a2b3c-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
    "case_number": "ABU-2026-00042",
    "message": "Report received. Case: ABU-2026-00042"
}
```

If the target IP is not in the system's IP inventory, the report is still stored but may not generate a case:

```json
{
    "id": "9e1a2b3c-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
    "case_number": null,
    "message": "Report received"
}
```

#### ARF Request

```
POST /api/v1/reports
Content-Type: message/feedback-report
X-API-Key: your-api-key

[RFC 5965 ARF body]
```

**ARF Response (201 Created):**

```json
{
    "id": "9e1a2b3c-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
    "message": "ARF report received",
    "parsed_type": "spam"
}
```

---

### POST /api/v1/reports/bulk

Submit up to 100 reports in a single request.

```
POST /api/v1/reports/bulk
Content-Type: application/json
X-API-Key: your-api-key
```

```json
{
    "reports": [
        {
            "abuse_type": "spam",
            "description": "Spam from 192.0.2.10",
            "target_ip": "192.0.2.10"
        },
        {
            "abuse_type": "phishing",
            "description": "Phishing page hosted on example.com",
            "target_domain": "example.com"
        }
    ]
}
```

**Validation Rules:**

| Field | Rules |
|---|---|
| `reports` | Required. Array, max 100 items |
| `reports.*.abuse_type` | Required. String (valid abuse type) |
| `reports.*.description` | Required. String, max 10,000 chars |
| `reports.*.target_ip` | Optional. Valid IP address |
| `reports.*.target_domain` | Optional. String, max 255 chars |

**Response (201 Created):**

```json
{
    "count": 2,
    "ids": [
        "9e1a2b3c-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
        "a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d"
    ],
    "message": "Reports received"
}
```

---

### GET /api/v1/cases/{number}

Retrieve the status of a case by its case number.

```
GET /api/v1/cases/ABU-2026-00042
X-API-Key: your-api-key
```

**Response (200 OK):**

```json
{
    "case_number": "ABU-2026-00042",
    "status": "investigating",
    "abuse_type": "spam",
    "severity_level": "medium",
    "created_at": "2026-04-06T12:00:00+00:00",
    "updated_at": "2026-04-06T14:30:00+00:00"
}
```

**Response (404 Not Found):**

```json
{
    "message": "Not found."
}
```

**Status values:** `open`, `investigating`, `actioned`, `resolved`, `closed`

**Severity levels:** `low`, `medium`, `high`, `critical`

---

### POST /api/v1/webhook/{provider}

Receive webhook callbacks from external abuse feed providers.

**Supported providers:** `abuseipdb`, `spamhaus`, `google_fbl`, `microsoft_fbl`, `spamcop`, `generic_arf`

```
POST /api/v1/webhook/abuseipdb
Content-Type: application/json
X-Signature: <hmac-sha256-signature>

{
    ...provider-specific payload...
}
```

**Signature Verification:**

The webhook controller verifies HMAC signatures when a secret is configured for the provider in `config/abusedesk.php`. Signatures are checked in these headers (in order):

1. `X-Signature`
2. `X-Hub-Signature-256`
3. `X-Webhook-Signature`

Both raw hex and prefixed formats (`sha256=...`, `sha1=...`) are accepted. If no secret is configured for the provider, signature verification is skipped.

**Response (202 Accepted):**

```json
{
    "message": "Webhook received"
}
```

The payload is queued as a `ProcessWebhookReport` job on the `ingestion` queue for async processing.

**Error Responses:**

| Code | Body | Condition |
|---|---|---|
| 404 | `{"error": "Unknown provider"}` | Provider not in supported list |
| 403 | `{"error": "Invalid signature"}` | HMAC verification failed |

---

### POST /api/v1/inbound-email

Receive raw email content for processing as an abuse report. Used for MTA pipe or email forwarding integrations.

```
POST /api/v1/inbound-email
Content-Type: message/rfc822

[raw email body]
```

**Response (202 Accepted):**

```json
{
    "message": "Email received"
}
```

**Note:** This endpoint does not require API key authentication. The raw email is queued for processing via the `email_inbound` provider on the `ingestion` queue.

---

## Error Codes

| HTTP Code | Meaning |
|---|---|
| 201 | Report(s) created successfully |
| 202 | Webhook/email accepted for async processing |
| 400 | Bad request (empty body, malformed input) |
| 401 | Authentication required (no API key or Bearer token) |
| 403 | Invalid API key, invalid token, blocked reporter, or invalid webhook signature |
| 404 | Resource not found (case number or provider) |
| 422 | Validation error (invalid fields) or unparseable ARF |
| 429 | Rate limit exceeded |

**Validation Error Response (422):**

```json
{
    "message": "The abuse type field is required.",
    "errors": {
        "abuse_type": [
            "The abuse type field is required."
        ]
    }
}
```
