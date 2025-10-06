<?php
// Conexión
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "finzen";

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Conexión fallida: " . $e->getMessage());
}

// Obtener datos para selects
$stmtCuentas = $conn->prepare("SELECT id, nombre FROM cuentas");
$stmtCuentas->execute();
$cuentas = $stmtCuentas->fetchAll(PDO::FETCH_ASSOC);

$stmtCategorias = $conn->prepare("SELECT id, nombre, tipo FROM categorias");
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);

// CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cuenta_id = filter_input(INPUT_POST, "cuenta_id", FILTER_VALIDATE_INT);
    $categoria_id = filter_input(
        INPUT_POST,
        "categoria_id",
        FILTER_VALIDATE_INT
    );
    $descripcion = filter_input(
        INPUT_POST,
        "descripcion",
        FILTER_SANITIZE_STRING
    );
    $fecha = $_POST["fecha"] ?? null;
    $recurrente = isset($_POST["recurrente"]) ? 1 : 0;

    // Procesar monto
    $monto_input = str_replace([".", ","], "", $_POST["monto"] ?? "");
    $monto = is_numeric($monto_input) ? (int) $monto_input : 0;

    if (!$cuenta_id || !$categoria_id || !$fecha || $monto <= 0) {
        die(
            "Datos inválidos. Asegúrate de llenar correctamente todos los campos."
        );
    }

    try {
        if (isset($_POST["create"])) {
            $stmt = $conn->prepare("INSERT INTO transacciones (cuenta_id, categoria_id, monto, descripcion, fecha, recurrente, creado_en, actualizado_en)
                                    VALUES (:cuenta_id, :categoria_id, :monto, :descripcion, :fecha, :recurrente, NOW(), NOW())");
        } elseif (isset($_POST["update"])) {
            $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
            if (!$id) {
                die("ID inválido.");
            }
            $stmt = $conn->prepare("UPDATE transacciones SET
                                    cuenta_id = :cuenta_id,
                                    categoria_id = :categoria_id,
                                    monto = :monto,
                                    descripcion = :descripcion,
                                    fecha = :fecha,
                                    recurrente = :recurrente,
                                    actualizado_en = NOW()
                                    WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        }

        // Parámetros comunes
        $stmt->bindParam(":cuenta_id", $cuenta_id, PDO::PARAM_INT);
        $stmt->bindParam(":categoria_id", $categoria_id, PDO::PARAM_INT);
        $stmt->bindParam(":monto", $monto, PDO::PARAM_INT);
        $stmt->bindParam(":descripcion", $descripcion, PDO::PARAM_STR);
        $stmt->bindParam(":fecha", $fecha, PDO::PARAM_STR);
        $stmt->bindParam(":recurrente", $recurrente, PDO::PARAM_INT);
        $stmt->execute();
    } catch (PDOException $e) {
        die("Error al guardar transacción: " . $e->getMessage());
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Eliminar transacción
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = filter_input(INPUT_GET, "delete", FILTER_VALIDATE_INT);
    if ($id) {
        try {
            $stmt = $conn->prepare("DELETE FROM transacciones WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al eliminar: " . $e->getMessage());
        }
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Obtener transacciones
$stmt = $conn->prepare("SELECT t.*, c.nombre AS cuenta_nombre, cat.nombre AS categoria_nombre, cat.tipo AS tipo_categoria
                        FROM transacciones t
                        LEFT JOIN cuentas c ON t.cuenta_id = c.id
                        LEFT JOIN categorias cat ON t.categoria_id = cat.id
                        ORDER BY t.id DESC");
$stmt->execute();
$transacciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Transacciones - FinZen</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
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

        .btn-primary {
            background-color: var(--primary);
            color: white;
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

        .badge-info {
            background-color: rgba(8, 61, 119, 0.1);
            color: var(--primary);
        }

        /* Layout */
        .columns {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.is-active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-card {
            background-color: var(--surface);
            border-radius: var(--border-radius);
            width: 800px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .modal-card-head {
            border-bottom: 1px solid var(--border);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-card-title {
            font-weight: 600;
            font-size: 1.25rem;
        }

        .modal-card-body {
            padding: 1.5rem;
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }

        .modal-card-foot {
            border-top: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .delete {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--on-background);
        }

        /* Form fields */
        .field {
            margin-bottom: 1rem;
        }

        .label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--on-background);
        }

        .input, .select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .checkbox {
            display: flex;
            align-items: center;
            color: var(--on-background);
        }

        .checkbox input {
            margin-right: 0.5rem;
        }

        .has-icons-left {
            position: relative;
        }

        .has-icons-left .icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--on-background);
        }

        .has-icons-left .input {
            padding-left: 2.5rem;
        }

        /* Estilos específicos de transacciones */
        .descripcion-col {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ingreso {
            color: var(--secondary);
        }

        .gasto {
            color: var(--error);
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

            .columns {
                grid-template-columns: 1fr;
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
                <a href="index.php" class="menu-item">
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
                <a href="#" class="menu-item active">
                    <i class="material-icons">swap_horiz</i>
                    <span>Transacciones</span>
                </a>
                <a href="transacciones-recurrentes.php" class="menu-item">
                    <i class="material-icons">autorenew</i>
                    <span>Trans. Recurrentes</span>
                </a>
                <a href="../auth/logout.php" class="menu-item">
                    <i class="material-icons">logout</i>
                    <span>Cerrar Sesión</span>
                </a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <h1 class="page-title">Administración de Transacciones</h1>
                <button class="btn btn-primary modal-button" data-target="addTransaccionModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nueva Transacción</span>
                </button>
            </header>

            <!-- Tabla de transacciones -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">swap_horiz</i>
                        <span>Transacciones</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cuenta</th>
                                <th>Categoría</th>
                                <th>Monto</th>
                                <th>Descripción</th>
                                <th>Fecha</th>
                                <th>Recurrente</th>
                                <th>Creado en</th>
                                <th>Actualizado en</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transacciones as $transaccion):
                                $tipoClase = $transaccion["tipo_categoria"] === "ingreso" ? "ingreso" : "gasto";
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaccion["id"]); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["cuenta_nombre"] ?? "Sin cuenta"); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["categoria_nombre"] ?? "Sin categoría"); ?></td>
                                <td class="<?php echo $tipoClase; ?>">
                                    Gs<?php echo number_format(abs($transaccion["monto"]) / 100, 0, ",", "."); ?>
                                </td>
                                <td class="descripcion-col" title="<?php echo htmlspecialchars($transaccion["descripcion"]); ?>">
                                    <?php echo htmlspecialchars($transaccion["descripcion"]); ?>
                                </td>
                                <td><?php echo htmlspecialchars($transaccion["fecha"]); ?></td>
                                <td>
                                    <?php if ($transaccion["recurrente"]): ?>
                                        <span class="badge badge-info">Sí</span>
                                    <?php else: ?>
                                        <span class="badge">No</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($transaccion["creado_en"]); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["actualizado_en"]); ?></td>
                                <td>
                                    <button class="btn btn-outlined btn-action edit-button" data-target="editTransaccionModal"
                                            data-id="<?php echo $transaccion["id"]; ?>"
                                            data-cuenta_id="<?php echo $transaccion["cuenta_id"]; ?>"
                                            data-categoria_id="<?php echo $transaccion["categoria_id"]; ?>"
                                            data-monto="<?php echo number_format(abs($transaccion["monto"]) / 100, 0, ",", "."); ?>"
                                            data-descripcion="<?php echo htmlspecialchars($transaccion["descripcion"]); ?>"
                                            data-fecha="<?php echo $transaccion["fecha"]; ?>"
                                            data-recurrente="<?php echo $transaccion["recurrente"]; ?>">
                                        <span class="icon"><i class="material-icons">edit</i></span>
                                    </button>
                                    <a href="?delete=<?php echo $transaccion["id"]; ?>" class="btn btn-outlined btn-action"
                                       onclick="return confirm('¿Estás seguro de eliminar esta transacción?')">
                                        <span class="icon"><i class="material-icons">delete</i></span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Transacción Modal -->
    <div class="modal" id="addTransaccionModal">
        <div class="modal-background" onclick="closeModal('addTransaccionModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nueva Transacción</p>
                <button class="delete" onclick="closeModal('addTransaccionModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Cuenta</label>
                        <div class="control">
                            <div class="select">
                                <select name="cuenta_id" required>
                                    <option value="">Seleccionar Cuenta</option>
                                    <?php foreach ($cuentas as $cuenta): ?>
                                        <option value="<?php echo $cuenta["id"]; ?>">
                                            <?php echo htmlspecialchars($cuenta["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Categoría</label>
                        <div class="control">
                            <div class="select">
                                <select name="categoria_id" required>
                                    <option value="">Seleccionar Categoría</option>
                                    <?php foreach ($categorias as $categoria): ?>
                                        <option value="<?php echo $categoria["id"]; ?>">
                                            <?php echo htmlspecialchars($categoria["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Monto</label>
                        <div class="control has-icons-left">
                            <span class="icon">Gs</span>
                            <input class="input" type="text" name="monto" placeholder="Ej: 500000" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Descripción</label>
                        <div class="control">
                            <input class="input" type="text" name="descripcion" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Fecha</label>
                        <div class="control">
                            <input class="input" type="date" name="fecha" required>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="recurrente">
                                Recurrente
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addTransaccionModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Edit Transacción Modal -->
    <div class="modal" id="editTransaccionModal">
        <div class="modal-background" onclick="closeModal('editTransaccionModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Transacción</p>
                <button class="delete" onclick="closeModal('editTransaccionModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="id">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Cuenta</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_cuenta_id" name="cuenta_id" required>
                                    <option value="">Seleccionar Cuenta</option>
                                    <?php foreach ($cuentas as $cuenta): ?>
                                        <option value="<?php echo $cuenta["id"]; ?>">
                                            <?php echo htmlspecialchars($cuenta["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Categoría</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_categoria_id" name="categoria_id" required>
                                    <option value="">Seleccionar Categoría</option>
                                    <?php foreach ($categorias as $categoria): ?>
                                        <option value="<?php echo $categoria["id"]; ?>">
                                            <?php echo htmlspecialchars($categoria["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Monto</label>
                        <div class="control has-icons-left">
                            <span class="icon">Gs</span>
                            <input class="input" type="text" id="edit_monto" name="monto" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Descripción</label>
                        <div class="control">
                            <input class="input" type="text" id="edit_descripcion" name="descripcion" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Fecha</label>
                        <div class="control">
                            <input class="input" type="date" id="edit_fecha" name="fecha" required>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" id="edit_recurrente" name="recurrente">
                                Recurrente
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editTransaccionModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            function openModal(id) {
                const modal = document.getElementById(id);
                modal.classList.add('is-active');
            }

            function closeModal(id) {
                const modal = document.getElementById(id);
                modal.classList.remove('is-active');
            }

            function closeAllModals() {
                (document.querySelectorAll('.modal') || []).forEach(($modal) => {
                    $modal.classList.remove('is-active');
                });
            }

            (document.querySelectorAll('.modal-button') || []).forEach(($trigger) => {
                const modalId = $trigger.dataset.target;
                $trigger.addEventListener('click', () => {
                    openModal(modalId);
                });
            });

            (document.querySelectorAll('.modal-background, .delete') || []).forEach(($close) => {
                $close.addEventListener('click', () => {
                    closeAllModals();
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });

            const editButtons = document.querySelectorAll('.edit-button');
            editButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_cuenta_id').value = button.dataset.cuenta_id;
                    document.getElementById('edit_categoria_id').value = button.dataset.categoria_id;
                    document.getElementById('edit_monto').value = button.dataset.monto;
                    document.getElementById('edit_descripcion').value = button.dataset.descripcion;
                    document.getElementById('edit_fecha').value = button.dataset.fecha;
                    document.getElementById('edit_recurrente').checked = button.dataset.recurrente === '1';

                    openModal('editTransaccionModal');
                });
            });
        });
    </script>
</body>
</html>
