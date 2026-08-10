# Archive — superseded engineering documents

Nothing here is current. Nothing here was deleted.

Every file in this directory carries a banner at the top saying what superseded it and
why. They are kept because they record what was true when they were written, and because
the reasoning in them is often still useful even where the numbers are not.

**The single authoritative engineering document is
[`../../RELEASE_HANDOFF.md`](../../RELEASE_HANDOFF.md) at the repository root.** Where
anything here disagrees with it, the handoff is correct.

| Directory | What is in it |
|---|---|
| [`RELEASE_HANDOFF-v1.0.0-evidence.md`](RELEASE_HANDOFF-v1.0.0-evidence.md) | The previous release handoff, unedited. The detailed evidence trail behind v1.0.0: per-area certification runs, the finalization-pass defect table, environment matrices, and the reasoning behind the artifact-identity discipline. |
| [`handoffs/`](handoffs/) | Every earlier handoff, from the step-numbered era through to the 2026-08-07 resume note. |
| [`roadmaps/`](roadmaps/) | Pre-1.0 roadmaps. The forward plan is now `RELEASE_HANDOFF.md` §13. |
| [`reports/`](reports/) | Point-in-time UX and environment reports for work that has shipped. |
| [`submission/`](submission/) | WordPress.org submission notes describing artifacts that are now superseded. |
| [`certifications/`](certifications/) | V1 and release-candidate certifications, and the WordPress.org compliance report. |

## Two traps in these documents

**Artifact checksums.** Several files record a SHA256 or Content-ID for "the" package.
Every one of them except the current value in `RELEASE_HANDOFF.md` §2 describes a build
that has been superseded. A checksum here matching a file on your disk does **not** mean
that file is the product.

**Plugin Check counts.** Figures like "302 → 164 errors" are scans of the whole working
tree, which includes `tests/`, `sdk/php/` and `examples/` — none of which are packaged.
The authoritative number is the one measured against the extracted ZIP: **0 errors, 824
warnings**.
