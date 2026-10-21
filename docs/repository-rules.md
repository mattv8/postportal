# Repository rule activation

The JSON files in `.github/rulesets/` are templates. They do not change GitHub settings and must be activated only after their prerequisites have landed on `main`.

## Prerequisites

1. Merge `CODEOWNERS`, the `CI` workflow, and the rule templates to `main`.
2. Open a normal pull request and record the final merge-candidate SHA, the successful `gate` check context, and GitHub Actions app ID `15368`.
3. Resolve any GitHub CODEOWNERS validation errors before applying either template. A required check must be configured from this observed context, not guessed from a workflow filename.

## Safe activation

1. Immediately before changing rules, export or screenshot the current repository rulesets, branch settings, merge methods, and auto-merge setting. Retain unrelated settings.
2. Apply both templates together: `main-required.json` provides the no-bypass pull request, strict `gate`, deletion, non-fast-forward, and merge-only requirements; `main-review.json` provides the code-owner review requirement.
3. Read back each ruleset and compare it with the templates. Confirm the required template has no bypass actors and the review template has only User `9312603` (`mattv8`) with `exempt` bypass mode.
4. Validate with an intended owner pull request: native auto-merge without approval may proceed only after green CI. A failed or missing gate and a direct push must block. Do not create a synthetic merge or deployment to perform this validation.

`exempt` is actor-based. It can let the owner merge another person's pull request or a later external-writer commit without a review, and GitHub does not emit a special bypass audit record. Regular merge history remains the audit trail.

## Write-access trust boundary

Only `mattv8` currently has Write or administrator access. Repository review rules do not secure workflows, secrets, or runners from a future untrusted Write collaborator: that collaborator could alter branch workflows before `main` review. Before granting untrusted Write access, use a main-restricted protected publishing Environment and restrict access to credentialed self-hosted runners. `CODEOWNERS` alone is not sufficient for this boundary. This repository does not claim those Environment or runner restrictions are currently configured.

## Emergency recovery

If `gate` is renamed or unavailable, do not create a standing CI exemption. An administrator must make a deliberate, audited settings change: snapshot current rules, temporarily adjust the affected required context, restore a working observed context after the workflow fix lands, and capture fresh readback evidence. No CI, pull-request structure, deletion, or force-push exemption is permitted. A settings administrator can change settings; the no-bypass policy protects the normal merge path, not a hostile administrator.

The publisher's stale-SHA guard rejects a run if a newer `main` commit exists. The newer commit supersedes that run and cannot be promoted until it has passed CI; this is not a CI bypass.

The CI migration is logically required before enforcement. These templates are not evidence that rules are live, a pull request was opened, or an image was published.

Keep future runtime/CVE changes and governance changes in separate pull-request file groups: runtime images and their tests first, then `CODEOWNERS`, CI, rule templates, and this runbook. Apply enforcement only after the governance group is merged and verified on `main`.

The legacy `.github/actions/cleanup` action is intentionally unused and unchanged. Registry retention remains an administrator follow-up, not an unreviewed deletion-policy implementation.
