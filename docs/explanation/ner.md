# Enabling NER

This page expands the NER install guidance from the [README](../../README.md). Use it when you want named-entity recognition in addition to regex and rulepack detection.

By default gaze-laravel runs in regex/rulepack mode. Enable named-entity recognition with:

```bash
php artisan gaze:install-ner --yes
```

This downloads the pinned Davlan mBERT NER int8 ONNX artifact set into `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/`, verifies every file against the upstream `SHA256SUMS` contract, copies the packaged BIO-to-class `labels.json`, and prints the `[ner]` block to paste into `policy.toml`.

To wire `policy.toml` automatically, add `--update-policy`. Re-running the command is idempotent when artifacts already verify.

## Flags

- `--variant=int8` — only `int8` is supported in v0; other variants fail closed.
- `--dest=<abs path>` — override the model storage location.
- `--locale=de` — embed a BCP47 locale hint in the generated `[ner]` block.
- `--check` — verify an existing install without downloading.
- `--dry-run` — preview destination and policy output without writing.
- `--force` — redownload and overwrite even when the destination already verifies.
- `--update-policy` — write the `[ner]` block to `config('gaze.policy_path')`.

## CI / shared-host considerations

Set `HUGGINGFACE_TOKEN` when your host or CI network is rate-limited by HuggingFace. The token is sent as `Authorization: Bearer ...` to HuggingFace artifact requests.

Cache `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/` between CI jobs when you need NER-enabled integration tests. On locked-down hosts, fetch the artifact set from `onnx-community/bert-base-multilingual-cased-ner-hrl-ONNX`, place it at the destination, and run `php artisan gaze:install-ner --check`.
