<?php

// ============================================================
//  TESTS/GradeStatusVocabularyTest.php
//  The grade_status vocabulary must not drift from the column.
//
//  academic_grades.grade_status is an ENUM('passed','failed','incomplete',
//  'dropped'). The grading dropdown used to offer only three of those four.
//  The symptom was silent and lossy: a row stored as 'incomplete' reopened
//  with no matching <option>, the select fell back to "Not set", and the
//  next save wrote NULL over a real value. Nothing warned.
//
//  These tests compare the PHP vocabulary against the LIVE column, so the
//  two cannot drift apart again without a failure — including after someone
//  edits the ENUM in a migration and forgets the UI.
// ============================================================

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/term_grades.php';
require_once __DIR__ . '/../shared/database.php';

final class GradeStatusVocabularyTest extends TestCase
{
    /** The ENUM as the database actually defines it right now. */
    private static function enumValues(): array
    {
        $db = Database::getInstance();
        foreach ($db->fetchAll('SHOW COLUMNS FROM academic_grades') as $col) {
            if ($col['Field'] !== 'grade_status') {
                continue;
            }
            $type = (string) $col['Type'];
            if (!preg_match('/^enum\((.*)\)$/i', $type, $m)) {
                self::fail('grade_status is no longer an ENUM: ' . $type);
            }
            // "'passed','failed','incomplete','dropped'" → ['passed', ...]
            preg_match_all("/'([^']*)'/", $m[1], $vals);
            return $vals[1];
        }
        self::fail('academic_grades.grade_status not found.');
    }

    public function testEveryEnumValueIsOfferedByTheVocabulary(): void
    {
        self::assertEqualsCanonicalizing(
            self::enumValues(),
            gradeStatusValues(),
            'The grade_status ENUM and gradeStatusOptions() disagree. A value '
            . 'stored in the column with no matching option reopens as "Not '
            . 'set" and is overwritten with NULL on the next save.'
        );
    }

    public function testVocabularyOffersNothingTheColumnCannotStore(): void
    {
        foreach (gradeStatusValues() as $v) {
            self::assertContains($v, self::enumValues(),
                "'{$v}' is offered by the UI but the column cannot store it.");
        }
    }

    /**
     * 'incomplete' is the value that was missing. It is a real state —
     * midterm done, final not yet — so it must stay in the vocabulary.
     */
    public function testIncompleteIsRetained(): void
    {
        self::assertContains('incomplete', gradeStatusValues());
        self::assertContains('incomplete', self::enumValues());
    }

    public function testTheEmptyNotSetOptionIsExcludedFromValidValues(): void
    {
        // "Not set" is a UI affordance for NULL, not a storable string. If
        // '' ever entered gradeStatusValues() it would be offered as a real
        // status and could be written to the column.
        self::assertNotContains('', gradeStatusValues());
        self::assertFalse(gradeStatusValid(''));
        self::assertFalse(gradeStatusValid(null));
    }

    public function testValidatorAcceptsEachRealStatusAndRejectsOthers(): void
    {
        foreach (gradeStatusValues() as $v) {
            self::assertTrue(gradeStatusValid($v), "{$v} should be valid");
        }
        self::assertFalse(gradeStatusValid('enrolled'));
        self::assertFalse(gradeStatusValid('PASSED'), 'Matching must be exact, not loose.');
    }

    /** Every option must carry a label, or the dropdown shows a blank row. */
    public function testEveryOptionHasANonEmptyLabel(): void
    {
        foreach (gradeStatusOptions() as $opt) {
            self::assertArrayHasKey('value', $opt);
            self::assertArrayHasKey('label', $opt);
            self::assertNotSame('', trim((string) $opt['label']));
        }
    }

    public function testOptionValuesAreUnique(): void
    {
        $values = array_column(gradeStatusOptions(), 'value');
        self::assertSame(
            count($values),
            count(array_unique($values)),
            'Two options share a value; the select would show the first only.'
        );
    }
}