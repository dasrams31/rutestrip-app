# ALTCHA widget (vendored)

- Package: `altcha` 3.7.0 from npm (https://www.npmjs.com/package/altcha)
- File: `altcha.min.js` = `dist/main/altcha.min.js` from the package, unmodified
  (sha256 `8471d1c1c99591501f89731c8cb7ef6d10d770c2d1fb739ed52418378b48e56e`)
- License: MIT, Copyright (c) 2023-2026 Daniel Regeci, BAU Software s.r.o. (see `LICENSE.txt`)
- Project: https://altcha.org, source https://github.com/altcha-org/altcha

Self-hosted so the register page loads no third-party script. It is loaded as an
ES module by `public/js/altcha-init.js`, which also registers the Indonesian
strings. The server side (challenge + verification) is `app/Services/AltchaService.php`;
it issues ALTCHA v1 challenges (`SHA-256`, `salt?expires=...`), which the v3 widget
still solves and answers with a v1 payload.

To update: `npm pack altcha@<version>`, copy `package/dist/main/altcha.min.js` and
`package/LICENSE.txt` here, then bump the `?v=` query in `public/js/altcha-init.js`.
