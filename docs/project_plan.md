# Shared Modules Project Plan — S0

## Approved direction
Build shared functionality first, then integrate Tiandi, Wuxiang and EachCore separately. S1 first outcome is a narrow Flutter + Laravel + Filament vertical slice for login, member profile and notices. Repo: ilifeup/Shared_Modules_For_App.

## Gates
- S0 documentation: README, AGENTS.md, CLAUDE.md, original unmodified SPEC/POC, shared_modules_list, module_matrix, architecture, decision record, documentation validation workflow. PASS only after PR review and successful CI.
- S1 proof of concept: Flutter login/logout, token handling, M01 profile, M02 minimum roles/Policies, M06 notices, M07 essential audit, Filament admin, disposable DB, automated tests and Android evidence. No assumption that compiled APK equals end-to-end PASS.
- S2 Tiandi integration: separate integration PR, pin shared version, prove Tiandi app business separation and repeat PoC acceptance. No rewrite of historical ADR; new superseding ADR for new repo strategy.
- S3 additional modules: one approved vertical slice at a time, after rules are confirmed.
- S4 Wuxiang integration: independently deployed backend and member database; confirm shared package is reusable without Tiandi identifiers.
- S5 EachCore integration: independently deployed backend; WooCommerce remains transaction authority.

## GitHub workflow
Each task: branch → smallest change → PR → review → CI evidence → merge if explicitly within approved governance. No force push, bypass or deploy. GitHub Free private repos may lack enforceable required checks; manual SHA/CI verification is essential. Failed checks stop merge.

## Pending decisions
Specific S1 endpoints/schema and test environment, private cross-repo dependency authentication, module release/tag conventions, Tiandi ADR supersession, cost and hosting authorization. Do not fill gaps by inventing user-approved policies.
