# SiteRadian WordPress.org assets

The directory-ready asset set lives in `wordpress-org-assets/`. These files belong in
the WordPress.org SVN repository's top-level `assets/` directory, alongside `trunk/`
and `tags/`; they are intentionally excluded from the installable plugin ZIP.

The filenames and dimensions follow the current WordPress.org plugin asset guidance:

| File | Dimensions | Purpose |
|---|---:|---|
| `icon.svg` | scalable | Primary directory icon |
| `icon-128x128.png` | 128 x 128 | Raster icon fallback |
| `icon-256x256.png` | 256 x 256 | High-density icon fallback |
| `banner-772x250.png` | 772 x 250 | Standard directory banner |
| `banner-1544x500.png` | 1544 x 500 | High-density directory banner |
| `screenshot-1.png` | 1440 x 1000 | Home dashboard and governed-operation flow |
| `screenshot-2.png` | 1440 x 1000 | Connections and assistant selection |
| `screenshot-3.png` | 1440 x 1000 | Access setup before credential creation |
| `screenshot-4.png` | 1440 x 1000 | Approvals workflow |
| `screenshot-5.png` | 1440 x 1000 | Changes, audit trail, and rollback surface |
| `screenshot-6.png` | 1440 x 1000 | Protection settings |

The screenshots are captures of a real SiteRadian 1.0.0 install on WordPress 7.1.
Screenshot 3 deliberately shows the pre-credential form: no token or secret appears in
the image. Captions are maintained in the `readme.txt` Screenshots section.

Source-only design material lives under `design/` and is not submitted with the plugin
ZIP. Regenerate the real product screenshots with
`scripts/capture-wordpress-org-screenshots.mjs`; the required login values are provided
only through process-local environment variables and are never persisted by the script.

Reference: <https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/>
