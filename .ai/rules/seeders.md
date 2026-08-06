---
paths:
  - database/seeders/CpeMapSeeder.php
---

# Seeders

## Alias extra CPE names, never alias a paid edition
A product's CVEs are often filed under a CPE name that is not the obvious one, and several names can feed one product: `elementor:elementor` carries zero CVEs while `elementor:website_builder` and `elementor:elementor_page_builder` carry the free plugin's real ones. An unmapped name is invisible — the product just reports clean — so run `cpe:variants` to find them and verify each against NVD before adding it.

The opposite is a hard no: `elementor:elementor_pro` and `wordpress:wordpress_mu` are separately released products on their own version lines. Adding either here would override the resolver's edition veto and attribute their CVEs to every base-product install.
