# TODO: choose a licence

Keelson ships **without a `LICENSE` file**, deliberately. The terms of use are the
owner's decision and nobody has made it, so nothing here may be read as a grant or
a restriction of rights: absent a licence, the default is "all rights reserved".

This file exists so the gap is tracked in `docs/` instead of being remembered. It is
**not** a licence and must not be renamed to `LICENSE`, copied into one, or turned
into a one-line placeholder: a wrong or ambiguous licence text is worse than none.

## What is blocked on the decision

- A `LICENSE` file at the repository root (or `LICENSE.md`), which GitHub and most
  package registries read automatically.
- `"license"` in `backend/composer.json` (currently `"proprietary"`) and in the
  `frontend/` and `e2e/` `package.json` files (currently unset), which should agree
  with the chosen terms.
- The "Licence" entry in [../limitations.md](../limitations.md) and the licence line
  in [../../README.md](../../README.md), if the README gains one.

## What an owner has to decide

1. Which licence: a permissive one (MIT, Apache-2.0, BSD-3-Clause), a copyleft one
   (AGPL-3.0, GPL-3.0), or a commercial/proprietary licence with its own text.
2. Whether the demo data, the screenshots and the documentation fall under the same
   terms as the code.
3. Whether a CLA or DCO is required before outside contributions, which changes
   `CONTRIBUTING.md`.

Once decided, add the file **and** update the three places above in the same commit;
a licence that contradicts `composer.json` is worse than a documented absence.
