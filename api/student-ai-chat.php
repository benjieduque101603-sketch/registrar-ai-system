<?php
// ============================================================
//  API/STUDENT-AI-CHAT.PHP
//  Floating AI chat for the Student Portal.
//
//  POST  { message }
//
//  Flow:
//    1. requireStudent() — only a logged-in student (or admin).
//    2. keyword fast-path → deterministic intent (offline).
//    3. LLM via shared/ai_client.php (aiGenerateJson) with the
//       student's own context for personalized answers.
//    4. low-confidence / empty / unreachable → exact registrar
//       referral fallback.
//  Responses are cached in `ai_cache` through aiGenerate().
//  Nothing here ever writes to student records.
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../shared/config.php';
corsSameOrigin();
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/ai_client.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only student-role (or admin) may use the portal chat.
requireStudent();

// Resolve the caller's own student context (same rule as the portal:
// never trust a client-supplied id).
$studentId = getCurrentStudentId();
$student   = $studentId ? Database::getInstance()->fetchOne("SELECT * FROM students WHERE id = ?", [$studentId]) : null;

$fallback = 'This requires registrar assistance. Please visit the registrar\'s office or contact staff directly.';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim((string) ($input['message'] ?? ''));
if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'Please type a question.']);
    exit;
}
if (mb_strlen($message) > 500) {
    $message = mb_substr($message, 0, 500);
}

$db = Database::getInstance();

// ─── Tier 1 · deterministic keyword fast-path ──────────────────
// Mirrors the api/rfid-ai-search.php keyword-list pattern. Gives an
// immediate answer (or a pointer) offline; the LLM is still the
// primary path for anything unsupported.
$intents = [
    'good_moral' => [
        ['good moral', 'character', 'conduct'],
        'Certificate of Good Moral costs ₱150.00. Request it under Document Requests → New Request (needs no pending disciplinary case). Fulfillment options are Pickup, Digital, or Courier.',
    ],
    'transcript' => [
        ['transcript', 'tor', 'record of grades'],
        'A Transcript of Records (TOR) is ₱250.00 per page. Submit it under Document Requests → New Request, then track it from the desk until it is ready to collect.',
    ],
    'form137' => [
        ['form 137', 'form137', 'sf10', 'school form'],
        'Form 137 (School Form 10) is your permanent academic record. Official copies are released through the catalog as a Transcript of Records or Certified True Copy under Document Requests.',
    ],
    'certificate' => [
        ['certificate', 'certification', 'enrollment cert', 'cert of enrollment'],
        'Certificate of Enrollment costs ₱100.00. Request it under Document Requests → New Request and state your purpose and recipient (or office).',
    ],
    'enrollment' => [
        ['enroll', 'enrollment', 'enrolment', 'register for classes'],
        'Enrollment is handled by the school. For registration issues visit the Registrar\'s Office or your adviser.',
    ],
    'queue' => [
        ['queue', 'line', 'number', 'waiting', 'in line'],
        'Your current queue ticket and position appear under Queue Management. If you no longer need your ticket, you can cancel it there. New numbers are issued at the kiosk.',
    ],
    'schedule' => [
        ['schedule', 'class time', 'time table'],
        'Your subject schedule, room, and instructor are listed under Academic Records, per term.',
    ],
    'grades' => [
        ['grade', 'grades', 'gwa', 'midterm', 'final', 'rating'],
        'Your grades, including midterm and final ratings, are listed under Academic Records per term. For grading concerns, contact the instructor or the Registrar\'s Office.',
    ],
    'id' => [
        ['id card', 'school id', 'library card', 'cafeteria card', 'student id'],
        'Issued IDs are shown under ID & Status. For a replacement or a new card, visit the Registrar\'s Office or guidance. RFID-related lost-card reports go through the office too.',
    ],
    'documents' => [
        ['document', 'form request', 'request doc', 'get a copy', 'apply for', 'request a copy', 'diploma', 'course description', 'certified true copy', 'ctc', 'honorable dismissal', 'catalog', 'price', 'cost', 'how much'],
        'Document Requests covers the priced catalog: TOR ₱250/page, Certificate of Enrollment ₱100, Good Moral ₱150, Diploma Replacement ₱1,000, Certified True Copy ₱50/page, Honorable Dismissal ₱300, Course Description ₱100/syllabus. Pick Pickup, Digital, or Courier, then track it from filing through to collection.',
    ],
    'digital_document' => [
        ['digital', 'pdf', 'download pdf', 'encrypted', 'password for pdf'],
        'Digital documents are released as encrypted PDFs — the PDF password is your birthdate (YYYY-MM-DD). Each PDF carries a QR code linking to the public verification portal.',
    ],
    'health' => [
        ['health', 'medical', 'clinic', 'blood type', 'bmi'],
        'Your health profile (blood type, height, weight, BMI, medical/surgical history) is under Health Records. Clinic visits are logged by the campus clinic.',
    ],
    'contact' => [
        ['contact', 'office', 'registrar', 'staff', 'where', 'address', 'phone'],
        'The Registrar\'s Office is on the campus main building. The portal cannot disclose staff contact numbers; please visit or call the school\'s listed hotline.',
    ],
    'password' => [
        ['password', 'forgot', 'login', 'sign in', 'otp'],
        'To reset your password, use the "Forgot password" link on the login page — it will ask for your email and send a one-time code.',
    ],
    'tuition' => [
        ['tuition', 'payment', 'pay', 'fee', 'balance', 'bursar', 'finance'],
        'Tuition and payment concerns are handled by the Finance/Bursar office, not the Registrar. Document requests are held as "Pending Clearance" until any outstanding balance is settled.',
    ],
    'graduation' => [
        ['graduate', 'graduation', 'commencement', 'convocation'],
        'Your student status shows as Graduated when records are marked. For graduation requirements and TOR release, coordinate with the Registrar\'s Office.',
    ],
];

$matchedIntent = null;
foreach ($intents as $key => [$keywords, $answer]) {
    foreach ($keywords as $kw) {
        if (mb_stripos($message, $kw) !== false) {
            $matchedIntent = $key;
            break 2;
        }
    }
}

$reply = '';
if ($matchedIntent !== null) {
    // Only use the deterministic answer when the wording is tightly matched
    // (short question) — otherwise let the LLM give a personalized reply.
    $reply = mb_strlen($message) <= 45 ? $intents[$matchedIntent][1] : '';
}

$usedTier = 'keyword';

// ─── Tier 2 · LLM ──────────────────────────────────────────────
if ($reply === '') {
    $usedTier = 'llm';

    // Personalized system prompt with the student's own context.
    $ctx = '';
    if ($student) {
        $ctx = "- Student: " . trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))
             . " [" . ($student['student_number'] ?? '') . "], status " . getStudentStatusLabel($student['status'] ?? '')
             . ", course " . ($student['course'] ?? '—')
             . ", AY " . ($student['school_year'] ?? '—') . ' ' . ($student['semester'] ?? '')
             . ", section " . ($student['section'] ?? '—');
        // Active queue ticket, if any.
        $today = date('Y-m-d');
        $q = $db->fetchOne(
            "SELECT ticket_number, status, counter FROM queue_tickets
             WHERE queue_date = ? AND student_id = ? AND status IN ('waiting','serving')
             ORDER BY joined_at DESC LIMIT 1",
            [$today, $student['id']]
        );
        if ($q) {
            $ctx .= ". Queue: #" . str_pad((string) $q['ticket_number'], 3, '0', STR_PAD_LEFT) . ' (' . $q['status'] . ')';
        }
        // Pending document requests (v2 workflow — anything not yet terminal).
        $pending = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM document_requests
             WHERE student_id = ?
               AND document_status IN ('Pending_Clearance','Awaiting_Payment','Processing','Ready','Shipped')",
            [$student['id']]
        );
        if ($pending > 0) {
            $ctx .= ". $pending active document request(s)";
        }

        // Academic history — compact stats so the LLM can answer
        // questions about the student's own grades/GWA.
        $acadTerms = $db->fetchAll(
            "SELECT id, school_name, school_year, semester, gwa FROM academic_history
             WHERE student_id = ? ORDER BY school_year IS NULL, school_year, semester IS NULL, semester, id ASC",
            [$student['id']]
        );
        if ($acadTerms) {
            $termIds = array_column($acadTerms, 'id');
            $ph = implode(',', array_fill(0, count($termIds), '?'));
            $acadGrades = $db->fetchAll(
                "SELECT subject, grade FROM academic_grades WHERE academic_history_id IN ($ph)",
                $termIds
            );
            $nums = [];
            $weak = [];
            foreach ($acadGrades as $ag) {
                $subj = trim((string) ($ag['subject'] ?? ''));
                $gr   = trim((string) ($ag['grade'] ?? ''));
                if ($subj === '') continue;
                if (is_numeric($gr)) {
                    $gv = (float) $gr;
                    if ($gv >= 1.0 && $gv <= 5.0) $nums[] = $gv;
                    if ($gv > 3.0) $weak[] = $subj;
                } else {
                    $gl = strtolower($gr);
                    if (in_array($gl, ['f', 'inc', 'ng', 'drp'], true)) $weak[] = $subj;
                }
            }
            $acadGwa = count($nums) ? number_format(array_sum($nums) / count($nums), 2) : null;
            $ctx .= '. Academic: ' . count($acadTerms) . ' term(s) on file, ' . count($acadGrades) . ' subject(s), computed GWA ' . ($acadGwa ?? 'n/a')
                 . ($weak ? ', needs attention: ' . implode(', ', array_slice(array_unique($weak), 0, 4)) : '');
        }
    }

    $system = "You are the friendly AI assistant for the BCP (Bestlink College of the Philippines) "
            . "Registrar's Office, answering students inside their portal. Answer ONLY about registrar "
            . "matters: documents (priced catalog — TOR ₱250/page, Certificate of Enrollment ₱100, Good Moral ₱150, "
            . "Diploma Replacement ₱1,000, Certified True Copy ₱50/page, Honorable Dismissal ₱300, Course Description ₱100/syllabus; "
            . "workflow: filed at the desk → being prepared → ready to collect → collected, with any fee paid at the counter; digital PDFs are encrypted "
            . "with the birthdate password and verified via QR, "
            . "queue tickets, enrollment, student status, grades, IDs, RFIDs, and general school record procedures.\n"
            . "Rules:\n"
            . "- Be concise and helpful, 2-4 short sentences.\n"
            . "- Do NOT invent office phone numbers, emails, prices, or policies that you are not sure of.\n"
            . "- If you do not know an answer or it is out of scope, respond with EXACTLY and ONLY the phrase:\n"
            . "  \"This requires registrar assistance. Please visit the registrar's office or contact staff directly.\"\n"
            . ($ctx !== '' ? "\nCurrent logged-in student context (you may personalize using it):\n$ctx\n" : '')
            . "\nReturn JSON: {\"intent\": <short kebab-case label>, \"confidence\": <0.0-1.0>, \"answer\": <your reply>}.";

    $fallbackJson = ['intent' => 'fallback', 'confidence' => 0.0, 'answer' => $fallback];
    $result = aiGenerateJson($system, $message, $fallbackJson, ['max_tokens' => 300, 'temperature' => 0.3]);
    $reply  = (string) ($result['answer'] ?? $fallback);
    $intent = (string) ($result['intent'] ?? 'general');
    $confidence = (float) ($result['confidence'] ?? 0.0);

    // Refuse obviously empty/off-topic replies → registrar referral.
    if (trim($reply) === '' || $confidence < 0.25 || !is_string($reply)) {
        $reply = $fallback;
    }

    // Never leak raw data the model flagged as unsure.
    if (preg_match('/\b(I do not know|I don\'t know|not sure|unable to answer)\b/i', $reply)) {
        $reply = $fallback;
    }
    $usedTier = $usedTier . ':' . $intent;
}

logActivity(getCurrentUserId(), 'student_ai_chat', json_encode(['question' => $message, 'tier' => $usedTier]));

echo json_encode([
    'success' => true,
    'data'    => [
        'answer' => $reply,
        'tier'   => $usedTier,
        'fallback_used' => ($reply === $fallback),
    ],
]);
