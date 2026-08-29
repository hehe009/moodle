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
 * Fix question_set_references rows whose questionscontextid (and embedded filtercondition
 * 'cat' value) disagree with the CURRENT, authoritative contextid of the question category
 * they actually point at.
 *
 * This can happen after restoring a Moodle 4 quiz backup into Moodle 5, in three distinct ways:
 * - A category merged into its context's consolidated "top" category during restore, where the
 *   set reference pointing at the pre-merge category was never updated to match.
 * - A course-level category relocated or reused independently of the quiz's own module-context
 *   bank during restore, which is never revisited by the relocation step that only scans
 *   CONTEXT_MODULE-level banks.
 * - A category id mapping not yet resolved at the point a random question's filter condition was
 *   first written during restore, producing a literal placeholder category id of 0.
 *
 * See MDL-89552 for full details of all three causes and the corresponding restore-time fixes.
 * This script exists to retroactively repair data on sites that already have affected records
 * from restores performed before those fixes were in place.
 *
 * @package   core_question
 * @copyright 2026 onwards
 * @author    Max Kan <max_kan@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

// This script does NOT write anything unless --execute is explicitly given: running it with no
// arguments (or with any combination of options that omits --execute) always reports what it
// would do without changing any data. This is a deliberate safety net given the scale of impact
// seen in practice (thousands of records on a single site) - an administrator who runs this
// without first reading --help should not be able to accidentally apply a site-wide,
// irreversible change on their very first invocation.
[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'execute' => false,
        'component' => '',
        'questionarea' => '',
        'limit' => 0,
        'backup-file' => '',
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized), 2);
}

if ($options['help']) {
    $help = <<<EOF
Fix stale question category contextid data in question_set_references

Checks question_set_references records against the CURRENT contextid of the question category
each one's filter condition points at, and corrects any that disagree.

SAFE BY DEFAULT: running this script WITHOUT --execute never writes anything, regardless of
any other options given - it always just reports what it would do. You must pass --execute
explicitly to actually apply changes.

Options:
--execute               Actually apply the fix. Without this, the script always runs as a
                        dry run, no matter what other options are given.
--dry-run               Explicitly request a dry run (this is also the default with no
                        arguments at all - this flag exists for clarity/documentation in
                        scripts or cron jobs that want to be explicit about their intent).
--component=STRING      Restrict to a specific set_references component (e.g. mod_quiz). Default: all.
--questionarea=STRING   Restrict to a specific set_references questionarea (e.g. slot). Default: all.
--limit=N               Only apply the fix to the first N matching records (0 = no limit). Useful for
                        validating a small batch before running against everything.
--backup-file=PATH      Before each live update, append the record's OLD values to this CSV file, so
                        changes can be reviewed or manually reversed later. Only used with --execute.
-h, --help               Print out this help

Examples:
\$sudo -u www-data /usr/bin/php question/cli/fix_stale_set_reference_category_context.php
\$sudo -u www-data /usr/bin/php question/cli/fix_stale_set_reference_category_context.php \\
    --component=mod_quiz --questionarea=slot --limit=10 --backup-file=/tmp/setref_backup.csv --execute
\$sudo -u www-data /usr/bin/php question/cli/fix_stale_set_reference_category_context.php \\
    --component=mod_quiz --questionarea=slot --execute
EOF;

    echo $help;
    exit(0);
}

$dryrun = true;
if ($options['execute'] && !$options['dry-run']) {
    $dryrun = false;
}
$limit = (int) $options['limit'];
$backupfile = trim((string) $options['backup-file']);

$selectparts = [];
$params = [];
if ($options['component'] !== '') {
    $selectparts[] = 'component = :component';
    $params['component'] = $options['component'];
}
if ($options['questionarea'] !== '') {
    $selectparts[] = 'questionarea = :questionarea';
    $params['questionarea'] = $options['questionarea'];
}
$select = implode(' AND ', $selectparts);

cli_writeln($dryrun
    ? 'Running in DRY-RUN mode. No data will be changed.'
    : 'Running in LIVE mode. Matching records WILL be updated. Make sure you have a backup.');
cli_writeln('Checking for question_set_references with a stale category contextid...');

// Work out which records need fixing (read-only) before deciding which of them to actually
// apply, so --limit and --backup-file can be honoured and a consistent summary can be printed
// regardless of which options were given.
$sets = $DB->get_records_select('question_set_references', $select, $params);
$tofix = [];
foreach ($sets as $set) {
    $filtercondition = json_decode($set->filtercondition, true);
    if (!is_array($filtercondition)) {
        continue;
    }
    if (!array_key_exists('filter', $filtercondition)) {
        $filtercondition = core_question\question_reference_manager::convert_legacy_set_reference_filter_condition(
            $filtercondition
        );
    }
    $catid = $filtercondition['filter']['category']['values'][0] ?? null;
    if ($catid === null) {
        continue;
    }
    $catidwasplaceholder = ((int) $catid === 0);
    if ($catidwasplaceholder) {
        if (empty($set->usingcontextid)) {
            continue;
        }
        $catid = question_get_top_category($set->usingcontextid, true)->id;
    }
    $category = $DB->get_record('question_categories', ['id' => $catid]);
    if (!$category) {
        continue;
    }
    if (!$catidwasplaceholder && (int) $set->questionscontextid === (int) $category->contextid) {
        continue;
    }
    $tofix[] = ['set' => $set, 'newcontextid' => (int) $category->contextid];
}

$totalchecked = count($sets);
$needfix = count($tofix);

if ($limit > 0 && count($tofix) > $limit) {
    cli_writeln("Limiting to the first {$limit} matching record(s).");
    $tofix = array_slice($tofix, 0, $limit);
}

$backuphandle = null;
if ($backupfile !== '' && !$dryrun) {
    $isnewfile = !file_exists($backupfile);
    $backuphandle = fopen($backupfile, 'a');
    if (!$backuphandle) {
        cli_error("Could not open backup file for writing: {$backupfile}");
    }
    if ($isnewfile) {
        fputcsv($backuphandle, [
            'id', 'component', 'questionarea', 'itemid',
            'old_questionscontextid', 'old_filtercondition',
        ]);
    }
    cli_writeln("Backing up old values to: {$backupfile}");
}

$fixed = 0;
foreach ($tofix as $item) {
    $set = $item['set'];

    if ($dryrun) {
        cli_writeln("  [DRY RUN] would fix id={$set->id} component={$set->component} " .
            "area={$set->questionarea} itemid={$set->itemid}: " .
            "questionscontextid {$set->questionscontextid} -> {$item['newcontextid']}");
        continue;
    }

    if ($backuphandle) {
        fputcsv($backuphandle, [
            $set->id, $set->component, $set->questionarea, $set->itemid,
            $set->questionscontextid, $set->filtercondition,
        ]);
    }

    // Delegate the actual write to the manager method, scoped to just this one record, so
    // the manager class remains the single source of truth for how a fix is applied.
    core_question\question_reference_manager::fix_stale_category_context('id = :id', ['id' => $set->id]);
    $fixed++;
}

if ($backuphandle) {
    fclose($backuphandle);
}

cli_writeln('');
cli_writeln('Total record checked: ' . $totalchecked);
cli_writeln('Need to fix: ' . $needfix);
cli_writeln('Fixed: ' . $fixed);

if ($dryrun && $needfix > 0) {
    cli_writeln('');
    cli_writeln('No changes were made (dry run). Re-run with --execute to apply these changes. ' .
        'Take a database backup first.');
}

exit(0);
