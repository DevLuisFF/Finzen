<?php
// index.php

declare(strict_types=1);

namespace FinZen\Dashboard;

use PDO;
use PDOException;

// Principio de Responsabilidad Única (SRP)
class DatabaseConnection
{
    private PDO $connection;

    public function __construct()
    {
        try {
            $this->connection = new PDO(
                "mysql:host=localhost;dbname=finzen",
                "root",
                ""
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}

// Principio de Inversión de Dependencias (DIP)
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

    private function getSingleMetric(string $query, string $column): float
    {
        $stmt = $this->connection->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result[$column] ?? 0);
    }

    public function getMetrics(): array
    {
        $metrics = [];

        $metrics["total_usuarios"] = $this->getSingleMetric(
            "SELECT COUNT(*) as total FROM usuarios",
            "total"
        );
        
        $metrics["total_cuentas"] = $this->getSingleMetric(
            "SELECT COUNT(*) as total FROM cuentas WHERE activa = TRUE",
            "total"
        );
        
        $metrics["total_transacciones"] = $this->getSingleMetric(
            "SELECT COUNT(*) as total FROM transacciones",
            "total"
        );
        
        $metrics["saldo_total"] = $this->getSingleMetric(
            "SELECT SUM(saldo) as total FROM cuentas WHERE moneda = 'USD'",
            "total"
        ) / 100;
        
        $metrics["transacciones_recurrentes"] = $this->getSingleMetric(
            "SELECT COUNT(*) as total FROM transacciones_recurrentes WHERE activa = TRUE",
            "total"
        );

        $stmt = $this->connection->query("
            SELECT c.tipo, SUM(t.monto) as total
            FROM transacciones t
            JOIN categorias c ON t.categoria_id = c.id
            GROUP BY c.tipo
        ");
        
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tipo) {
            $key = $tipo["tipo"] === "ingreso" ? "total_ingresos" : "total_gastos";
            $metrics[$key] = abs((float)$tipo["total"]) / 100;
        }

        return $metrics;
    }

    public function getLatestTransactions(int $limit = 5): array
    {
        $stmt = $this->connection->prepare("
            SELECT t.*, c.nombre as cuenta_nombre, cat.nombre as categoria_nombre, cat.tipo as categoria_tipo
            FROM transacciones t
            JOIN cuentas c ON t.cuenta_id = c.id
            JOIN categorias cat ON t.categoria_id = cat.id
            ORDER BY t.fecha DESC, t.creado_en DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransactionsByMonth(int $months = 6): array
    {
        $stmt = $this->connection->query("
            SELECT
                DATE_FORMAT(t.fecha, '%Y-%m') as mes,
                SUM(CASE WHEN c.tipo = 'ingreso' THEN t.monto ELSE 0 END) as ingresos,
                SUM(CASE WHEN c.tipo = 'gasto' THEN t.monto ELSE 0 END) as gastos
            FROM transacciones t
            JOIN categorias c ON t.categoria_id = c.id
            WHERE t.fecha >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
            GROUP BY mes
            ORDER BY mes
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Configuración
try {
    $db = new DatabaseConnection();
    $metricsRepo = new MetricsRepository($db->getConnection());

    // Obtener datos
    $metrics = $metricsRepo->getMetrics();
    $ultimas_transacciones = $metricsRepo->getLatestTransactions();
    $transacciones_por_mes = $metricsRepo->getTransactionsByMonth();

    // Procesar datos para el gráfico
    $chart_labels = [];
    $chart_ingresos = [];
    $chart_gastos = [];

    foreach ($transacciones_por_mes as $mes) {
        $timestamp = strtotime("{$mes["mes"]}-01");
        $chart_labels[] = date("M Y", $timestamp);
        $chart_ingresos[] = (float)$mes["ingresos"] / 100;
        $chart_gastos[] = abs((float)$mes["gastos"]) / 100;
    }
} catch (\Exception $e) {
    die("Error: " . $e->getMessage());
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
            --secondary: #10b981;
            --error: #ef4444;
            --background: #f9fafb;
            --surface: #ffffff;
            --on-surface: #111827;
            --on-background: #374151;
            --border: #e5e7eb;
            --border-radius: 8px;
            --spacing: 1rem;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
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
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            background-color: var(--surface);
            border-right: 1px solid var(--border);
            padding: var(--spacing);
            position: fixed;
            height: 100vh;
            width: 240px;
        }

        .logo {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            color: var(--primary);
            padding: 0 0.5rem;
        }

        .logo i {
            margin-right: 0.5rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.25rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--on-background);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .menu-item i {
            margin-right: 1rem;
            opacity: 0.8;
        }

        .menu-item.active {
            background-color: rgba(8, 61, 119, 0.1);
            color: var(--primary);
        }

        .menu-item:hover:not(.active) {
            background-color: rgba(0, 0, 0, 0.03);
        }

        /* Main content */
        .main-content {
            grid-column: 2;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--on-surface);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            font-size: 0.875rem;
            gap: 0.5rem;
        }

        .btn-outlined {
            background-color: transparent;
            border: 1px solid var(--border);
            color: var(--on-background);
        }

        /* Cards */
        .card {
            background-color: var(--surface);
            border-radius: var(--border-radius);
            border: 1px solid var(--border);
            padding: var(--spacing);
            margin-bottom: var(--spacing);
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            color: var(--on-surface);
            gap: 0.5rem;
        }

        /* Metrics grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing);
            margin-bottom: 2rem;
        }

        .metric-card {
            display: flex;
            flex-direction: column;
            padding: var(--spacing);
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--on-surface);
        }

        .metric-label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        /* Charts */
        .chart-container {
            height: 300px;
            margin-top: var(--spacing);
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .table th, .table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            font-weight: 500;
            color: #6b7280;
            font-size: 0.75rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--secondary);
        }

        .badge-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error);
        }

        /* Layout */
        .columns {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--spacing);
            margin-bottom: var(--spacing);
        }

        /* Utility classes */
        .text-success {
            color: var(--secondary);
        }

        .text-error {
            color: var(--error);
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

        /* Responsive */
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
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <i class="material-icons">account_balance</i>
                <span>FinZen</span>
            </div>
            
            <nav>
                <a href="index.php" class="menu-item active">
                    <i class="material-icons">dashboard</i>
                    <span>Dashboard</span>
                </a>
                <a href="roles.php" class="menu-item">
                    <i class="material-icons">assignment_ind</i>
                    <span>Roles</span>
                </a>
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
                <a href="presupuestos.php" class="menu-item">
                    <i class="material-icons">pie_chart</i>
                    <span>Presupuestos</span>
                </a>
                <a href="transacciones.php" class="menu-item">
                    <i class="material-icons">swap_horiz</i>
                    <span>Transacciones</span>
                </a>
                <a href="transacciones-recurrentes.php" class="menu-item">
                    <i class="material-icons">autorenew</i>
                    <span>Trans. Recurrentes</span>
                </a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <h1 class="page-title">Resumen Financiero</h1>
                <a href="../auth/logout.php" class="btn btn-outlined">
                    <i class="material-icons">logout</i>
                    <span>Cerrar Sesión</span>
                </a>
            </header>

            <!-- Métricas principales -->
            <div class="metrics-grid">
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_usuarios"]) ?></div>
                    <div class="metric-label">Usuarios</div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_cuentas"]) ?></div>
                    <div class="metric-label">Cuentas</div>
                </div>
                
                <div class="card metric-card">
                    <div class="metric-value"><?= number_format($metrics["total_transacciones"]) ?></div>
                    <div class="metric-label">Transacciones</div>
                </div>
            </div>

            <!-- Gráfico -->
            <div class="columns">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="material-icons">show_chart</i>
                            <span>Flujo de Transacciones</span>
                        </h2>
                    </div>
                    <div class="chart-container">
                        <canvas id="transactionsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Últimas transacciones -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">receipt</i>
                        <span>Últimas Transacciones</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Cuenta</th>
                                <th>Monto</th>
                                <th>Fecha</th>
                                <th>Tipo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_transacciones as $transaccion): 
                                $tipoClase = $transaccion["categoria_tipo"] == "ingreso" ? "text-success" : "text-error";
                                $tipoBadge = $transaccion["categoria_tipo"] == "ingreso" ? "badge-success" : "badge-error";
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($transaccion["cuenta_nombre"]) ?></td>
                                <td class="<?= $tipoClase ?>">
                                    <?= $transaccion["categoria_tipo"] == "ingreso" ? '+' : '-' ?>$<?= number_format(abs((float)$transaccion["monto"]) / 100, 2) ?>
                                </td>
                                <td><?= date("d M Y", strtotime($transaccion["fecha"])) ?></td>
                                <td>
                                    <span class="badge <?= $tipoBadge ?>">
                                        <?= ucfirst($transaccion["categoria_tipo"]) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('transactionsChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [
                        {
                            label: 'Ingresos',
                            data: <?= json_encode($chart_ingresos) ?>,
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 2,
                            tension: 0.3,
                            
                        },
                        {
                            label: 'Gastos',
                            data: <?= json_encode($chart_gastos) ?>,
                            borderColor: 'rgba(239, 68, 68, 1)',
                            borderWidth: 2,
                            tension: 0.3,
                            
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>