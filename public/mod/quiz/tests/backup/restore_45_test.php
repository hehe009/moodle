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

namespace mod_quiz\backup;

use advanced_testcase;
use backup;
use restore_controller;

/**
 * Test restoring a Moodle 4.5 backup containing random questions that draw from categories
 * requiring various kinds of relocation/merging during restore into Moodle 5.
 *
 * This covers three distinct ways a question_set_references row's questionscontextid and
 * embedded filter condition category id can end up stale or invalid if not corrected:
 * - A category merged into the target context's consolidated "top" category (see
 *   restore_move_module_questions_categories()'s $oldtopid handling).
 * - A course-level category reused/relocated independently of the quiz's own module-context
 *   bank, which restore_move_module_questions_categories() never revisits at all, since it only
 *   scans CONTEXT_MODULE-level banks (see restore_fix_question_set_references_context()).
 * - A category whose id mapping had not yet been resolved at the point the filter condition was
 *   written, producing a literal placeholder category id of 0 (see
 *   qbank_managecategories\category_condition::restore_filtercondition() and
 *   core_question\question_reference_manager::fix_stale_category_context()).
 *
 * @package   mod_quiz
 * @copyright 2026 onwards
 * @author    Max Kan <max_kan@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \restore_move_module_questions_categories
 * @covers    \restore_fix_question_set_references_context
 * @covers    \qbank_managecategories\category_condition
 * @covers    \core_question\question_reference_manager
 */
final class restore_45_test extends advanced_testcase {
    public function test_restore_random_questions_category_relocation_45(): void {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        // The example Moodle 4.5 backup file used in this test is an activity-level backup of a
        // quiz called "Quiz 1", from a course with shortname "qme-01", containing 5 random
        // question slots, each drawing from a differently-located category:
        // | - "Default for Quiz 1" (x1) - the quiz's own private category (module context).
        // |     Restores cleanly with no relocation needed; included as a control case.
        // | - "Top for Quiz 1"     (x1) - the quiz's own top category itself, selected directly
        // |     with "include subcategories" (shown in the UI as "Any category of this quiz").
        // |     This category gets merged into the site's own newly-created top category for
        // |     the relocated module context during restore.
        // | - "Default for qme-01" (x1) - a course-level category, shared beyond this one quiz.
        // |     Its final resting context is only resolved after this quiz's own per-activity
        // |     restore step has already run.
        // | - "Level 1 category"   (x2) - a subcategory of "Default for qme-01", two separate
        // |     random question slots both drawing from it, covering the same course-level
        // |     relocation timing issue one level down the category tree.
        $backupfile = 'moodle_45_quiz_with_random_question_category_relocation';

        // Extract backup file.
        $backupid = $backupfile;
        $backuppath = make_backup_temp_directory($backupid);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname(
            __DIR__ . "/../fixtures/$backupfile.mbz",
            $backuppath
        );

        // Restore the quiz activity in the backup from Moodle 4.5 to a new course.
        $coursecat = self::getDataGenerator()->create_category();
        $course = self::getDataGenerator()->create_course(['category' => $coursecat->id]);
        $rc = new restore_controller(
            $backupid,
            $course->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $precheckresults = $rc->get_precheck_results();
        // Any results at this point may be warnings only (e.g. "category X will be created at a
        // question bank module context by restore" - exactly the relocation this test exists to
        // verify is handled correctly), which are not blocking, matching what a user does by
        // clicking "Continue" past the warnings page in the UI. Only a genuine 'errors' entry
        // should fail the test.
        $this->assertEmpty($precheckresults['errors'] ?? [], 'restore precheck reported errors');
        $rc->execute_plan();
        $rc->destroy();

        // Get information about the restored quiz activity.
        $modinfo = get_fast_modinfo($course->id);
        $quizzes = array_values($modinfo->get_instances_of('quiz'));
        $this->assertCount(1, $quizzes);
        $quizcontextid = $quizzes[0]->context->id;

        // Get question_set_references records for the restored quiz activity.
        $references = $DB->get_records('question_set_references', [
            'usingcontextid' => $quizcontextid,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
        ]);
        $this->assertCount(5, $references);

        // For every reference, resolve which category it actually points at (by id), and assert
        // that id/context are both valid and mutually consistent - this is the core assertion
        // covering all three bug scenarios at once, since each of them manifests as EITHER an
        // unresolvable/placeholder category id, OR a questionscontextid/filtercondition['cat']
        // that disagrees with the category's own, current, authoritative contextid.
        $categoriesbyreferenceid = [];
        foreach ($references as $reference) {
            $filtercondition = json_decode($reference->filtercondition, true);
            $this->assertIsArray(
                $filtercondition,
                "filtercondition for reference id {$reference->id} did not decode to an array"
            );
            $this->assertArrayHasKey('filter', $filtercondition);
            $catid = $filtercondition['filter']['category']['values'][0] ?? null;

            // None of the five references should still be pointing at the placeholder id 0 -
            // this is the literal symptom of the "Missing question category" bug.
            $this->assertNotSame(
                0,
                (int) $catid,
                "reference id {$reference->id} still has placeholder category id 0"
            );

            $category = $DB->get_record('question_categories', ['id' => $catid]);
            $this->assertNotFalse(
                $category,
                "reference id {$reference->id} points at a non-existent category id " . var_export($catid, true)
            );
            $categoriesbyreferenceid[$reference->id] = $category;

            $this->assertEquals(
                $category->contextid,
                $reference->questionscontextid,
                "reference id {$reference->id} (category '{$category->name}') has a stale questionscontextid"
            );
            $this->assertEquals(
                "{$category->id},{$category->contextid}",
                $filtercondition['cat'],
                "reference id {$reference->id} (category '{$category->name}') has a stale filtercondition['cat']"
            );
        }

        // Confirm all five expected categories were actually exercised - i.e. that we are really
        // testing all four distinct relocation scenarios this fixture is for (one of which,
        // "Level 1 category", is exercised twice), not just asserting self-consistency on
        // whatever happened to restore.
        //
        // Note: the quiz's own top category is always literally named 'top' in the database -
        // "Top for Quiz 1" is a display label synthesised dynamically in the category picker UI
        // (see question_category_selector.php), not a stored value.
        $namesseen = array_map(fn($category) => $category->name, $categoriesbyreferenceid);
        sort($namesseen);
        $this->assertEquals(
            ['Default for Quiz 1', 'Default for qme-01', 'Level 1 category', 'Level 1 category', 'top'],
            array_values($namesseen)
        );

        // The "top" category reference is the one selected in the UI as "any category of this
        // quiz, including subcategories" - confirm that flag survived the restore correctly too,
        // since it is part of what identifies this as the "old top category merge" scenario
        // rather than an ordinary category selection.
        foreach ($references as $reference) {
            if ($categoriesbyreferenceid[$reference->id]->name === 'top') {
                $filtercondition = json_decode($reference->filtercondition, true);
                $this->assertTrue(
                    (bool) ($filtercondition['filter']['category']['filteroptions']['includesubcategories'] ?? false),
                    'the top-category reference should have includesubcategories set'
                );
            }
        }
    }
}
