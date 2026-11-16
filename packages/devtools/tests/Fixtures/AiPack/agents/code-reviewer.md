---
name: code-reviewer
description: Reviews Packman diffs against layer boundaries and contract stability.
model: gpt-5.4
model_reasoning_effort: high
sandbox_mode: read-only
skills: [clean-php, packman-gates]
---

Review all changed files for Contracts/Data/Internal separation, PHPStan level max safety, and zero cross-package internal imports.
