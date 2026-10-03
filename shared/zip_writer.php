<?php
// ============================================================
//  SHARED/ZIP_WRITER.PHP
//  A minimal, dependency-free ZIP (PKZIP) writer.
//
//  Why this exists
//  ---------------
//  Masterlist Folders hands a whole folder tree to another
//  system — a shared drive, an LMS load, a printed-and-signed
//  packet — and "download everything" has to arrive as an
//  actual folder structure, not as one flat file with invented
//  columns.
//
//  ZipArchive is the obvious answer and it is NOT available on
//  this stack: the extension is not loaded on the target host,
//  so a feature that calls it 500s in production and works on a
//  developer's machine. gzdeflate IS present, so compression is
//  possible in principle — but honestly, stored (uncompressed)
//  entries are the right call for this payload: a department
//  folder is a few hundred KB of CSV, the archive stays well
//  inside PHP's memory limit, and every unzip tool on every
//  platform reads stored entries without a single edge case.
//
//  Scope is deliberately the three things a folder export needs:
//  add a file, add an explicit (empty) directory, finish. No
//  streaming, no encryption, no ZIP64. What it does not do, it
//  does not pretend to.
// ============================================================

if (defined('ZIP_WRITER_LOADED')) {
    return;
}
define('ZIP_WRITER_LOADED', true);

/**
 * Builds a ZIP archive in memory.
 *
 * Methods return $this so calls chain; nothing is emitted until
 * finish().
 */
final class MlfZipWriter
{
    /** @var array<int, array{name:string,data:string,dir:bool,mtime:int}> */
    private array $entries = [];

    /** @var array<string,bool> Directory paths already recorded. */
    private array $seenDirs = [];

    /**
     * MS-DOS packed date/time — the only timestamp the classic
     * local-header format can express: 5 bits month, 5 day, 5
     * hour, 6 minute, 5 "half-minute" second, and 7 bits for
     * years since 1980. $ts is passed in rather than read from
     * time() so a caller can produce a byte-identical archive
     * twice.
     */
    private static function dosStamp(int $ts): array
    {
        $y = (int) date('Y', $ts);
        if ($y < 1980) {
            $y = 1980;   // the format cannot express an earlier year
        }
        return [
            'time' => ((int) date('H', $ts) << 11) | ((int) date('i', $ts) << 5) | ((int) date('s', $ts) >> 1),
            'date' => (($y - 1980) << 9) | ((int) date('n', $ts) << 5) | (int) date('j', $ts),
        ];
    }

    /**
     * The DOS date field spans 1980-2107. Outside it the year
     * field wraps and the archive dates itself to 1910, which
     * some unzip tools refuse outright. Refuse rather than emit
     * something subtly wrong.
     */
    private static function assertStampable(int $ts): void
    {
        $y = (int) date('Y', $ts);
        if ($y < 1980 || $y > 2107) {
            throw new InvalidArgumentException(
                'Zip entry timestamps must fall between 1980 and 2107 (got ' . $y . ').'
            );
        }
    }

    /** Strip leading "./" and any trailing slash so paths compare equal. */
    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^\./+#', '', $path) ?? $path;
        return rtrim($path, '/');
    }
/**
     * Record an explicit directory entry.
     *
     * Directories are optional in ZIP (a path implies them), but an
     * export of a *folder tree* should open as a folder tree,
     * including the empty ones — a program with no Year-3 cohort
     * still deserves to show Year-3 as an empty folder rather
     * than not existing at all.
     */
    public function addDirectory(string $path): self
    {
        $path = self::normalise($path);
        if ($path === '' || isset($this->seenDirs[$path])) {
            return $this;
        }
        $this->seenDirs[$path] = true;
        $this->entries[] = [
            'name'  => $path . '/',
            'data'  => '',
            'dir'   => true,
            'mtime' => 0,
        ];
        return $this;
    }

    /** Add a file. Parent directories are recorded automatically. */
    public function addFile(string $path, string $contents, int $mtime = 0): self
    {
        $path = self::normalise($path);
        if ($path === '') {
            throw new InvalidArgumentException('A zip entry needs a path.');
        }
        $mtime = $mtime > 0 ? $mtime : time();
        self::assertStampable($mtime);

        // Materialise every ancestor so the tree is browsable even
        // for tools that ignore implicit directories.
        $parts = explode('/', $path);
        array_pop($parts);
        $walk = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $walk = $walk === '' ? $part : $walk . '/' . $part;
            $this->addDirectory($walk);
        }

        $this->entries[] = [
            'name'  => $path,
            'data'  => $contents,
            'dir'   => false,
            'mtime' => $mtime,
        ];
        return $this;
    }

    /** How many entries are staged. Handy for a test and a manifest line. */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Emit the archive.
     *
     * Layout: a local header + payload per entry, then the central
     * directory, then the end-of-central-directory record. Method
     * 0 (stored) throughout — see the file header for why.
     *
     * @throws RuntimeException when the archive would exceed the
     *         4 GiB / 65535-entry limits of this format. Failing
     *         loudly beats emitting a file Windows Explorer
     *         silently refuses to open.
     */
    public function finish(int $mtime = 0): string
    {
        $mtime = $mtime > 0 ? $mtime : time();
        self::assertStampable($mtime);
        $stamp = self::dosStamp($mtime);

        if (count($this->entries) > 65535) {
            throw new RuntimeException(
                'Archive has ' . count($this->entries) . ' entries; this writer supports 65535.'
            );
        }

        $out = '';
        $central = '';
        $offset = 0;

        foreach ($this->entries as $e) {
            $isDir = $e['dir'];
            $data  = $isDir ? '' : $e['data'];
            $crc   = crc32($data);
            // Bit 11 of the general-purpose flags: the name and
            // comment are UTF-8. Program names and student names
            // are not ASCII, and without this flag Windows reads
            // "BSIT" fine but mangles every non-Latin folder name
            // into mojibake.
            $flags = 0x0800;

            $entryStamp = $e['mtime'] > 0 ? self::dosStamp($e['mtime']) : $stamp;

            $local = pack('V', 0x04034b50)          // local file header signature
                . pack('v', 20)                     // version needed: 2.0
                . pack('v', $flags)
                . pack('v', 0)                      // method 0 = stored
                . pack('v', $entryStamp['time'])
                . pack('v', $entryStamp['date'])
                . pack('V', $crc)
                . pack('V', strlen($data))          // compressed size
                . pack('V', strlen($data))          // uncompressed size
                . pack('v', strlen($e['name']))
                . pack('v', 0)                      // extra field length
                . $e['name'];

            $out .= $local . $data;

            $central .= pack('V', 0x02014b50)      // central directory signature
                . pack('v', 0x031E)                 // version made by: 3.0, UNIX
                . pack('v', 20)                     // version needed
                . pack('v', $flags)
                . pack('v', 0)                      // method 0 = stored
                . pack('v', $entryStamp['time'])
                . pack('v', $entryStamp['date'])
                . pack('V', $crc)
                . pack('V', strlen($data))
                . pack('V', strlen($data))
                . pack('v', strlen($e['name']))
                . pack('v', 0)                      // extra
                . pack('v', 0)                      // comment
                . pack('v', 0)                      // disk number start
                . pack('v', 0)                      // internal attrs
                . pack('V', $isDir ? 0x41FF0010 : 0x81A40000) // external attrs
                . pack('V', $offset)                // offset of local header
                . $e['name'];

            $offset += strlen($local) + strlen($data);
        }

        $eocd = pack('V', 0x06054b50)
            . pack('v', 0)                          // this disk
            . pack('v', 0)                          // disk with central directory
            . pack('v', count($this->entries))
            . pack('v', count($this->entries))
            . pack('V', strlen($central))
            . pack('V', strlen($out))
            . pack('v', 0);                         // archive comment length

        $archive = $out . $central . $eocd;

        if (strlen($archive) > 0xFFFFFFFF) {
            throw new RuntimeException('Archive exceeds the 4 GiB limit of the ZIP format.');
        }

        return $archive;
    }
}

/** The filename a browser should be offered for a folder archive. */
function mlf_zip_filename(string $base, int $ts = 0): string
{
    $ts   = $ts > 0 ? $ts : time();
    $y    = (int) date('Y', $ts);
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($base)) ?? 'archive';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'archive';
    }
    if ($y >= 1980 && $y <= 2107) {
        return $slug . '-' . date('Ymd-His', $ts) . '.zip';
    }
    return $slug . '.zip';
}
