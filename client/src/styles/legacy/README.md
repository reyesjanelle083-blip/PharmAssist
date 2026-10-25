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
| `branches.css` | `plugins/branches.css` | nothing (the whole file) |
| `carousel.css` | `plugins/carousel.css` | the `@import` and the page-wide `*` and `body` rules; the old `.card` rules are prefixed with `.carousel-container` so they do not clash with the medicine cards |
| `guide.css` | the `<style>` block in `guide.php`, lines 34-89 | the text-to-speech bar, floating buttons and guide modal; wrapped in `.legacy-guide` |
| `cart.css` | the `<style>` block in `cart.php` | the "reserve by piece, 10 pieces or box" options, the text-to-speech buttons, the floating buttons and the guide modal; nested under `.legacy-cart` |
| `notifications.css` | the `<style>` block in `user_notif.php` (the customer's "Reservation Notifications" page, used for My Reservations) | the text-to-speech bar, guide modal and floating buttons; nested under `.legacy-notif` |
| `auth.css` | `plugins/style.css` (login and register) | nested in `.legacy-auth` (the pages shared one document with the shell); the hot-linked background photo URL was removed (the gradient stays) |
| `auth-register.css` | the `<style>` block in `register.php` | nested in `.legacy-auth` |

Bootstrap 5.3.8 (CSS only), Bootstrap Icons 1.13.1, Boxicons 2.0.7 and Font Awesome 6.4.0 (CSS and fonts) come from npm, as on the old pages.
The load order in `src/index.css` follows the old pages (page stylesheets, then Bootstrap, then the inline styles).

The About and Contact dialogs copy the look (colours, sizes, corners) of the old guide modal into `../app-extra.css` under the class `legacy-dialog`: the old
class names (`modal-content` and so on) are Bootstrap's own and would clash.

Everything the app adds is outside this folder: `../app.css` (accessibility, notices, the clashes between old stylesheets),
`../contrast.css` (the high contrast theme) and `../responsive.css` (phone rules).
