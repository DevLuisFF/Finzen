<?php
// index.php

declare(strict_types=1);

namespace FinZen\Dashboard;

use PDO;
use PDOException;

// Principios SOLID mejor aplicados
class DatabaseConnection
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            try {
                self::$connection = new PDO(
                    "mysql:host=localhost;dbname=finzen;charset=utf8mb4",
                    "root",
                    "",
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
            } catch (PDOException $e) {
                throw new \RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

// Value Objects para type safety
class TransactionData
{
    public function __construct(
        public readonly int $id,
        public readonly string $cuentaNombre,
        public readonly string $categoriaNombre,
        public readonly string $categoriaTipo,
        public readonly int $monto,
        public readonly string $descripcion,
        public readonly string $fecha,
        public readonly bool $recurrente
    ) {}
}

class MonthlyData
{
    public function __construct(
        public readonly string $mes,
        public readonly float $ingresos,
        public readonly float $gastos
    ) {}
}

interface MetricsRepositoryInterface
{
    public function getMetrics(): array;
    public function getLatestTransactions(int $limit = 5): array;
    public function getTransactionsByMonth(int $months = 6): array;
}

class MetricsRepository implements MetricsRepositoryInterface
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    private function executeSingleValueQuery(string $query, array $params = []): float
    {
        $stmt = $this->connection->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_NUM);
        return (float)($result[0] ?? 0);
    }

    public function getMetrics(): array
    {
        $metrics = [];

        // Métricas básicas
        $metrics["total_usuarios"] = $this->executeSingleValueQuery(
            "SELECT COUNT(*) FROM usuarios WHERE activo = TRUE"
        );
        
        $metrics["total_cuentas"] = $this->executeSingleValueQuery(
            "SELECT COUNT(*) FROM cuentas WHERE activa = TRUE"
        );
        
        $metrics["total_transacciones"] = $this->executeSingleValueQuery(
            "SELECT COUNT(*) FROM transacciones"
        );
        
        // Saldo total en USD (convertido de centavos)
        $metrics["saldo_total"] = $this->executeSingleValueQuery(
            "SELECT COALESCE(SUM(saldo), 0) FROM cuentas WHERE activa = TRUE AND moneda = 'USD'"
        ) / 100;

        // Transacciones recurrentes (si existe la tabla)
        try {
            $metrics["transacciones_recurrentes"] = $this->executeSingleValueQuery(
                "SELECT COUNT(*) FROM transacciones_recurrentes WHERE activa = TRUE"
            );
        } catch (PDOException) {
            $metrics["transacciones_recurrentes"] = 0;
        }

        // Ingresos y gastos del mes actual
        $currentMonth = date('Y-m');
        $metrics["ingresos_mes_actual"] = $this->executeSingleValueQuery("
            SELECT COALESCE(SUM(t.monto), 0) 
            FROM transacciones t 
            JOIN categorias c ON t.categoria_id = c.id 
            WHERE c.tipo = 'ingreso' AND DATE_FORMAT(t.fecha, '%Y-%m') = ?
        ", [$currentMonth]) / 100;

        $metrics["gastos_mes_actual"] = $this->executeSingleValueQuery("
            SELECT COALESCE(SUM(t.monto), 0) 
            FROM transacciones t 
            JOIN categorias c ON t.categoria_id = c.id 
            WHERE c.tipo = 'gasto' AND DATE_FORMAT(t.fecha, '%Y-%m') = ?
        ", [$currentMonth]) / 100;

        return $metrics;
    }

    public function getLatestTransactions(int $limit = 5): array
    {
        $stmt = $this->connection->prepare("
            SELECT 
                t.id,
                c.nombre as cuenta_nombre,
                cat.nombre as categoria_nombre,
                cat.tipo as categoria_tipo,
                t.monto,
                t.descripcion,
                t.fecha,
                t.recurrente
            FROM transacciones t
            JOIN cuentas c ON t.cuenta_id = c.id
            JOIN categorias cat ON t.categoria_id = cat.id
            WHERE c.activa = TRUE
            ORDER BY t.fecha DESC, t.creado_en DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $transactions = [];
        foreach ($stmt->fetchAll() as $row) {
            $transactions[] = new TransactionData(
                (int)$row['id'],
                htmlspecialchars($row['cuenta_nombre']),
                htmlspecialchars($row['categoria_nombre']),
                $row['categoria_tipo'],
                (int)$row['monto'],
                htmlspecialchars($row['descripcion'] ?? ''),
                $row['fecha'],
                (bool)$row['recurrente']
            );
        }
        
        return $transactions;
    }

    public function getTransactionsByMonth(int $months = 6): array
    {
        $stmt = $this->connection->prepare("
            SELECT
                DATE_FORMAT(t.fecha, '%Y-%m') as mes,
                COALESCE(SUM(CASE WHEN c.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) as ingresos,
                COALESCE(SUM(CASE WHEN c.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) as gastos
            FROM transacciones t
            JOIN categorias c ON t.categoria_id = c.id
            JOIN cuentas cu ON t.cuenta_id = cu.id
            WHERE t.fecha >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
            AND cu.activa = TRUE
            GROUP BY mes
            ORDER BY mes
        ");
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();
        
        $monthlyData = [];
        foreach ($stmt->fetchAll() as $row) {
            $monthlyData[] = new MonthlyData(
                $row['mes'],
                (float)$row['ingresos'] / 100,
                abs((float)$row['gastos']) / 100
            );
        }
        
        return $monthlyData;
    }
}

// Helper para formatear moneda
class CurrencyFormatter
{
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $amount = $cents / 100;
        
        $symbols = [
            'USD' => '$',
            'PYG' => '₲',
            'EUR' => '€'
        ];
        
        $symbol = $symbols[$currency] ?? '$';
        
        return $symbol . number_format($amount, 2);
    }
}

// Configuración y ejecución
try {
    $db = DatabaseConnection::getConnection();
    $metricsRepo = new MetricsRepository($db);

    $metrics = $metricsRepo->getMetrics();
    $ultimas_transacciones = $metricsRepo->getLatestTransactions();
    $transacciones_por_mes = $metricsRepo->getTransactionsByMonth();

    // Procesar datos para el gráfico
    $chart_labels = [];
    $chart_ingresos = [];
    $chart_gastos = [];

    foreach ($transacciones_por_mes as $mes) {
        $timestamp = strtotime("{$mes->mes}-01");
        $chart_labels[] = date("M Y", $timestamp);
        $chart_ingresos[] = $mes->ingresos;
        $chart_gastos[] = $mes->gastos;
    }

} catch (\Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    die("<div style='padding: 2rem; text-align: center;'>Error al cargar el dashboard. Por favor, intente más tarde.</div>");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Financiero - FinZen</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #083D77;
            --primary-light: rgba(8, 61, 119, 0.1);
            --secondary: #10b981;
            --secondary-light: rgba(16, 185, 129, 0.1);
            --error: #ef4444;
            --error-light: rgba(239, 68, 68, 0.1);
            --warning: #f59e0b;
            --background: #f8fafc;
            --surface: #ffffff;
            --on-surface: #111827;
            --on-background: #374151;
            --border: #e2e8f0;
            --border-radius: 12px;
            --spacing: 1rem;
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }

        /* Sidebar mejorado */
        .sidebar {
            background: linear-gradient(180deg, var(--primary) 0%, #062a52 100%);
            color: white;
            padding: var(--spacing);
            position: fixed;
            height: 100vh;
            width: 240px;
            overflow-y: auto;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            padding: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
        }

        .logo i {
            margin-right: 0.75rem;
            font-size: 1.75rem;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 0.5rem;
            padding: 0 0.5rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            margin-bottom: 0.25rem;
            border-radius: 8px;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-item i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
            opacity: 0.8;
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .menu-item:hover:not(.active) {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        /* Main content mejorado */
        .main-content {
            grid-column: 2;
            padding: 2rem;
            background-color: var(--background);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .page-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--primary);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 0.875rem;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-outlined {
            background-color: transparent;
            border: 2px solid var(--border);
            color: var(--on-background);
        }

        .btn-outlined:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Cards mejoradas */
        .card {
            background-color: var(--surface);
            border-radius: var(--border-radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            margin-bottom: var(--spacing);
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            color: var(--on-surface);
            gap: 0.75rem;
        }

        /* Metrics grid mejorado */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .metric-card {
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--on-surface);
        }

        .metric-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .metric-trend {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .trend-positive {
            color: var(--secondary);
        }

        .trend-negative {
            color: var(--error);
        }

        /* Charts mejorados */
        .chart-container {
            height: 350px;
            margin-top: 1rem;
        }

        /* Tables mejoradas */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--border);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            min-width: 600px;
        }

        .table th {
            background-color: #f8fafc;
            padding: 1rem 1.25rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
        }

        .table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background-color: #f8fafc;
        }

        /* Badges mejorados */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.025em;
        }

        .badge-success {
            background-color: var(--secondary-light);
            color: var(--secondary);
            border: 1px solid var(--secondary);
        }

        .badge-error {
            background-color: var(--error-light);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .badge-warning {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        /* Layout mejorado */
        .columns {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Utility classes */
        .text-success {
            color: var(--secondary);
            font-weight: 600;
        }

        .text-error {
            color: var(--error);
            font-weight: 600;
        }

        .text-muted {
            color: #64748b;
        }

        .mb-2 {
            margin-bottom: var(--spacing);
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        .gap-4 {
            gap: 1rem;
        }

        /* Loading states */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 4px;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Responsive mejorado */
        @media (max-width: 1024px) {
            .columns {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .main-content {
                grid-column: 1;
                padding: 1rem;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            .card {
                padding: 1rem;
            }
            
            .metric-value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar mejorado -->
        <div class="sidebar">
            <div class="logo">
                <i class="material-icons">account_balance</i>
                <span>FinZen</span>
            </div>
            
            <nav>
                <div class="nav-section">
                    <div class="nav-title">Principal</div>
                    <a href="index.php" class="menu-item active">
                        <i class="material-icons">dashboard</i>
                        <span>Dashboard</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-title">Gestión</div>
                    <a href="usuarios.php" class="menu-item">
                        <i class="material-icons">people</i>
                        <span>Usuarios</span>
                    </a>
                    <a href="cuentas.php" class="menu-item">
                        <i class="material-icons">account_balance_wallet</i>
                        <span>Cuentas</span>
                    </a>
                    <a href="categorias.php" class="menu-item">
                        <i class="material-icons">category</i>
                        <span>Categorías</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-title">Transacciones</div>
                    <a href="transacciones.php" class="menu-item">
                        <i class="material-icons">swap_horiz</i>
                        <span>Transacciones</span>
                    </a>
                    <a href="presupuestos.php" class="menu-item">
                        <i class="material-icons">pie_chart</i>
                        <span>Presupuestos</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-title">Administración</div>
                    <a href="roles.php" class="menu-item">
                        <i class="material-icons">admin_panel_settings</i>
                        <span>Roles</span>
                    </a>
                </div>
            </nav>
        </div>

        <!-- Main content mejorado -->
        <div class="main-content">
            <!-- Header mejorado -->
            <header class="header">
                <h1 class="page-title">
                    <i class="material-icons">analytics</i>
                    <span>Dashboard Financiero</span>
                </h1>
                <div class="flex items-center gap-4">
                    <div class="text-muted">
                        <?= date('d M Y - H:i') ?>
                    </div>
                    <a href="../auth/logout.php" class="btn btn-outlined">
                        <i class="material-icons">logout</i>
                        <span>Cerrar Sesión</span>
                    </a>
                </div>
            </header>

            <!-- Métricas principales mejoradas -->
            <div class="metrics-grid">
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_usuarios"]) ?></div>
                    <div class="metric-label">Usuarios Activos</div>
                    <div class="metric-trend trend-positive">
                        <i class="material-icons">trending_up</i>
                        <span>Activos en el sistema</span>
                    </div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_cuentas"]) ?></div>
                    <div class="metric-label">Cuentas Activas</div>
                    <div class="metric-trend trend-positive">
                        <i class="material-icons">account_balance</i>
                        <span>Gestionando finanzas</span>
                    </div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_transacciones"]) ?></div>
                    <div class="metric-label">Transacciones Totales</div>
                    <div class="metric-trend trend-positive">
                        <i class="material-icons">receipt_long</i>
                        <span>Registradas</span>
                    </div>
                </div>
            </div>

            <!-- Resumen financiero -->
            <div class="metrics-grid">
                <div class="card metric-card">
                    <div class="metric-value text-success">
                        $<?= number_format($metrics["ingresos_mes_actual"], 2) ?>
                    </div>
                    <div class="metric-label">Ingresos del Mes</div>
                    <div class="metric-trend trend-positive">
                        <i class="material-icons">arrow_upward</i>
                        <span>Entradas actuales</span>
                    </div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value text-error">
                        $<?= number_format($metrics["gastos_mes_actual"], 2) ?>
                    </div>
                    <div class="metric-label">Gastos del Mes</div>
                    <div class="metric-trend trend-negative">
                        <i class="material-icons">arrow_downward</i>
                        <span>Salidas actuales</span>
                    </div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value">
                        $<?= number_format($metrics["saldo_total"], 2) ?>
                    </div>
                    <div class="metric-label">Saldo Total (USD)</div>
                    <div class="metric-trend trend-positive">
                        <i class="material-icons">savings</i>
                        <span>Disponible</span>
                    </div>
                </div>
            </div>

            <!-- Gráfico y resumen -->
            <div class="columns">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="material-icons">show_chart</i>
                            <span>Evolución Financiera (6 meses)</span>
                        </h2>
                    </div>
                    <div class="chart-container">
                        <canvas id="transactionsChart"></canvas>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="material-icons">insights</i>
                            <span>Resumen Rápido</span>
                        </h2>
                    </div>
                    <div class="flex flex-col gap-4">
                        <div>
                            <div class="text-muted">Balance Neto Mensual</div>
                            <div class="metric-value <?= ($metrics["ingresos_mes_actual"] - $metrics["gastos_mes_actual"]) >= 0 ? 'text-success' : 'text-error' ?>">
                                $<?= number_format($metrics["ingresos_mes_actual"] - $metrics["gastos_mes_actual"], 2) ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-muted">Transacciones Recurrentes</div>
                            <div class="metric-value">
                                <?= number_format($metrics["transacciones_recurrentes"]) ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-muted">Estado del Sistema</div>
                            <span class="badge badge-success">
                                <i class="material-icons">check_circle</i>
                                Operativo
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Últimas transacciones mejoradas -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">receipt_long</i>
                        <span>Últimas Transacciones</span>
                    </h2>
                    <a href="transacciones.php" class="btn btn-outlined">
                        <i class="material-icons">list</i>
                        <span>Ver Todas</span>
                    </a>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Descripción</th>
                                <th>Cuenta</th>
                                <th>Categoría</th>
                                <th>Monto</th>
                                <th>Fecha</th>
                                <th>Tipo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ultimas_transacciones)): ?>
                                <tr>
                                    <td colspan="6" class="text-muted text-center" style="padding: 2rem;">
                                        <i class="material-icons" style="font-size: 3rem; opacity: 0.5;">receipt</i>
                                        <div style="margin-top: 0.5rem;">No hay transacciones recientes</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ultimas_transacciones as $transaccion): 
                                    $tipoClase = $transaccion->categoriaTipo == "ingreso" ? "text-success" : "text-error";
                                    $tipoBadge = $transaccion->categoriaTipo == "ingreso" ? "badge-success" : "badge-error";
                                    $icon = $transaccion->categoriaTipo == "ingreso" ? "arrow_upward" : "arrow_downward";
                                ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <?php if ($transaccion->recurrente): ?>
                                                <i class="material-icons text-muted" style="font-size: 1rem;">autorenew</i>
                                            <?php endif; ?>
                                            <?= $transaccion->descripcion ?: 'Sin descripción' ?>
                                        </div>
                                    </td>
                                    <td><?= $transaccion->cuentaNombre ?></td>
                                    <td><?= $transaccion->categoriaNombre ?></td>
                                    <td class="<?= $tipoClase ?>">
                                        <div class="flex items-center gap-1">
                                            <i class="material-icons" style="font-size: 1.125rem;"><?= $icon ?></i>
                                        <?= $transaccion->categoriaTipo == "ingreso" ? '+' : '-' ?><?= CurrencyFormatter::format(abs($transaccion->monto)) ?>
                                        </div>
                                    </td>
                                    <td><?= date("d M Y", strtotime($transaccion->fecha)) ?></td>
                                    <td>
                                        <span class="badge <?= $tipoBadge ?>">
                                            <?= ucfirst($transaccion->categoriaTipo) ?>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('transactionsChart').getContext('2d');
            
            // Configuración del gráfico mejorada
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [
                        {
                            label: 'Ingresos',
                            data: <?= json_encode($chart_ingresos) ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Gastos',
                            data: <?= json_encode($chart_gastos) ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#ef4444',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
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
                                padding: 20,
                                font: {
                                    size: 13,
                                    weight: '600'
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: '#1f2937',
                            bodyColor: '#374151',
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            usePointStyle: true
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false,
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                },
                                font: {
                                    size: 12
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'nearest'
                    },
                    animations: {
                        tension: {
                            duration: 1000,
                            easing: 'linear'
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>