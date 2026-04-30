<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

require_superadmin_auth();
send_security_headers();

require_permission('audit.read', [
    'actor_role' => 'superadmin',
    'response' => 'http',
    'message' => 'Forbidden: missing permission audit.read.',
]);

$auditLogs = [];
try {
    $pdo = pdo();
    $stmt = $pdo->query("SELECT * FROM audit_logs WHERE action_type != 'EXPORT_AUDIT_LOG' ORDER BY created_at DESC LIMIT 100");
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Superadmin audit view error: ' . $e->getMessage());
    $auditLogs = [];
}

$actionColors = [
    'APPROVE_STUDENT' => 'bg-green-100 text-green-800',
    'DENY_STUDENT' => 'bg-red-100 text-red-800',
    'REGISTER_RFID' => 'bg-blue-100 text-blue-800',
    'UNREGISTER_RFID' => 'bg-yellow-100 text-yellow-800',
    'MARK_LOST' => 'bg-orange-100 text-orange-800',
    'MARK_FOUND' => 'bg-emerald-100 text-emerald-800',
    'UPDATE_STUDENT' => 'bg-indigo-100 text-indigo-800',
    'ARCHIVE_STUDENT' => 'bg-slate-100 text-slate-800',
    'DELETE_STUDENT' => 'bg-red-100 text-red-800',
    'ADD_VIOLATION' => 'bg-rose-100 text-rose-800',
    'RESOLVE_VIOLATION' => 'bg-teal-100 text-teal-800',
    'RESOLVE_ALL_VIOLATIONS' => 'bg-emerald-100 text-emerald-800',
    'ASSIGN_REPARATION' => 'bg-amber-100 text-amber-800',
    'EXPORT_AUDIT_LOG' => 'bg-purple-100 text-purple-800',
];

$totalLogs = count($auditLogs);
$criticalActionTypes = ['DENY_STUDENT', 'DELETE_STUDENT', 'MARK_LOST', 'ADD_VIOLATION'];
$criticalCount = 0;
$activeAdmins = [];
foreach ($auditLogs as $log) {
    $actionType = (string)($log['action_type'] ?? '');
    if (in_array($actionType, $criticalActionTypes, true)) {
        $criticalCount++;
    }
    $adminName = trim((string)($log['admin_name'] ?? ''));
    if ($adminName !== '') {
        $activeAdmins[strtolower($adminName)] = true;
    }
}
$activeAdminCount = count($activeAdmins);
$latestTimestamp = !empty($auditLogs[0]['created_at'])
    ? date('M d, Y h:i A', strtotime((string)$auditLogs[0]['created_at']))
    : 'No activity';

$page_title = 'Audit Logs';
include __DIR__ . '/includes/header.php';
?>

<style>
    .audit-shell {
        background: linear-gradient(145deg, rgba(248, 250, 252, 0.7), rgba(226, 232, 240, 0.58));
        border: 1px solid rgba(186, 230, 253, 0.4);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.2);
    }

    .audit-panel {
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.94));
        border: 1px solid rgba(148, 163, 184, 0.32);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .audit-input {
        width: 100%;
        min-height: 2.75rem;
        border: 1px solid rgba(148, 163, 184, 0.6);
        border-radius: 0.75rem;
        padding: 0.625rem 0.875rem;
        background: rgba(255, 255, 255, 0.96);
        color: #0f172a;
        transition: box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .audit-input:focus {
        outline: none;
        border-color: rgba(59, 130, 246, 0.9);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }

    .audit-table-wrap {
        max-height: min(62vh, 720px);
        overflow: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(59, 130, 246, 0.75) rgba(226, 232, 240, 0.75);
    }

    .audit-table-wrap::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    .audit-table-wrap::-webkit-scrollbar-track {
        background: rgba(226, 232, 240, 0.65);
        border-radius: 999px;
    }

    .audit-table-wrap::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, rgba(37, 99, 235, 0.85), rgba(2, 132, 199, 0.8));
        border-radius: 999px;
        border: 2px solid rgba(241, 245, 249, 0.95);
    }

    .audit-live-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        padding: 0.36rem 0.72rem;
        border-radius: 999px;
        border: 1px solid rgba(16, 185, 129, 0.4);
        background: rgba(236, 253, 245, 0.95);
        color: #047857;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0;
    }

    .audit-live-dot {
        width: 0.48rem;
        height: 0.48rem;
        border-radius: 999px;
        background: #10b981;
        box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.16);
        animation: auditPulse 1.9s ease-in-out infinite;
    }

    @keyframes auditPulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.65; transform: scale(0.86); }
    }

    .audit-stat-card {
        border: 1.5px solid rgba(100, 116, 139, 0.5);
        background: rgba(255, 255, 255, 0.92);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.65), 0 2px 10px rgba(15, 23, 42, 0.08);
    }
</style>

<div class="space-y-6 audit-shell rounded-2xl p-5 sm:p-6 lg:p-7">
    <section class="audit-panel rounded-2xl px-5 py-5 sm:px-6 sm:py-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Audit Log</h1>
            <p class="text-slate-700 mt-1">Track all administrative actions and changes with high-visibility real-time updates.</p>
            <div class="mt-3">
                <span class="audit-live-pill">
                    <span class="audit-live-dot" aria-hidden="true"></span>
                    Auto Live
                </span>
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 w-full lg:w-auto">
            <div class="audit-stat-card rounded-xl px-3 py-2">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Total</p>
                <p class="text-lg font-bold text-slate-900"><?php echo (int)$totalLogs; ?></p>
            </div>
            <div class="audit-stat-card rounded-xl px-3 py-2">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Critical</p>
                <p class="text-lg font-bold text-rose-700"><?php echo (int)$criticalCount; ?></p>
            </div>
            <div class="audit-stat-card rounded-xl px-3 py-2">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Admins</p>
                <p class="text-lg font-bold text-slate-900"><?php echo (int)$activeAdminCount; ?></p>
            </div>
            <div class="audit-stat-card rounded-xl px-3 py-2">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Latest</p>
                <p class="text-[13px] font-semibold text-slate-700 leading-tight"><?php echo e($latestTimestamp); ?></p>
            </div>
        </div>
    </section>

    <section class="audit-panel rounded-2xl p-5 sm:p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
            <div class="xl:col-span-2">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Action Type</label>
                <select id="filterActionType" class="audit-input">
                    <option value="">All Actions</option>
                    <option value="APPROVE_STUDENT">Approve Student</option>
                    <option value="DENY_STUDENT">Deny Student</option>
                    <option value="REGISTER_RFID">Register RFID</option>
                    <option value="UNREGISTER_RFID">Unregister RFID</option>
                    <option value="MARK_LOST">Mark Lost</option>
                    <option value="MARK_FOUND">Mark Found</option>
                    <option value="UPDATE_STUDENT">Update Student</option>
                    <option value="ARCHIVE_STUDENT">Archive Student</option>
                    <option value="DELETE_STUDENT">Delete Student (Legacy)</option>
                    <option value="ADD_VIOLATION">Add Violation</option>
                    <option value="RESOLVE_VIOLATION">Resolve Violation</option>
                    <option value="RESOLVE_ALL_VIOLATIONS">Resolve All Violations</option>
                    <option value="ASSIGN_REPARATION">Assign Reparation</option>
                    <option value="EXPORT_AUDIT_LOG">Export Audit Log</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Date From</label>
                <input type="date" id="filterDateFrom" class="audit-input">
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Date To</label>
                <input type="date" id="filterDateTo" class="audit-input">
            </div>

            <div class="xl:col-span-1 flex items-end">
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-1 gap-2 w-full">
                    <button onclick="applyAuditFilters()" class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
                        Apply Filters
                    </button>
                    <button onclick="exportAuditToCsv()" class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-semibold flex items-center justify-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V3m0 13.5L7.5 12m4.5 4.5 4.5-4.5M3 19.5h18"/>
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="audit-panel rounded-2xl overflow-hidden">
        <div class="audit-table-wrap">
            <table class="w-full min-w-[1040px]">
                <thead class="sticky top-0 z-10 bg-slate-100/95 backdrop-blur-sm border-b border-slate-300">
                    <tr>
                        <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Timestamp</th>
                        <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Admin</th>
                        <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Action</th>
                        <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Target</th>
                        <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Description</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-slate-700">Details</th>
                    </tr>
                </thead>
                <tbody id="auditLogTableBody">
                    <?php if (empty($auditLogs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-16 text-slate-600 bg-white/70">
                                <p class="font-semibold text-slate-700 mb-1">No audit logs yet</p>
                                <p class="text-sm text-slate-500">Administrative actions will appear here.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                            <?php $actionColor = $actionColors[$log['action_type']] ?? 'bg-slate-100 text-slate-800'; ?>
                            <tr class="border-b border-slate-200/80 odd:bg-white/75 even:bg-slate-50/78 hover:bg-sky-50/65 transition-colors">
                                <td class="py-3 px-4 text-sm text-slate-700 whitespace-nowrap">
                                    <div class="font-medium"><?php echo date('M d, Y', strtotime((string)$log['created_at'])); ?></div>
                                    <div class="text-xs text-slate-500"><?php echo date('h:i A', strtotime((string)$log['created_at'])); ?></div>
                                </td>
                                <td class="py-3 px-4 text-sm text-slate-800">
                                    <span class="font-semibold"><?php echo e((string)$log['admin_name']); ?></span>
                                </td>
                                <td class="py-3 px-4 align-top">
                                    <span class="<?php echo e($actionColor); ?> inline-flex text-xs font-semibold px-2.5 py-1 rounded-full border border-black/5">
                                        <?php echo e(str_replace('_', ' ', (string)$log['action_type'])); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-sm text-slate-700">
                                    <?php if (!empty($log['target_name'])): ?>
                                        <div class="font-semibold text-slate-800"><?php echo e((string)$log['target_name']); ?></div>
                                        <div class="text-xs text-slate-500"><?php echo e(ucfirst((string)$log['target_type'])); ?> ID: <?php echo (int)$log['target_id']; ?></div>
                                    <?php else: ?>
                                        <span class="text-slate-500">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-sm text-slate-700 max-w-[420px]">
                                    <div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo e((string)$log['description']); ?></div>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if (!empty($log['details'])): ?>
                                        <button
                                            onclick='showAuditDetails(<?php echo json_encode($log, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'
                                            class="text-sky-700 hover:text-sky-900 text-sm font-semibold transition-colors"
                                        >
                                            View Details
                                        </button>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
const csrfToken = '<?php echo e($_SESSION['csrf_token']); ?>';
const actionColors = {
    APPROVE_STUDENT: 'bg-green-100 text-green-800',
    DENY_STUDENT: 'bg-red-100 text-red-800',
    REGISTER_RFID: 'bg-blue-100 text-blue-800',
    UNREGISTER_RFID: 'bg-yellow-100 text-yellow-800',
    MARK_LOST: 'bg-orange-100 text-orange-800',
    MARK_FOUND: 'bg-emerald-100 text-emerald-800',
    UPDATE_STUDENT: 'bg-indigo-100 text-indigo-800',
    ARCHIVE_STUDENT: 'bg-slate-100 text-slate-800',
    DELETE_STUDENT: 'bg-red-100 text-red-800',
    ADD_VIOLATION: 'bg-rose-100 text-rose-800',
    RESOLVE_VIOLATION: 'bg-teal-100 text-teal-800',
    RESOLVE_ALL_VIOLATIONS: 'bg-emerald-100 text-emerald-800',
    ASSIGN_REPARATION: 'bg-amber-100 text-amber-800',
    EXPORT_AUDIT_LOG: 'bg-purple-100 text-purple-800',
};

let auditLiveInterval = null;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    if (window.Swal) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: type,
            title: message,
            showConfirmButton: false,
            timer: 1800,
            timerProgressBar: true
        });
    }
}

function showAuditDetails(log) {
    try {
        if (!log || !log.details) {
            return;
        }

        let details = log.details;
        if (typeof details === 'string') {
            try {
                details = JSON.parse(details);
            } catch (_) {
                details = { details: details };
            }
        }
        if (!details || typeof details !== 'object') {
            details = { details: String(log.details) };
        }
        const detailsHtml = Object.entries(details).map(([key, value]) => {
            const prettyKey = key.replace(/_/g, ' ').toUpperCase();
            const prettyValue = typeof value === 'object'
                ? JSON.stringify(value)
                : String(value ?? '-');
            return `<div class="flex flex-col gap-1"><span class="text-xs text-slate-500">${escapeHtml(prettyKey)}</span><span class="text-sm text-slate-700 font-medium break-all">${escapeHtml(prettyValue)}</span></div>`;
        }).join('');

        Swal.fire({
            title: `Audit Log Details - ${(log.action_type || '').replace(/_/g, ' ')}`,
            html: `<div class="text-left space-y-3">${detailsHtml}<div class="text-xs text-slate-500 pt-3 border-t border-slate-200">Timestamp: ${new Date(log.created_at).toLocaleString()}</div></div>`,
            icon: 'info',
            confirmButtonText: 'Close',
            confirmButtonColor: '#2563eb'
        });
    } catch (error) {
        console.error('Error displaying audit details:', error);
        showToast('Failed to display audit details', 'error');
    }
}

function exportAuditToCsv() {
    const actionType = document.getElementById('filterActionType').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;

    const params = new URLSearchParams();
    if (actionType) params.set('action_type', actionType);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('csrf_token', csrfToken);

    window.location.href = 'export_audit_logs.php?' + params.toString();
}

async function applyAuditFilters(silent = false) {
    const actionType = document.getElementById('filterActionType').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const params = new URLSearchParams({ action_type: actionType, date_from: dateFrom, date_to: dateTo });

    try {
        const response = await fetch(`filter_audit_logs.php?${params.toString()}`, {
            headers: { 'X-CSRF-Token': csrfToken }
        });
        const data = await response.json();

        if (!data.success) {
            if (!silent) {
                showToast(data.error || 'Failed to filter logs', 'error');
            }
            return;
        }

        updateAuditTable(data.logs || []);
        if (!silent) {
            const count = Number(data.count || 0);
            showToast(`Found ${count} audit log${count === 1 ? '' : 's'}`, 'success');
        }
    } catch (error) {
        console.error('Audit filter request failed:', error);
        if (!silent) {
            showToast('Failed to apply filters', 'error');
        }
    }
}

function updateAuditTable(logs) {
    const tbody = document.getElementById('auditLogTableBody');
    if (!Array.isArray(logs) || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-16 text-slate-600 bg-white/70">
                    <p class="font-semibold text-slate-700 mb-1">No audit logs found</p>
                    <p class="text-sm text-slate-500">Try adjusting your filter criteria.</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = logs.map((log) => {
        const createdAt = new Date(log.created_at);
        const actionColor = actionColors[log.action_type] || 'bg-slate-100 text-slate-800';
        const targetHtml = log.target_name
            ? `<strong>${escapeHtml(log.target_name)}</strong><br><span class="text-xs text-slate-500">${escapeHtml((log.target_type || '').charAt(0).toUpperCase() + (log.target_type || '').slice(1))} ID: ${escapeHtml(String(log.target_id ?? ''))}</span>`
            : '<span class="text-slate-500">N/A</span>';

        const detailsPayload = JSON.stringify(log).replace(/'/g, "\\'");

        return `
            <tr class="border-b border-slate-200/80 odd:bg-white/75 even:bg-slate-50/78 hover:bg-sky-50/65 transition-colors">
                <td class="py-3 px-4 text-sm text-slate-700 whitespace-nowrap">
                    <div class="font-medium">${createdAt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                    <div class="text-xs text-slate-500">${createdAt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</div>
                </td>
                <td class="py-3 px-4 text-sm text-slate-800"><span class="font-semibold">${escapeHtml(log.admin_name || 'System')}</span></td>
                <td class="py-3 px-4 align-top">
                    <span class="${actionColor} inline-flex text-xs font-semibold px-2.5 py-1 rounded-full border border-black/5">${escapeHtml((log.action_type || '').replace(/_/g, ' '))}</span>
                </td>
                <td class="py-3 px-4 text-sm text-slate-700">${targetHtml}</td>
                <td class="py-3 px-4 text-sm text-slate-700 max-w-[420px]">
                    <div style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escapeHtml(log.description || '')}</div>
                </td>
                <td class="py-3 px-4 text-center">
                    ${log.details ? `<button onclick='showAuditDetails(${detailsPayload})' class="text-sky-700 hover:text-sky-900 text-sm font-semibold transition-colors">View Details</button>` : '<span class="text-slate-400 text-sm">-</span>'}
                </td>
            </tr>
        `;
    }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    applyAuditFilters(true);
    auditLiveInterval = setInterval(() => applyAuditFilters(true), 5000);
});
</script>

</main>
</body>
</html>
