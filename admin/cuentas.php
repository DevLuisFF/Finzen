<?php
// Configuración de la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "finzen";

// Conexión PDO
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

// Obtener lista de usuarios
try {
    $stmtUsuarios = $conn->prepare("SELECT id, nombre_usuario FROM usuarios");
    $stmtUsuarios->execute();
    $usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al obtener usuarios: " . $e->getMessage());
}

// Monedas disponibles
$monedas = [
    "MXN" => "Peso Mexicano",
    "USD" => "Dólar Estadounidense",
    "EUR" => "Euro",
    "CAD" => "Dólar Canadiense",
    "GBP" => "Libra Esterlina",
    "PYG" => "Guaraní Paraguayo",
    "BRL" => "Real Brasileño",
];

// Procesar formularios
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario_id = filter_input(INPUT_POST, "usuario_id", FILTER_VALIDATE_INT);
    $nombre = filter_input(INPUT_POST, "nombre", FILTER_SANITIZE_STRING);
    $saldo = floatval($_POST["saldo"]) * 100;
    $moneda = filter_input(INPUT_POST, "moneda", FILTER_SANITIZE_STRING);
    $activa = isset($_POST["activa"]) ? 1 : 0;

    if (!$usuario_id || empty($nombre) || empty($moneda)) {
        die("Datos inválidos.");
    }

    if (isset($_POST["create"])) {
        // Crear cuenta
        try {
            $stmt = $conn->prepare("INSERT INTO cuentas (usuario_id, nombre, saldo, moneda, activa, creado_en, actualizado_en)
                                    VALUES (:usuario_id, :nombre, :saldo, :moneda, :activa, NOW(), NOW())");
            $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
            $stmt->bindParam(":nombre", $nombre, PDO::PARAM_STR);
            $stmt->bindParam(":saldo", $saldo, PDO::PARAM_INT);
            $stmt->bindParam(":moneda", $moneda, PDO::PARAM_STR);
            $stmt->bindParam(":activa", $activa, PDO::PARAM_BOOL);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al crear cuenta: " . $e->getMessage());
        }
    } elseif (isset($_POST["update"])) {
        // Actualizar cuenta
        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if (!$id) {
            die("ID inválido");
        }

        try {
            $stmt = $conn->prepare("UPDATE cuentas SET
                                    usuario_id = :usuario_id,
                                    nombre = :nombre,
                                    saldo = :saldo,
                                    moneda = :moneda,
                                    activa = :activa,
                                    actualizado_en = NOW()
                                    WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
            $stmt->bindParam(":nombre", $nombre, PDO::PARAM_STR);
            $stmt->bindParam(":saldo", $saldo, PDO::PARAM_INT);
            $stmt->bindParam(":moneda", $moneda, PDO::PARAM_STR);
            $stmt->bindParam(":activa", $activa, PDO::PARAM_BOOL);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al actualizar cuenta: " . $e->getMessage());
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Eliminar cuenta
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = filter_input(INPUT_GET, "delete", FILTER_VALIDATE_INT);
    if ($id) {
        try {
            $stmt = $conn->prepare("DELETE FROM cuentas WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al eliminar cuenta: " . $e->getMessage());
        }
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Obtener todas las cuentas
try {
    $stmt = $conn->prepare("SELECT c.*, u.nombre_usuario FROM cuentas c
                            LEFT JOIN usuarios u ON c.usuario_id = u.id
                            ORDER BY c.id DESC");
    $stmt->execute();
    $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al obtener cuentas: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Cuentas - FinZen</title>
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
            width: 500px;
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
                <a href="#" class="menu-item active">
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
                <h1 class="page-title">Administración de Cuentas</h1>
                <button class="btn btn-primary modal-button" data-target="addCuentaModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nueva Cuenta</span>
                </button>
            </header>

            <!-- Accounts Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">account_balance_wallet</i>
                        <span>Cuentas</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Saldo</th>
                                <th>Moneda</th>
                                <th>Estado</th>
                                <th>Creado</th>
                                <th>Actualizado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cuentas as $cuenta): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cuenta["id"]); ?></td>
                                <td><?php echo htmlspecialchars($cuenta["nombre"]); ?></td>
                                <td><?php echo htmlspecialchars($cuenta["nombre_usuario"] ?? "Sin usuario"); ?></td>
                                <td><?php echo number_format($cuenta["saldo"], 0, ",", "."); ?></td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($cuenta["moneda"]); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $cuenta["activa"] ? "badge-success" : "badge-error"; ?>">
                                        <?php echo $cuenta["activa"] ? "Activa" : "Inactiva"; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($cuenta["creado_en"]); ?></td>
                                <td><?php echo htmlspecialchars($cuenta["actualizado_en"]); ?></td>
                                <td>
                                    <button class="btn btn-outlined btn-action edit-button" data-target="editCuentaModal"
                                            data-id="<?php echo $cuenta["id"]; ?>"
                                            data-usuario_id="<?php echo $cuenta["usuario_id"]; ?>"
                                            data-nombre="<?php echo htmlspecialchars($cuenta["nombre"]); ?>"
                                            data-saldo="<?php echo $cuenta["saldo"] / 100; ?>"
                                            data-moneda="<?php echo $cuenta["moneda"]; ?>"
                                            data-activa="<?php echo $cuenta["activa"]; ?>">
                                        <span class="icon"><i class="material-icons">edit</i></span>
                                    </button>
                                    <a href="?delete=<?php echo $cuenta["id"]; ?>" class="btn btn-outlined btn-action"
                                       onclick="return confirm('¿Estás seguro de eliminar esta cuenta?')">
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

    <!-- Add Account Modal -->
    <div class="modal" id="addCuentaModal">
        <div class="modal-background" onclick="closeModal('addCuentaModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nueva Cuenta</p>
                <button class="delete" onclick="closeModal('addCuentaModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de la Cuenta</label>
                        <div class="control">
                            <input class="input" type="text" name="nombre" required placeholder="Ej: Cuenta Principal">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Usuario</label>
                        <div class="control">
                            <div class="select">
                                <select name="usuario_id" required>
                                    <option value="">Seleccionar Usuario</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario["id"]; ?>">
                                            <?php echo htmlspecialchars($usuario["nombre_usuario"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Saldo Inicial</label>
                        <div class="control has-icons-left">
                            <span class="icon">$</span>
                            <input class="input" type="number" step="0.01" name="saldo" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Moneda</label>
                        <div class="control">
                            <div class="select">
                                <select name="moneda" required>
                                    <option value="">Seleccionar Moneda</option>
                                    <?php foreach ($monedas as $codigo => $nombre): ?>
                                        <option value="<?php echo $codigo; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="activa" checked>
                                Cuenta Activa
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addCuentaModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Edit Account Modal -->
    <div class="modal" id="editCuentaModal">
        <div class="modal-background" onclick="closeModal('editCuentaModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Cuenta</p>
                <button class="delete" onclick="closeModal('editCuentaModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="id">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de la Cuenta</label>
                        <div class="control">
                            <input class="input" type="text" id="edit_nombre" name="nombre" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Usuario</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_usuario_id" name="usuario_id" required>
                                    <option value="">Seleccionar Usuario</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario["id"]; ?>">
                                            <?php echo htmlspecialchars($usuario["nombre_usuario"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Saldo</label>
                        <div class="control has-icons-left">
                            <span class="icon">$</span>
                            <input class="input" type="number" step="0.01" id="edit_saldo" name="saldo" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Moneda</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_moneda" name="moneda" required>
                                    <option value="">Seleccionar Moneda</option>
                                    <?php foreach ($monedas as $codigo => $nombre): ?>
                                        <option value="<?php echo $codigo; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" id="edit_activa" name="activa">
                                Cuenta Activa
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editCuentaModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Modal functions
            function openModal($el) {
                $el.classList.add('is-active');
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

            // Open modals
            (document.querySelectorAll('.modal-button') || []).forEach(($trigger) => {
                const modal = $trigger.dataset.target;
                const $target = document.getElementById(modal);

                $trigger.addEventListener('click', () => {
                    openModal($target);
                });
            });

            // Handle account edit
            const editButtons = document.querySelectorAll('.edit-button');
            editButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_nombre').value = button.dataset.nombre;
                    document.getElementById('edit_usuario_id').value = button.dataset.usuario_id;
                    document.getElementById('edit_saldo').value = button.dataset.saldo;
                    document.getElementById('edit_moneda').value = button.dataset.moneda;
                    document.getElementById('edit_activa').checked = button.dataset.activa === '1';

                    const modal = document.getElementById('editCuentaModal');
                    openModal(modal);
                });
            });

            // Close with ESC
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });
        });
    </script>
</body>
</html>
