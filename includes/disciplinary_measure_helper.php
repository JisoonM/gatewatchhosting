<?php
declare(strict_types=1);

/**
 * Map cumulative offense count to a level value for UI severity rendering.
 */
function violation_mark_level_from_total_marks(int $totalMarks): int {
    if ($totalMarks <= 0) {
        return 1;
    }

    return max(1, min(4, $totalMarks));
}

/**
 * Under the direct-offense policy, each recorded incident increments offense.
 */
function violation_offense_number_from_total_marks(int $totalMarks): int {
    if ($totalMarks <= 0) {
        return 1;
    }

    return $totalMarks;
}

/**
 * Normalize category names for policy-rule matching.
 */
function violation_policy_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

/**
 * Shared policy intent for all disciplinary interventions.
 */
function violation_policy_intervention_intent(): string {
    return 'Disciplinary interventions are both instructive (awareness, responsibility, growth) and corrective/deterrent (prevent repetition of violations).';
}

/**
 * Category-level rationale used in notice payloads.
 */
function violation_policy_category_rationale(string $categoryType): string {
    $type = strtolower(trim($categoryType));

    if ($type === 'minor') {
        return 'Minor offenses use educative and corrective interventions to build accountability and positive behavior change.';
    }

    if ($type === 'major') {
        return 'Moderate offenses are intermediate in severity and require stronger corrective interventions to protect order and discipline.';
    }

    if ($type === 'grave') {
        return 'Major offenses require the highest disciplinary interventions to protect health, safety, welfare, and institutional integrity.';
    }

    return 'Disciplinary interventions are applied systematically for fairness, consistency, and the best interests of learners and the school community.';
}

/**
 * Official handbook definition for DIS 4.
 */
function violation_policy_dis4_definition(): string {
    return 'Dismissal or Withdrawal from School (Code: DIS 4): This is an action taken by the school where the violating student is dropped from the school because of a major offense that poses a threat to the school\'s reputation and the safety of its academic community. For Manila Campus, all issued dismissals are final and executory once approved by the SSO (Student Services Office) and VP for ICT and Student Services.';
}

/**
 * Build a student-facing SSO disciplinary notice from offense progression.
 *
 * @return array<string,mixed>|null
 */
function build_disciplinary_intervention_message(int $markLevel, int $offenseNumber, string $categoryName = '', string $categoryType = ''): ?array {
    if ($offenseNumber < 1) {
        return null;
    }

    $safeOffense = max(1, $offenseNumber);
    $safeCategory = trim($categoryName);
    $safeCategoryType = strtolower(trim($categoryType));
    $categoryPart = $safeCategory !== '' ? (' for "' . $safeCategory . '"') : '';
    $policyContext = [
        'intervention_intent' => violation_policy_intervention_intent(),
        'category_rationale' => violation_policy_category_rationale($safeCategoryType),
        'dis4_definition' => violation_policy_dis4_definition(),
    ];

    // Moderate-offense track (db type "major") starts at DIS 2.
    // 1st offense -> DIS 2, 2nd offense -> DIS 3, 3rd+ offense -> DIS 4.
    if ($safeCategoryType === 'major') {
        if ($safeOffense === 1) {
            return [
                'code' => 'DIS 2',
                'title' => 'School Community Service',
                'message' => 'This moderate offense' . $categoryPart . ' is recorded as your 1st offense and maps to DIS 2. Required interventions include conference with the student, conference with parents, and a written apology letter signed by the offender and parent/guardian.',
                'action' => 'Coordinate with the Student Services Office for DIS 2 compliance, parent conference schedule, and signed apology submission.',
            ] + $policyContext;
        }

        if ($safeOffense === 2) {
            return [
                'code' => 'DIS 3',
                'title' => 'School Suspension',
                'message' => 'This moderate offense' . $categoryPart . ' is recorded as your 2nd offense and maps to DIS 3. Required interventions include conference with the student, conference with parents, signed apology letter, referral to Guidance Office, and referral to Chaplaincy Office for Spiritual Counseling.',
                'action' => 'Report to the Student Services Office for DIS 3 directives, suspension workflow, and completion of all referrals.',
            ] + $policyContext;
        }

        return [
            'code' => 'DIS 4',
            'title' => 'Dismissal or Withdrawal from School',
            'message' => 'This moderate offense' . $categoryPart . ' is recorded as your 3rd offense and maps to DIS 4. Required interventions include conference with the student and conference with parents, subject to final disciplinary disposition by SSO. ' . violation_policy_dis4_definition(),
            'action' => 'Escalate immediately to the Student Services Office for DIS 4 case handling and final decision process, including SSO and VP approval flow.',
        ] + $policyContext;
    }

    // Major-offense track (db type "grave") from policy table.
    // Some categories are immediate DIS 4 at first offense.
    // Others use DIS 3 at 1st offense, then DIS 4 from 2nd offense onward.
    if ($safeCategoryType === 'grave') {
        $categorySlug = violation_policy_slug($safeCategory);

        $immediateDis4Slugs = [
            violation_policy_slug('Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.'),
            violation_policy_slug('Robbery'),
            violation_policy_slug('Hazing'),
            violation_policy_slug('Organization-related violence'),
            violation_policy_slug('Drug-related offense'),
            violation_policy_slug('Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school'),
            violation_policy_slug('Sexual intercourse while inside the school.'),
            violation_policy_slug('Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students'),
            violation_policy_slug('Repeated willful violations of the school\'s rules and regulations including the commission of a fourth minor offense.'),
            violation_policy_slug('Creating and/or joining unauthorized or illegal student organizations.'),
        ];

        $isImmediateDis4 = $categorySlug !== '' && in_array($categorySlug, $immediateDis4Slugs, true);

        if ($isImmediateDis4 || $safeOffense >= 2) {
            return [
                'code' => 'DIS 4',
                'title' => 'Dismissal or Withdrawal from School',
                'message' => 'This major offense' . $categoryPart . ' is recorded under DIS 4. Required interventions include conference with the student and conference with parents, subject to final disciplinary disposition by SSO. ' . violation_policy_dis4_definition(),
                'action' => 'Escalate immediately to the Student Services Office for DIS 4 case handling and final decision process, including SSO and VP approval flow.',
            ] + $policyContext;
        }

        return [
            'code' => 'DIS 3',
            'title' => 'School Suspension',
            'message' => 'This major offense' . $categoryPart . ' is recorded as your 1st offense and maps to DIS 3. Required interventions include conference with the student, conference with parents, a written apology letter signed by the offender and parent/guardian, referral to Guidance Office, and referral to Chaplaincy Office for Spiritual Counseling.',
            'action' => 'Report immediately to the Student Services Office for DIS 3 directives and completion of all required referrals.',
        ] + $policyContext;
    }

    // Minor-offense track (db type "minor") from policy table.
    // 1st offense -> DIS 1, 2nd offense -> DIS 2, 3rd+ offense -> DIS 3.
    if ($safeCategoryType === 'minor') {
        if ($safeOffense === 1) {
            return [
                'code' => 'DIS 1',
                'title' => 'Violation Form Filling',
                'message' => 'This minor offense' . $categoryPart . ' is recorded as your 1st offense and maps to DIS 1. Required interventions include verbal reprimand and a written apology letter signed by the offender and parent/guardian.',
                'action' => 'Submit the signed written apology and comply with the verbal reprimand guidance from school authorities.',
            ] + $policyContext;
        }

        if ($safeOffense === 2) {
            return [
                'code' => 'DIS 2',
                'title' => 'School Community Service',
                'message' => 'This minor offense' . $categoryPart . ' is recorded as your 2nd offense and maps to DIS 2. Required interventions include conference with the student, conference with parents, and a written apology letter signed by the offender and parent/guardian.',
                'action' => 'Coordinate with the Student Services Office for DIS 2 compliance, conference schedules, and apology submission.',
            ] + $policyContext;
        }

        return [
            'code' => 'DIS 3',
            'title' => 'School Suspension',
            'message' => 'This minor offense' . $categoryPart . ' is recorded as your 3rd offense and maps to DIS 3. Required interventions include conference with the student, conference with parents, referral to Guidance Office for Behavioral Counseling, and referral to Chaplaincy Office for Spiritual Counseling.',
            'action' => 'Report immediately to the Student Services Office for DIS 3 directives and completion of all required referrals.',
        ] + $policyContext;
    }

    if ($safeOffense === 1) {
        return [
            'code' => 'DIS 1',
            'title' => 'Violation Form Filling',
            'message' => 'This incident' . $categoryPart . ' is recorded as your 1st offense under DIS 1 (Violation Form Filling). A Violation Form is issued as a formal written record of misconduct and reminder to improve behavior.',
            'action' => 'Sign the Violation Form with your class adviser and return it to the Discipline Office immediately.',
        ] + $policyContext;
    }

    if ($safeOffense === 2) {
        return [
            'code' => 'DIS 2',
            'title' => 'School Community Service',
            'message' => 'This incident' . $categoryPart . ' is recorded as your 2nd offense under DIS 2 (School Community Service).',
            'action' => 'Report to the Student Services Office for your community service assignment and completion guidelines.',
        ] + $policyContext;
    }

    if ($safeOffense === 3) {
        return [
            'code' => 'DIS 3',
            'title' => 'School Suspension',
            'message' => 'This incident' . $categoryPart . ' is recorded as your 3rd offense under DIS 3 (School Suspension).',
            'action' => 'Report immediately to the Student Services Office for suspension directives and compliance requirements.',
        ] + $policyContext;
    }

    return [
        'code' => 'DIS 4',
        'title' => 'Dismissal or Withdrawal from School',
        'message' => 'This incident' . $categoryPart . ' is recorded as your 4th offense under DIS 4 (Dismissal or Withdrawal from School). ' . violation_policy_dis4_definition(),
        'action' => 'Refer the case to the Student Services Office for final disciplinary disposition and approval workflow, including SSO and VP approval.',
    ] + $policyContext;
}
