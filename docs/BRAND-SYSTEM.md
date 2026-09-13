# SiteRadian brand system

SiteRadian is the master brand. “The AI Command Center for WordPress” is its category descriptor, not part of the product name. The primary value proposition is “Give AI a safer way to work on your site.”

## Logo concept

The mark combines an open control boundary, a bounded inner arc, a measured radius, and two command points. It represents controlled reach and precise scope without using a shield, lock, brain, robot, gear, sparkle, or WordPress mark.

Runtime artwork lives in `assets/brand/`:

- `wpcc-logo.svg` and `wpcc-logo-dark.svg`: horizontal SiteRadian lockups
- `wpcc-mark.svg` and `wpcc-mark-dark.svg`: standalone light/dark marks
- `wpcc-mark-mono.svg`: one-colour mark
- `wpcc-admin-16.svg` and `wpcc-admin-20.svg`: small-size admin masters

The `wpcc` filenames remain internal compatibility identifiers. They are not public-facing brand copy.

## Interface tokens

- Primary text/navy: `#14213D`
- Brand indigo: `#4055D5`
- Dark-surface signal indigo: `#8B9BFF`
- Primary surface: `#FFFFFF`
- Sunken surface: `#F8FAFC`
- Subtle border: `#E2E7EF`
- Success: `#11662F` on `#EDF8F1`
- Warning: `#996800` on `#FCF3E6`
- Danger: `#B32D2E` on `#FCF0F1`
- Focus: 2px `#4055D5` outline with a 2px offset

Typography uses the WordPress/system sans-serif stack. The spacing rhythm is based on 4px, with 8, 12, 16, 20, 24, 32, and 40px steps. Controls use a 6px radius; cards use 10–12px; pills use a fully rounded radius. Shadows are reserved for overlays rather than ordinary cards.

Primary buttons use brand indigo; secondary buttons use a white surface and visible border; destructive actions retain WordPress-aligned red. Statuses combine text, shape, and labels so color is never the only signal.

## WordPress.org assets

Directory upload files live in `wordpress-org-assets/`, outside the runtime ZIP. Source artwork lives in `design/wordpress-org/` and is also excluded from the ZIP. WordPress.org SVN expects these assets in its top-level `/assets` directory, alongside `trunk` and `tags`.

- `icon.svg`, `icon-128x128.png`, `icon-256x256.png`
- `banner-772x250.png`, `banner-1544x500.png`
- `screenshot-1.png` through `screenshot-6.png`, captured from the real packaged plugin

Do not add feature lists or small text to the icon/banner. Do not use the dark-surface logo on a light surface, or the light-surface logo on a dark surface.
