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

namespace core_question;

use advanced_testcase;
use context_system;
use core_question\local\bank\question_version_status;
use core_question_generator;

/**
 * Unit tests for the {@see question_reference_manager} class.
 *
 * @package   core_question
 * @category  test
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \core_question\question_reference_manager
 */
final class question_reference_manager_test extends advanced_testcase {

    public function test_questions_with_references(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $systemcontext = context_system::instance();

        // Create three questions, each with three versions.
        // In each case, the third version is draft.
        $cat = $questiongenerator->create_question_category();
        $q1v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q1V1', 'category' => $cat->id]);
        $q1v2 = $questiongenerator->update_question($q1v1, null, ['name' => 'Q1V2']);
        $q1v3 = $questiongenerator->update_question($q1v2, null,
                ['name' => 'Q1V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);
        $q2v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q2V1', 'category' => $cat->id]);
        $q2v2 = $questiongenerator->update_question($q2v1, null, ['name' => 'Q2V2']);
        $q2v3 = $questiongenerator->update_question($q2v2, null,
                ['name' => 'Q2V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);
        $q3v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q3V1', 'category' => $cat->id]);
        $q3v2 = $questiongenerator->update_question($q3v1, null, ['name' => 'Q3V2']);
        $q3v3 = $questiongenerator->update_question($q3v2, null,
                ['name' => 'Q3V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);

        // Create specific references to Q2V1 and Q2V3.
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 0,
                'questionbankentryid' => $q2v1->questionbankentryid, 'version' => 1]);
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 1,
                'questionbankentryid' => $q2v1->questionbankentryid, 'version' => 3]);

        // Create an always-latest reference to Q3.
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 2,
                'questionbankentryid' => $q3v1->questionbankentryid, 'version' => null]);

        // Verify which versions of Q1 are used.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v1->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v3->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v1->id, $q1v2->id, $q1v3->id]));

        // Verify which versions of Q2 are used.
        $this->assertEqualsCanonicalizing([$q2v1->id],
                question_reference_manager::questions_with_references([$q2v1->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q2v2->id]));
        $this->assertEqualsCanonicalizing([$q2v3->id],
                question_reference_manager::questions_with_references([$q2v3->id]));
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id],
                question_reference_manager::questions_with_references([$q2v1->id, $q2v2->id, $q2v3->id]));

        // Verify which versions of Q1 are used.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q3v1->id]));
        $this->assertEqualsCanonicalizing([$q3v2->id],
                question_reference_manager::questions_with_references([$q3v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q3v3->id]));
        $this->assertEqualsCanonicalizing([$q3v2->id],
                question_reference_manager::questions_with_references([$q3v1->id, $q3v2->id, $q3v3->id]));

        // Do some combined queries.
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id, $q3v2->id],
                question_reference_manager::questions_with_references([
                        $q1v1->id, $q1v2->id, $q1v3->id,
                        $q2v1->id, $q2v2->id, $q2v3->id,
                        $q3v1->id, $q3v2->id, $q3v3->id]));
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id, $q3v2->id],
                question_reference_manager::questions_with_references([$q2v1->id, $q2v3->id, $q3v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([
                        $q1v1->id, $q1v2->id, $q1v3->id,
                        $q2v2->id,
                        $q3v1->id, $q3v3->id]));

        // Test some edge cases.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([-1]));

    }

    /**
     * Any question set references where questioncategoryid does not match filtercondition['cat'] should have the cat updated.
     *
     * @todo Deprecate in Moodle 6.0 (MDL-87844) for removal in 7.0 (MDL-87845).
     */
    public function test_fix_set_references_category_context(): void {
        global $DB;
        $this->resetAfterTest();

        $correctreference = (object) [
            'usingcontextid' => 1,
            'component' => 'core_question',
            'questionarea' => 'test',
            'itemid' => 1,
            'questionscontextid' => 2,
            'filtercondition' => json_encode(
                [
                    'filter' => [
                        'category' => [
                            'name' => 'category',
                            'jointype' => 1,
                            'values' => [1],
                            'filteroptions' => [
                                'includesubcategories' => 0,
                            ],
                        ],
                    ],
                    'cmid' => 1,
                    'courseid' => 1,
                    'cat' => '1,2',
                ],
            ),
        ];
        $correctreference->id = $DB->insert_record('question_set_references', $correctreference);

        $incorrectreference = (object) [
            'usingcontextid' => 1,
            'component' => 'core_question',
            'questionarea' => 'test',
            'itemid' => 2,
            'questionscontextid' => 4,
            'filtercondition' => json_encode(
                [
                    'filter' => [
                        'category' => [
                            'name' => 'category',
                            'jointype' => 1,
                            'values' => [6],
                            'filteroptions' => [
                                'includesubcategories' => 0,
                            ],
                        ],
                    ],
                    'cmid' => 1,
                    'courseid' => 1,
                    'cat' => '6,3',
                ],
            ),
        ];
        $incorrectreference->id = $DB->insert_record('question_set_references', $incorrectreference);

        $fixedcount = question_reference_manager::fix_set_references_category_context();

        $this->assertEquals(1, $fixedcount);

        $updatedcorrectrefrence = $DB->get_record('question_set_references', ['id' => $correctreference->id]);
        $this->assertEquals($correctreference, $updatedcorrectrefrence);

        $updatedincorrectrefrence = $DB->get_record('question_set_references', ['id' => $incorrectreference->id]);
        $this->assertEquals($incorrectreference->usingcontextid, $updatedincorrectrefrence->usingcontextid);
        $this->assertEquals($incorrectreference->questionscontextid, $updatedincorrectrefrence->questionscontextid);
        $filtercondition = json_decode($updatedincorrectrefrence->filtercondition, true);
        $this->assertEquals(
            implode(',', [$filtercondition['filter']['category']['values'][0], $updatedincorrectrefrence->questionscontextid]),
            $filtercondition['cat'],
        );
    }

    /**
     * A set reference whose questionscontextid and embedded filtercondition['cat'] already agree
     * with each other, but are both stale relative to question_categories.contextid, should still
     * be corrected by fix_stale_category_context() - this is the case
     * fix_set_references_category_context() cannot detect, because it only compares
     * a set reference's questionscontextid against its own filtercondition['cat'], not against
     * the category's actual current contextid.
     * @covers ::fix_stale_category_context
     */
    public function test_fix_stale_category_context(): void {
        global $DB;
        $this->resetAfterTest();

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $correctcategory = $questiongenerator->create_question_category();
        $movedcategory = $questiongenerator->create_question_category();

        // A reference that is already correct: questionscontextid matches the category's
        // current contextid, and so does the embedded filtercondition['cat'].
        $correctreference = (object) [
            'usingcontextid' => 1,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => 1,
            'questionscontextid' => $correctcategory->contextid,
            'filtercondition' => json_encode([
                'filter' => [
                    'category' => [
                        'name' => 'category',
                        'jointype' => 1,
                        'values' => [$correctcategory->id],
                        'filteroptions' => ['includesubcategories' => 0],
                    ],
                ],
                'cat' => "{$correctcategory->id},{$correctcategory->contextid}",
            ]),
        ];
        $correctreference->id = $DB->insert_record('question_set_references', $correctreference);

        // A reference that is internally self-consistent (questionscontextid agrees with its own
        // filtercondition['cat']) but both are stale: the category has since moved to a different
        // contextid than either of them records. fix_set_references_category_context() would not
        // detect this, since it only compares the two stored values against each other.
        $stalecontextid = $movedcategory->contextid + 1000; // Guaranteed to differ from the real one.
        $stalereference = (object) [
            'usingcontextid' => 1,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => 2,
            'questionscontextid' => $stalecontextid,
            'filtercondition' => json_encode([
                'filter' => [
                    'category' => [
                        'name' => 'category',
                        'jointype' => 1,
                        'values' => [$movedcategory->id],
                        'filteroptions' => ['includesubcategories' => 0],
                    ],
                ],
                'cat' => "{$movedcategory->id},{$stalecontextid}",
            ]),
        ];
        $stalereference->id = $DB->insert_record('question_set_references', $stalereference);

        // A legacy-format reference (pre-4.3 style, no 'filter' key) pointing at the same moved
        // category, to confirm the legacy conversion path is also corrected.
        $legacyreference = (object) [
            'usingcontextid' => 1,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => 3,
            'questionscontextid' => $stalecontextid,
            'filtercondition' => json_encode([
                'questioncategoryid' => $movedcategory->id,
                'includingsubcategories' => false,
            ]),
        ];
        $legacyreference->id = $DB->insert_record('question_set_references', $legacyreference);

        $fixedcount = question_reference_manager::fix_stale_category_context();
        $this->assertEquals(2, $fixedcount);

        // The already-correct reference must be left untouched.
        $updatedcorrect = $DB->get_record('question_set_references', ['id' => $correctreference->id]);
        $this->assertEquals($correctreference, $updatedcorrect);

        // The stale (but self-consistent) reference must now point at the category's real context.
        $updatedstale = $DB->get_record('question_set_references', ['id' => $stalereference->id]);
        $this->assertEquals($movedcategory->contextid, $updatedstale->questionscontextid);
        $stalefilter = json_decode($updatedstale->filtercondition, true);
        $this->assertEquals("{$movedcategory->id},{$movedcategory->contextid}", $stalefilter['cat']);

        // The legacy-format reference must also be corrected and converted.
        $updatedlegacy = $DB->get_record('question_set_references', ['id' => $legacyreference->id]);
        $this->assertEquals($movedcategory->contextid, $updatedlegacy->questionscontextid);
        $legacyfilter = json_decode($updatedlegacy->filtercondition, true);
        $this->assertEquals($movedcategory->id, $legacyfilter['filter']['category']['values'][0]);
        $this->assertEquals("{$movedcategory->id},{$movedcategory->contextid}", $legacyfilter['cat']);
    }

    /**
     * A set reference whose stored category id is the literal placeholder 0 - which can happen
     * when a category mapping (particularly a context's "top" category) has not been resolved
     * yet at the point the filter condition was written during a restore - must be recovered via
     * the reference's own usingcontextid, resolving to that context's top category, rather than
     * being treated as "no category present" and skipped.
     * @covers ::fix_stale_category_context
     */
    public function test_fix_stale_category_context_placeholder_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $topcategory = question_get_top_category($category->contextid, true);

        $reference = (object) [
            'usingcontextid' => $category->contextid,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => 1,
            'questionscontextid' => $category->contextid + 1000, // Deliberately wrong too.
            'filtercondition' => json_encode([
                'filter' => [
                    'category' => [
                        'name' => 'category',
                        'jointype' => 1,
                        'values' => [0],
                        'filteroptions' => ['includesubcategories' => true],
                    ],
                ],
                'cat' => "0,{$category->contextid}",
            ]),
        ];
        $reference->id = $DB->insert_record('question_set_references', $reference);

        $fixedcount = question_reference_manager::fix_stale_category_context();
        $this->assertEquals(1, $fixedcount);

        $updated = $DB->get_record('question_set_references', ['id' => $reference->id]);
        $this->assertEquals($topcategory->contextid, $updated->questionscontextid);
        $filter = json_decode($updated->filtercondition, true);
        $this->assertEquals($topcategory->id, $filter['filter']['category']['values'][0]);
        $this->assertEquals("{$topcategory->id},{$topcategory->contextid}", $filter['cat']);
    }

    /**
     * The $select/$params arguments to fix_stale_category_context() should scope which records
     * are checked, so callers (such as the restore step this was written for) can limit the
     * check to just the records relevant to them rather than scanning the whole table.
     * @covers ::fix_stale_category_context
     */
    public function test_fix_stale_category_context_scoped(): void {
        global $DB;
        $this->resetAfterTest();

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $stalecontextid = $category->contextid + 1000;

        $inscopereference = (object) [
            'usingcontextid' => 1,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => 1,
            'questionscontextid' => $stalecontextid,
            'filtercondition' => json_encode([
                'filter' => ['category' => ['name' => 'category', 'jointype' => 1,
                    'values' => [$category->id], 'filteroptions' => ['includesubcategories' => 0]]],
                'cat' => "{$category->id},{$stalecontextid}",
            ]),
        ];
        $inscopereference->id = $DB->insert_record('question_set_references', $inscopereference);

        $outofscopereference = (object) [
            'usingcontextid' => 1,
            'component' => 'mod_someotherplugin',
            'questionarea' => 'slot',
            'itemid' => 1,
            'questionscontextid' => $stalecontextid,
            'filtercondition' => json_encode([
                'filter' => ['category' => ['name' => 'category', 'jointype' => 1,
                    'values' => [$category->id], 'filteroptions' => ['includesubcategories' => 0]]],
                'cat' => "{$category->id},{$stalecontextid}",
            ]),
        ];
        $outofscopereference->id = $DB->insert_record('question_set_references', $outofscopereference);

        $fixedcount = question_reference_manager::fix_stale_category_context(
            'component = :component',
            ['component' => 'mod_quiz']
        );
        $this->assertEquals(1, $fixedcount);

        $updatedinscope = $DB->get_record('question_set_references', ['id' => $inscopereference->id]);
        $this->assertEquals($category->contextid, $updatedinscope->questionscontextid);

        // The out-of-scope record must be left untouched, even though it also needed fixing.
        $updatedoutofscope = $DB->get_record('question_set_references', ['id' => $outofscopereference->id]);
        $this->assertEquals($stalecontextid, $updatedoutofscope->questionscontextid);
    }
}
