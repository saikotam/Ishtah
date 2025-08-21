<?php
/**
 * Comprehensive Sync Monitoring Dashboard
 * Real-time monitoring and management interface for the synchronization system
 */

require_once __DIR__ . '/../includes/db.php';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'dashboard_stats':
            echo json_encode(getDashboardStats($pdo));
            break;
        case 'queue_items':
            echo json_encode(getQueueItems($pdo));
            break;
        case 'recent_alerts':
            echo json_encode(getRecentAlerts($pdo));
            break;
        case 'system_health':
            echo json_encode(getSystemHealth($pdo));
            break;
        case 'reconciliation_history':
            echo json_encode(getReconciliationHistory($pdo));
            break;
        case 'retry_failed':
            $result = retryFailedSyncs($pdo, $_GET['operation_id'] ?? null);
            echo json_encode($result);
            break;
        case 'resolve_alert':
            $result = resolveAlert($pdo, $_GET['alert_id'] ?? null);
            echo json_encode($result);
            break;
    }
    exit;
}

function getDashboardStats($pdo) {
    $stats = [];
    
    // Queue statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_pending,
            COUNT(CASE WHEN retry_count >= max_retries THEN 1 END) as failed,
            COUNT(CASE WHEN locked_at IS NOT NULL THEN 1 END) as processing,
            MIN(created_at) as oldest_pending
        FROM sync_queue 
        WHERE processed_at IS NULL
    ");
    $queueStats = $stmt->fetch();
    
    // Today's processing statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as processed_today,
            COUNT(CASE WHEN retry_count = 0 THEN 1 END) as success_first_try,
            AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_processing_time
        FROM sync_queue 
        WHERE processed_at >= CURDATE()
    ");
    $todayStats = $stmt->fetch();
    
    // Sync status by type
    $stmt = $pdo->query("
        SELECT 
            reference_type,
            COUNT(*) as total,
            COUNT(CASE WHEN is_synced = TRUE THEN 1 END) as synced,
            COUNT(CASE WHEN is_synced = FALSE THEN 1 END) as unsynced
        FROM sync_master_status 
        GROUP BY reference_type
    ");
    $typeStats = $stmt->fetchAll();
    
    // Active alerts
    $stmt = $pdo->query("
        SELECT severity, COUNT(*) as count
        FROM sync_alerts 
        WHERE resolved_at IS NULL
        GROUP BY severity
    ");
    $alertStats = $stmt->fetchAll();
    
    return [
        'queue' => $queueStats,
        'today' => $todayStats,
        'by_type' => $typeStats,
        'alerts' => $alertStats,
        'last_updated' => date('Y-m-d H:i:s')
    ];
}

function getQueueItems($pdo) {
    $limit = $_GET['limit'] ?? 50;
    $status = $_GET['status'] ?? 'all';
    
    $whereClause = "1=1";
    $params = [];
    
    if ($status === 'pending') {
        $whereClause = "processed_at IS NULL AND retry_count < max_retries";
    } elseif ($status === 'failed') {
        $whereClause = "processed_at IS NULL AND retry_count >= max_retries";
    } elseif ($status === 'processing') {
        $whereClause = "processed_at IS NULL AND locked_at IS NOT NULL";
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            operation_id,
            reference_type,
            reference_id,
            operation_type,
            priority,
            retry_count,
            max_retries,
            created_at,
            scheduled_at,
            locked_at,
            locked_by,
            processed_at,
            TIMESTAMPDIFF(MINUTE, created_at, CURRENT_TIMESTAMP) as age_minutes
        FROM sync_queue 
        WHERE {$whereClause}
        ORDER BY priority ASC, created_at ASC
        LIMIT ?
    ");
    $stmt->execute(array_merge($params, [$limit]));
    return $stmt->fetchAll();
}

function getRecentAlerts($pdo) {
    $limit = $_GET['limit'] ?? 20;
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            alert_type,
            severity,
            message,
            details,
            triggered_at,
            acknowledged_at,
            resolved_at,
            notification_sent
        FROM sync_alerts 
        ORDER BY triggered_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getSystemHealth($pdo) {
    $stmt = $pdo->query("
        SELECT 
            check_time,
            queue_size,
            failed_syncs_count,
            avg_processing_time_ms,
            last_successful_sync,
            system_status,
            details
        FROM sync_system_health 
        ORDER BY check_time DESC
        LIMIT 100
    ");
    return $stmt->fetchAll();
}

function getReconciliationHistory($pdo) {
    $stmt = $pdo->query("
        SELECT 
            reconciliation_type,
            start_time,
            end_time,
            records_checked,
            missing_entries_found,
            sync_corrections_made,
            errors_encountered,
            status
        FROM sync_reconciliation_log 
        ORDER BY start_time DESC
        LIMIT 20
    ");
    return $stmt->fetchAll();
}

function retryFailedSyncs($pdo, $operationId = null) {
    try {
        if ($operationId) {
            // Retry specific operation
            $stmt = $pdo->prepare("
                UPDATE sync_queue 
                SET retry_count = 0, scheduled_at = CURRENT_TIMESTAMP, locked_at = NULL, locked_by = NULL
                WHERE operation_id = ? AND processed_at IS NULL
            ");
            $stmt->execute([$operationId]);
            $affected = $stmt->rowCount();
        } else {
            // Retry all failed operations
            $stmt = $pdo->query("
                UPDATE sync_queue 
                SET retry_count = 0, scheduled_at = CURRENT_TIMESTAMP, locked_at = NULL, locked_by = NULL
                WHERE processed_at IS NULL AND retry_count >= max_retries
            ");
            $affected = $stmt->rowCount();
        }
        
        return ['success' => true, 'affected' => $affected];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function resolveAlert($pdo, $alertId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE sync_alerts 
            SET resolved_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$alertId]);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Monitoring Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .status-healthy { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-critical { color: #dc3545; }
        .alert-critical { border-left: 4px solid #dc3545; }
        .alert-high { border-left: 4px solid #fd7e14; }
        .alert-medium { border-left: 4px solid #ffc107; }
        .alert-low { border-left: 4px solid #6c757d; }
        .metric-card { transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .refresh-indicator { display: none; }
        .refresh-indicator.active { display: inline-block; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <h1><i class="fas fa-sync-alt"></i> Sync Monitoring Dashboard</h1>
                    <div>
                        <span class="refresh-indicator" id="refreshIndicator">
                            <i class="fas fa-spinner fa-spin"></i> Refreshing...
                        </span>
                        <button class="btn btn-outline-primary btn-sm" onclick="refreshDashboard()">
                            <i class="fas fa-refresh"></i> Refresh
                        </button>
                        <button class="btn btn-outline-warning btn-sm" onclick="retryAllFailed()">
                            <i class="fas fa-redo"></i> Retry Failed
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Stats -->
        <div class="row mb-4" id="dashboardStats">
            <!-- Stats will be loaded here -->
        </div>

        <!-- Charts Row -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line"></i> System Health Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="healthChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-pie"></i> Sync Status by Type</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="typeChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs" id="mainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="queue-tab" data-bs-toggle="tab" data-bs-target="#queue" type="button">
                    <i class="fas fa-list"></i> Queue Items
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#alerts" type="button">
                    <i class="fas fa-exclamation-triangle"></i> Alerts
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reconciliation-tab" data-bs-toggle="tab" data-bs-target="#reconciliation" type="button">
                    <i class="fas fa-balance-scale"></i> Reconciliation
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button">
                    <i class="fas fa-server"></i> System Health
                </button>
            </li>
        </ul>

        <div class="tab-content" id="mainTabContent">
            <!-- Queue Tab -->
            <div class="tab-pane fade show active" id="queue" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5>Sync Queue</h5>
                            <div>
                                <select class="form-select form-select-sm d-inline-block w-auto" id="queueFilter">
                                    <option value="all">All Items</option>
                                    <option value="pending">Pending</option>
                                    <option value="failed">Failed</option>
                                    <option value="processing">Processing</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="queueTable">
                                <thead>
                                    <tr>
                                        <th>Operation ID</th>
                                        <th>Type</th>
                                        <th>Reference ID</th>
                                        <th>Operation</th>
                                        <th>Priority</th>
                                        <th>Retries</th>
                                        <th>Age</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Queue items will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts Tab -->
            <div class="tab-pane fade" id="alerts" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5>Recent Alerts</h5>
                    </div>
                    <div class="card-body" id="alertsList">
                        <!-- Alerts will be loaded here -->
                    </div>
                </div>
            </div>

            <!-- Reconciliation Tab -->
            <div class="tab-pane fade" id="reconciliation" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5>Reconciliation History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="reconciliationTable">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Start Time</th>
                                        <th>Duration</th>
                                        <th>Records Checked</th>
                                        <th>Missing Found</th>
                                        <th>Corrections Made</th>
                                        <th>Errors</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Reconciliation history will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Health Tab -->
            <div class="tab-pane fade" id="system" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5>System Health History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm" id="systemHealthTable">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Queue Size</th>
                                        <th>Failed Syncs</th>
                                        <th>Avg Processing Time</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- System health will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let healthChart, typeChart;
        let refreshInterval;

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            refreshDashboard();
            
            // Auto-refresh every 30 seconds
            refreshInterval = setInterval(refreshDashboard, 30000);
            
            // Queue filter change handler
            document.getElementById('queueFilter').addEventListener('change', loadQueueItems);
        });

        function initializeCharts() {
            // Health trend chart
            const healthCtx = document.getElementById('healthChart').getContext('2d');
            healthChart = new Chart(healthCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Queue Size',
                        data: [],
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'Failed Syncs',
                        data: [],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Type distribution chart
            const typeCtx = document.getElementById('typeChart').getContext('2d');
            typeChart = new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#007bff',
                            '#28a745',
                            '#ffc107',
                            '#dc3545'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        }

        async function refreshDashboard() {
            showRefreshIndicator();
            
            try {
                await Promise.all([
                    loadDashboardStats(),
                    loadQueueItems(),
                    loadAlerts(),
                    loadReconciliationHistory(),
                    loadSystemHealth()
                ]);
            } catch (error) {
                console.error('Error refreshing dashboard:', error);
            } finally {
                hideRefreshIndicator();
            }
        }

        async function loadDashboardStats() {
            const response = await fetch('?action=dashboard_stats');
            const data = await response.json();
            
            const statsHtml = `
                <div class="col-md-3">
                    <div class="card metric-card text-center">
                        <div class="card-body">
                            <h3 class="text-primary">${data.queue.total_pending || 0}</h3>
                            <p class="card-text">Pending Items</p>
                            <small class="text-muted">Queue Size</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card metric-card text-center">
                        <div class="card-body">
                            <h3 class="text-danger">${data.queue.failed || 0}</h3>
                            <p class="card-text">Failed Syncs</p>
                            <small class="text-muted">Need Attention</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card metric-card text-center">
                        <div class="card-body">
                            <h3 class="text-success">${data.today.processed_today || 0}</h3>
                            <p class="card-text">Processed Today</p>
                            <small class="text-muted">Success Rate: ${data.today.success_first_try ? Math.round((data.today.success_first_try / data.today.processed_today) * 100) : 0}%</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card metric-card text-center">
                        <div class="card-body">
                            <h3 class="text-warning">${data.queue.processing || 0}</h3>
                            <p class="card-text">Processing</p>
                            <small class="text-muted">Active Workers</small>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('dashboardStats').innerHTML = statsHtml;
            
            // Update type chart
            if (data.by_type && data.by_type.length > 0) {
                typeChart.data.labels = data.by_type.map(item => item.reference_type);
                typeChart.data.datasets[0].data = data.by_type.map(item => item.total);
                typeChart.update();
            }
        }

        async function loadQueueItems() {
            const filter = document.getElementById('queueFilter').value;
            const response = await fetch(`?action=queue_items&status=${filter}&limit=100`);
            const items = await response.json();
            
            const tbody = document.querySelector('#queueTable tbody');
            tbody.innerHTML = '';
            
            items.forEach(item => {
                const row = document.createElement('tr');
                const statusClass = item.retry_count >= item.max_retries ? 'table-danger' : 
                                  item.locked_at ? 'table-warning' : 'table-light';
                row.className = statusClass;
                
                const status = item.retry_count >= item.max_retries ? 'Failed' :
                              item.locked_at ? 'Processing' : 'Pending';
                
                row.innerHTML = `
                    <td><code>${item.operation_id.substring(0, 8)}...</code></td>
                    <td><span class="badge bg-secondary">${item.reference_type}</span></td>
                    <td>${item.reference_id}</td>
                    <td>${item.operation_type}</td>
                    <td>${item.priority}</td>
                    <td>${item.retry_count}/${item.max_retries}</td>
                    <td>${item.age_minutes}m</td>
                    <td><span class="badge bg-${status === 'Failed' ? 'danger' : status === 'Processing' ? 'warning' : 'secondary'}">${status}</span></td>
                    <td>
                        ${item.retry_count >= item.max_retries ? 
                          `<button class="btn btn-sm btn-outline-warning" onclick="retrySync('${item.operation_id}')">
                             <i class="fas fa-redo"></i> Retry
                           </button>` : ''}
                    </td>
                `;
                
                tbody.appendChild(row);
            });
        }

        async function loadAlerts() {
            const response = await fetch('?action=recent_alerts&limit=20');
            const alerts = await response.json();
            
            const container = document.getElementById('alertsList');
            container.innerHTML = '';
            
            if (alerts.length === 0) {
                container.innerHTML = '<div class="alert alert-success"><i class="fas fa-check"></i> No active alerts</div>';
                return;
            }
            
            alerts.forEach(alert => {
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${alert.severity.toLowerCase()} alert-${alert.severity.toLowerCase()}`;
                
                const resolvedBadge = alert.resolved_at ? '<span class="badge bg-success ms-2">Resolved</span>' : '';
                const resolveButton = !alert.resolved_at ? 
                    `<button class="btn btn-sm btn-outline-secondary" onclick="resolveAlert(${alert.id})">
                       <i class="fas fa-check"></i> Resolve
                     </button>` : '';
                
                alertDiv.innerHTML = `
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>${alert.alert_type}</strong> ${resolvedBadge}
                            <p class="mb-1">${alert.message}</p>
                            <small class="text-muted">
                                <i class="fas fa-clock"></i> ${new Date(alert.triggered_at).toLocaleString()}
                            </small>
                        </div>
                        <div>
                            ${resolveButton}
                        </div>
                    </div>
                `;
                
                container.appendChild(alertDiv);
            });
        }

        async function loadReconciliationHistory() {
            const response = await fetch('?action=reconciliation_history');
            const history = await response.json();
            
            const tbody = document.querySelector('#reconciliationTable tbody');
            tbody.innerHTML = '';
            
            history.forEach(item => {
                const row = document.createElement('tr');
                const duration = item.end_time ? 
                    Math.round((new Date(item.end_time) - new Date(item.start_time)) / 1000) + 's' : 
                    'Running...';
                
                row.innerHTML = `
                    <td>${item.reconciliation_type}</td>
                    <td>${new Date(item.start_time).toLocaleString()}</td>
                    <td>${duration}</td>
                    <td>${item.records_checked || 0}</td>
                    <td>${item.missing_entries_found || 0}</td>
                    <td>${item.sync_corrections_made || 0}</td>
                    <td>${item.errors_encountered || 0}</td>
                    <td><span class="badge bg-${item.status === 'COMPLETED' ? 'success' : item.status === 'FAILED' ? 'danger' : 'warning'}">${item.status}</span></td>
                `;
                
                tbody.appendChild(row);
            });
        }

        async function loadSystemHealth() {
            const response = await fetch('?action=system_health');
            const health = await response.json();
            
            // Update health chart
            const labels = health.slice(0, 20).reverse().map(item => 
                new Date(item.check_time).toLocaleTimeString()
            );
            const queueData = health.slice(0, 20).reverse().map(item => item.queue_size);
            const failedData = health.slice(0, 20).reverse().map(item => item.failed_syncs_count);
            
            healthChart.data.labels = labels;
            healthChart.data.datasets[0].data = queueData;
            healthChart.data.datasets[1].data = failedData;
            healthChart.update();
            
            // Update system health table
            const tbody = document.querySelector('#systemHealthTable tbody');
            tbody.innerHTML = '';
            
            health.slice(0, 50).forEach(item => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${new Date(item.check_time).toLocaleString()}</td>
                    <td>${item.queue_size}</td>
                    <td>${item.failed_syncs_count}</td>
                    <td>${item.avg_processing_time_ms}ms</td>
                    <td><span class="status-${item.system_status.toLowerCase()}">${item.system_status}</span></td>
                `;
                tbody.appendChild(row);
            });
        }

        async function retrySync(operationId) {
            try {
                const response = await fetch(`?action=retry_failed&operation_id=${operationId}`);
                const result = await response.json();
                
                if (result.success) {
                    alert('Sync operation queued for retry');
                    loadQueueItems();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error retrying sync: ' + error.message);
            }
        }

        async function retryAllFailed() {
            if (!confirm('Are you sure you want to retry all failed sync operations?')) return;
            
            try {
                const response = await fetch('?action=retry_failed');
                const result = await response.json();
                
                if (result.success) {
                    alert(`${result.affected} operations queued for retry`);
                    refreshDashboard();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error retrying failed syncs: ' + error.message);
            }
        }

        async function resolveAlert(alertId) {
            try {
                const response = await fetch(`?action=resolve_alert&alert_id=${alertId}`);
                const result = await response.json();
                
                if (result.success) {
                    loadAlerts();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error resolving alert: ' + error.message);
            }
        }

        function showRefreshIndicator() {
            document.getElementById('refreshIndicator').classList.add('active');
        }

        function hideRefreshIndicator() {
            document.getElementById('refreshIndicator').classList.remove('active');
        }
    </script>
</body>
</html>