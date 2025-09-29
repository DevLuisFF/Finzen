<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION['user_id'];

// Clase base para métricas
abstract class MetricCalculator {
    protected $db;
    public function __construct($db) {
        $this->db = $db;
    }
    abstract public function calculate($userId);
}

// Métricas específicas mejoradas según esquema
class TotalAccountsMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM cuentas 
            WHERE usuario_id = ? AND activa = TRUE
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?? 0;
    }
}

class TotalBalanceMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(saldo), 0) 
            FROM cuentas 
            WHERE usuario_id = ? AND activa = TRUE
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }
}

class IncomeExpenseMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                cat.tipo, 
                COALESCE(SUM(t.monto), 0) as total
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            WHERE c.usuario_id = ? 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
            AND c.activa = TRUE
            GROUP BY cat.tipo
        ");
        $stmt->execute([$userId]);
        $result = ['ingresos' => 0, 'gastos' => 0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['tipo'] === 'ingreso') {
                $result['ingresos'] = (int)$row['total'];
            } elseif ($row['tipo'] === 'gasto') {
                $result['gastos'] = (int)$row['total'];
            }
        }
        return $result;
    }
}

class RecentTransactionsMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                t.id,
                t.descripcion,
                t.monto,
                t.fecha,
                c.nombre as cuenta,
                cat.nombre as categoria,
                cat.tipo,
                cat.color,
                c.moneda
            FROM transacciones t
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE c.usuario_id = ?
            AND c.activa = TRUE
            ORDER BY t.fecha DESC, t.creado_en DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class MonthlyFlowMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(t.fecha, '%Y-%m') as mes,
                COALESCE(SUM(CASE WHEN cat.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) as ingresos,
                COALESCE(SUM(CASE WHEN cat.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) as gastos
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            WHERE c.usuario_id = ? 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            AND c.activa = TRUE
            GROUP BY mes
            ORDER BY mes ASC
        ");
        $stmt->execute([$userId]);
        $data = ['labels' => [], 'ingresos' => [], 'gastos' => []];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data['labels'][] = date('M Y', strtotime($row['mes'] . '-01'));
            $data['ingresos'][] = (int)$row['ingresos'];
            $data['gastos'][] = (int)$row['gastos'];
        }
        return $data;
    }
}

class ExpenseByCategoryMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                cat.nombre,
                COALESCE(SUM(t.monto), 0) as total,
                COALESCE(cat.color, '#6c757d') as color
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            WHERE c.usuario_id = ? 
            AND cat.tipo = 'gasto' 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            AND c.activa = TRUE
            GROUP BY cat.id, cat.nombre, cat.color
            ORDER BY total DESC
            LIMIT 6
        ");
        $stmt->execute([$userId]);
        $data = ['labels' => [], 'data' => [], 'colors' => []];
        while ($cat = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data['labels'][] = htmlspecialchars($cat['nombre']);
            $data['data'][] = abs((int)$cat['total']);
            $data['colors'][] = $cat['color'];
        }
        return $data;
    }
}

// Nueva métrica: Presupuestos activos
class ActiveBudgetsMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM presupuestos 
            WHERE usuario_id = ? 
            AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?? 0;
    }
}

// Obtener métricas
try {
    $metrics = [
        'total_cuentas' => (new TotalAccountsMetric($db))->calculate($usuario_id),
        'saldo_total' => (new TotalBalanceMetric($db))->calculate($usuario_id),
        'presupuestos_activos' => (new ActiveBudgetsMetric($db))->calculate($usuario_id),
    ];
    
    $incomeExpense = (new IncomeExpenseMetric($db))->calculate($usuario_id);
    $metrics = array_merge($metrics, $incomeExpense);
    $recentTransactions = (new RecentTransactionsMetric($db))->calculate($usuario_id);
    $monthlyFlow = (new MonthlyFlowMetric($db))->calculate($usuario_id);
    $expenseByCategory = (new ExpenseByCategoryMetric($db))->calculate($usuario_id);
    
} catch (Exception $e) {
    error_log("Error en dashboard: " . $e->getMessage());
    // Valores por defecto en caso de error
    $metrics = [
        'total_cuentas' => 0,
        'saldo_total' => 0,
        'presupuestos_activos' => 0,
        'ingresos' => 0,
        'gastos' => 0
    ];
    $recentTransactions = [];
    $monthlyFlow = ['labels' => [], 'ingresos' => [], 'gastos' => []];
    $expenseByCategory = ['labels' => [], 'data' => [], 'colors' => []];
}

// Función para formatear dinero mejorada
function formatMoney($amount, $moneda = 'PYG') {
    $symbols = [
        'PYG' => 'Gs ',
        'USD' => '$ ',
        'EUR' => '€ '
    ];
    $symbol = $symbols[$moneda] ?? 'Gs ';
    return $symbol . number_format($amount / 100, 0, ',', '.');
}

// Función para exportar datos a CSV
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Escribir encabezados
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        
        // Escribir datos
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit();
}

// Manejar exportación de transacciones
if (isset($_GET['export']) && $_GET['export'] === 'transacciones') {
    $stmt = $db->prepare("
        SELECT 
            t.fecha,
            t.descripcion,
            cat.nombre as categoria,
            cat.tipo,
            c.nombre as cuenta,
            t.monto,
            c.moneda
        FROM transacciones t
        INNER JOIN cuentas c ON t.cuenta_id = c.id
        INNER JOIN categorias cat ON t.categoria_id = cat.id
        WHERE c.usuario_id = ?
        ORDER BY t.fecha DESC
    ");
    $stmt->execute([$usuario_id]);
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para exportación
    foreach ($exportData as &$row) {
        $row['monto'] = formatMoney($row['monto'], $row['moneda']);
        unset($row['moneda']);
    }
    
    exportToCSV($exportData, 'transacciones_' . date('Y-m-d'));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bs-primary: #0d6efd;
            --bs-success: #198754;
            --bs-danger: #dc3545;
            --bs-warning: #ffc107;
            --bs-info: #0dcaf0;
            --bs-light: #f8f9fa;
            --bs-dark: #212529;
            --bs-border: #e9ecef;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #fafbfc;
            color: #333;
            line-height: 1.5;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--bs-border);
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--bs-primary) !important;
        }
        
        .nav-link {
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            color: #666 !important;
        }
        
        .nav-link:hover, .nav-link.active {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary) !important;
        }
        
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            background: white;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        .metric-card {
            border-left: 4px solid transparent;
            position: relative;
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .metric-card:hover::before {
            opacity: 1;
        }
        
        .metric-card.primary { border-left-color: var(--bs-primary); }
        .metric-card.success { border-left-color: var(--bs-success); }
        .metric-card.danger { border-left-color: var(--bs-danger); }
        .metric-card.warning { border-left-color: var(--bs-warning); }
        .metric-card.info { border-left-color: var(--bs-info); }
        
        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }
        
        .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            padding: 1rem;
        }
        
        .transaction-item {
            transition: all 0.2s ease;
            border-radius: 0.5rem;
        }
        
        .transaction-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
            transform: translateX(4px);
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1rem;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.75rem;
            font-weight: 500;
        }
        
        .btn {
            border-radius: 0.75rem;
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s ease;
        }
        
        .btn-sm {
            padding: 0.375rem 0.875rem;
            font-size: 0.875rem;
        }
        
        .table {
            border-radius: 1rem;
            overflow: hidden;
        }
        
        .table th {
            border: none;
            background-color: var(--bs-light);
            font-weight: 600;
            color: #495057;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            border: none;
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr {
            border-bottom: 1px solid var(--bs-border);
        }
        
        .table tbody tr:last-child {
            border-bottom: none;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
            color: var(--bs-dark);
        }
        
        .card-title {
            font-weight: 600;
            color: var(--bs-dark);
            margin-bottom: 1.5rem;
        }
        
        .container {
            max-width: 1400px;
        }
        
        @media (max-width: 768px) {
            .chart-container {
                height: 250px;
            }
            
            .metric-value {
                font-size: 1.5rem;
            }
            
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
        
        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                Finzen
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cuentas.php">
                            <i class="bi bi-wallet2 me-2"></i> Cuentas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="categorias.php">
                            <i class="bi bi-tags me-2"></i> Categorías
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-2"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-2"></i> Transacciones
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <a href="?export=transacciones" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download me-1"></i> Exportar CSV
                    </a>
                    <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i> Salir
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Dashboard</h1>
            <div class="text-muted">
                <?= date('d M Y') ?>
            </div>
        </div>

        <!-- Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-wallet2"></i>
                            </div>
                            <div>
                                <div class="metric-label">Cuentas activas</div>
                                <div class="metric-value"><?= htmlspecialchars($metrics['total_cuentas']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card success">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div>
                                <div class="metric-label">Saldo total</div>
                                <div class="metric-value"><?= formatMoney($metrics['saldo_total']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card info">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div>
                                <div class="metric-label">Presupuestos activos</div>
                                <div class="metric-value"><?= htmlspecialchars($metrics['presupuestos_activos']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'success' : 'danger' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' ?>">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div>
                                <div class="metric-label">Balance mensual</div>
                                <div class="metric-value <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatMoney($metrics['ingresos'] - $metrics['gastos']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Flujo de efectivo (6 meses)</h5>
                        <div class="chart-container">
                            <canvas id="monthlyFlowChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Gastos por categoría</h5>
                        <div class="chart-container">
                            <canvas id="expenseCategoryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">Transacciones recientes</h5>
                    <div class="d-flex gap-2">
                        <a href="?export=transacciones" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-download me-1"></i> Exportar
                        </a>
                        <a href="transacciones.php" class="btn btn-primary btn-sm">
                            Ver todas <i class="bi bi-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
                
                <?php if (empty($recentTransactions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-receipt"></i>
                        <p class="mb-0">No hay transacciones recientes</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Descripción</th>
                                    <th>Categoría</th>
                                    <th>Cuenta</th>
                                    <th class="text-end">Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $t): ?>
                                <tr class="transaction-item">
                                    <td>
                                        <div class="fw-medium"><?= htmlspecialchars(date('d M', strtotime($t['fecha']))) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars(date('H:i', strtotime($t['fecha']))) ?></small>
                                    </td>
                                    <td class="fw-medium"><?= htmlspecialchars($t['descripcion'] ?? 'Sin descripción') ?></td>
                                    <td>
                                        <span class="badge" style="background-color: <?= htmlspecialchars($t['color']) ?>; color: white;">
                                            <?= htmlspecialchars($t['categoria']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($t['cuenta']) ?></td>
                                    <td class="text-end">
                                        <span class="fw-bold <?= $t['tipo'] === 'ingreso' ? 'text-success' : 'text-danger' ?>">
                                            <?= $t['tipo'] === 'ingreso' ? '+' : '-' ?>
                                            <?= formatMoney(abs($t['monto']), $t['moneda']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Charts -->
    <script>
        // Monthly Flow Chart
        const monthlyFlowCtx = document.getElementById('monthlyFlowChart').getContext('2d');
        new Chart(monthlyFlowCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($monthlyFlow['labels']) ?>,
                datasets: [
                    {
                        label: 'Ingresos',
                        data: <?= json_encode(array_map(function($v) { return $v/100; }, $monthlyFlow['ingresos'])) ?>,
                        backgroundColor: 'rgba(25, 135, 84, 0.8)',
                        borderRadius: 6,
                        borderWidth: 0,
                    },
                    {
                        label: 'Gastos',
                        data: <?= json_encode(array_map(function($v) { return $v/100; }, $monthlyFlow['gastos'])) ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderRadius: 6,
                        borderWidth: 0,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#333',
                        bodyColor: '#666',
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });

        // Expense by Category Chart
        const expenseCategoryCtx = document.getElementById('expenseCategoryChart').getContext('2d');
        new Chart(expenseCategoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($expenseByCategory['labels']) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(function($v) { return $v/100; }, $expenseByCategory['data'])) ?>,
                    backgroundColor: <?= json_encode($expenseByCategory['colors']) ?>,
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { 
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#333',
                        bodyColor: '#666',
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((context.raw / total) * 100);
                                return `${context.label}: ${formatCurrency(context.raw)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Helper function to format currency
        function formatCurrency(value) {
            return 'Gs ' + value.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }
    </script>
</body>
</html>