<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Database-independent process regression tests for the cache administration CLI.
 *
 * Runs copies of the actual CLI entrypoints and clilib argument parser in a temporary
 * fixture tree. Bootstrap, upgrade and purge operations are recorded stubs: this tests
 * argument handling/control flow, not real database upgrades or cache-store behaviour.
 * Execute from the checkout root with: php admin/cli/tests/cache_commands_test.php
 *
 * @package    core
 * @subpackage cli
 * @copyright  2026 Charalampos Nadiotis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Fail regardless of the runtime's zend.assertions setting. */
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 3);
$fixture = sys_get_temp_dir() . '/moodle-cache-cli-' . bin2hex(random_bytes(8));
mkdir($fixture . '/admin/cli', 0700, true);
mkdir($fixture . '/lib', 0700, true);
$count = 0;

try {
    foreach (['purge_caches.php', 'upgrade.php'] as $script) {
        check(copy($root . '/admin/cli/' . $script, $fixture . '/admin/cli/' . $script), 'Copy CLI script');
    }
    check(copy($root . '/public/lib/clilib.php', $fixture . '/lib/clilib.php'), 'Copy real option parser');
    foreach (['upgradelib.php', 'environmentlib.php'] as $library) {
        file_put_contents($fixture . '/lib/' . $library, '<?php');
    }
    file_put_contents($fixture . '/lib/adminlib.php', <<<'STUB'
<?php
namespace core\session;
class manager {
    public static function set_user($user) {}
}
STUB);
    file_put_contents($fixture . '/version.php', <<<'STUB'
<?php
$version = (int) (getenv('MOODLE_CLI_TEST_CODE_VERSION') ?: 10);
$release = 'test';
$branch = 'test';
STUB);
    file_put_contents($fixture . '/config.php', <<<'STUB'
<?php
$CFG = (object) ['libdir' => __DIR__ . '/lib', 'dirroot' => __DIR__, 'lang' => 'en',
    'version' => 10, 'release' => 'test', 'branch' => 'test', 'allcomponenthash' => 'same'];
$SESSION = (object) [];
function record_effect($name, $value = null) {
    file_put_contents(__DIR__ . '/effects.jsonl', json_encode([$name, $value]) . "\n", FILE_APPEND);
}
function get_string($name, $component = '', $value = null) { return $name; }
function purge_caches($options = []) { record_effect('purge', $options); }
function purge_all_caches() { record_effect('purge_all'); }
function moodle_needs_upgrading($locks) { return getenv('MOODLE_CLI_TEST_PENDING') === '1'; }
function set_config($key, $value) { record_effect('set_config', [$key, $value]); }
function check_moodle_environment($v, $s) { return [getenv('MOODLE_CLI_TEST_ENV_FAILURE') !== '1', []]; }
function normalize_version($value) { return $value; }
function environment_get_errors($results) { return []; }
function upgrade_stale_php_files_present() { return getenv('MOODLE_CLI_TEST_STALE_FILES') === '1'; }
function get_docs_url($value) { return $value; }
function upgrade_core($version, $verbose) { record_effect('upgrade_core'); }
function upgrade_noncore($verbose) { record_effect('upgrade_noncore'); }
function get_admin() { return (object) ['id' => 2]; }
function admin_apply_default_settings($arg, $force) { return []; }
function upgrade_themes() { record_effect('themes'); }
function html_to_text($value) { return $value; }
class core_component {
    public static function get_all_component_hash() {
        return getenv('MOODLE_CLI_TEST_SCHEMA_CHANGE') === '1' ? 'new' : 'same';
    }
}
class cache_helper {
    public static function purge_all($writer = false) { record_effect('purge_muc', $writer); }
}
class core_plugin_manager {
    public static function instance() { return new self(); }
    public function all_plugins_ok($v, &$failures, $branch) {
        return getenv('MOODLE_CLI_TEST_DEPENDENCY_FAILURE') !== '1';
    }
}
define('ENV_SELECT_RELEASE', 1);
STUB);

    $run = function (string $script, array $args, array $environment = []) use ($fixture): array {
        file_put_contents($fixture . '/effects.jsonl', '');
        $process = proc_open([PHP_BINARY, '-n', $fixture . '/admin/cli/' . $script, ...$args], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $fixture, array_merge(getenv(), $environment));
        check(is_resource($process), 'Start CLI child process');
        // Cancel any unexpected interactive upgrade instead of hanging the test runner.
        fwrite($pipes[0], "clianswerno\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $effects = [];
        foreach (file($fixture . '/effects.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $effects[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return [$code, $stdout, $stderr, $effects];
    };

    $purgecase = function (array $args, array $expected, int $code = 0, bool $preview = false) use ($run, &$count): void {
        [$actualcode, $stdout, $stderr, $effects] = $run('purge_caches.php', $args);
        $label = 'purge_caches.php ' . implode(' ', $args);
        check($actualcode === $code, $label . ': unexpected exit code ' . $actualcode . ' ' . $stderr);
        if ($preview) {
            check($effects === [], $label . ': dry-run invoked a mutation');
            check(json_decode($stdout)->caches instanceof stdClass, $label . ': preview caches must be a JSON object');
            $plan = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            check($plan === ['dry_run' => true, 'caches' => $expected], $label . ': incorrect preview');
        } else {
            check($effects === ($expected ? [['purge', $expected]] : []), $label . ': unexpected mutation');
        }
        $count++;
    };

    $all = array_fill_keys(['muc', 'courses', 'theme', 'lang', 'js', 'template', 'filter', 'other'], true);
    $purgecase([], $all);
    $purgecase(['--theme', '--js'], ['theme' => true, 'js' => true]);
    $purgecase(['--template'], ['template' => true]);
    $purgecase(['--courses'], ['courses' => true]);
    $purgecase(['--courses=1'], ['courses' => '1']);
    $purgecase(['--courses=4, 67,4'], ['courses' => '4,67']);
    $purgecase(['--theme=false', '--js'], ['js' => true]);
    $purgecase(['--theme', '--no-theme'], []);
    $purgecase(['--no-theme', '--theme'], ['theme' => true]);
    foreach (array_keys($all) as $name) {
        $purgecase(['--' . $name], [$name => true]);
        foreach (['true', 'yes', 'on'] as $value) {
            $purgecase(['--' . $name . '=' . $value], [$name => true]);
        }
        $purgecase(['--no-' . $name], []);
        foreach (['false', '0', 'off', 'no'] as $value) {
            $purgecase(['--' . $name . '=' . $value], []);
        }
    }
    $purgecase(['--dry-run'], $all, 0, true);
    $purgecase(['--theme', '--js', '--dry-run'], ['theme' => true, 'js' => true], 0, true);
    $purgecase(['--courses=4,67', '--dry-run'], ['courses' => '4,67'], 0, true);
    $purgecase(['--no-theme', '--dry-run'], [], 0, true);
    $purgecase(['--dry-run=false', '--theme'], ['theme' => true]);
    $purgecase(['--no-dry-run', '--theme'], ['theme' => true]);
    foreach (['', ' ', ',4', '4,', '4,,67', '-4', '1.5', '4e2', 'all', '4;5', str_repeat('9', 40)] as $ids) {
        $purgecase(['--theme', '--courses=' . $ids], [], 2);
        $purgecase(['--dry-run', '--courses=' . $ids], [], 2);
    }
    $purgecase(['--theme=garbage'], [], 2);
    $purgecase(['--dry-run=garbage'], [], 2);
    $purgecase(['--unknown-cache'], [], 2);
    $purgecase(['--help', '--theme'], []);

    $upgradecase = function (array $args, bool $purged = false, int $code = 0, array $env = []) use ($run, &$count): array {
        [$actualcode, $stdout, $stderr, $effects] = $run('upgrade.php', $args, $env);
        $label = 'upgrade.php ' . implode(' ', $args);
        check($actualcode === $code, $label . ': unexpected exit code ' . $actualcode . ' ' . $stderr);
        $purges = array_values(array_filter($effects, static fn($effect) => str_starts_with($effect[0], 'purge')));
        check($purges === ($purged ? [['purge_muc', true], ['purge_all', null]] : []), $label . ': incorrect purges');
        $count++;
        return $effects;
    };
    $upgradecase([]);
    $upgradecase(['--purge-caches'], true);
    $upgradecase(['--non-interactive', '--purge-caches'], true);
    foreach (['false', '0', 'off', 'no'] as $value) {
        $upgradecase(['--purge-caches=' . $value]);
    }
    $upgradecase(['--no-purge-caches']);
    $upgradecase(['--purge-caches=invalid'], false, 1);
    foreach (['--is-pending', '--is-maintenance-required'] as $flag) {
        $upgradecase([$flag, '--purge-caches']);
        $upgradecase([$flag, '--purge-caches'], false, $flag === '--is-pending' ? 2 : 3, ['MOODLE_CLI_TEST_PENDING' => '1']);
    }
    foreach (['--no-maintenance', '--maintenance=false', '--maintenance=0'] as $flag) {
        check($upgradecase([$flag, '--purge-caches'], false, 1) === [], 'Conflict changed upgrade locks');
    }
    $pending = ['MOODLE_CLI_TEST_PENDING' => '1'];
    $upgradecase(['--is-maintenance-required', '--purge-caches'], false, 2, $pending + ['MOODLE_CLI_TEST_SCHEMA_CHANGE' => '1']);
    foreach (['ENV_FAILURE', 'STALE_FILES', 'DEPENDENCY_FAILURE'] as $failure) {
        $upgradecase(['--non-interactive', '--purge-caches'], false, 1, $pending + ['MOODLE_CLI_TEST_' . $failure => '1']);
    }
    $effects = $upgradecase(['--non-interactive', '--purge-caches'], false, 0, $pending);
    check(in_array(['themes', null], $effects, true), 'Existing upgrade did not build themes');
    $upgradecase(['--help', '--purge-caches']);
    $upgradecase(['--purge-caches', '--unknown-option'], false, 1);
    $upgradecase(['--purge-caches'], false, 1, ['MOODLE_CLI_TEST_CODE_VERSION' => '9']);

    echo 'PASS: ' . $count . " isolated CLI regression cases\n";
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
}
