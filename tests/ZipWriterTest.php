<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/zip_writer.php';

/**
 * The dependency-free ZIP writer behind the folder export.
 *
 * The important assertions are not "does it return bytes" but "does
 * a real unzip tool accept them". A malformed archive does not fail
 * loudly in PHP — it fails on the registrar's machine after the file
 * has already been sent to a department — so these parse the output
 * back byte by byte, and one of them hands the bytes to the system
 * `unzip` when it is available.
 */
final class ZipWriterTest extends TestCase
{
    /** A fixed timestamp so the archives are byte-identical run to run. */
    private const MTIME = 1780000000;   // inside the DOS-representable range

    /**
     * Read the central directory back out of a finished archive.
     *
     * Written as a parser rather than assertions on substrings
     * because the central directory is the only part every unzip
     * tool is guaranteed to read first: a wrong entry count or a
     * bad name length here is what produces "cannot open as zip".
     *
     * @return array<string,string> entry name => payload
     */
    private function readArchive(string $zip): array
    {
        $eocdPos = strrpos($zip, pack('V', 0x06054b50));
        self::assertNotFalse($eocdPos, 'no end-of-central-directory record');

        $eocd = substr($zip, $eocdPos, 22);
        $count  = unpack('v', substr($eocd, 10, 2))[1];
        $size   = unpack('V', substr($eocd, 12, 4))[1];
        $offset = unpack('V', substr($eocd, 16, 4))[1];

        self::assertSame(strlen($zip) - $eocdPos, 22, 'EOCD must be the last 22 bytes');

        $entries = [];
        $pos = $offset;
        for ($i = 0; $i < $count; $i++) {
            self::assertSame(pack('V', 0x02014b50), substr($zip, $pos, 4), 'bad central header');

            $method   = unpack('v', substr($zip, $pos + 10, 2))[1];
            $usize    = unpack('V', substr($zip, $pos + 24, 4))[1];
            $nameLen  = unpack('v', substr($zip, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($zip, $pos + 30, 2))[1];
            $cmtLen   = unpack('v', substr($zip, $pos + 32, 2))[1];
            $localAt  = unpack('V', substr($zip, $pos + 42, 4))[1];
            $name     = substr($zip, $pos + 46, $nameLen);

            // Method 0 (stored) is the only one this writer emits.
            self::assertSame(0, $method, 'entry not stored');

            // The central copy of the local header has to agree with
            // the real one, which is what a tool checks before it
            // trusts an entry.
            $localNameLen = unpack('v', substr($zip, $localAt + 26, 2))[1];
            self::assertSame(
                $name,
                substr($zip, $localAt + 30, $localNameLen),
                'central and local names disagree'
            );

            $dataAt = $localAt + 30 + $localNameLen
                + unpack('v', substr($zip, $localAt + 28, 2))[1];
            $entries[$name] = substr($zip, $dataAt, $usize);

            $pos += 46 + $nameLen + $extraLen + $cmtLen;
        }

        self::assertSame($pos - $offset, $size, 'central directory size mismatch');

        return $entries;
    }

    public function testArchiveCarriesTheLocalAndCentralSignatures(): void
    {
        $zip = (new MlfZipWriter())->addFile('a.txt', 'hello', self::MTIME)->finish(self::MTIME);

        self::assertSame(pack('V', 0x04034b50), substr($zip, 0, 4));
        self::assertStringContainsString((string) pack('V', 0x02014b50), $zip);
        self::assertSame(pack('V', 0x06054b50), substr($zip, -22, 4));
    }

    /** The local header's CRC must match the payload, or integrity checks reject it. */
    public function testCrcMatchesThePayload(): void
    {
        $payload = "Student No.,Name\r\n2026-0001,Dela Cruz\r\n";
        $zip = (new MlfZipWriter())->addFile('a.csv', $payload, self::MTIME)->finish(self::MTIME);
self::assertSame(crc32($payload), unpack('V', substr($zip, 14, 4))[1]);
    }

    public function testFileRoundTripsThroughTheCentralDirectory(): void
    {
        $csv = "Student No.,Name\r\n2026-0001,Dela Cruz\r\n";
        $zip = (new MlfZipWriter())
            ->addFile('BSIT/Year 1/11001/11001-roster.csv', $csv, self::MTIME)
            ->finish(self::MTIME);

        $entries = $this->readArchive($zip);

        self::assertArrayHasKey('BSIT/Year 1/11001/11001-roster.csv', $entries);
        self::assertSame($csv, $entries['BSIT/Year 1/11001/11001-roster.csv']);
    }

    /**
     * A folder tree is only browsable if the directories are real
     * entries. addFile() materialises each ancestor, so a caller
     * never has to create the parent folder first.
     */
    public function testParentFoldersAreMaterialisedAutomatically(): void
    {
        $names = array_keys($this->readArchive(
            (new MlfZipWriter())
                ->addFile('BSIT/Year 1/11001/roster.csv', 'x', self::MTIME)
                ->finish(self::MTIME)
        ));

        self::assertContains('BSIT/', $names);
        self::assertContains('BSIT/Year 1/', $names);
        self::assertContains('BSIT/Year 1/11001/', $names);
        self::assertContains('BSIT/Year 1/11001/roster.csv', $names);
    }

    /** The same directory must not be written twice, which some tools reject. */
    public function testDuplicateDirectoriesAreRecordedOnce(): void
    {
        $names = array_keys($this->readArchive(
            (new MlfZipWriter())
                ->addFile('BSIT/Year 1/a.csv', 'a', self::MTIME)
                ->addFile('BSIT/Year 1/b.csv', 'b', self::MTIME)
                ->finish(self::MTIME)
        ));

        self::assertSame(1, count(array_keys($names, 'BSIT/Year 1/', true)));
    }

    /**
     * An empty cohort still deserves a folder: a program with no
     * Year-3 students should show Year-3 as an empty folder rather
     * than not existing at all.
     */
    public function testExplicitEmptyDirectoryIsKept(): void
    {
        $entries = $this->readArchive(
            (new MlfZipWriter())
                ->addDirectory('BSIT/Year 3')
                ->addFile('BSIT/Year 1/roster.csv', 'x', self::MTIME)
                ->finish(self::MTIME)
        );

        self::assertArrayHasKey('BSIT/Year 3/', $entries);
        self::assertSame('', $entries['BSIT/Year 3/']);
    }

    /**
     * Without bit 11, Windows decodes the name in the local
     * codepage and every non-ASCII folder or student name comes out
     * as mojibake on the receiving machine.
     */
    public function testNamesAreFlaggedAsUtf8(): void
    {
        $zip = (new MlfZipWriter())
            ->addFile('BSIT/Year 1/11001/roster.csv', 'x', self::MTIME)
            ->finish(self::MTIME);

        self::assertSame(0x0800, unpack('v', substr($zip, 6, 2))[1] & 0x0800);
    }

    public function testUnicodeNamesRoundTripIntact(): void
    {
        $zip = (new MlfZipWriter())->addFile('BSED/Ñoño.csv', 'x', self::MTIME)->finish(self::MTIME);

        self::assertArrayHasKey('BSED/Ñoño.csv', $this->readArchive($zip));
    }

    /**
     * The DOS date field spans 1980-2107. Outside it the year
     * wraps and the archive dates itself to 1910.
     */
    public function testTimestampsOutsideTheDosRangeAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MlfZipWriter())->addFile('a.txt', 'x', 1);   // 1970
    }

    public function testFilenamesAreSanitisedOfLeadingDotSlash(): void
    {
        $zip = (new MlfZipWriter())->addFile('./BSIT/roster.csv', 'x', self::MTIME)->finish(self::MTIME);

        self::assertArrayHasKey('BSIT/roster.csv', $this->readArchive($zip));
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MlfZipWriter())->addFile('   ', 'x', self::MTIME);
    }

    /** Byte-identical output for identical input, which makes exports diffable. */
    public function testSameInputProducesByteIdenticalOutput(): void
    {
        $build = static fn(): string => (new MlfZipWriter())
            ->addFile('BSIT/roster.csv', "a,b\r\n", self::MTIME)
            ->finish(self::MTIME);

        self::assertSame($build(), $build());
    }

    public function testGeneratedFilenameIsSluggedAndTimestamped(): void
    {
        self::assertMatchesRegularExpression('/^BSIT-\d{8}-\d{6}\.zip$/', mlf_zip_filename('BSIT', self::MTIME));
        // A program name with a slash must not reach the filename.
        self::assertStringNotContainsString('/', mlf_zip_filename('BSIT/Night', self::MTIME));
        self::assertStringNotContainsString('/', mlf_zip_filename('', self::MTIME));
    }

    /**
     * The final check, and the only one that uses a tool that is
     * not this code: hand the bytes to the system unzip. Skipped
     * where unzip is absent — notably this project's Windows dev
     * host, where `command -v` is not a thing — because the parser
     * above already covers the format on its own.
     */
    public function testTheSystemUnzipAcceptsTheArchive(): void
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            self::markTestSkipped('No POSIX unzip on this host; the parser covers the format.');
        }

        $unzip = trim((string) @shell_exec('command -v unzip 2>/dev/null'));
        if ($unzip === '') {
            self::markTestSkipped('unzip is not available on this host.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mlfzip');
        file_put_contents($tmp, (new MlfZipWriter())
            ->addFile('BSIT/Year 1/11001/11001-roster.csv', "a,b\r\n", self::MTIME)
            ->finish(self::MTIME));

        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($unzip) . ' -t ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, 'unzip rejected the archive: ' . implode("\n", $out));
        } finally {
            @unlink($tmp);
        }
    }
}