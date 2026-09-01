<?php
require_once 'auth.php';
requireSuperAdminPage();

recepsionis_sync_staff_calls_to_visitors($koneksi);
recepsionis_run_auto_checkout($koneksi);

// Get statistics
$stats = [];

// Total visitors today
$result = $koneksi->query("SELECT COUNT(*) as count FROM visitors WHERE DATE(created_at) = CURDATE()");
$stats['visitors_today'] = $result->fetch_assoc()['count'];

// Checked in visitors
$result = $koneksi->query("SELECT COUNT(*) as count FROM visitors WHERE status = 'checked-in'");
$stats['checked_in'] = $result->fetch_assoc()['count'];

// Pending staff calls
$result = $koneksi->query("SELECT COUNT(*) as count FROM staff_calls WHERE status = 'pending'");
$stats['pending_staff_calls'] = $result->fetch_assoc()['count'];

// Get visitor statistics for the last 7 days
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $koneksi->prepare("SELECT COUNT(*) as count FROM visitors WHERE DATE(created_at) = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $chart_data['dates'][] = date('M d', strtotime($date));
    $chart_data['visitors'][] = $result->fetch_assoc()['count'];
    $stmt->close();
}

// Get recent visitors
$recent_visitors = $koneksi->query("SELECT v.*, h.nama as host_nama 
                                    FROM visitors v 
                                    LEFT JOIN hosts h ON v.host_id = h.id 
                                    ORDER BY v.created_at DESC 
                                    LIMIT 5");

// Get pending staff calls
$pending_staff_calls = $koneksi->query("SELECT * FROM staff_calls 
                                        WHERE status = 'pending' 
                                        ORDER BY created_at DESC 
                                        LIMIT 5");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - E-Recepsionis System</title>
    <script>
        window.originalPageTitle = 'Dashboard - E-Recepsionis System';
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>

            <!-- Main Content -->
            <div class="col-md-10 content-area admin-dashboard">
                <div class="adm-dash-head">
                    <h2 class="adm-dash-title"><i class="bi bi-speedometer2"></i> Dashboard</h2>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-2 mb-3 adm-dash-stats">
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="icon primary me-3">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 text-muted">Tamu Hari Ini</h6>
                                    <h3 class="mb-0"><?= $stats['visitors_today'] ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="icon success me-3">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 text-muted">Sedang Check-In</h6>
                                    <h3 class="mb-0"><?= $stats['checked_in'] ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="icon warning me-3">
                                    <i class="bi bi-telephone"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 text-muted">Panggilan Staff</h6>
                                    <h3 class="mb-0"><?= $stats['pending_staff_calls'] ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="icon info me-3">
                                    <i class="bi bi-door-open"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 text-muted">Total Ruangan</h6>
                                    <?php 
                                    $room_count = $koneksi->query("SELECT COUNT(*) as count FROM rooms WHERE status_aktif = 1")->fetch_assoc()['count'];
                                    ?>
                                    <h3 class="mb-0"><?= $room_count ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <!-- Recent Visitors -->
                    <div class="col-lg-6">
                        <div class="card adm-dash-card">
                            <div class="card-header py-2">
                                <i class="bi bi-clock-history"></i> Tamu Terbaru
                            </div>
                            <div class="card-body py-2">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Nama</th>
                                                <th>Host</th>
                                                <th>Status</th>
                                                <th>Waktu</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($recent_visitors && $recent_visitors->num_rows > 0): ?>
                                                <?php while ($visitor = $recent_visitors->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($visitor['nama'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($visitor['host_nama'] ?? '-') ?></td>
                                                        <td>
                                                            <span class="badge-status <?= $visitor['status'] ?>">
                                                                <?= ucfirst(str_replace('-', ' ', $visitor['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td><?= date('H:i', strtotime($visitor['created_at'])) ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">Belum ada tamu</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <a href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('visitors.php') : 'visitors.php') ?>" class="btn btn-primary btn-sm mt-1">
                                    <i class="bi bi-arrow-right"></i> Lihat Semua
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Staff Calls -->
                    <div class="col-lg-6">
                        <div class="card adm-dash-card">
                            <div class="card-header py-2">
                                <i class="bi bi-telephone"></i> Panggilan Staff Terbaru
                            </div>
                            <div class="card-body py-2">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Nama</th>
                                                <th>Telepon</th>
                                                <th>Waktu</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($pending_staff_calls && $pending_staff_calls->num_rows > 0): ?>
                                                <?php while ($call = $pending_staff_calls->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($call['visitor_name']) ?></td>
                                                        <td><?= htmlspecialchars($call['visitor_phone']) ?></td>
                                                        <td><?= date('H:i', strtotime($call['created_at'])) ?></td>
                                                        <td>
                                                            <span class="badge-status <?= $call['status'] ?>">
                                                                <?= ucfirst($call['status']) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">Tidak ada panggilan staff</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <a href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('staff_calls.php') : 'staff_calls.php') ?>" class="btn btn-primary btn-sm mt-1">
                                    <i class="bi bi-arrow-right"></i> Lihat Semua
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visitor Chart -->
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card adm-dash-card">
                            <div class="card-header py-2">
                                <i class="bi bi-bar-chart"></i> Statistik Pengunjung 7 Hari Terakhir
                            </div>
                            <div class="card-body py-2">
                                <div class="adm-dash-chart-wrap">
                                    <canvas id="visitorChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
    <script>
        // Visitor Chart
        const ctx = document.getElementById('visitorChart').getContext('2d');
        const visitorChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['dates']) ?>,
                datasets: [{
                    label: 'Jumlah Pengunjung',
                    data: <?= json_encode($chart_data['visitors']) ?>,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            maxTicksLimit: 5
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 7
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
