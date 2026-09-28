<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/section_code.php';

final class SectionCodeTest extends TestCase
{
    public function testBuildsCodeFromYearSemesterAndNumber(): void
    {
        self::assertSame('11001', sectionCodeFromParts(1, '1st', 1));
        self::assertSame('12001', sectionCodeFromParts(1, '2nd', 1));
        self::assertSame('21002', sectionCodeFromParts(2, '1st', 2));
        self::assertSame('43012', sectionCodeFromParts(4, 'summer', 12));
    }

    public function testEmptySemesterDefaultsToFirstSemesterDigit(): void
    {
        self::assertSame('11001', sectionCodeFromParts(1, '', 1));
        self::assertSame('11001', sectionCodeFromParts(1, null, 1));
    }

    /**
     * A missing year level must never be coerced into year 1 — that is what
     * stamped "11001" onto students who had no year level at all.
     */
    public function testRejectsMissingOrOutOfRangeYearLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sectionCodeFromParts(0, '1st', 1);
    }

    public function testRejectsYearLevelAboveNine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sectionCodeFromParts(10, '1st', 1);
    }

    public function testStillClampsSectionNumberIntoRange(): void
    {
        self::assertSame('11001', sectionCodeFromParts(1, '1st', 0));
        self::assertSame('11999', sectionCodeFromParts(1, '1st', 999));
    }
}
