# Old PharmAssist stylesheets

These files are copies of the old site's CSS (`old/PharmAssist/`), so the new React pages look like the old pages. The rules inside
are the old rules, unchanged. Each file starts with a comment that says where it came from and what was left out.

| File | Copied from | What was left out or changed |
|------|-------------|------------------------------|
| `sidebar.css` | `plugins/sidebar.css` | the Google Fonts `@import` (fonts are self-hosted with `@fontsource`) |
| `medicines.css` | `plugins/medicines.css` lines 1-224, 271-388, 476-516, 555-576 and the card rules of the responsive queries | text-to-speech, voice search, medicine scanner and branch toggle styles (removed features or not in this step) |
| `footer.css` | `plugins/footer.css` | the Google Fonts `@import` |
| `footer-inline.css` | the `<style>` block in `footer.php` | nothing |
| `homepage.css` | `plugins/homepage.css` | the `@import`; wrapped in `.legacy-home` so its `h1` font rule stays on the home page |
| `homepage-inline.css` | the `<style>` block in `homepage.php`: search box, page wrapper, floating buttons | the guide and about modals, the text-to-speech bar, the ID status banners |
| `auth.css` | `plugins/style.css` (login and register) | nested in `.legacy-auth` (the pages shared one document with the shell); the hot-linked background photo URL was removed (the gradient stays) |
| `auth-register.css` | the `<style>` block in `register.php` | nested in `.legacy-auth` |

Bootstrap 5.3.8 (CSS only), Bootstrap Icons 1.13.1, Boxicons 2.0.7 and Font Awesome 6.4.0 (CSS and fonts) come from npm, as on the old pages.
The load order in `src/index.css` follows the old pages (page stylesheets, then Bootstrap, then the inline styles).

Everything the app adds is outside this folder: `../app.css` (accessibility, notices, the clashes between old stylesheets),
`../contrast.css` (the high contrast theme) and `../responsive.css` (phone rules).
