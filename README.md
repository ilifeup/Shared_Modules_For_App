# Shared Modules for Three Apps

Reusable Flutter, Laravel API and Filament Web Admin modules for three **independent** applications: Tiandi (first integration), Wuxiang and EachCore.

- Requirements and module specifications: [docs/shared_modules_list.md](docs/shared_modules_list.md)
- Original requirement baseline: [SPEC](docs/specs/three_apps_shared_modules_spec_v1.md)
- Original proof-of-concept acceptance baseline: [POC](docs/specs/three_apps_poc_acceptance_plan_v1.md)
- Agent instructions: [AGENTS.md](AGENTS.md), with [CLAUDE.md](CLAUDE.md) as Claude-specific entry point.

**Status:** S0 documentation baseline; no module is implemented or accepted yet. Each application owns its own authentication, database, deployment and business-specific rules. WooCommerce remains the EachCore transaction source of truth.

Work is performed through reviewed pull requests and verifiable CI. No deployment or production-data migration is authorized by this documentation.
