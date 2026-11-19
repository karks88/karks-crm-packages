This folder holds the WordPress.org **plugin directory listing** assets (icon,
banner, screenshots, Live Preview blueprint) for karks-crm-packages. It is not
part of the plugin itself and is excluded from releases via `.distignore`.

The `deploy-wporg.yml` GitHub Actions workflow copies this folder's contents
into the plugin's SVN `assets/` folder on every tagged release.

Add files here using WordPress.org's expected names/sizes:

- `icon-128x128.png` and `icon-256x256.png` (or a single `icon.svg`)
- `banner-772x250.png` and `banner-1544x500.png` (retina)
- `screenshot-1.png`, `screenshot-2.png`, ... — numbered to match the
  `== Screenshots ==` section in `readme.txt` (each numbered caption there
  corresponds to the same-numbered file here)

All images should be `.png` or `.jpg` (or `.svg`/`.gif` for the icon only).

`blueprints/blueprint.json` powers the "Live Preview" button. It installs
Karks CRM and this add-on from WordPress.org, then fires
`kcrm_run_demo_seeder` and `kcrmpkg_run_demo_seeder` (see
`includes/class-kcrmpkg-demo-seeder.php`) so the preview opens on sample
packages. Live Preview is switched on in the plugin's Advanced settings on
WordPress.org, and only works once the plugin is published there.
