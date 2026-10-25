# Cache CLI process tests

From the checkout root, run:

```sh
php -n admin/cli/tests/cache_commands_test.php
```

The suite needs PHP 8.3+ and permission to launch child PHP processes. It creates
and removes a private temporary fixture directory. No installed Moodle site,
database, credentials, Composer packages, or cache server is needed.

It executes copies of the real `purge_caches.php` and `upgrade.php` entrypoints
and their real `clilib.php` option parser. Fixture stubs record mutations and
simulate upgrade preconditions. Checks cover selectors, explicit false values,
invalid course IDs, dry-run output, upgrade failures, status checks, downgrade
rejection and the no-maintenance conflict guard.

These are process/control-flow regressions. They do not test actual database
upgrades, plugin upgrades, cache-store invalidation, or theme rebuilding. Those
still need Moodle's normal integration environment. The focused GitHub workflow
runs the process suite on PHP 8.3 and 8.4.

On a configured Moodle installation, preview a purge before executing it:

```sh
php admin/cli/purge_caches.php --theme --js --dry-run
```

The JSON contains `dry_run: true` and a `caches` object. With no cache selectors,
it previews the existing default of all cache areas. With only explicitly
disabled selectors (for example `--no-theme`) the selection is empty and no
purge occurs. `--template` targets the existing template-cache purge operation.
The preview lists requested areas, not individual cache-store operations;
Moodle's existing overlaps and MUC precedence still apply.
Course IDs must be positive integers; duplicate IDs are removed. A failed option
validation exits 2 before calling any purge operation. Dry-run does not invoke
purging, but normal Moodle bootstrap still runs.
