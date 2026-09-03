# Aimee Global 1.5.4 test report

- Plugin version raised from 1.5.3 to 1.5.4.
- All PHP files passed `php -l` syntax validation.
- `black_lingerie_mirror_selfie_01` and `park_throwback_18_01` are present in the fallback catalogue.
- External `catalog.json` entries override matching fallback entries while fallback-only entries remain available.
- Missing protected assets are searched by exact filename in `_wp_attached_file`, standard `uploads/YYYY/MM`, and the uploads root.
- A missing source or failed copy is written to the PHP error log rather than failing silently.
- The Aimee Global settings page includes a per-user private-media diagnostic and repair control.
- No database schema changes.
