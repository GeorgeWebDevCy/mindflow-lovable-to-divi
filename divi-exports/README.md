# Divi 5 Imports

Use `theme-builder-global-header-footer.json` in Divi Theme Builder if you want the global header and footer assigned in one import.

Use the `layout-*.json` files inside the Divi 5 builder portability modal for each page. The standalone `layout-global-header.json` and `layout-global-footer.json` files are included if you prefer to import those layouts manually into Theme Builder areas.

All exports use the Divi 5 block format (`wp:divi/*`) and do not use legacy Divi shortcode layouts.

Suggested page slugs:

- Home: site front page
- Portfolio: `portfolio`
- 404: assign the imported layout to your not-found experience as needed
- Case study: `brand-strategy-identity`
- Case study: `social-media-campaign`
- Case study: `ecommerce-website-redesign`
- Case study: `ppc-performance-campaign`
- Case study: `email-automation-system`
- Case study: `ai-powered-ad-campaign`

Notes:

- Asset files are embedded in each JSON export, so Divi should upload and relink them during import.
- Internal links assume the homepage is the site root and the portfolio page uses `/portfolio/`.
- Shared Divi 5 variables for colors, fonts, spacing, and radii are included in each import payload.
- Layout styling uses responsive `rem`, `clamp()`, `calc()`, `var(--gcid-...)`, and `var(--gvid-...)` references instead of hardcoded theme values.
- The contact section includes a visual form replica. Replace it with a Divi Contact Form module after import if you need live submissions.
