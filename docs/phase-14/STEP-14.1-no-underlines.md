# Step 14.1 — No underlined links

Every link in the application — staff screens, public site, sign-in pages, blog articles — is now free of underlines: at rest, on hover, on focus and once visited.

- `resources/css/app.css`: the base `a:hover` no longer underlines, and an unlayered, `!important` rule (`a, a:hover, a:focus, a:active, a:visited { text-decoration: none !important; }`) sits after the layers so no utility class or future markup can bring one back. Compiled: `a,a:active,a:focus,a:hover,a:visited{text-decoration:none!important}`.
- 41 view files lost their `hover:underline` / `no-underline` / `[&_a]:underline` classes (article body links use `font-medium` instead).
- Links still read as links through colour (brand blue), the hover colour change and the keyboard focus ring (unchanged). Note for the record: WCAG 1.4.1 prefers a non-colour cue for links inside running text; the brand-blue links keep a 4.5:1 contrast, and in-text links in articles are medium-weight.
- `NoLinkUnderlinesTest` fails if any view mentions `underline` again or if the compiled stylesheet loses the rule.
