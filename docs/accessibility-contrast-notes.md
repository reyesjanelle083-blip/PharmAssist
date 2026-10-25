# Accessibility notes: where the old default look fails WCAG 2.x AA

PharmAssist's default look is the old PharmAssist look, kept as it was because the project owner asked for the old look to be restored
faithfully. Its colours are therefore not changed. The accessible alternative is the **High contrast** button (black, white and yellow,
`client/src/styles/contrast.css`), which is built to pass AA (most text is above 7:1). This list is for the paper's limitations section.

Ratios are computed from the colours in the old stylesheets (WCAG relative luminance). AA needs 4.5:1 for normal text, 3:1 for large text
(24 px, or 18.66 px bold) and 3:1 for the borders and icons of controls.

| Element | Colours | Ratio | Needs | Result |
|---|---|---|---|---|
| Sidebar link names; the white buttons (Reserve, Search, Use my location, hero buttons, Sign in) | white on `#7393A7` | 3.25 | 4.5 | fails |
| Hero title (3.5 rem, bold) | `#7393A7` on `#E8ECF1` | 2.74 | 3 (large text) | fails |
| Hero paragraph (20 px) | `#6C737E` on `#E8ECF1` | 4.03 | 4.5 | fails |
| Card prescription line (12 px); "Sign up here" link (12 px) | `#7393A7` on white | 3.25 | 4.5 | fails |
| Card category line | `#8faab8` on white | 2.44 | 4.5 | fails |
| Search bar placeholder | `#9AA6B2` on `#E8ECF1` | 2.09 | 4.5 | fails |
| Search status chip (14 px) | `#7393A7` on `#E8ECF1` | 2.74 | 4.5 | fails |
| Green Search button | white on `#84B179` | 2.46 | 4.5 | fails |
| Search banner, right half | white on `#B5CFD8` | 1.63 | 3 (large text) | fails |
| Footer text (15 px) | `#4e6a7b` on `#B5CFD8` | 3.51 | 4.5 | fails |
| Hints and small print (12 px) | `#999` on white | 2.85 | 4.5 | fails |
| Upload file name (12 px) | `#27ae60` on white | 2.87 | 4.5 | fails |
| Input placeholders | `#757575` on `#ededed` | 3.94 | 4.5 | fails |
| Search bar border; menu icon on the top bar (non-text) | `#7393A7` on `#E8ECF1`; `#E8ECF1` on `#7393A7` | 2.74 | 3 | fails |
| Pill input background (non-text) | `#ededed` on white | 1.17 | 3 | fails |
| Login title (24 px); floating button icons | `#7393A7` on white; white on `#7393A7` | 3.25 | 3 (large text, icons) | passes |

Also in the old design: no visible keyboard focus style (fixed in the new client without changing the mouse look), and the old form inputs are
about 30 px tall, under the 44 px touch target recommended for this project.

Added in the polish step: the carousel's slide dots are the old ones (inactive `#ccc` on `#E8ECF1` is 1.35:1 as a non-text control, the active `#007bff` is 3.35:1).
The new top bar location button and privacy note use dark text (`#2d3f50`) so they stay readable on the pale right end of the bar. All of the above are covered by the High contrast mode.
