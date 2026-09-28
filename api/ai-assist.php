<?php
// ============================================================
//  API/AI-ASSIST.PHP
//  AI-assisted data entry for the student form.
//  Actions:
//    POST action=paste_fill    → LLM extracts student fields from pasted text
//    POST action=suggest_field → LLM corrects/suggests a single field value
//    POST action=check_duplicate → deterministic fuzzy duplicate scan
//  All LLM responses are cached in ai_cache. Nothing writes to
//  the DB here — this is assist-only.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';
require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/document_reader.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

switch ($action) {

    // ─── EXTRACT FROM DOCUMENT (PDF/DOCX/TXT) ────────────────
    case 'extract_doc':
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload failed.']);
            exit;
        }

        $tmpName = $_FILES['file']['tmp_name'];
        $origName = (string) ($_FILES['file']['name'] ?? 'document');
        $maxBytes = 15 * 1024 * 1024; // 15 MB
        if ((int) $_FILES['file']['size'] > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'File too large (max 15 MB).']);
            exit;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // A4: Image uploads (scans/photos) go through the vision model.
        $imageExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (in_array($ext, $imageExt, true)) {
            $imageData = file_get_contents($tmpName);
            if ($imageData === false || $imageData === '') {
                echo json_encode(['success' => false, 'message' => 'Could not read the image file.']);
                exit;
            }
            $mimeMap = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'];
            $mime = $mimeMap[$ext] ?? 'image/' . $ext;

            $system = "You are a data-entry assistant for a Philippine university registrar. " .
                      "Look at the image of a document (enrolment slip, transcript, PSA birth cert, etc.) " .
                      "and extract the student's details. Respond with ONLY a JSON object using these keys " .
                      "(omit keys you cannot find): first_name, middle_name, last_name, gender, civil_status, " .
                      "birth_date (YYYY-MM-DD), place_of_birth, nationality, religion, email, contact_number, " .
                      "address, course, year_level, guardian_name, guardian_relationship. Be conservative — " .
                      "do not invent data not visible in the image.";

            $raw = aiGenerateVision($system, 'Extract the student details from this document image.', base64_encode($imageData), $mime, ['max_tokens' => 800]);
            $result = [];
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $result = $decoded;
            } elseif (trim($raw) !== '') {
                // Try to salvage a JSON object from the raw text.
                preg_match('/\{.*\}/s', $raw, $m);
                if ($m) {
                    $d = json_decode($m[0], true);
                    if (is_array($d)) $result = $d;
                }
            }

            $result = aiNormalizeExtracted($result);
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        }

        try {
            $text = extractDocumentText($tmpName, $origName);
        } catch (RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        if (trim($text) === '') {
            echo json_encode(['success' => false, 'message' => 'No readable text found in the document.']);
            exit;
        }

        // Reuse the same extraction prompt as paste_fill.
        $system = "You are a data-entry assistant for a Philippine university registrar. " .
                  "Extract student enrollment details from the document text into a JSON object. " .
                  "Use ONLY these keys (omit keys you cannot find): " .
                  "first_name, middle_name, last_name, gender, civil_status, birth_date (YYYY-MM-DD), " .
                  "place_of_birth, nationality, religion, email, contact_number (PH mobile), " .
                  "address, course, year_level (integer), guardian_name, guardian_relationship. " .
                  "Be conservative: do not invent data. If uncertain, leave the key out.";

        $result = aiGenerateJson($system, "Document text to extract from:\n\n" . mb_substr($text, 0, 6000), [], [
            'max_tokens' => 800,
        ]);

        if (is_array($result)) {
            $result = aiNormalizeExtracted($result);
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;

    // ─── PASTE-TO-FILL ──────────────────────────────────────
    case 'paste_fill':
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '') {
            echo json_encode(['success' => false, 'message' => 'No text provided.']);
            exit;
        }

        $system = "You are a data-entry assistant for a Philippine university registrar. " .
                  "Extract student enrollment details from the pasted text into a JSON object. " .
                  "Use ONLY these keys (omit keys you cannot find): " .
                  "first_name, middle_name, last_name, gender, civil_status, birth_date (YYYY-MM-DD), " .
                  "place_of_birth, nationality, religion, email, contact_number (PH mobile), " .
                  "address, course, year_level (integer), guardian_name, guardian_relationship. " .
                  "Be conservative: do not invent data. If uncertain, leave the key out.";

        $result = aiGenerateJson($system, "Text to extract from:\n\n" . mb_substr($text, 0, 6000), [], [
            'max_tokens' => 800,
        ]);

        // Normalize extracted values through the deterministic layer.
        if (is_array($result)) {
            $result = aiNormalizeExtracted($result);
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;

    // ─── SINGLE-FIELD SUGGESTION ────────────────────────────
    case 'suggest_field':
        $field = (string) ($input['field'] ?? '');
        $value = trim((string) ($input['value'] ?? ''));
        $context = (string) ($input['context'] ?? '');
        if ($field === '' || $value === '') {
            echo json_encode(['success' => false, 'message' => 'Field and value required.']);
            exit;
        }

        $system = "You are a data-quality assistant for a Philippine university. " .
                  "Correct/standardize a single field value. Respond with a JSON object: " .
                  "{\"suggested\": \"...\", \"reason\": \"short note\"}. If the value is already fine, " .
                  "return the same value with reason \"ok\". Context: " . mb_substr($context, 0, 400);

        $result = aiGenerateJson($system, "Field: $field\nValue: $value", ['suggested' => $value, 'reason' => 'ok'], [
            'max_tokens' => 200,
        ]);

        $suggested = (string) ($result['suggested'] ?? $value);
        // Deterministic normalization for known fields.
        if ($field === 'contact_number') $suggested = normalizePhone($suggested);
        if ($field === 'course')         $suggested = courseStandardize($suggested);
        if (in_array($field, ['first_name','middle_name','last_name','place_of_birth','religion'], true)) {
            $suggested = normalizeNameCase($suggested);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'suggested' => $suggested,
                'reason'    => (string) ($result['reason'] ?? ''),
            ],
        ]);
        exit;

    // ─── SECTION SUGGESTION (deterministic) ─────────────────
    case 'suggest_section':
        $course = trim((string) ($input['course'] ?? ''));
        $year   = (int) ($input['year_level'] ?? 0);
        $sem    = trim((string) ($input['semester'] ?? ''));
        $sem    = $sem !== '' ? $sem : null;

        if ($course === '' || $year <= 0) {
            echo json_encode(['success' => false, 'data' => ['suggestion' => ''], 'message' => 'Choose a course and year first.']);
            exit;
        }

        require_once __DIR__ . '/../shared/functions.php';
        $next = nextSectionNumber($course, $year, $sem);
        $code = sectionCodeFromParts($year, $sem, $next);

        echo json_encode(['success' => true, 'data' => ['suggestion' => $code]]);
        exit;

    // ─── DUPLICATE CHECK (deterministic) ────────────────────
    case 'check_duplicate':
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName  = trim((string) ($input['last_name'] ?? ''));
        $birthDate = trim((string) ($input['birth_date'] ?? ''));
        $candidates = findDuplicateStudents($firstName, $lastName, $birthDate !== '' ? $birthDate : null);
        echo json_encode(['success' => true, 'data' => $candidates]);
        exit;

    // ── FORM 137 / SF10 SCAN-TO-ENTER ──
    case 'extract_form137':
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload failed.']);
            exit;
        }

        $tmpName = $_FILES['file']['tmp_name'];
        $origName = (string) ($_FILES['file']['name'] ?? 'form137');
        $maxBytes = 15 * 1024 * 1024; // 15 MB
        if ((int) $_FILES['file']['size'] > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'File too large (max 15 MB).']);
            exit;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $imageExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!in_array($ext, $imageExt, true)) {
            echo json_encode(['success' => false, 'message' => 'Upload a scan or photo (PNG, JPG, JPEG, WEBP, GIF).']);
            exit;
        }

        $imageData = file_get_contents($tmpName);
        if ($imageData === false || $imageData === '') {
            echo json_encode(['success' => false, 'message' => 'Could not read the image file.']);
            exit;
        }
        $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $mime = $mimeMap[$ext] ?? 'image/' . $ext;

        $system = "You are a data-entry assistant for a Philippine university registrar. " .
                  "This image is a Form 137 / School Form 10 (permanent academic record) or a transcript page. " .
                  "Extract the record into JSON with these keys (omit keys you cannot find): " .
                  "school_name (string), school_year (string like \2024-2025\), grade_level (string), " .
                  "semester (\1st\, \2nd\, \Summer\ or null), gwa (number or null), remarks (string), " .
                  "subjects (array of {subject: string, units: number or null, grade: string, remarks: string}). " .
                  "Grades may be numbers (1.00-5.00) or letters (P, F, INC, NG). " .
                  "Include every subject row you can read. Be conservative: never invent subjects or grades.";

        $raw = aiGenerateVision($system, 'Extract the Form 137 academic record from this image.', base64_encode($imageData), $mime, ['max_tokens' => 1200]);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            preg_match('/\{.*\}/s', $raw, $m);
            if ($m) {
                $d = json_decode($m[0], true);
                if (is_array($d)) $decoded = $d;
            }
        }
        if (!is_array($decoded)) $decoded = [];

        // Clean up the subject list for the modal.
        $subjects = [];
        foreach ((array) ($decoded['subjects'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $subject = trim((string) ($s['subject'] ?? ''));
            if ($subject === '') continue;
            $subjects[] = [
                'subject' => $subject,
                'units'   => isset($s['units']) && is_numeric($s['units']) ? (float) $s['units'] : null,
                'grade'   => trim((string) ($s['grade'] ?? '')),
                'remarks' => trim((string) ($s['remarks'] ?? '')),
            ];
        }
        $decoded['subjects'] = $subjects;

        // Deterministic GWA fallback when the model did not return one.
        if (empty($decoded['gwa']) && count($subjects) > 0) {
            $vals = [];
            foreach ($subjects as $s) {
                if (is_numeric($s['grade'])) $vals[] = (float) $s['grade'];
            }
            if (count($vals)) $decoded['gwa'] = round(array_sum($vals) / count($vals), 2);
        }

        echo json_encode(['success' => true, 'data' => $decoded]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
