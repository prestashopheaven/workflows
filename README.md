# PrestaHaven workflows

Reusable GitHub workflows for the monorepos of the PrestaHaven package collection. The rules themselves live in
`prestashopheaven/build-tools` (`ps-module mono`), which every monorepo installs. The plan, the
monorepos and the rules are in the workspace (`docs/packages/`).

| File | What |
|---|---|
| `.github/workflows/mono-ci.yml` | CI of a monorepo: rules, `composer check`, PHP syntax per version, real-shop tests through a fixture module |
| `.github/workflows/mono-prepare.yml` | First job of a release: reads `Release X.Y.Z - <notes>` and checks version, changelog and tag |
| `.github/workflows/mono-release.yml` | Tag and GitHub release, split of every package to its read-only repo (deploy key per repo), Packagist |

## A monorepo uses them like this

`.github/workflows/ci.yml`:

```yaml
name: CI
on:
  push:
  pull_request:
  workflow_call:
jobs:
  ci:
    uses: prestashopheaven/workflows/.github/workflows/mono-ci.yml@main
```

`.github/workflows/release.yml`:

```yaml
name: Release
on:
  push:
    branches: [main]
permissions:
  contents: write
jobs:
  prepare:
    uses: prestashopheaven/workflows/.github/workflows/mono-prepare.yml@main
  checks:
    needs: prepare
    if: needs.prepare.outputs.release == 'true'
    uses: ./.github/workflows/ci.yml
  release:
    needs: [prepare, checks]
    uses: prestashopheaven/workflows/.github/workflows/mono-release.yml@main
    with:
      version: ${{ needs.prepare.outputs.version }}
      notes: ${{ needs.prepare.outputs.notes }}
    secrets: inherit
```

Secrets of the monorepo: `SPLIT_KEY_<KEY>` per package (the private deploy key of its split repo) and
`PACKAGIST_USERNAME` / `PACKAGIST_TOKEN`. `ps-module mono register` creates the split repos, the deploy keys and the
secrets.
