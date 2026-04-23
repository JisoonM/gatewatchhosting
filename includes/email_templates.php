<?php

/**
 * PCU GateWatch – Email Template Library
 *
 * All HTML is pre-compiled from MJML markup and can be re-compiled on demand
 * via the MJML API (https://api.mjml.io/v1/render) if credentials are provided
 * in the .env file (MJML_APP_ID, MJML_SECRET_KEY).
 *
 * Usage:
 *   require_once __DIR__ . '/email_templates.php';
 *   $html = emailGateMark1($student, $timestamp);
 *   sendMail($student['email'], 'Gate Mark #1', $html);
 */

// ─── MJML API Compiler ────────────────────────────────────────────────────────

/**
 * Compile MJML source to HTML via the MJML API.
 * Returns compiled HTML on success, null on failure (uses pre-compiled fallback).
 * Requires MJML_APP_ID and MJML_SECRET_KEY in .env
 */
function compileMjml(string $mjmlSource): ?string {
    $appId     = function_exists('env') ? env('MJML_APP_ID', '')     : ($_ENV['MJML_APP_ID']     ?? '');
    $secretKey = function_exists('env') ? env('MJML_SECRET_KEY', '') : ($_ENV['MJML_SECRET_KEY'] ?? '');

    if (empty($appId) || empty($secretKey)) {
        return null; // Fall back to pre-compiled HTML
    }

    if (!function_exists('curl_init')) {
        error_log('MJML API: cURL not available.');
        return null;
    }

    $ch = curl_init('https://api.mjml.io/v1/render');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $appId . ':' . $secretKey,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['mjml' => $mjmlSource]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("MJML API cURL error: {$curlError}");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("MJML API returned HTTP {$httpCode}: {$response}");
        return null;
    }

    $data = json_decode($response, true);
    if (!empty($data['errors'])) {
        error_log('MJML compile errors: ' . json_encode($data['errors']));
    }

    return $data['html'] ?? null;
}

// ─── Shared Base Template ─────────────────────────────────────────────────────

/**
 * Builds a complete, Outlook-compatible responsive email.
 *
 * @param string $title        Browser/email client title
 * @param string $headerBg     Header background color (hex)
 * @param string $headerIcon   Unicode icon (safe for most email clients via UTF-8 encoding)
 * @param string $headerTitle  Bold heading text
 * @param string $headerSub    Smaller subtitle below heading
 * @param string $body         Inner body HTML (pre-built rows)
 * @return string              Full HTML email
 */
function _emailBase(
    string $title,
    string $headerBg,
    string $headerIcon,
    string $headerTitle,
    string $headerSub,
    string $body
): string {
    $year = date('Y');
    // Darken header color for Outlook solid fallback (used instead of gradient)
    return <<<HTML
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <title>{$title}</title>
  <!--[if !mso]><!-->
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!--<![endif]-->
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <style type="text/css">
    #outlook a { padding: 0; }
    body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    p { display: block; margin: 0; }
    a { color: inherit; }
    .ExternalClass { width: 100%; }
    .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div { line-height: 100%; }
  </style>
  <style type="text/css">
    @media only screen and (max-width: 620px) {
      .email-wrapper  { width: 100% !important; }
      .email-body-pad { padding: 24px 20px !important; }
      .stat-cell      { display: block !important; width: 100% !important; }
      .mobile-center  { text-align: center !important; }
    }
  </style>
</head>
<body style="background-color:#eef2f7; margin:0; padding:0; font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">

<!--[if mso | IE]>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#eef2f7">
  <tr><td>
<![endif]-->

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#eef2f7;">
  <tr>
    <td align="center" style="padding: 28px 16px;">

      <!-- ═══ Email Card ═══ -->
      <table class="email-wrapper" role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
             style="max-width:600px; background-color:#ffffff; border-radius:10px; overflow:hidden;
                    box-shadow:0 4px 20px rgba(0,0,0,0.12);">

        <!-- ▸ Header -->
        <tr>
          <td align="center" bgcolor="{$headerBg}"
              style="background-color:{$headerBg}; padding: 32px 40px 28px;">
            <!--[if mso]>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td>
            <![endif]-->
            <p style="margin:0 0 6px; font-family:Arial,sans-serif; font-size:10px; font-weight:700;
                      letter-spacing:2.5px; color:rgba(255,255,255,0.7); text-transform:uppercase;">
              Philippine Christian University
            </p>
            <h1 style="margin:0; font-family:Arial,sans-serif; font-size:24px; font-weight:700;
                       color:#ffffff; line-height:1.3;">
              {$headerIcon}&nbsp;{$headerTitle}
            </h1>
            <p style="margin:8px 0 0; font-family:Arial,sans-serif; font-size:13px;
                      color:rgba(255,255,255,0.85); line-height:1.4;">
              {$headerSub}
            </p>
            <!--[if mso]></td></tr></table><![endif]-->
          </td>
        </tr>

        <!-- ▸ Body -->
        <tr>
          <td class="email-body-pad" style="padding: 32px 40px; background-color:#ffffff;">
            {$body}
          </td>
        </tr>

        <!-- ▸ Footer -->
        <tr>
          <td align="center"
              style="background-color:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 40px;">
            <p style="margin:0; font-family:Arial,sans-serif; font-size:11px; color:#94a3b8; line-height:1.6;">
              This is an automated message from the <strong style="color:#64748b;">PCU GateWatch System</strong>.<br>
              Philippine Christian University &bull; Student Services Office<br>
              &copy; {$year} PCU &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>

      </table><!-- /Email Card -->

    </td>
  </tr>
</table>

<!--[if mso | IE]></td></tr></table><![endif]-->

</body>
</html>
HTML;
}

// ─── Shared UI Components ─────────────────────────────────────────────────────

/** Greeting line */
function _emailGreeting(string $name): string {
    $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return "<p style='margin:0 0 20px; font-family:Arial,sans-serif; font-size:15px; color:#374151;'>Dear <strong>{$safe}</strong>,</p>";
}

/** Coloured callout banner */
function _emailBanner(string $bgColor, string $borderColor, string $textColor, string $html): string {
    return "
    <table role='presentation' border='0' cellpadding='0' cellspacing='0' width='100%' style='margin:18px 0;'>
      <tr>
        <td style='background-color:{$bgColor}; border-left:4px solid {$borderColor};
                   padding:14px 18px; border-radius:0 6px 6px 0;'>
          <p style='margin:0; font-family:Arial,sans-serif; font-size:15px; font-weight:700;
                    color:{$textColor}; line-height:1.4;'>{$html}</p>
        </td>
      </tr>
    </table>";
}

/** Info details card (key→value rows) */
function _emailDetailsTable(array $rows): string {
    $html = "
    <table role='presentation' border='0' cellpadding='0' cellspacing='0' width='100%'
           style='background-color:#f8fafc; border-radius:8px; margin:18px 0; overflow:hidden;'>
      <tr>
        <td style='padding:4px 0;'>
          <table role='presentation' border='0' cellpadding='0' cellspacing='0' width='100%'>";
    foreach ($rows as [$label, $value, $valueColor]) {
        $html .= "
            <tr>
              <td width='44%' style='padding:10px 20px; font-family:Arial,sans-serif; font-size:13px;
                                     color:#64748b; vertical-align:top; border-bottom:1px solid #e2e8f0;'>
                {$label}
              </td>
              <td width='56%' style='padding:10px 20px; font-family:Arial,sans-serif; font-size:13px;
                                     color:{$valueColor}; font-weight:600; vertical-align:top;
                                     border-bottom:1px solid #e2e8f0;'>
                {$value}
              </td>
            </tr>";
    }
    $html .= "
          </table>
        </td>
      </tr>
    </table>";
    return $html;
}

/** Action box (e.g. "What You Need To Do") */
function _emailActionBox(string $bgColor, string $borderColor, string $titleColor, string $textColor, string $iconTitle, string $content): string {
    return "
    <table role='presentation' border='0' cellpadding='0' cellspacing='0' width='100%'
           style='margin:18px 0; border-radius:8px; overflow:hidden;
                  border:2px solid {$borderColor}; background-color:{$bgColor};'>
      <tr>
        <td style='padding:18px 20px;'>
          <p style='margin:0 0 10px; font-family:Arial,sans-serif; font-size:14px; font-weight:700;
                    color:{$titleColor};'>{$iconTitle}</p>
          <div style='font-family:Arial,sans-serif; font-size:13px; color:{$textColor}; line-height:1.7;'>
            {$content}
          </div>
        </td>
      </tr>
    </table>";
}

/** Body paragraph */
function _emailPara(string $text): string {
    return "<p style='margin:0 0 14px; font-family:Arial,sans-serif; font-size:14px; color:#374151; line-height:1.65;'>{$text}</p>";
}

// ─── Individual Email Templates ───────────────────────────────────────────────

/**
 * Gate Mark #1 — informational blue
 * Sent when a student enters without physical ID for the first time.
 */
function emailGateMark1(array $student, string $timestamp): string {
    $name      = htmlspecialchars($student['name'],       ENT_QUOTES, 'UTF-8');
    $studentId = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($student['name']);
    $body .= _emailBanner('#dbeafe', '#3b82f6', '#1e40af',
        'You entered the gate without your physical ID card today.');
    $body .= _emailPara('This is your <strong>1st gate mark</strong> this semester. The GateWatch System records entries without a physical student ID. After <strong>3 marks total</strong>, a formal violation will be automatically created.');
    $body .= _emailDetailsTable([
        ['Date &amp; Time',  $timestamp, '#111827'],
        ['Student ID',       $studentId, '#111827'],
        ['Gate Marks',       '1 of 3',  '#2563eb'],
        ['Status',           'Entry Allowed', '#059669'],
    ]);
    $body .= _emailActionBox('#eff6ff', '#bfdbfe', '#1e40af', '#1e3a8a',
        'ℹ️ Reminder',
        'Please remember to bring your <strong>physical student ID card</strong> every day. Avoid accumulating gate marks to prevent a formal violation.');

    return _emailBase(
        'Gate Mark #1 — PCU GateWatch',
        '#2563eb',
        'ℹ️',
        'Gate Mark #1 Recorded',
        'PCU GateWatch System — Entry Notification',
        $body
    );
}

/**
 * Gate Mark #2 — amber warning
 * Sent on the second entry without ID.
 */
function emailGateMark2(array $student, string $timestamp): string {
    $name      = htmlspecialchars($student['name'],       ENT_QUOTES, 'UTF-8');
    $studentId = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($student['name']);
    $body .= _emailBanner('#fef3c7', '#f59e0b', '#92400e',
        '⚠️ This is your 2nd gate mark. ONE more scan without your physical ID will trigger a formal violation.');
    $body .= _emailPara('You have accumulated <strong>2 out of 3</strong> gate marks. A third entry without your physical student ID card will automatically generate a <strong>formal violation record</strong> that will need to be resolved at the Student Services Office.');
    $body .= _emailDetailsTable([
        ['Date &amp; Time',  $timestamp, '#111827'],
        ['Student ID',       $studentId, '#111827'],
        ['Gate Marks',       '2 of 3 — WARNING', '#d97706'],
        ['Status',           'Entry Allowed', '#059669'],
    ]);
    $body .= _emailActionBox('#fffbeb', '#f59e0b', '#b45309', '#78350f',
        '⚠️ Urgent Reminder',
        'Bring your <strong>physical student ID card tomorrow and every school day</strong>. One more gate entry without it will create a formal violation and may affect your access to school services.');

    return _emailBase(
        'Gate Mark #2 Warning — PCU GateWatch',
        '#d97706',
        '⚠️',
        'Gate Mark #2 — Warning',
        'One more mark creates a formal violation',
        $body
    );
}

/**
 * Formal Violation Created — red/urgent
 * Sent when a student hits the 3rd gate mark (violation auto-created).
 */
function emailViolationCreated(array $student, int $violationCount, string $timestamp): string {
    $name      = htmlspecialchars($student['name'],       ENT_QUOTES, 'UTF-8');
    $studentId = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($student['name']);
    $body .= _emailBanner('#fee2e2', '#dc2626', '#991b1b',
        'You accumulated 3 gate marks without a physical ID. A formal violation has now been recorded in your student record.');
    $body .= _emailPara('Your gate mark counter has been reset to zero. You now have an <strong>active violation</strong> on your record. You are required to report to the <strong>Student Services Office (SSO)</strong> to resolve it.');
    $body .= _emailDetailsTable([
        ['Date &amp; Time',  $timestamp,                 '#111827'],
        ['Student ID',       $studentId,                 '#111827'],
        ['Violation',        'No Physical ID',           '#dc2626'],
        ['Total Violations', "#{$violationCount} Active", '#dc2626'],
        ['Gate Marks Reset', '0 / 3',                   '#374151'],
    ]);
    $body .= _emailActionBox('#fff1f2', '#fca5a5', '#991b1b', '#7f1d1d',
        '🚨 Action Required',
        'Please visit the <strong>Student Services Office (SSO)</strong> during office hours to:
        <ul style="margin:8px 0 0 0; padding-left:20px;">
          <li style="margin-bottom:4px;">Learn the required reparation task assigned to you</li>
          <li style="margin-bottom:4px;">Complete the task and have it cleared by the SSO</li>
          <li>Ensure you carry your physical student ID every day going forward</li>
        </ul>');

    return _emailBase(
        'Formal Violation Recorded — PCU GateWatch',
        '#dc2626',
        '🚨',
        'Formal Violation Recorded',
        'Report to the Student Services Office immediately',
        $body
    );
}

/**
 * First Violation Notice — blue-gray informational
 * Sent when admin records a student's first active violation.
 */
function emailFirstViolationNotice(string $name, string $studentId, string $violationName, string $violationTypeLong, int $offenseNumber, string $semester, string $schoolYear, string $timestamp): string {
    $safeName   = htmlspecialchars($name,          ENT_QUOTES, 'UTF-8');
    $safeId     = htmlspecialchars($studentId,     ENT_QUOTES, 'UTF-8');
    $safeViol   = htmlspecialchars($violationName, ENT_QUOTES, 'UTF-8');
    $safeType   = htmlspecialchars($violationTypeLong, ENT_QUOTES, 'UTF-8');
    $safeSem    = htmlspecialchars($semester,      ENT_QUOTES, 'UTF-8');
    $safeSY     = htmlspecialchars($schoolYear,    ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($name);
    $body .= _emailBanner('#eff6ff', '#60a5fa', '#1e40af',
        'A violation has been recorded on your student account by the Student Services Office.');
    $body .= _emailPara('This is a notification that the SSO has recorded your first ({$safeType}) violation this school year. Please take this notice seriously and report to the SSO as soon as possible.');
    $body = str_replace('{$safeType}', $safeType, $body);
    $body .= _emailDetailsTable([
        ['Student ID',      $safeId,    '#111827'],
        ['Violation',       $safeViol,  '#dc2626'],
        ['Type',            ucfirst($safeType) . ' Violation', '#374151'],
        ['Strike Number',   "Strike #{$offenseNumber}", '#d97706'],
        ['Semester',        "{$safeSem} Semester — {$safeSY}", '#374151'],
        ['Date Recorded',   $timestamp, '#374151'],
    ]);
    $body .= _emailActionBox('#f0f9ff', '#7dd3fc', '#0369a1', '#0c4a6e',
        'ℹ️ What This Means',
        'A formal violation notice has been recorded. You will receive further communication from the SSO about the required reparation task.<br><br>
        Your Student ID card and other documents may be withheld until the violation is resolved. <strong>Please visit the SSO promptly.</strong>');

    return _emailBase(
        'First Violation Notice — PCU GateWatch',
        '#3b82f6',
        'ℹ️',
        'First Violation Notice',
        'A violation has been recorded on your student account',
        $body
    );
}

/**
 * Parent/Guardian Violation Notice — formal navy
 * Sent to a student's primary parent/guardian when a violation is recorded.
 */
function emailGuardianViolationNotice(
    string $guardianName,
    string $studentName,
    string $studentId,
    string $violationName,
    string $violationTypeLabel,
    int $offenseNumber,
    string $semester,
    string $schoolYear,
    string $timestamp,
    string $disciplinaryCode,
    string $disciplinaryTitle,
    string $disciplinaryMessage,
    string $disciplinaryAction,
    string $categoryRationale,
    string $interventionIntent,
    string $incidentNotes = ''
): string {
    $safeGuardian = htmlspecialchars($guardianName, ENT_QUOTES, 'UTF-8');
    $safeStudent  = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeId       = htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8');
    $safeViol     = htmlspecialchars($violationName, ENT_QUOTES, 'UTF-8');
    $safeType     = htmlspecialchars($violationTypeLabel, ENT_QUOTES, 'UTF-8');
    $safeSem      = htmlspecialchars($semester, ENT_QUOTES, 'UTF-8');
    $safeSY       = htmlspecialchars($schoolYear, ENT_QUOTES, 'UTF-8');
    $safeCode     = htmlspecialchars($disciplinaryCode, ENT_QUOTES, 'UTF-8');
    $safeTitle    = htmlspecialchars($disciplinaryTitle, ENT_QUOTES, 'UTF-8');
    $safeMessage  = htmlspecialchars($disciplinaryMessage, ENT_QUOTES, 'UTF-8');
    $safeAction   = htmlspecialchars($disciplinaryAction, ENT_QUOTES, 'UTF-8');
    $safeRationale = htmlspecialchars($categoryRationale, ENT_QUOTES, 'UTF-8');
    $safeIntent   = htmlspecialchars($interventionIntent, ENT_QUOTES, 'UTF-8');
    $safeNotes    = htmlspecialchars($incidentNotes, ENT_QUOTES, 'UTF-8');

    $offenseLabel = 'Offense #' . max(1, $offenseNumber);

    $body  = _emailGreeting($guardianName);
    $body .= _emailBanner('#e0e7ff', '#1d4ed8', '#1e3a8a',
        'This is a formal notice from the Student Services Office (SSO) regarding a recorded student violation.');

    $body .= "<div style='text-align:justify;'>";
    $body .= _emailPara("We respectfully inform you that <strong>{$safeStudent}</strong> has a violation recorded in the GateWatch system. This communication is intended to keep the parent/guardian informed and to guide timely compliance with the SSO process.");
    $body .= "</div>";

    $body .= _emailDetailsTable([
        ['Student Name', $safeStudent, '#111827'],
        ['Student ID', $safeId, '#111827'],
        ['Violation', $safeViol, '#dc2626'],
        ['Violation Category', $safeType, '#374151'],
        ['Offense Level', htmlspecialchars($offenseLabel, ENT_QUOTES, 'UTF-8'), '#b45309'],
        ['Semester', "{$safeSem} Semester — {$safeSY}", '#374151'],
        ['Date Recorded', htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'), '#374151'],
    ]);

    if ($safeNotes !== '') {
        $body .= _emailActionBox('#f8fafc', '#e2e8f0', '#0f172a', '#334155',
            '📝 Incident Notes (as recorded)',
            "<div style='text-align:justify;'>{$safeNotes}</div>"
        );
    }

    $complianceParts = '';
    if (trim($safeCode) !== '' || trim($safeTitle) !== '') {
        $complianceParts .= "<p style='margin:0 0 10px; font-family:Arial,sans-serif; font-size:13px; color:#0f172a; line-height:1.6; text-align:justify;'>" .
            "<strong>Disciplinary Reference:</strong> {$safeCode}" . (trim($safeTitle) !== '' ? " — {$safeTitle}" : '') .
            "</p>";
    }

    if (trim($safeMessage) !== '') {
        $complianceParts .= "<p style='margin:0 0 10px; font-family:Arial,sans-serif; font-size:13px; color:#0f172a; line-height:1.7; text-align:justify;'>{$safeMessage}</p>";
    }

    if (trim($safeAction) !== '') {
        $complianceParts .= "<p style='margin:0; font-family:Arial,sans-serif; font-size:13px; color:#0f172a; line-height:1.7; text-align:justify;'><strong>Guidance to Comply:</strong> {$safeAction}</p>";
    }

    if (trim($safeRationale) !== '' || trim($safeIntent) !== '') {
        $complianceParts .= "<div style='margin-top:12px; padding:12px; background:#eef2ff; border-radius:8px;'>";
        if (trim($safeIntent) !== '') {
            $complianceParts .= "<p style='margin:0 0 8px; font-family:Arial,sans-serif; font-size:12px; color:#1e3a8a; line-height:1.6; text-align:justify;'><strong>Intervention Intent:</strong> {$safeIntent}</p>";
        }
        if (trim($safeRationale) !== '') {
            $complianceParts .= "<p style='margin:0; font-family:Arial,sans-serif; font-size:12px; color:#1e3a8a; line-height:1.6; text-align:justify;'><strong>Category Rationale:</strong> {$safeRationale}</p>";
        }
        $complianceParts .= "</div>";
    }

    if ($complianceParts === '') {
        $complianceParts = "<p style='margin:0; font-family:Arial,sans-serif; font-size:13px; color:#0f172a; line-height:1.7; text-align:justify;'>Please coordinate with the Student Services Office (SSO) for the official compliance requirements and timelines.</p>";
    }

    $body .= _emailActionBox('#eff6ff', '#93c5fd', '#1e3a8a', '#0f172a',
        '📌 Compliance Guidance',
        $complianceParts
    );

    $body .= _emailActionBox('#f8fafc', '#e2e8f0', '#0f172a', '#334155',
        '📍 Next Steps',
        "<div style='text-align:justify;'>If a parent/guardian conference is required, the SSO will provide scheduling instructions. We encourage you to guide your son/daughter to comply promptly with the SSO process. If you believe this notice was sent in error or you need clarification, please contact the Student Services Office.</div>"
    );

    return _emailBase(
        'SSO Parent/Guardian Notice — Violation Recorded',
        '#0f172a',
        '🏛️',
        'Student Services Office Notice',
        'Parent/Guardian Notification — Violation Recorded in GateWatch',
        $body
    );
}

/**
 * Final Warning — Strike #3
 * Sent when a student reaches their 3rd strike on a category.
 */
function emailFinalWarning(string $name, string $studentId, string $violationName, string $violationType, int $offenseNumber, string $semester, string $schoolYear, string $timestamp): string {
    $safeName  = htmlspecialchars($name,          ENT_QUOTES, 'UTF-8');
    $safeId    = htmlspecialchars($studentId,     ENT_QUOTES, 'UTF-8');
    $safeViol  = htmlspecialchars($violationName, ENT_QUOTES, 'UTF-8');
    $safeType  = htmlspecialchars($violationType, ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($name);
    $body .= _emailBanner('#fef2f2', '#ef4444', '#991b1b',
        '⚠️ FINAL WARNING: You have now reached Strike #3 for this violation type. Further escalation may result in serious disciplinary consequences.');
    $body .= _emailPara('This is an urgent notice. The Student Services Office has recorded your <strong>3rd offense</strong> for this violation type. At this level, graduated sanctions are applied and the matter may be escalated to school administration. You must report to the SSO <em>immediately</em>.');
    $body .= _emailDetailsTable([
        ['Student ID',      $safeId,    '#111827'],
        ['Violation',       $safeViol,  '#dc2626'],
        ['Type',            ucfirst($safeType) . ' Violation', '#dc2626'],
        ['Strike',          "Strike #{$offenseNumber} — FINAL WARNING", '#dc2626'],
        ['Semester',        "{$semester} Semester — {$schoolYear}", '#374151'],
        ['Date Recorded',   $timestamp, '#374151'],
    ]);
    $body .= _emailActionBox('#fff1f2', '#fca5a5', '#991b1b', '#7f1d1d',
        '🚨 URGENT — Required Actions',
        '<ol style="margin:8px 0 0 0; padding-left:20px;">
          <li style="margin-bottom:6px;">Visit the <strong>Student Services Office immediately</strong></li>
          <li style="margin-bottom:6px;">Bring your valid student ID and any required documents</li>
          <li style="margin-bottom:6px;">Complete all assigned reparation tasks without delay</li>
          <li>Failure to comply may result in <strong>suspension or further disciplinary action</strong></li>
        </ol>');

    return _emailBase(
        'FINAL WARNING — Strike #3 — PCU GateWatch',
        '#b91c1c',
        '⚠️',
        'FINAL WARNING — Strike #3',
        'Immediate action required at the Student Services Office',
        $body
    );
}

/**
 * Access Denied — Maximum Violations Reached
 * Sent when a student with max active violations attempts gate entry.
 */
function emailAccessDenied(array $student, int $activeViolationCount, string $timestamp): string {
    $name      = htmlspecialchars($student['name'],       ENT_QUOTES, 'UTF-8');
    $studentId = htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8');
    $email     = htmlspecialchars($student['email'] ?? '', ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($student['name']);
    $body .= _emailBanner('#fef2f2', '#dc2626', '#991b1b',
        '⛔ Your gate access has been automatically blocked due to the maximum number of active violations on your account.');
    $body .= _emailPara('Our GateWatch system has detected that you have reached the <strong>maximum number of active violations (3)</strong>. Access to campus has been restricted until all violations are resolved.');
    $body .= _emailDetailsTable([
        ['Date &amp; Time',     $timestamp,                         '#111827'],
        ['Student ID',          $studentId,                         '#111827'],
        ['Active Violations',   "{$activeViolationCount} (Maximum Reached)", '#dc2626'],
        ['Gate Status',         'ACCESS DENIED',                    '#dc2626'],
    ]);
    $body .= _emailActionBox('#fff1f2', '#fca5a5', '#991b1b', '#7f1d1d',
        '⛔ How to Restore Campus Access',
        '<ol style="margin:8px 0 0 0; padding-left:20px;">
          <li style="margin-bottom:6px;">Visit the <strong>Student Services Office (SSO)</strong> immediately</li>
          <li style="margin-bottom:6px;">Complete all outstanding reparation tasks for your active violations</li>
          <li style="margin-bottom:6px;">Have the SSO mark all violations as resolved</li>
          <li>Once all violations are cleared, your gate access will be automatically restored</li>
        </ol>');
    $body .= _emailActionBox('#eff6ff', '#bfdbfe', '#1e40af', '#1e3a8a',
        '📋 Documents Currently Held',
        'The following documents will be released upon resolution of all violations:
        <ul style="margin:8px 0 0 0; padding-left:20px;">
          <li style="margin-bottom:4px;">Student ID Card</li>
          <li style="margin-bottom:4px;">Good Moral Certificate</li>
          <li>Any other held school documents</li>
        </ul>');

    return _emailBase(
        'ACCESS DENIED — Maximum Violations — PCU GateWatch',
        '#991b1b',
        '⛔',
        'ACCESS DENIED',
        'Maximum violations reached — Campus access blocked',
        $body
    );
}

/**
 * Reparation Task Assigned — amber
 * Sent when admin assigns a reparation task to an active violation.
 */
function emailReparationTask(
    string $name,
    string $studentId,
    string $violationName,
    string $reparationLabel,
    string $taskInstruction,
    string $reparationNotes,
    string $timestamp
): string {
    $safeName      = htmlspecialchars($name,            ENT_QUOTES, 'UTF-8');
    $safeId        = htmlspecialchars($studentId,       ENT_QUOTES, 'UTF-8');
    $safeViol      = htmlspecialchars($violationName,   ENT_QUOTES, 'UTF-8');
    $safeLabel     = htmlspecialchars($reparationLabel, ENT_QUOTES, 'UTF-8');
    $safeTask      = htmlspecialchars($taskInstruction, ENT_QUOTES, 'UTF-8');
    $safeNotes     = htmlspecialchars($reparationNotes, ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($name);
    $body .= _emailBanner('#fef3c7', '#f59e0b', '#92400e',
        'You have a pending violation that requires your action. Please complete the assigned task below to resolve your violation record.');
    $body .= _emailDetailsTable([
        ['Student ID',      $safeId,     '#111827'],
        ['Violation',       $safeViol,   '#dc2626'],
        ['Required Task',   $safeLabel,  '#d97706'],
        ['Status',          '⏳ Pending Your Compliance', '#d97706'],
        ['Date Assigned',   $timestamp,  '#374151'],
    ]);
    $body .= _emailActionBox('#fffbeb', '#f59e0b', '#b45309', '#78350f',
        '📋 What You Need To Do',
        $safeTask .
        ($reparationNotes !== ''
            ? "<div style='margin-top:12px; padding:10px; background:#fde68a; border-radius:6px;'>
                 <strong style='color:#78350f;'>Additional Instructions from Admin:</strong><br>
                 <span style='color:#78350f;'>{$safeNotes}</span>
               </div>"
            : '')
    );
    $body .= _emailActionBox('#eff6ff', '#bfdbfe', '#1e40af', '#1e3a8a',
        '📍 Where to Go',
        'Report to the <strong>Student Services Office (SSO)</strong> during office hours.<br>
        Once you complete your reparation task, the admin will update your record and notify you when your violation is resolved.');
    $body .= _emailActionBox('#fff1f2', '#fca5a5', '#991b1b', '#7f1d1d',
        '⚠️ Important',
        'Failure to comply may result in additional disciplinary action. Your <strong>Student ID, Good Moral Certificate</strong>, and other documents on hold will remain withheld until your violation is fully resolved.');

    return _emailBase(
        'Action Required — Violation Reparation — PCU GateWatch',
        '#d97706',
        '⚠️',
        'Action Required',
        'Violation reparation task assigned — Please comply promptly',
        $body
    );
}

/**
 * Violation Resolved (single) — green
 * Sent when admin marks a single violation as apprehended/resolved.
 */
function emailViolationResolved(
    string $name,
    string $studentId,
    string $violationName,
    string $reparationLabel,
    string $timestamp
): string {
    $safeName   = htmlspecialchars($name,            ENT_QUOTES, 'UTF-8');
    $safeId     = htmlspecialchars($studentId,       ENT_QUOTES, 'UTF-8');
    $safeViol   = htmlspecialchars($violationName,   ENT_QUOTES, 'UTF-8');
    $safeLabel  = htmlspecialchars($reparationLabel, ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($name);
    $body .= _emailBanner('#dcfce7', '#22c55e', '#166534',
        'Your violation has been resolved! The required reparation has been completed and recorded by the Student Services Office.');
    $body .= _emailPara('We are pleased to inform you that the required reparation for your violation has been completed and recorded by the SSO.');
    $body .= _emailDetailsTable([
        ['Student ID',           $safeId,    '#111827'],
        ['Violation',            $safeViol,  '#374151'],
        ['Reparation Completed', $safeLabel, '#059669'],
        ['Status',               '✅ Apprehended (Resolved)', '#059669'],
        ['Date Resolved',        $timestamp, '#374151'],
    ]);
    return _emailBase(
        'Violation Resolved — PCU GateWatch',
        '#059669',
        '✅',
        'Violation Resolved',
        'Your violation has been marked as resolved by the SSO',
        $body
    );
}

/**
 * All Violations Resolved — green
 * Sent when admin clears ALL active violations for a student at once.
 */
function emailAllViolationsResolved(
    string $name,
    int    $resolvedCount,
    string $reparationLabel,
    string $timestamp
): string {
    $safeName  = htmlspecialchars($name,            ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($reparationLabel, ENT_QUOTES, 'UTF-8');

    $body  = _emailGreeting($name);
    $body .= _emailBanner('#dcfce7', '#22c55e', '#166534',
        'All of your active violations have been resolved! Your student record is now clear. You may collect any previously held documents.');
    $body .= _emailPara('The Student Services Office has recorded the completion of all your required reparations. Your account has been fully reinstated and all violation counters have been reset to zero.');
    $body .= _emailDetailsTable([
        ['Violations Resolved', (string) $resolvedCount, '#059669'],
        ['Reparation',          $safeLabel,              '#059669'],
        ['Status',              '✅ All Cleared',         '#059669'],
        ['Date Resolved',       $timestamp,              '#374151'],
    ]);

    $body .= _emailActionBox('#eff6ff', '#bfdbfe', '#1e40af', '#1e3a8a',
        'ℹ️ Keep This in Mind',
        'Please ensure you carry your <strong>physical student ID card every day</strong> to avoid future gate marks and violations. Your fresh record is an opportunity to maintain full compliance throughout the school year.');

    return _emailBase(
        'All Violations Resolved — PCU GateWatch',
        '#059669',
        '✅',
        'All Violations Resolved',
        'Your student record is now clear',
        $body
    );
}
