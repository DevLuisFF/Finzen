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
        }
        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
            background-color: white;
        }
        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .metric-card {
            border-left: 4px solid var(--bs-primary);
        }
        .metric-card.success {
            border-left-color: var(--bs-success);
        }
        .metric-card.danger {
            border-left-color: var(--bs-danger);
        }
        .metric-card.warning {
            border-left-color: var(--bs-warning);
        }
        .metric-card.info {
            border-left-color: var(--bs-info);
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        .transaction-item {
            transition: background-color 0.2s;
        }
        .transaction-item:hover {
            background-color: rgba(0,0,0,0.025);
        }
        .export-btn {
            font-size: 0.875rem;
        }
        @media (max-width: 768px) {
            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-cash-stack me-2"></i>
                <strong>Finzen</strong>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cuentas.php">
                            <i class="bi bi-wallet2 me-1"></i> Cuentas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="categorias.php">
                            <i class="bi bi-tags me-1"></i> Categorías
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-1"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-1"></i> Transacciones
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="?export=transacciones" class="btn btn-outline-success btn-sm me-2 export-btn">
                        <i class="bi bi-download me-1"></i> Exportar CSV
                    </a>
                    <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i> Cerrar Sesión
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <h1 class="mb-4">Dashboard</h1>

        <!-- Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="transaction-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-wallet2"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Cuentas activas</h6>
                                <h3 class="mb-0"><?= htmlspecialchars($metrics['total_cuentas']) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card success">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="transaction-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Saldo total</h6>
                                <h3 class="mb-0"><?= formatMoney($metrics['saldo_total']) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card info">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="transaction-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Presupuestos activos</h6>
                                <h3 class="mb-0"><?= htmlspecialchars($metrics['presupuestos_activos']) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'success' : 'danger' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="transaction-icon <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' ?>">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Balance mensual</h6>
                                <h3 class="mb-0 <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatMoney($metrics['ingresos'] - $metrics['gastos']) ?>
                                </h3>
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
                        <h5 class="card-title mb-4">Flujo de efectivo (6 meses)</h5>
                        <div class="chart-container">
                            <canvas id="monthlyFlowChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">Gastos por categoría</h5>
                        <div class="chart-container">
                            <canvas id="expenseCategoryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">Transacciones recientes</h5>
                    <div>
                        <a href="?export=transacciones" class="btn btn-sm btn-outline-success me-2">
                            <i class="bi bi-download me-1"></i> Exportar CSV
                        </a>
                        <a href="transacciones.php" class="btn btn-sm btn-outline-primary">
                            Ver todas <i class="bi bi-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
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
                            <?php if (empty($recentTransactions)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                        No hay transacciones recientes
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactions as $t): ?>
                                <tr class="transaction-item">
                                    <td><?= htmlspecialchars(date('d M', strtotime($t['fecha']))) ?></td>
                                    <td><?= htmlspecialchars($t['descripcion'] ?? 'Sin descripción') ?></td>
                                    <td>
                                        <span class="badge" style="background-color: <?= htmlspecialchars($t['color']) ?>;">
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
                        backgroundColor: '#198754',
                        borderRadius: 4,
                    },
                    {
                        label: 'Gastos',
                        data: <?= json_encode(array_map(function($v) { return $v/100; }, $monthlyFlow['gastos'])) ?>,
                        backgroundColor: '#dc3545',
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
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
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
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