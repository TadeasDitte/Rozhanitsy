---
paths:
  - app/Services/VulnerabilityRangeBuilder.php
  - app/Services/NvdCpeResolver.php
  - app/Services/NvdCveMapper.php
---

# Services

## Range identity is matchCriteriaId, never raw_cpe
NVD repeats one identical `criteria` string across every affected maintenance branch of a CVE, separating branches only by their version bounds (CVE-2022-21661 has 22 copies of the same WordPress CPE). Keying the range upsert on the CPE string collapsed all of them onto one row and deleted the rest on the next rebuild, so only the newest branch could ever match.

Identity is `(group_index, clause_index, matchCriteriaId)`, with a fallback to the CPE plus its bounds when NVD sends no id. Never reintroduce raw_cpe as an identity component, and never assume one CPE string means one range.

## Never fuzzy match an edition onto its base product
`elementor_pro` scores 0.891 against `elementor` and `wordpress_mu` scores 0.914 against `wordpress`, both over the 0.87 threshold — shared prefixes dominate similar_text. Both were learned as fuzzy mappings and produced thousands of false findings.

fuzzyMatch() discards any candidate whose words are the CPE's words plus or minus one, before scoring. Compare whole words, not substrings: `woo-commerce` vs `woocommerce` must still match. A learned fuzzy row is served by the exact lookup forever, so tightening this policy also needs `cpe:prune-variants` run against existing data.

## CVSS: take the Primary entry, and take all four fields from it
NVD lists its own analysis (`type: Primary`) and the CNA's (`Secondary`) in the same metric family in no guaranteed order. Reading `metrics[family][0]` took the CNA's score on 52 of 639 sampled CVEs — CVE-2024-31211 published as 5.5 MEDIUM against NVD's 9.8 CRITICAL.

Select one entry, prefer Primary, fall back to the first only when NVD published no analysis, and read score/vector/version/severity from that single entry (CVE-2024-2121's two entries share a score but disagree on the vector). Do not add cvssMetricV40 to the family order: NVD publishes it alongside V31, and 4.0 base scores are not comparable to 3.1 ones.
