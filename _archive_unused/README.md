# Workspace Archive & Quarantine Manifest (`_archive_unused`)

This directory houses legacy prototypes, historical notes, one-off scripts, and unused artifacts that were moved out of the active repository root and workspaces.

> **Zero Deletion Guarantee:**  
> In accordance with repository safety rules, **zero files were deleted**. All items were moved (`git mv` / filesystem move) to preserve full historical git provenance and ensure any asset, snippet, or idea can be retrieved at any time.

---

## Directory Structure

```
_archive_unused/
├── designs_and_prototypes/    # Standalone HTML/JSX mockups and design explorations
├── scripts_and_tools/         # One-off utility scripts, Repomix configs, and context dumps
├── docs_and_notes/            # Historical audit briefs, prompt dumps, and agent logs
└── misc_unrelated/            # Scratch notes and accidental command redirection outputs
```

---

## Manifest of Archived Items

### 1. `designs_and_prototypes/`

| Item | Original Path | Description & Archival Reason |
| :--- | :--- | :--- |
| `new_design/` | `/new_design/` | Early JSX/CSS POS & backoffice prototypes (`checkout.jsx`, `inventory.jsx`, `returns.jsx`, `shift.jsx`, `terminal.jsx`, `tokens.css`, `ui.jsx`, `tweaks_panel.jsx`). Fully integrated into `bags-platform/frontend/apps/backoffice/src/pages/pos/`. |
| `ai-chat.html` | `/ai-chat.html` | Standalone HTML mockup exploring an AI assistant chat interface. |
| `atelier_v_ra_luxury_bag_order_confirmation.html` | `/atelier_v_ra_luxury_bag_order_confirmation.html` | Standalone HTML mockup exploring luxury bag order confirmation design. |
| `categories.html` | `/categories.html` | Standalone HTML mockup of storefront category navigation. |
| `design.html` | `/design.html` | Early prototype page for backoffice UI elements. |
| `home.html` | `/home.html` | Standalone HTML mockup of the storefront landing page. |
| `index.html` | `/index.html` | Root standalone landing page prototype referencing local assets. |
| `natural_cloud_animation.mp4` | `/natural_cloud_animation.mp4` | Video asset used exclusively by root prototype `index.html`. |
| `data.jsx` | `/data.jsx` | Static mock data used by early HTML prototypes. |

### 2. `scripts_and_tools/`

| Item | Original Path | Description & Archival Reason |
| :--- | :--- | :--- |
| `build_admin_app.cjs` | `/build_admin_app.cjs` | Legacy node script previously used to parse CSS from a `dasign.txt` file. |
| `compare_models.js` | `/compare_models.js` | Standalone benchmark script comparing LLM outputs via an external Radox API key. |
| `proxy_port.cjs` | `/bags-platform/proxy_port.cjs` | Standalone TCP proxy forwarder (port 5137 -> 5173); unreferenced by platform configs. |
| `probe-servers-tmp.cjs` | `/bags-platform/frontend/probe-servers-tmp.cjs` | Temporary script used during port discovery. |
| `repomix.config.json` | `/repomix.config.json` | Configuration file for Repomix codebase packaging. |
| `repomix.design.json` | `/repomix.design.json` | Repomix config for packaging design files. |
| `repomix.phase14.json` | `/repomix.phase14.json` | Repomix config for Phase 14 returns context packaging. |
| `repomix.pos.json` | `/repomix.pos.json` | Repomix config for POS context packaging. |
| `repomix-design-wholesale-storefront.xml` | `/repomix-design-wholesale-storefront.xml` | Generated Repomix codebase context XML bundle. |
| `repomix-output.xml` | `/repomix-output.xml` | Generated Repomix codebase context XML bundle. |
| `repomix-phase12-wholesale.xml` | `/repomix-phase12-wholesale.xml` | Generated Repomix codebase context XML bundle. |
| `repomix-phase14-returns.md` | `/repomix-phase14-returns.md` | Generated Repomix codebase context Markdown bundle. |
| `repomix-phase14-returns.xml` | `/repomix-phase14-returns.xml` | Generated Repomix codebase context XML bundle. |
| `repomix-pos.xml` | `/repomix-pos.xml` | Generated Repomix codebase context XML bundle. |

### 3. `docs_and_notes/`

| Item | Original Path | Description & Archival Reason |
| :--- | :--- | :--- |
| `C-REVIEW-COMPLETION.md` | `/C-REVIEW-COMPLETION.md` | Audit completion notes from Review C. |
| `audit-issues-brief.md` | `/audit-issues-brief.md` | Markdown brief from previous audit rounds. |
| `audit-round2-issues-brief.md` | `/audit-round2-issues-brief.md` | Markdown brief from round 2 audit. |
| `audit-auth-motion-lint.log` | `/audit-auth-motion-lint.log` | Lint log output from past motion audit. |
| `audit-auth-motion-types.log` | `/audit-auth-motion-types.log` | TypeScript check log output from past motion audit. |
| `check.txt` | `/check.txt` | Prompt instructions for exhaustive code review. |
| `plan.txt` | `/plan.txt` | Historical implementation roadmap notes from Muse Code. |
| `store.txt` | `/store.txt` | Early phase 0 plan notes for storefront components. |
| `testing.txt` | `/testing.txt` | Prompt instructions for test coverage planning. |
| `ui-ux.txt` | `/ui-ux.txt` | Prompt instructions for responsive UI/UX review. |
| `vortix.txt` | `/vortix.txt` | Review findings list in Arabic for wholesale & POS. |
| `branch.txt` | `/bags-platform/branch.txt` | Scratch notes on branch and location requirements. |
| `exuction.txt` | `/bags-platform/exuction.txt` | Scratch snippets for Vortix shell navigation. |
| `optimize.txt` | `/bags-platform/optimize.txt` | Scratch prompt instructions for continuous refactoring. |
| `assistant-*.log` (7 files) | `/bags-platform/frontend/*.log` | Verification logs from frontend test/build verification runs. |
| `pos-voice-verification.log` | `/bags-platform/frontend/*.log` | Verification log for POS voice count feature. |

### 4. `misc_unrelated/`

| Item | Original Path | Description & Archival Reason |
| :--- | :--- | :--- |
| `-e` | `/-e` | Shell redirect output created accidentally when running grep with `-e`. |
| `c` | `/c` | Scratch text file with Arabic review notes on POS discounts. |
| `can` | `/can` | Grep search output for `$actor->can()` calls. |
| `currentVersion` | `/currentVersion` | Grep search output for `currentVersion` methods. |
| `download` | `/download` | Grep search output for `download()` controller methods. |
| `store` | `/store` | Grep search output for file `store()` methods. |

---

## Active Project Components (Retained in Working Tree)

The following components remain in their authoritative locations:
- **`bags-platform/`**: The core production monorepo containing:
  - `backend/`: Laravel API application, models, migrations, tests.
  - `frontend/`: PNPM workspace with Next.js storefront, Vite backoffice & POS, shared packages.
  - `infrastructure/`: HAProxy, Docker, and Nginx configurations.
  - `wa-gateway/`: WhatsApp webhook integration gateway.
  - `docs/`: Active architectural, API, and QA specifications.
  - `dev.bat`, `dev.ps1`, `start-all.bat`, `stop-all.bat`: Environment control scripts.
- **`task.txt`**: Active project plan and requirement backlog.
- **`test-results/`**: Output directory for Playwright, browser screenshots, and test execution artifacts.
- **`.github/`**: Active GitHub Actions CI/CD workflows.
- **`.gitignore`**, **`README.md`**: Core repository configuration and readme.
