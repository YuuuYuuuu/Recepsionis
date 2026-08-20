<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';

if (currentUserIsAdmin()) {
    header('Location: ' . adminUrl('index.php'));
    exit;
}

requireComplaintOperatorPage();

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserRole = (string) ($_SESSION['role'] ?? '');
$userName = (string) ($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'PIC');
$notifPrefs = recepsionis_get_notification_preferences($koneksi, $currentUserId);

$assignedCategoryIds = recepsionis_get_admin_category_ids($koneksi, $currentUserId);
$isHelpdeskPic = recepsionis_user_is_helpdesk_pic($koneksi, $currentUserId);
$assignedCategories = [];
foreach (recepsionis_get_complaint_categories($koneksi, true) as $category) {
    if (in_array((int) $category['id'], $assignedCategoryIds, true)) {
        $assignedCategories[] = $category;
    }
}

$pendingCalls = [];
$pendingQuery = "SELECT sc.*, cc.nama_kategori as category_name
                 FROM staff_calls sc
                 LEFT JOIN complaint_categories cc ON sc.category_id = cc.id
                 WHERE sc.status = 'pending'
                 ORDER BY sc.created_at DESC
                 LIMIT 50";
$pendingResult = $koneksi->query($pendingQuery);
if ($pendingResult) {
    while ($row = $pendingResult->fetch_assoc()) {
        if (!recepsionis_user_can_receive_staff_call(
            $koneksi,
            $currentUserId,
            (int) ($row['category_id'] ?? 0),
            (int) ($row['assigned_user_id'] ?? 0),
            $currentUserRole
        )) {
            continue;
        }
        $pendingCalls[] = $row;
        if (count($pendingCalls) >= 5) {
            break;
        }
    }
}

$pendingHelpdeskTickets = [];
if ($isHelpdeskPic && recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    $helpdeskCategoryId = recepsionis_get_helpdesk_it_category_id($koneksi);
    $ticketResult = $koneksi->query(
        "SELECT * FROM helpdesk_it_tickets WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50"
    );
    if ($ticketResult) {
        while ($row = $ticketResult->fetch_assoc()) {
            $assignedUserId = isset($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null;
            if ($assignedUserId !== null && $assignedUserId <= 0) {
                $assignedUserId = null;
            }
            if (!recepsionis_user_can_receive_helpdesk_it_ticket(
                $koneksi,
                $currentUserId,
                $assignedUserId,
                recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $row)
            )) {
                continue;
            }
            $pendingHelpdeskTickets[] = $row;
            if (count($pendingHelpdeskTickets) >= 5) {
                break;
            }
        }
    }
    unset($helpdeskCategoryId);
}

$pendingItems = [];
foreach ($pendingCalls as $call) {
    $pendingItems[] = [
        'type' => 'call',
        'created_at' => $call['created_at'],
        'data' => $call,
    ];
}
foreach ($pendingHelpdeskTickets as $ticket) {
    $pendingItems[] = [
        'type' => 'ticket',
        'created_at' => $ticket['created_at'],
        'data' => $ticket,
    ];
}
usort($pendingItems, static function ($a, $b) {
    return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
});
$pendingItems = array_slice($pendingItems, 0, 5);

$actionCounts = recepsionis_get_helpdesk_action_counts(
    $koneksi,
    $currentUserId,
    false,
    'mine',
    $currentUserRole
);

$greetingHour = (int) date('G');
if ($greetingHour < 11) {
    $greeting = 'Selamat pagi';
} elseif ($greetingHour < 15) {
    $greeting = 'Selamat siang';
} elseif ($greetingHour < 18) {
    $greeting = 'Selamat sore';
} else {
    $greeting = 'Selamat malam';
}

$ticketStatusApiUrl = function_exists('apiUrl') ? apiUrl('helpdesk_it_update_status.php') : '../api/helpdesk_it_update_status.php';
$answerCallApiUrl = function_exists('apiUrl') ? apiUrl('answer_staff_call.php') : '../api/answer_staff_call.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard PIC - E-Recepsionis System</title>
    <script>
        window.originalPageTitle = 'Dashboard PIC - E-Recepsionis System';
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_admin_head.php'; ?>
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .pic-dash-pending-item.is-resolved {
            opacity: .72;
            background: #f8fafc;
        }
        .pic-dash-pending-item.is-resolved .btn-open-item {
            display: none;
        }
        .pic-dash-detail-grid {
            display: grid;
            gap: .85rem;
        }
        .pic-dash-detail-row {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: .5rem;
            font-size: .925rem;
        }
        .pic-dash-detail-row dt {
            margin: 0;
            color: #64748b;
            font-weight: 600;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding-top: .15rem;
        }
        .pic-dash-detail-row dd {
            margin: 0;
            color: #15202b;
            font-weight: 500;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .pic-dash-detail-kendala {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: .85rem 1rem;
        }
        @media (max-width: 575.98px) {
            .pic-dash-detail-row { grid-template-columns: 1fr; gap: .15rem; }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area pic-dash">
                <div class="pic-dash-hero">
                    <div class="pic-dash-hero-text">
                        <p class="pic-dash-greeting"><?= htmlspecialchars($greeting) ?>,</p>
                        <h1 class="pic-dash-title">
                            <?= htmlspecialchars($userName) ?>
                            <?php if ($actionCounts['total'] > 0): ?>
                                <span class="pic-dash-hero-badge helpdesk-action-badge" data-helpdesk-badge="total"><?= htmlspecialchars(recepsionis_format_action_count($actionCounts['total'])) ?> perlu ditanggapi</span>
                            <?php endif; ?>
                        </h1>
                        <p class="pic-dash-lead">
                            Satu antrian Helpdesk untuk panggilan tamu dan tiket QR kelas. Notifikasi muncul otomatis sesuai sumber panggilan.
                        </p>
                        <?php if (!empty($assignedCategories)): ?>
                            <div class="pic-dash-categories">
                                <span class="pic-dash-categories-label">Kategori Anda</span>
                                <?php foreach ($assignedCategories as $category): ?>
                                    <span class="pic-dash-category">
                                        <i class="bi <?= htmlspecialchars($category['icon'] ?: 'bi-tag') ?>"></i>
                                        <?= htmlspecialchars($category['nama_kategori']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pic-dash-hero-icon" aria-hidden="true">
                        <i class="bi bi-headset"></i>
                    </div>
                </div>

                <?php if (!$notifPrefs['notifications_enabled'] || !$notifPrefs['sound_enabled']): ?>
                    <div class="alert alert-warning pic-dash-alert">
                        <i class="bi bi-bell-slash"></i>
                        Beberapa preferensi notifikasi Anda nonaktif.
                        <a href="<?= htmlspecialchars(adminUrl('settings.php#pref-notifikasi')) ?>" class="alert-link">Atur di sini</a>
                        agar tidak melewatkan panggilan.
                    </div>
                <?php endif; ?>

                <div class="pic-dash-section-label">Menu cepat</div>
                <div class="row g-3 pic-dash-actions mb-4">
                    <div class="col-md-4">
                        <a href="<?= htmlspecialchars(adminUrl('staff_calls.php?status=pending')) ?>" class="pic-dash-action-card" data-helpdesk-nav="dashboard-card">
                            <span class="pic-dash-action-icon pic-dash-action-icon--call">
                                <i class="bi bi-headset"></i>
                                <?php if ($actionCounts['total'] > 0): ?>
                                    <span class="pic-dash-action-badge helpdesk-action-badge" data-helpdesk-badge="total"><?= htmlspecialchars(recepsionis_format_action_count($actionCounts['total'])) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="pic-dash-action-body">
                                <strong>Helpdesk</strong>
                                <span>Panggilan tamu & tiket QR kelas</span>
                            </span>
                            <i class="bi bi-chevron-right pic-dash-action-arrow"></i>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="<?= htmlspecialchars(adminUrl('settings.php#pref-notifikasi')) ?>" class="pic-dash-action-card">
                            <span class="pic-dash-action-icon pic-dash-action-icon--prefs">
                                <i class="bi bi-bell-fill"></i>
                            </span>
                            <span class="pic-dash-action-body">
                                <strong>Preferensi Notifikasi</strong>
                                <span>Atur suara dan popup panggilan</span>
                            </span>
                            <i class="bi bi-chevron-right pic-dash-action-arrow"></i>
                        </a>
                    </div>
                </div>

                <div class="card pic-dash-card mb-4">
                    <div class="card-header pic-dash-card-header">
                        <div>
                            <h5 class="mb-0">
                                <i class="bi bi-hourglass-split"></i> Helpdesk menunggu
                                <?php if ($actionCounts['total'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill ms-1 helpdesk-action-badge" data-helpdesk-badge="total"><?= htmlspecialchars(recepsionis_format_action_count($actionCounts['total'])) ?></span>
                                <?php endif; ?>
                            </h5>
                            <small class="text-muted">Panggilan staff & tiket QR — sumber berbeda, antrian sama</small>
                        </div>
                        <a href="<?= htmlspecialchars(adminUrl('staff_calls.php?status=pending')) ?>" class="btn btn-sm btn-outline-primary">
                            Lihat semua
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($pendingItems)): ?>
                            <div class="pic-dash-empty">
                                <i class="bi bi-check2-circle"></i>
                                <p>Tidak ada antrian pending saat ini.</p>
                                <span>Anda siap menerima panggilan baru.</span>
                            </div>
                        <?php else: ?>
                            <ul class="pic-dash-pending-list" id="picPendingList">
                                <?php foreach ($pendingItems as $item): ?>
                                    <?php if ($item['type'] === 'ticket'): ?>
                                        <?php
                                        $ticket = $item['data'];
                                        $ticketName = trim((string) ($ticket['nama'] ?? 'Pelapor'));
                                        $initials = strtoupper(mb_substr($ticketName, 0, 1, 'UTF-8'));
                                        $ticketPayload = [
                                            'type' => 'ticket',
                                            'id' => (int) ($ticket['id'] ?? 0),
                                            'nama' => $ticketName,
                                            'nomor' => (string) ($ticket['nomor'] ?? ''),
                                            'kelas' => (string) ($ticket['kelas'] ?? ''),
                                            'kendala' => (string) ($ticket['kendala'] ?? ''),
                                            'status' => (string) ($ticket['status'] ?? 'pending'),
                                            'created_at' => date('d/m/Y H:i', strtotime((string) $item['created_at'])),
                                        ];
                                        ?>
                                        <li class="pic-dash-pending-item"
                                            data-item-type="ticket"
                                            data-item-id="<?= (int) $ticketPayload['id'] ?>"
                                            data-item='<?= htmlspecialchars(json_encode($ticketPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                                            <div class="pic-dash-pending-main">
                                                <div class="pic-dash-pending-avatar pic-dash-pending-avatar--helpdesk"><?= htmlspecialchars($initials) ?></div>
                                                <div class="pic-dash-pending-info">
                                                    <strong><?= htmlspecialchars($ticketName) ?></strong>
                                                    <span>
                                                        <span class="badge bg-primary me-1">Tiket QR</span>
                                                        <span class="item-status-badge badge bg-warning text-dark">pending</span>
                                                        · Kelas: <?= htmlspecialchars((string) ($ticket['kelas'] ?? '-')) ?>
                                                    </span>
                                                    <?php if (!empty($ticket['kendala'])): ?>
                                                        <em><?= htmlspecialchars((string) $ticket['kendala']) ?></em>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="pic-dash-pending-meta">
                                                <time><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></time>
                                                <button type="button" class="btn btn-sm btn-primary btn-open-item">Buka</button>
                                            </div>
                                        </li>
                                    <?php else: ?>
                                        <?php
                                        $call = $item['data'];
                                        $visitorName = trim((string) ($call['visitor_name'] ?? ''));
                                        if ($visitorName === '') {
                                            $visitorName = 'Tamu';
                                        }
                                        $initials = strtoupper(mb_substr($visitorName, 0, 1, 'UTF-8'));
                                        if (preg_match('/\s+(\S)/u', $visitorName, $m)) {
                                            $initials .= strtoupper($m[1]);
                                        }
                                        $callPayload = [
                                            'type' => 'call',
                                            'id' => (int) ($call['id'] ?? 0),
                                            'nama' => $visitorName,
                                            'nomor' => (string) ($call['visitor_phone'] ?? ''),
                                            'kelas' => (string) ($call['room_name'] ?? $call['category_name'] ?? 'Umum'),
                                            'kendala' => (string) ($call['message'] ?? ''),
                                            'status' => (string) ($call['status'] ?? 'pending'),
                                            'kategori' => (string) ($call['category_name'] ?? 'Umum'),
                                            'created_at' => date('d/m/Y H:i', strtotime((string) $item['created_at'])),
                                        ];
                                        ?>
                                        <li class="pic-dash-pending-item"
                                            data-item-type="call"
                                            data-item-id="<?= (int) $callPayload['id'] ?>"
                                            data-item='<?= htmlspecialchars(json_encode($callPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                                            <div class="pic-dash-pending-main">
                                                <div class="pic-dash-pending-avatar"><?= htmlspecialchars($initials) ?></div>
                                                <div class="pic-dash-pending-info">
                                                    <strong><?= htmlspecialchars($visitorName) ?></strong>
                                                    <span>
                                                        <span class="badge bg-secondary me-1">Panggilan</span>
                                                        <span class="item-status-badge badge bg-warning text-dark">pending</span>
                                                        · <?= htmlspecialchars((string) ($call['category_name'] ?? 'Umum')) ?>
                                                    </span>
                                                    <?php if (!empty($call['message'])): ?>
                                                        <em><?= htmlspecialchars((string) $call['message']) ?></em>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="pic-dash-pending-meta">
                                                <time><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></time>
                                                <button type="button" class="btn btn-sm btn-success btn-open-item">Buka</button>
                                            </div>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="picHelpdeskModal" tabindex="-1" aria-labelledby="picHelpdeskModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="picHelpdeskModalLabel">Detail Helpdesk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <span class="badge" id="picModalTypeBadge">Tiket QR</span>
                        <span class="badge bg-warning text-dark ms-1" id="picModalStatusBadge">pending</span>
                        <span class="text-muted small ms-2" id="picModalIdLabel">#0</span>
                    </div>
                    <dl class="pic-dash-detail-grid mb-0">
                        <div class="pic-dash-detail-row">
                            <dt>Nama</dt>
                            <dd id="picModalNama">—</dd>
                        </div>
                        <div class="pic-dash-detail-row">
                            <dt>Kontak</dt>
                            <dd id="picModalKontak">—</dd>
                        </div>
                        <div class="pic-dash-detail-row">
                            <dt id="picModalLokasiLabel">Kelas</dt>
                            <dd id="picModalLokasi">—</dd>
                        </div>
                        <div class="pic-dash-detail-row">
                            <dt>Waktu</dt>
                            <dd id="picModalWaktu">—</dd>
                        </div>
                    </dl>
                    <div class="mt-3">
                        <div class="small text-muted fw-semibold text-uppercase mb-1" style="letter-spacing:.03em;" id="picModalKendalaLabel">Kendala</div>
                        <div class="pic-dash-detail-kendala" id="picModalKendala">—</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" id="picModalProsesBtn">
                        <i class="bi bi-check2-circle"></i> Proses
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script>
    (function () {
        var ticketApi = <?= json_encode($ticketStatusApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var callApi = <?= json_encode($answerCallApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var modalEl = document.getElementById('picHelpdeskModal');
        if (!modalEl) return;
        var modal = new bootstrap.Modal(modalEl);
        var currentItem = null;
        var currentLi = null;
        var prosesBtn = document.getElementById('picModalProsesBtn');

        function setText(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value && String(value).trim() !== '' ? String(value) : '—';
        }

        function openItem(li) {
            var raw = li.getAttribute('data-item') || '{}';
            var item;
            try { item = JSON.parse(raw); } catch (e) { return; }
            currentItem = item;
            currentLi = li;

            var isTicket = item.type === 'ticket';
            document.getElementById('picHelpdeskModalLabel').textContent = isTicket ? 'Detail Tiket Helpdesk' : 'Detail Panggilan Staff';
            var typeBadge = document.getElementById('picModalTypeBadge');
            typeBadge.textContent = isTicket ? 'Tiket QR' : 'Panggilan';
            typeBadge.className = 'badge ' + (isTicket ? 'bg-primary' : 'bg-secondary');
            document.getElementById('picModalStatusBadge').textContent = item.status || 'pending';
            document.getElementById('picModalStatusBadge').className = 'badge bg-warning text-dark ms-1';
            setText('picModalIdLabel', '#' + (item.id || 0));
            setText('picModalNama', item.nama);
            setText('picModalKontak', item.nomor);
            document.getElementById('picModalLokasiLabel').textContent = isTicket ? 'Kelas' : 'Kategori / Ruang';
            setText('picModalLokasi', item.kelas || item.kategori || '—');
            setText('picModalWaktu', item.created_at);
            document.getElementById('picModalKendalaLabel').textContent = isTicket ? 'Kendala' : 'Pesan';
            setText('picModalKendala', item.kendala);

            var done = item.status === 'resolved' || item.status === 'answered';
            prosesBtn.disabled = !!done;
            prosesBtn.innerHTML = done
                ? '<i class="bi bi-check2-circle"></i> Sudah diproses'
                : '<i class="bi bi-check2-circle"></i> Proses';
            modal.show();
        }

        function markListResolved(li, statusLabel) {
            if (!li) return;
            li.classList.add('is-resolved');
            var badge = li.querySelector('.item-status-badge');
            if (badge) {
                badge.textContent = statusLabel;
                badge.className = 'item-status-badge badge bg-success';
            }
            var data;
            try { data = JSON.parse(li.getAttribute('data-item') || '{}'); } catch (e) { data = {}; }
            data.status = statusLabel;
            li.setAttribute('data-item', JSON.stringify(data));
        }

        function bumpBadgesDown() {
            document.querySelectorAll('[data-helpdesk-badge="total"]').forEach(function (el) {
                var n = parseInt((el.textContent || '').replace(/\D/g, ''), 10);
                if (isNaN(n)) return;
                n = Math.max(0, n - 1);
                if (n <= 0) {
                    el.remove();
                } else {
                    el.textContent = el.classList.contains('pic-dash-hero-badge')
                        ? (n + ' perlu ditanggapi')
                        : String(n);
                }
            });
            if (typeof window.refreshHelpdeskBadges === 'function') {
                try { window.refreshHelpdeskBadges(); } catch (e) {}
            }
        }

        document.querySelectorAll('.btn-open-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var li = btn.closest('.pic-dash-pending-item');
                if (li) openItem(li);
            });
        });

        prosesBtn.addEventListener('click', async function () {
            if (!currentItem || !currentItem.id) return;
            prosesBtn.disabled = true;
            var original = prosesBtn.innerHTML;
            prosesBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memproses...';

            try {
                var body = new FormData();
                var res;
                var doneLabel;
                if (currentItem.type === 'ticket') {
                    body.append('ticket_id', String(currentItem.id));
                    body.append('status', 'resolved');
                    res = await fetch(ticketApi, { method: 'POST', body: body, credentials: 'same-origin' });
                    doneLabel = 'resolved';
                } else {
                    body.append('call_id', String(currentItem.id));
                    res = await fetch(callApi, { method: 'POST', body: body, credentials: 'same-origin' });
                    doneLabel = 'answered';
                }
                var data = await res.json();
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'Gagal memproses.');
                }

                markListResolved(currentLi, doneLabel);
                currentItem.status = doneLabel;
                document.getElementById('picModalStatusBadge').textContent = doneLabel;
                document.getElementById('picModalStatusBadge').className = 'badge bg-success ms-1';
                prosesBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Sudah diproses';
                bumpBadgesDown();

                if (typeof showSuccess === 'function') {
                    showSuccess('Berhasil', currentItem.type === 'ticket'
                        ? ('Tiket #' + currentItem.id + ' diproses (resolved).')
                        : ('Panggilan #' + currentItem.id + ' ditandai terjawab.'));
                }

                setTimeout(function () { modal.hide(); }, 700);
            } catch (err) {
                prosesBtn.disabled = false;
                prosesBtn.innerHTML = original;
                if (typeof showError === 'function') {
                    showError('Gagal', err.message || 'Tidak dapat memproses.');
                } else {
                    alert(err.message || 'Tidak dapat memproses.');
                }
            }
        });
    })();
    </script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>

