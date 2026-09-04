# Watchtower Configuration Reference

## Configuration Summary by Event Type

| Event Type            | Sampling                                           | Filtering                                                                    | Redaction                 |
| --------------------- | -------------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------- |
| **Requests**          | `WATCHTOWER_REQUEST_SAMPLE_RATE`, Route middleware | Not applicable                                                               | Headers, payload, URL, IP |
| **Commands**          | `WATCHTOWER_COMMAND_SAMPLE_RATE`, Event listener   | Not applicable                                                               | Command arguments         |
| **Queries**           | Parent context                                     | `rejectQueries()`, `WATCHTOWER_IGNORE_QUERIES`                               | SQL statement             |
| **Cache**             | Parent context                                     | `rejectCacheKeys()`, `rejectCacheEvents()`, `WATCHTOWER_IGNORE_CACHE_EVENTS` | Cache key                 |
| **Jobs**              | Parent context, Queue::before                      | `rejectQueuedJobs()`                                                         | Not applicable            |
| **Mail**              | Parent context                                     | `rejectMail()`, `WATCHTOWER_IGNORE_MAIL`                                     | Subject                   |
| **Notifications**     | Parent context                                     | `rejectNotifications()`, `WATCHTOWER_IGNORE_NOTIFICATIONS`                   | Not applicable            |
| **Outgoing Requests** | Parent context                                     | `rejectOutgoingRequests()`, `WATCHTOWER_IGNORE_OUTGOING_REQUESTS`            | URL                       |
| **Exceptions**        | `WATCHTOWER_EXCEPTION_SAMPLE_RATE`                 | Not applicable                                                               | Exception message         |

---

## Production Recommendations

### High-Traffic Applications

```bash
# Conservative sampling
WATCHTOWER_REQUEST_SAMPLE_RATE=0.01          # 1% of requests
WATCHTOWER_COMMAND_SAMPLE_RATE=0.1           # 10% of commands
WATCHTOWER_EXCEPTION_SAMPLE_RATE=1.0         # Always capture exceptions

# Filter noisy events
WATCHTOWER_IGNORE_CACHE_EVENTS=true
WATCHTOWER_IGNORE_QUERIES=true               # Or filter specific queries programmatically
```

### Privacy-Conscious Applications

```bash
# Disable sensitive data collection
WATCHTOWER_CAPTURE_REQUEST_PAYLOAD=false
WATCHTOWER_REDACT_HEADERS=Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN

# Or use redaction in AppServiceProvider
```

### Balanced Configuration (Recommended Start)

```bash
# Sample rates
WATCHTOWER_REQUEST_SAMPLE_RATE=0.1
WATCHTOWER_COMMAND_SAMPLE_RATE=1.0
WATCHTOWER_EXCEPTION_SAMPLE_RATE=1.0

# Filter obvious noise programmatically
# Redact PII as needed
```

---

## Verification Checklist

After configuration:

- [ ] Sampling rates appropriate for traffic volume
- [ ] Noisy events filtered (cache, certain queries)
- [ ] Sensitive data redacted (PII, tokens, credentials)
- [ ] Exceptions always captured for debugging
- [ ] Test in development with `WATCHTOWER_REQUEST_SAMPLE_RATE=1.0`
- [ ] Monitor event quota usage in Watchtower dashboard

---

## Common Patterns

### Filter Health Checks + Reduce Sampling

```php
Route::get('/health', fn() => ['status' => 'ok'])
    ->middleware(Sample::never());
```

### Exclude Internal/Vendor Queries

```php
Watchtower::rejectQueries(fn($q) =>
    str_contains($q->sql, 'telescope') ||
    str_contains($q->sql, 'pulse')
);
```

### Protect User Data in Cache Keys

```php
Watchtower::redactCacheEvents(fn($e) =>
    $e->key = preg_replace('/user:\d+/', 'user:***', $e->key)
);
```
