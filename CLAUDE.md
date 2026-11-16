# Claude Development Guide — mbuzz-php

## CRITICAL rules

- **NEVER put client-specific values in a product repo.** No customer name, domain, CSS class, selector, event name, ID, tag, URL, key or anything else that belongs to one client — not as a default, not in a constant, not in a test, not in a comment, not "just for now". mbuzz, the SDKs and the WordPress plugin are client-agnostic conduits: the site owner supplies those values through settings, config or a filter, and the code ships with none. Client specifics live only in `lib/clients/<client>/` in the mbuzz repo and in the client's own settings. If a request is for one client, build the general mechanism and put the client's values in config. (2026-11-14: the WordPress plugin shipped the LMI booking-widget selectors and the `booking_opened` event name as hardcoded defaults in 0.8.2/0.8.3. The user caught it, not the tests.)
