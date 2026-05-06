# AGENTS.md

This file provides guidance for AI agents (Claude Code, Codex, Cursor, etc.) when working with code in this repository.

`gaze-laravel` is a Laravel adapter for the Gaze pseudonymization engine.

## Tooling preference

Standard shell tools (`rg`, `git`, `bash`, `jq`) are preferred for repo-inspection commands. If your AI environment provides a context-compression layer (e.g. lean-ctx), use it when available — otherwise plain shell is fine.
