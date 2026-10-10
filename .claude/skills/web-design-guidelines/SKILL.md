---
name: web-design-guidelines
description: Review UI code for Web Interface Guidelines compliance (accessibility, forms, focus, typography, UX). Use when asked to "review my UI", "check accessibility", "audit design", "review UX", "cek aksesibilitas", or "review tampilan".
metadata:
  author: "vercel (disesuaikan, aturan disimpan lokal)"
  argument-hint: <file-or-pattern>
---

# Web Interface Guidelines

Review files for compliance with Web Interface Guidelines.

## How It Works

1. Read the rules in `guidelines.md` in this skill folder (do NOT fetch from the internet).
2. Read the specified files (or ask the user for files/pattern).
3. Check against all rules in `guidelines.md`.
4. Output findings in the terse `file:line` format defined in `guidelines.md`.

## Project-specific priorities (SIMRS)

When rules conflict, prioritise in this order: input-error prevention on clinical
forms > keyboard-only operation for high-volume data entry > readability/contrast >
visual polish. Follow `DESIGN.md` at the project root for project tokens.

If no files are specified, ask the user which files to review.
