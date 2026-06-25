# PROGRAM-5B — Phase F: Safety Mode UX

## Baseline (post-5A)
Security Mode (Access → Security Mode) already had: 3-mode chooser, a posture banner (warning in Developer / success otherwise), a RECOMMENDED badge on Client, a `confirm()` before choosing Developer, and an audit event on change.

## 5B enhancements — make consequences obvious
1. **Developer mode is now flagged in red** with a **"NOT FOR CLIENT SITES"** badge.
2. **Consequence-led, plain-language copy** for Developer mode: *"No approval step: AI can change or delete things on this site immediately, with no review. Use only on your own development or staging site. (Audit trail and undo still work, but there is no gate to stop a change before it happens.)"* — replaces the softer "recommended during development" framing that undersold the risk.
3. **Client mode remains the recommended path** (RECOMMENDED badge + success banner) — the safe default for a design partner / live client site.
4. **Accidental self-approval prevented** (retained from 5A): selecting Developer + saving triggers a blocking `confirm()`; every change is audited (`security.mode.changed {from,to,self_approve}`).

## Trust improvements
- The copy now names the *real* consequence ("change or delete things… immediately") instead of abstract "no approval gate."
- It is honest that audit + undo still work in Developer mode, but clarifies the missing thing: a *gate before the change happens*.
- Enterprise mode retained unchanged (max oversight) — not removed; the mission emphasized Developer/Client but Enterprise remains valid for compliance users.

## Safety invariants preserved
- Mode only changes on explicit submit; default unchanged (`developer`); program never sets the option itself; `SecurityModeManager` logic untouched; access gated by the page's `manage_options`.

## Validation
- `php -l` clean.
- `test-usability-5b.sh` §7 → not-for-client flag, plain-language consequence, Client recommended, confirm guard — all green.
- `test-security-modes.sh` 28/0 · `test-security-mode-validation.sh` 27/0 (behavior intact).

**Phase F: GREEN.**
