<?php
require_once '../config.php';
require_once '../staff_call_routing.php';

$linkKey = trim((string) ($_GET['c'] ?? $_GET['t'] ?? ''));
$validation = recepsionis_validate_helpdesk_wa_action_token($koneksi, $linkKey);

$error = '';
$entity = null;
$entityType = null;
$alreadyUsed = false;
$actionTaken = '';

if (!$validation['ok'] && empty($validation['already_used'])) {
    $error = (string) ($validation['error'] ?? 'Link tidak valid.');
} else {
    $entity = $validation['entity'] ?? null;
    $entityType = (string) ($validation['entity_type'] ?? '');
    $alreadyUsed = !empty($validation['already_used']);
    $actionTaken = (string) ($validation['action_taken'] ?? '');
}

$apiUrl = function_exists('apiUrl') ? apiUrl('helpdesk_wa_action.php') : '../api/helpdesk_wa_action.php';

function helpdesk_action_summary(?array $entity, string $entityType): array
{
    if (!$entity) {
        return [];
    }

    if ($entityType === 'ticket') {
        return [
            'sumber' => 'Tiket QR',
            'nama' => (string) ($entity['nama'] ?? '-'),
            'nomor' => (string) ($entity['nomor'] ?? '-'),
            'detail_label' => 'Kelas / Kendala',
            'detail' => trim((string) ($entity['kelas'] ?? '') . "\n" . (string) ($entity['kendala'] ?? '')),
            'ref' => 'Tiket #' . (int) ($entity['id'] ?? 0),
        ];
    }

    return [
        'sumber' => 'Panggilan Staff',
        'nama' => (string) ($entity['visitor_name'] ?? '-'),
        'nomor' => (string) ($entity['visitor_phone'] ?? '-'),
        'detail_label' => 'Keperluan',
        'detail' => (string) ($entity['message'] ?? '-'),
        'ref' => 'Panggilan #' . (int) ($entity['id'] ?? 0),
    ];
}

$summary = helpdesk_action_summary($entity, $entityType);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tindak Lanjut Helpdesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .action-card {
            max-width: 520px;
            margin: 2rem auto;
            border: none;
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
        }
        .action-header {
            background: linear-gradient(135deg, #2563eb, #0369a1);
            color: #fff;
            border-radius: 18px 18px 0 0;
            padding: 1.25rem 1.5rem;
        }
        .summary-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-bottom: 0.75rem;
        }
        .summary-item strong {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .btn-action {
            min-height: 52px;
            font-weight: 700;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <div class="container px-3">
        <div class="card action-card">
            <div class="action-header">
                <h1 class="h4 mb-1"><i class="bi bi-headset"></i> Tindak Lanjut Helpdesk</h1>
                <p class="mb-0 small opacity-75">Pilih tindakan untuk memberi tahu pelapor via WhatsApp.</p>
            </div>
            <div class="card-body p-4">
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php elseif ($alreadyUsed): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-check-circle"></i>
                        Link ini sudah diproses.
                        <?php if ($actionTaken !== ''): ?>
                            Aksi: <strong><?= htmlspecialchars(recepsionis_follow_up_action_label($actionTaken)) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($summary)): ?>
                        <div class="summary-item">
                            <strong><?= htmlspecialchars($summary['ref']) ?></strong>
                            <?= htmlspecialchars($summary['nama']) ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="summary-item">
                        <strong>Sumber</strong>
                        <?= htmlspecialchars($summary['sumber'] ?? '-') ?>
                    </div>
                    <div class="summary-item">
                        <strong>Pelapor</strong>
                        <?= htmlspecialchars($summary['nama'] ?? '-') ?>
                    </div>
                    <div class="summary-item">
                        <strong>Nomor</strong>
                        <?= htmlspecialchars($summary['nomor'] ?? '-') ?>
                    </div>
                    <div class="summary-item">
                        <strong><?= htmlspecialchars($summary['detail_label'] ?? 'Detail') ?></strong>
                        <?= nl2br(htmlspecialchars($summary['detail'] ?? '-')) ?>
                    </div>

                    <div id="actionFeedback" class="alert d-none" role="alert"></div>

                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-success btn-action" id="btnConfirm" data-action="confirm">
                            <i class="bi bi-check2-circle"></i> Konfirmasi — Sedang ditindaklanjuti
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-action" id="btnWait" data-action="wait">
                            <i class="bi bi-hourglass-split"></i> Tunggu — Masih dalam antrian
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($error === '' && !$alreadyUsed && $linkKey !== ''): ?>
    <script>
        (function () {
            const apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const token = <?= json_encode($linkKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const feedback = document.getElementById('actionFeedback');
            const buttons = [document.getElementById('btnConfirm'), document.getElementById('btnWait')];

            function showFeedback(type, message) {
                feedback.className = 'alert alert-' + type;
                feedback.textContent = message;
                feedback.classList.remove('d-none');
            }

            function setLoading(isLoading) {
                buttons.forEach(function (btn) {
                    if (!btn) return;
                    btn.disabled = isLoading;
                });
            }

            function submitAction(action) {
                setLoading(true);
                const formData = new FormData();
                formData.append('token', token);
                formData.append('action', action);

                fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            showFeedback('success', data.message || 'Berhasil diproses.');
                            buttons.forEach(function (btn) {
                                if (btn) btn.remove();
                            });
                            return;
                        }
                        showFeedback('danger', (data && data.message) ? data.message : 'Gagal memproses aksi.');
                        setLoading(false);
                    })
                    .catch(function () {
                        showFeedback('danger', 'Terjadi kesalahan jaringan.');
                        setLoading(false);
                    });
            }

            buttons.forEach(function (btn) {
                if (!btn) return;
                btn.addEventListener('click', function () {
                    submitAction(btn.getAttribute('data-action'));
                });
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
