# WP Command Center — Documentation

Documentation for **version 1.0.1**. Every document here describes the shipped product and
is verified against it; where a number appears (42 tools, 23 capabilities, 3 protection
modes) it was read from the running plugin, not from memory.

---

## Start here

| You want to… | Read |
|---|---|
| **Pick up the project (start here)** | **[../PROJECT_STATUS.md](../PROJECT_STATUS.md)** — three-minute overview: current release, blocking work, resume checklist |
| **Then: the engineering detail** | [../RELEASE_HANDOFF.md](../RELEASE_HANDOFF.md) — the single authoritative handoff |
| Understand what this is | [OVERVIEW.md](OVERVIEW.md) |
| Connect an assistant in five minutes | [QUICKSTART.md](QUICKSTART.md) |
| Install, upgrade, uninstall | [INSTALLATION.md](INSTALLATION.md) |

## Reference

| Document | Covers |
|---|---|
| [SECURITY.md](SECURITY.md) | Tokens, scopes, capabilities, protection modes, destructive guard, audit, redaction |
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the pieces fit; data model; multisite policy; extension points |
| [MCP.md](MCP.md) | The MCP interface: handshake, tools, resources, response shapes, undo |
| [API.md](API.md) | The REST API and its error codes |
| [OPERATIONS.md](OPERATIONS.md) | All 42 operations, their actions, risk tiers and aliases |
| [CAPABILITIES.md](CAPABILITIES.md) | The 23 capabilities and how to scope a token |
| [AI-INTEGRATIONS.md](AI-INTEGRATIONS.md) | Supported clients and their configuration |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Symptoms and fixes |
| [RELEASE.md](RELEASE.md) | Superseded — release process now lives in [../RELEASE_HANDOFF.md](../RELEASE_HANDOFF.md) §7–§9 |

## Certification

| Document | Purpose |
|---|---|
| [ASSISTANT-CERTIFICATION.md](ASSISTANT-CERTIFICATION.md) | The twelve-step manual MCP certification checklist — **the next phase** |
| [WORDPRESS-ORG-REVIEWER-NOTES.md](WORDPRESS-ORG-REVIEWER-NOTES.md) | Notes for the wordpress.org reviewer |
| [SLUG-REPLY.md](SLUG-REPLY.md) | Prepared reply to the wordpress.org slug email |

The V1 and release-candidate certifications, the WordPress.org compliance report and the
2026-08-02 certifications have moved to [`archive/certifications/`](archive/certifications/).
They certify artifacts that the 2026-08-10 security re-cut superseded, and each carries a
banner saying so. The authoritative Plugin Check result — **0 errors, 828 classified warnings against
the extracted package** — is in [../RELEASE_HANDOFF.md](../RELEASE_HANDOFF.md) §2.

## Archive

[`archive/`](archive/) holds every superseded handoff, roadmap, report, submission note and
certification. Nothing was deleted; everything carries a superseded banner.

## Historical record — read the directory README first

None of these describe the current product. Each directory explains what its contents are
still good for, and warns that every invariant in them (tool counts, DB version, plugin
version) is superseded.

| Directory | What it holds |
|---|---|
| [`product/`](product/README.md) | Programme and phase records, session handoffs, strategy notes — **why** decisions were made |
| [`governance/`](governance/README.md) | The design, audit and validation record of the rollback engine — read before changing undo behaviour |
| [`reports/`](reports/README.md) | Step-level audits and validation reports |
| [`architecture/`](architecture/) | Two point-in-time specifications (`AI-CERTIFICATION`, `WPCC-STEP-104-SPEC`). The current architecture is `../RELEASE_HANDOFF.md` §4. |
| [`archive/`](archive/README.md) | Formally superseded handoffs, roadmaps, submission notes and certifications, each individually bannered |

---

**Note:** `docs/` is not shipped in the plugin ZIP. End-user documentation that ships is
`readme.txt`.
