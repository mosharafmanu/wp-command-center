# PROGRAM-6S — AI Dashboard Design

The screen now opens as a **dashboard**, readable in seconds.

## Hero (gradient, branded)
- Title "AI Connections" + one-line purpose ("Your AI control panel… AI stays off until you add a key").
- **Setup readiness ring** (0–100, conic-gradient) — honest, derived: a connection (+30), a default (+25), a healthy test (+30), no connections needing attention (+15).

## KPI tiles (4)
- **Connections** (total) · **Healthy** (green/red) · **Default environment** (name) · **AI status** (Ready/Off).

## Warnings (conditional, actionable)
- "N connections need attention. Open them below for the recommended fix." OR "No default connection yet…". Only shown when true.

## Quick action
- Prominent **+ New connection** (opens the wizard).

## Below the fold
- **Your connections** (card grid) · **Feature routing** (visual) · **Next steps** + **Security**.

## Why it reads in seconds
Score + KPIs + warnings answer the four questions ("am I set up / what's healthy / what's the default / is AI on") before the user scrolls. Everything else is progressive (cards expand to capabilities/edit; wizard opens on demand).

## Honesty preserved
The readiness score and "AI status: Ready/Off" reflect *real* state (a healthy runtime-usable connection), never an aspirational claim. "Ready" means a runtime-usable connection tested healthy — not that AI features are enabled (that stays a separate, per-site flag, restated in Next steps).
