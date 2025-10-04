<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION['user_id'];

// Clase base para métricas adaptada al esquema actual
abstract class MetricCalculator {
    protected $db;
    public function __construct($db) {
        $this->db = $db;
    }
    abstract public function calculate($userId);
}

// Métricas específicas para el esquema actual
class TotalBalanceMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT saldo 
            FROM usuarios 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?? 0;
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
            WHERE t.usuario_id = ? 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
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
                cat.nombre as categoria,
                cat.tipo,
                cat.color,
                cat.icono,
                u.moneda
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            INNER JOIN usuarios u ON t.usuario_id = u.id
            WHERE t.usuario_id = ?
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
            WHERE t.usuario_id = ? 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
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
                COALESCE(cat.color, '#6c757d') as color,
                cat.icono
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE t.usuario_id = ? 
            AND cat.tipo = 'gasto' 
            AND t.fecha >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            GROUP BY cat.id, cat.nombre, cat.color, cat.icono
            ORDER BY total DESC
            LIMIT 6
        ");
        $stmt->execute([$userId]);
        $data = ['labels' => [], 'data' => [], 'colors' => [], 'icons' => []];
        while ($cat = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data['labels'][] = htmlspecialchars($cat['nombre']);
            $data['data'][] = abs((int)$cat['total']);
            $data['colors'][] = $cat['color'];
            $data['icons'][] = $cat['icono'];
        }
        return $data;
    }
}

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

class CategoriesCountMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM categorias 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?? 0;
    }
}

class BudgetAlertsMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM presupuestos p
            INNER JOIN (
                SELECT 
                    categoria_id,
                    COALESCE(SUM(monto), 0) as gasto_actual
                FROM transacciones t
                INNER JOIN categorias c ON t.categoria_id = c.id
                WHERE t.usuario_id = ?
                AND c.tipo = 'gasto'
                AND t.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND CURDATE()
                GROUP BY categoria_id
            ) g ON p.categoria_id = g.categoria_id
            WHERE p.usuario_id = ?
            AND (g.gasto_actual / p.monto) >= 0.8
            AND (g.gasto_actual / p.monto) < 1.0
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchColumn() ?? 0;
    }
}

class OverBudgetMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM presupuestos p
            INNER JOIN (
                SELECT 
                    categoria_id,
                    COALESCE(SUM(monto), 0) as gasto_actual
                FROM transacciones t
                INNER JOIN categorias c ON t.categoria_id = c.id
                WHERE t.usuario_id = ?
                AND c.tipo = 'gasto'
                AND t.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND CURDATE()
                GROUP BY categoria_id
            ) g ON p.categoria_id = g.categoria_id
            WHERE p.usuario_id = ?
            AND g.gasto_actual > p.monto
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchColumn() ?? 0;
    }
}

// NUEVA CLASE: Para obtener las alertas detalladas de presupuestos
class DetailedBudgetAlertsMetric extends MetricCalculator {
    public function calculate($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.nombre as categoria_nombre,
                c.color as categoria_color,
                c.icono as categoria_icono,
                COALESCE(SUM(t.monto), 0) as gastos_actuales,
                (COALESCE(SUM(t.monto), 0) / p.monto) * 100 as porcentaje_uso
            FROM presupuestos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN transacciones t ON p.categoria_id = t.categoria_id
                AND t.fecha BETWEEN p.fecha_inicio AND COALESCE(p.fecha_fin, CURDATE())
            WHERE p.usuario_id = ?
            AND p.notificacion = 1
            AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())
            GROUP BY p.id, c.nombre, c.color, c.icono
            HAVING porcentaje_uso >= 80
            ORDER BY porcentaje_uso DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Obtener métricas
try {
    $metrics = [
        'saldo_total' => (new TotalBalanceMetric($db))->calculate($usuario_id),
        'total_categorias' => (new CategoriesCountMetric($db))->calculate($usuario_id),
        'presupuestos_activos' => (new ActiveBudgetsMetric($db))->calculate($usuario_id),
        'alertas_presupuesto' => (new BudgetAlertsMetric($db))->calculate($usuario_id),
        'presupuestos_excedidos' => (new OverBudgetMetric($db))->calculate($usuario_id),
    ];
    
    $incomeExpense = (new IncomeExpenseMetric($db))->calculate($usuario_id);
    $metrics = array_merge($metrics, $incomeExpense);
    $recentTransactions = (new RecentTransactionsMetric($db))->calculate($usuario_id);
    $monthlyFlow = (new MonthlyFlowMetric($db))->calculate($usuario_id);
    $expenseByCategory = (new ExpenseByCategoryMetric($db))->calculate($usuario_id);
    
    // OBTENER ALERTAS DETALLADAS - NUEVO
    $detailedBudgetAlerts = (new DetailedBudgetAlertsMetric($db))->calculate($usuario_id);
    
    // Obtener información del usuario para la moneda
    $stmt = $db->prepare("SELECT moneda FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $user_currency = $stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("Error en dashboard: " . $e->getMessage());
    // Valores por defecto en caso de error
    $metrics = [
        'saldo_total' => 0,
        'total_categorias' => 0,
        'presupuestos_activos' => 0,
        'alertas_presupuesto' => 0,
        'presupuestos_excedidos' => 0,
        'ingresos' => 0,
        'gastos' => 0
    ];
    $recentTransactions = [];
    $monthlyFlow = ['labels' => [], 'ingresos' => [], 'gastos' => []];
    $expenseByCategory = ['labels' => [], 'data' => [], 'colors' => [], 'icons' => []];
    $detailedBudgetAlerts = []; // NUEVO: alertas vacías por defecto
    $user_currency = 'PYG';
}

// Función para formatear dinero
function formatMoney($amount, $moneda = 'PYG') {
    $symbols = [
        'PYG' => 'Gs ',
        'USD' => '$ ',
        'EUR' => '€ '
    ];
    $symbol = $symbols[$moneda] ?? 'Gs ';
    return $symbol . number_format($amount / 100, 0, ',', '.');
}

// Función para obtener el icono de Bootstrap
function getBootstrapIcon($iconName) {
    // Si el icono ya incluye 'bi-', usarlo directamente
    if (strpos($iconName, 'bi-') === 0) {
        return $iconName;
    }
    // Si no, agregar 'bi-' como prefijo
    return 'bi-' . $iconName;
}

// Manejar exportación de transacciones
if (isset($_GET['export']) && $_GET['export'] === 'transacciones') {
    $stmt = $db->prepare("
        SELECT 
            t.fecha,
            t.descripcion,
            cat.nombre as categoria,
            cat.tipo,
            t.monto,
            u.moneda
        FROM transacciones t
        INNER JOIN categorias cat ON t.categoria_id = cat.id
        INNER JOIN usuarios u ON t.usuario_id = u.id
        WHERE t.usuario_id = ?
        ORDER BY t.fecha DESC
    ");
    $stmt->execute([$usuario_id]);
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para exportación
    foreach ($exportData as &$row) {
        $row['monto'] = formatMoney($row['monto'], $row['moneda']);
        unset($row['moneda']);
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transacciones_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Escribir encabezados
    if (!empty($exportData)) {
        fputcsv($output, array_keys($exportData[0]));
        
        // Escribir datos
        foreach ($exportData as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit();
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
        
        .alert-warning {
            border-left: 4px solid var(--bs-warning);
        }
        
        .alert-danger {
            border-left: 4px solid var(--bs-danger);
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
        
        .quick-actions .btn {
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .quick-actions .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        /* NUEVOS ESTILOS PARA ALERTAS DETALLADAS */
        .alert-budget {
            border-left: 4px solid var(--bs-warning);
            background: linear-gradient(135deg, #fff 0%, #fff9e6 100%);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .alert-budget.danger {
            border-left-color: var(--bs-danger);
            background: linear-gradient(135deg, #fff 0%, #ffe6e6 100%);
        }
        
        .alert-budget:last-child {
            margin-bottom: 0;
        }
        
        .progress-container {
            height: 8px;
            background-color: var(--bs-light);
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
            position: relative;
        }
        
        .progress-success {
            background: linear-gradient(90deg, var(--bs-success), #20c997);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, var(--bs-warning), #fd7e14);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, var(--bs-danger), #e52535);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-wallet2 me-2"></i>Finzen
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-speedometer2 me-2"></i> Resumen
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
                    <li class="nav-item">
                        <a href="reportes.php" class="nav-link">
                            <i class="bi bi-graph-up me-2"></i>Reportes
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted me-3"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <a href="?export=transacciones" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download me-1"></i> Exportar
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
            <h1 class="mb-0">Resumen Financiero</h1>
            <div class="text-muted">
                <?= date('d M Y') ?>
            </div>
        </div>

        <!-- Alertas de Presupuesto - MEJORADO CON ALERTAS DETALLADAS -->
        <?php if (!empty($detailedBudgetAlerts)): ?>
            <div class="card mb-4 border-warning">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-2 fs-4"></i>
                        <h5 class="mb-0">Alertas de Presupuesto</h5>
                    </div>
                    <?php foreach ($detailedBudgetAlerts as $alert): ?>
                        <div class="alert alert-budget <?= $alert['porcentaje_uso'] > 100 ? 'danger' : '' ?> d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="transaction-icon me-3" style="background-color: <?= htmlspecialchars($alert['categoria_color']) ?>20; color: <?= htmlspecialchars($alert['categoria_color']) ?>;">
                                    <i class="<?= getBootstrapIcon($alert['categoria_icono']) ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong><?= htmlspecialchars($alert['categoria_nombre']) ?></strong> - 
                                    <?= round($alert['porcentaje_uso']) ?>% usado 
                                    (<?= formatMoney($alert['gastos_actuales'], $user_currency) ?> de <?= formatMoney($alert['monto'], $user_currency) ?>)
                                    
                                    <!-- Barra de progreso -->
                                    <div class="progress-container">
                                        <div class="progress-bar <?= $alert['porcentaje_uso'] > 100 ? 'progress-danger' : ($alert['porcentaje_uso'] > 80 ? 'progress-warning' : 'progress-success') ?>" 
                                             style="width: <?= min(100, $alert['porcentaje_uso']) ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <?php if ($alert['porcentaje_uso'] > 100): ?>
                                    <span class="badge bg-danger">Excedido</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Cerca del límite</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-end mt-2">
                        <a href="presupuestos.php" class="btn btn-outline-primary btn-sm">
                            Gestionar Presupuestos <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Alertas generales (mantener las existentes) -->
        <?php if ($metrics['presupuestos_excedidos'] > 0 && empty($detailedBudgetAlerts)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong>¡Alerta!</strong> Tienes <?= $metrics['presupuestos_excedidos'] ?> presupuesto(s) excedido(s). 
                <a href="presupuestos.php" class="alert-link">Revisar ahora</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($metrics['alertas_presupuesto'] > 0 && empty($detailedBudgetAlerts)): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
            <div>
                <strong>Atención:</strong> Tienes <?= $metrics['alertas_presupuesto'] ?> presupuesto(s) cerca del límite (80% o más).
                <a href="presupuestos.php" class="alert-link">Ver detalles</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row g-3 mb-4 quick-actions">
            <div class="col-md-3">
                <a href="transacciones.php?action=new" class="btn btn-primary w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-plus-circle fs-2 mb-2"></i>
                    <span>Nueva Transacción</span>
                </a>
            </div>
            <div class="col-md-3">
                <a href="categorias.php" class="btn btn-success w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-tag fs-2 mb-2"></i>
                    <span>Gestionar Categorías</span>
                </a>
            </div>
            <div class="col-md-3">
                <a href="presupuestos.php" class="btn btn-info w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-pie-chart fs-2 mb-2"></i>
                    <span>Presupuestos</span>
                </a>
            </div>
            <div class="col-md-3">
                <a href="reportes.php" class="btn btn-warning w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-graph-up fs-2 mb-2"></i>
                    <span>Reportes</span>
                </a>
            </div>
        </div>

        <!-- Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card primary">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div>
                                <div class="metric-label">Saldo Total</div>
                                <div class="metric-value"><?= formatMoney($metrics['saldo_total'], $user_currency) ?></div>
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
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                            <div>
                                <div class="metric-label">Ingresos Mensuales</div>
                                <div class="metric-value"><?= formatMoney($metrics['ingresos'], $user_currency) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card metric-card danger">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                            <div>
                                <div class="metric-label">Gastos Mensuales</div>
                                <div class="metric-value"><?= formatMoney($metrics['gastos'], $user_currency) ?></div>
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
                                <div class="metric-label">Balance Mensual</div>
                                <div class="metric-value <?= ($metrics['ingresos'] - $metrics['gastos']) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatMoney($metrics['ingresos'] - $metrics['gastos'], $user_currency) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card metric-card info">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-tags"></i>
                            </div>
                            <div>
                                <div class="metric-label">Total Categorías</div>
                                <div class="metric-value"><?= htmlspecialchars($metrics['total_categorias']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card metric-card warning">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div>
                                <div class="metric-label">Presupuestos Activos</div>
                                <div class="metric-value"><?= htmlspecialchars($metrics['presupuestos_activos']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card metric-card <?= $metrics['alertas_presupuesto'] > 0 ? 'warning' : 'success' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon <?= $metrics['alertas_presupuesto'] > 0 ? 'bg-warning bg-opacity-10 text-warning' : 'bg-success bg-opacity-10 text-success' ?>">
                                <i class="bi bi-bell"></i>
                            </div>
                            <div>
                                <div class="metric-label">Alertas Presupuesto</div>
                                <div class="metric-value"><?= htmlspecialchars($metrics['alertas_presupuesto']) ?></div>
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
                        <a href="transacciones.php?action=new" class="btn btn-primary mt-2">
                             Crear primera transacción
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Descripción</th>
                                    <th>Categoría</th>
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
                                    <td class="fw-medium">
                                        <div class="d-flex align-items-center">
                                            <div class="transaction-icon" style="background-color: <?= htmlspecialchars($t['color']) ?>20; color: <?= htmlspecialchars($t['color']) ?>;">
                                                <i class="<?= getBootstrapIcon($t['icono']) ?>"></i>
                                            </div>
                                            <?= htmlspecialchars($t['descripcion'] ?? 'Sin descripción') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background-color: <?= htmlspecialchars($t['color']) ?>; color: white;">
                                            <?= htmlspecialchars($t['categoria']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold <?= $t['tipo'] === 'ingreso' ? 'text-success' : 'text-danger' ?>">
                                            <?= $t['tipo'] === 'ingreso' ? '+' : '-' ?>
                                            <?= formatMoney(abs($t['monto']), $user_currency) ?>
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