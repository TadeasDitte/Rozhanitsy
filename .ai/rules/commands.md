---
paths:
  - app/Console/Commands/SyncNvdVulnerabilities.php
---

# Commands

## Never decode an NVD page whole
nvd:sync reads the response body through App\Services\NvdPageReader, which streams the payload and decodes one CVE at a time. Do not replace it with $response->json(): a full 2000-CVE page is ~7 MB on the wire and peaks past 50 MB decoded (~7x), which is what forced sources.page_size down to 200 once already.

Because of the reader, page_size is a throughput/rate-limit knob, not a memory one — peak memory tracks the largest single CVE (~170 KB) regardless of page size. The seeder ships 2000, NVD's per-page maximum.
