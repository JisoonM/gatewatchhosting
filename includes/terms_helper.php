<?php
declare(strict_types=1);

/**
 * GateWatch Terms & Conditions helper.
 *
 * This file centralizes the Terms content so the full Terms page and
 * the post–Google Sign-In consent prompt always stay in sync.
 */

function gatewatch_terms_version(): string {
    // Keep this in sync with the displayed Effective Date.
    return '2026-04-17';
}

function gatewatch_terms_title(): string {
    return 'GateWatch Terms and Conditions';
}

/**
 * Returns HTML (static content) for the GateWatch Terms & Conditions.
 * The caller is responsible for wrapping/styling.
 */
function gatewatch_terms_html(): string {
    $effectiveDate = gatewatch_terms_version();

    return '
        <p><strong>Effective Date:</strong> ' . htmlspecialchars($effectiveDate, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>

        <h3>1. Overview</h3>
        <p>
            GateWatch is a campus safety and student services support system designed for gate entry/exit monitoring and
            student violation tracking using technologies such as RFID, QR, and (when enabled) face recognition.
            By creating an account or using GateWatch, you agree to these Terms and Conditions.
        </p>

        <h3>2. Consent for Collection of Student & Guardian Information</h3>
        <p>
            During registration, GateWatch requests your consent to collect and use the following information for
            <strong>registration</strong> and <strong>emergency contact purposes</strong>:
        </p>
        <ul>
            <li><strong>Student Full Name</strong></li>
            <li><strong>Student ID</strong> (temporary ID may be issued first for verification)</li>
            <li><strong>PCU Email Address</strong></li>
            <li><strong>Parent/Guardian Full Name</strong></li>
            <li><strong>Parent/Guardian Email Address</strong> and <strong>Contact Number</strong></li>
        </ul>
        <p>
            If you do not consent, you should not proceed with registration and you will not be able to create an account.
        </p>

        <h3>3. Additional Data Processed by the System</h3>
        <p>
            Depending on features enabled by the institution, GateWatch may also process data needed to operate the system,
            such as:
        </p>
        <ul>
            <li>RFID UID(s) assigned to a student account</li>
            <li>Gate entry/exit logs (date/time, gate location where applicable)</li>
            <li>Violation records, assigned actions, and status updates</li>
            <li>Security logs (e.g., IP address, browser/device information) for security and fraud prevention</li>
            <li>Face recognition enrollment and matching metadata when the feature is used (if applicable)</li>
        </ul>

        <h3>4. Use of Information</h3>
        <p>
            The information collected is used to:
        </p>
        <ul>
            <li>Create and manage student accounts, including verification by authorized administrators</li>
            <li>Support campus entry/exit monitoring and related security workflows</li>
            <li>Maintain accurate disciplinary/violation tracking records</li>
            <li>Contact the listed Parent/Guardian for notifications and emergency communication, when enabled</li>
            <li>Maintain system integrity, auditing, and abuse prevention</li>
        </ul>

        <h3>5. Account Verification and Access</h3>
        <p>
            New student accounts may require administrative verification before access is granted. GateWatch may restrict
            access while verification is pending or if an account is locked due to security policies.
        </p>

        <h3>6. Responsibilities of Users</h3>
        <p>
            You are responsible for ensuring the information you provide (including Parent/Guardian details) is accurate and
            up to date. You should keep your Google account secure and not share access with others.
        </p>

        <h3>7. Data Protection</h3>
        <p>
            GateWatch implements security measures intended to protect your information. However, no system can guarantee
            absolute security. If you believe your account or data is compromised, contact the administrator immediately.
        </p>

        <h3>8. Changes to These Terms</h3>
        <p>
            The institution may update these Terms and Conditions from time to time. Continued use of GateWatch after
            changes are posted indicates acceptance of the updated Terms.
        </p>

        <h3>9. Contact</h3>
        <p>
            For questions about registration, account verification, or data handling, please contact the Student Services
            Office or the system administrator.
        </p>
    ';
}
