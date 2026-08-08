# WP Command Center — Documentation

Documentation for **version 1.0.0**. Every document here describes the shipped product and
is verified against it; where a number appears (42 tools, 23 capabilities, 3 protection
modes) it was read from the running plugin, not from memory.

---

## Start here

| You want to… | Read |
|---|---|
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
| [RELEASE.md](RELEASE.md) | Invariants, build, test tiers, compliance, lifecycle certification |

## Certification

| Document | Purpose |
|---|---|
| [V1-RELEASE-CERTIFICATION.md](V1-RELEASE-CERTIFICATION.md) | The V1 release certification |
| [V1-CLOSEOUT-CERTIFICATION.md](V1-CLOSEOUT-CERTIFICATION.md) | Closeout: zero open items |
| [WORDPRESS-ORG-COMPLIANCE-REPORT.md](WORDPRESS-ORG-COMPLIANCE-REPORT.md) | Plugin Check findings and their resolution |
| [WORDPRESS-ORG-REVIEWER-NOTES.md](WORDPRESS-ORG-REVIEWER-NOTES.md) | Notes for the wordpress.org reviewer |

Earlier certification reports (`CERTIFICATION-2026-08-02.md`,
`FINAL-CERTIFICATION-2026-08-02.md`, `FINAL-RC-CERTIFICATION.md`,
`V1-FINALIZATION-REPORT.md`) are retained as a record of the release programme. They are
**superseded** — where they disagree with the documents above, the documents above are
correct.

## Internal

`product/` and `architecture/` hold strategy notes, specifications and session handoffs.
They are working documents, not product documentation, and may lag the shipped product.

---

**Note:** `docs/` is not shipped in the plugin ZIP. End-user documentation that ships is
`readme.txt`.
