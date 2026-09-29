---
tags: [subsystem]
---

# 📁 Digital File Storage

Subsystem 9 (Phase 1) — uploaded student documents.

## What it does

- Stores uploaded files per student with type, size, MIME, description, uploader
- Doc types: `enrollment`, `transcript`, `health`, `photo`, `clearance`, `other`
- Files land under `uploads/` (gitignored; `uploads/students/` and `uploads/ids/` keep `.htaccess`)
- Phase 1 adds `category` and `is_locked` flags
- **Student photographs live here and only here.** A student's face is a
  `photo` document. The `students.photo` column is a legacy first choice that
  is still *read* (so rows predating File Storage keep their picture) but is no
  longer *written* — the parallel `students.php?action=upload-photo` endpoint was
  removed, because two locations for one face is what left the student list and
  the View modal both rendering initials for students who had a photo on file.

## Tables

- [[documents]] — file metadata (filename, path, size, type)

## Pages

- `registrar/file-storage.php` — file storage management
- `registrar/documents-archive.php` — archive view

## Resolving a stored file

- `shared/stored_file.php` — the one place that decides what a `file_path` means
  - `storedFileRel()` / `storedFileDiskPath()` / `storedFileUrl()` — a path is
    only useful if something can load it, so all three check the disk. A
    database from a copied dump or a promoted staging box names files this host
    never received; those resolve to `''` so callers render an honest "not on
    this server" state rather than a broken image and a 404.
  - `studentPhotoSelectSql()` — the `photo` document subquery, used by every
    page that shows a face. It was written out inline in two pages and the two
    copies were free to drift.
  - `studentPhotoUrl()` / `studentInitials()` — resolution order and the
    initials fallback. Shared by [[RFID Cards]] and student masterlist.


## Related

- [[Subsystems MOC]] · [[Document Requests]] · [[Document Reader]] · [[documents]]
