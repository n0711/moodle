<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to purge caches without asking for confirmation.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2011 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('IGNORE_COMPONENT_CACHE', true);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');

$cachekeys = ['muc', 'courses', 'theme', 'lang', 'js', 'template', 'filter', 'other'];
$longoptions = [
    'help' => false,
    'dry-run' => false,
] + array_fill_keys($cachekeys, null);
list($options, $unrecognized) = cli_get_params($longoptions, ['h' => 'help']);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized), 2);
}

if ($options['help']) {
    // The indentation of this string is "wrong" but this is to avoid a extra whitespace in console output.
    $help = <<<EOF
Invalidates Moodle internal caches

Specific caches can be defined (alone or in combination) using arguments. If none are specified,
all caches will be purged.
Explicitly disabled selections (e.g. --no-theme or --theme=false) do not select any caches.

Options:
-h, --help            Print out this help
    --dry-run         Print the selected caches as JSON without purging them
    --muc             Purge all MUC caches (includes lang cache)
    --courses         Purge all course caches (or only those specified by a comma-separated list).
                      e.g. --courses=4,67,145
    --theme           Purge theme cache
    --lang            Purge language string cache
    --js              Purge JavaScript cache
    --template        Purge template cache
    --filter          Purge text filter cache
    --other           Purge all file caches and other miscellaneous caches (may include MUC
                      if using cachestore_file).

Example:
\$ sudo -u www-data /usr/bin/php admin/cli/purge_caches.php
\$ sudo -u www-data /usr/bin/php admin/cli/purge_caches.php --theme --js --dry-run

EOF;

    echo $help;
    exit(0);
}

$invalidvalue = function (string $name) use ($options): void {
    cli_error(get_string('cliincorrectvalueerror', 'admin', (object) [
        'option' => '--' . $name,
        'value' => $options[$name],
    ]), 2);
};

$dryrun = filter_var($options['dry-run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($dryrun === null) {
    $invalidvalue('dry-run');
}

// Keep omitted selectors distinct from explicitly disabled ones. Passing an empty array to
// purge_caches() means "purge everything", so an explicit empty selection must exit below.
$selected = array_filter(
    array_intersect_key($options, array_fill_keys($cachekeys, true)),
    static fn($value) => $value !== null,
);
$purgeoptions = [];
if (!$selected) {
    $purgeoptions = array_fill_keys($cachekeys, true);
} else {
    foreach ($selected as $name => $value) {
        if ($name === 'courses' && is_string($value)
                && !in_array(strtolower(trim($value)), ['true', 'yes', 'on', 'false', 'no', 'off', '0'], true)) {
            $courseids = preg_split('/\s*,\s*/', trim($value));
            foreach ($courseids as $courseid) {
                if (filter_var($courseid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $invalidvalue($name);
                }
            }
            $purgeoptions[$name] = implode(',', array_unique($courseids));
            continue;
        }

        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $invalidvalue($name);
        }
        if ($enabled) {
            $purgeoptions[$name] = true;
        }
    }
}

if ($dryrun) {
    cli_writeln(json_encode(['dry_run' => true, 'caches' => (object) $purgeoptions], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit(0);
}

if (!$purgeoptions) {
    cli_writeln(get_string('purgecachesnoneselected', 'admin'));
    exit(0);
}

purge_caches($purgeoptions);

exit(0);
