# Releasing PHProxy

The release image is `miglen/phproxy`. The Dockerfile pins PHP 8.5.12 and its
multi-platform base digest. Update both together when refreshing the base;
`--pull` alone cannot upgrade a pinned digest.

Read the [2026-10-11 security review](security-review.md) before publishing: the
tested images still have base-package CVE findings despite passing application checks.

## Before publishing

1. Update the application version, release notes and relevant documentation.
2. Run the regression scripts in `tests/` and inspect the image smoke checks.
3. Review the final image and its vulnerability scan results. CI build/smoke
   success is not a vulnerability scan.
4. Confirm the intended release version and Docker Hub destination.

The `checks` workflow runs syntax checks and regression tests on PHP 8.1/8.5.
It separately builds and starts Apache on Linux AMD64 and ARM64 with networking
disabled, verifies the homepage/assets and checks the baked application's ownership.

## Publish through GitHub Actions

Configure repository or `dockerhub` environment secrets:

- `DOCKERHUB_USERNAME`: an account allowed to push to `miglen/phproxy`.
- `DOCKERHUB_TOKEN`: a Docker Hub access token with push permission.

Protect the `dockerhub` environment with required reviewers if release approval
is required for this repository. Actions are pinned to commit SHAs; review their
updates alongside the base image.

After approval, either push a stable version tag such as `v1.4.0`, or run
**publish Docker Hub image** manually for the intended Git ref and version.
Manual runs update `latest` only when that checkbox is selected; stable version
tag pushes also update `latest`.

The workflow reruns all checks, then publishes:

- `miglen/phproxy:1.4.0` (the selected version)
- `miglen/phproxy:sha-<full-commit-sha>`
- `miglen/phproxy:latest` when selected as described above

Each release contains AMD64 and ARM64 images, OCI metadata, an SBOM and build
provenance. The workflow summary records the resulting immutable image digest.
Use that digest in deployments when exact image immutability is required; registry
tags can be overwritten. Do not reuse an application version for different code.

## Docker Hub overview

Copy [dockerhub.md](dockerhub.md) into the overview of
[the Docker Hub repository](https://hub.docker.com/r/miglen/phproxy) when publishing.
This file is the overview source; the workflow does not alter the repository
metadata. Review the rendered install commands and links after updating it.

## Local verification

```sh
docker compose config --quiet
docker compose -f docker-compose.yml -f compose.dev.yml config --quiet
docker build --pull -t phproxy:release-check .
docker run --rm --network none --entrypoint php phproxy:release-check \
  -l /var/www/html/index.php
```

Use [Docker's multi-platform workflow documentation](https://docs.docker.com/build/ci/github-actions/multi-platform/)
and [attestation documentation](https://docs.docker.com/build/ci/github-actions/attestations/)
when changing the release workflow. No secrets belong in Docker build arguments.
