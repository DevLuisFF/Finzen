<?php
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

// Listados
$stmtUsuarios = $conn->prepare("SELECT id, nombre_usuario FROM usuarios");
$stmtUsuarios->execute();
$usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

$stmtCuentas = $conn->prepare("SELECT id, nombre FROM cuentas");
$stmtCuentas->execute();
$cuentas = $stmtCuentas->fetchAll(PDO::FETCH_ASSOC);

$stmtCategorias = $conn->prepare("SELECT id, nombre FROM categorias");
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);

// Frecuencias
$frecuencias = [
    "diaria" => "Diaria",
    "semanal" => "Semanal",
    "mensual" => "Mensual",
    "anual" => "Anual",
];

// Días
$diasSemana = [
    "1" => "Lunes",
    "2" => "Martes",
    "3" => "Miércoles",
    "4" => "Jueves",
    "5" => "Viernes",
    "6" => "Sábado",
    "7" => "Domingo",
];

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario_id = filter_input(INPUT_POST, "usuario_id", FILTER_VALIDATE_INT);
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
    $frecuencia = $_POST["frecuencia"] ?? "";
    $dia = filter_input(INPUT_POST, "dia", FILTER_VALIDATE_INT);
    $proxima_fecha = $_POST["proxima_fecha"] ?? "";
    $finaliza_en = !empty($_POST["finaliza_en"]) ? $_POST["finaliza_en"] : null;
    $activa = isset($_POST["activa"]) ? 1 : 0;

    // Convertir monto a centavos
    $monto_input = str_replace(".", "", $_POST["monto"] ?? "");
    $monto = is_numeric($monto_input) ? (int) $monto_input : 0;

    if (
        !$usuario_id ||
        !$cuenta_id ||
        !$categoria_id ||
        $monto <= 0 ||
        !$proxima_fecha
    ) {
        die(
            "Datos inválidos. Asegúrate de llenar todos los campos correctamente."
        );
    }

    if (isset($_POST["create"])) {
        $stmt = $conn->prepare("INSERT INTO transacciones_recurrentes
            (usuario_id, cuenta_id, categoria_id, monto, descripcion, frecuencia, dia,
             proxima_fecha, finaliza_en, activa, creado_en, actualizado_en)
            VALUES
            (:usuario_id, :cuenta_id, :categoria_id, :monto, :descripcion, :frecuencia, :dia,
             :proxima_fecha, :finaliza_en, :activa, NOW(), NOW())");
    } elseif (isset($_POST["update"])) {
        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if (!$id) {
            die("ID inválido.");
        }
        $stmt = $conn->prepare("UPDATE transacciones_recurrentes SET
            usuario_id = :usuario_id,
            cuenta_id = :cuenta_id,
            categoria_id = :categoria_id,
            monto = :monto,
            descripcion = :descripcion,
            frecuencia = :frecuencia,
            dia = :dia,
            proxima_fecha = :proxima_fecha,
            finaliza_en = :finaliza_en,
            activa = :activa,
            actualizado_en = NOW()
            WHERE id = :id");
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
    }

    $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
    $stmt->bindParam(":cuenta_id", $cuenta_id, PDO::PARAM_INT);
    $stmt->bindParam(":categoria_id", $categoria_id, PDO::PARAM_INT);
    $stmt->bindParam(":monto", $monto, PDO::PARAM_INT);
    $stmt->bindParam(":descripcion", $descripcion, PDO::PARAM_STR);
    $stmt->bindParam(":frecuencia", $frecuencia, PDO::PARAM_STR);
    $stmt->bindParam(":dia", $dia, PDO::PARAM_INT);
    $stmt->bindParam(":proxima_fecha", $proxima_fecha, PDO::PARAM_STR);
    $stmt->bindParam(":finaliza_en", $finaliza_en, PDO::PARAM_STR);
    $stmt->bindParam(":activa", $activa, PDO::PARAM_BOOL);
    $stmt->execute();

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Eliminar
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = filter_input(INPUT_GET, "delete", FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $conn->prepare(
            "DELETE FROM transacciones_recurrentes WHERE id = :id"
        );
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->execute();
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Consultar
$stmt = $conn->prepare("SELECT tr.*, u.nombre_usuario, c.nombre AS cuenta_nombre, cat.nombre AS categoria_nombre
                        FROM transacciones_recurrentes tr
                        LEFT JOIN usuarios u ON tr.usuario_id = u.id
                        LEFT JOIN cuentas c ON tr.cuenta_id = c.id
                        LEFT JOIN categorias cat ON tr.categoria_id = cat.id
                        ORDER BY tr.proxima_fecha ASC");
$stmt->execute();
$transaccionesRecurrentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Transacciones Recurrentes - FinZen</title>
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

        .input, .select, .textarea {
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

        /* Specific styles */
        .descripcion-col {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #diaContainer, #edit_diaContainer {
            display: none;
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
                <a href="transacciones.php" class="menu-item">
                    <i class="material-icons">swap_horiz</i>
                    <span>Transacciones</span>
                </a>
                <a href="#" class="menu-item active">
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
                <h1 class="page-title">Administración de Transacciones Recurrentes</h1>
                <button class="btn btn-primary modal-button" data-target="addTransaccionRecurrenteModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nueva Transacción Recurrente</span>
                </button>
            </header>

            <!-- Tabla de transacciones recurrentes -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">autorenew</i>
                        <span>Transacciones Recurrentes</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Cuenta</th>
                                <th>Categoría</th>
                                <th>Monto</th>
                                <th>Descripción</th>
                                <th>Frecuencia</th>
                                <th>Día</th>
                                <th>Próxima Fecha</th>
                                <th>Finaliza</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaccionesRecurrentes as $transaccion):
                                $tipoClase = $transaccion["monto"] >= 0 ? "ingreso" : "gasto";
                                $diaMostrar = "";
                                if ($transaccion["frecuencia"] === "semanal") {
                                    $diaMostrar = $diasSemana[$transaccion["dia"]] ?? $transaccion["dia"];
                                } else {
                                    $diaMostrar = $transaccion["dia"];
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaccion["id"]); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["nombre_usuario"] ?? "Sin usuario"); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["cuenta_nombre"] ?? "Sin cuenta"); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["categoria_nombre"] ?? "Sin categoría"); ?></td>
                                <td class="<?php echo $tipoClase; ?>">
                                    $<?php echo number_format(abs($transaccion["monto"]) / 100, 2, ",", "."); ?>
                                </td>
                                <td class="descripcion-col" title="<?php echo htmlspecialchars($transaccion["descripcion"]); ?>">
                                    <?php echo htmlspecialchars($transaccion["descripcion"]); ?>
                                </td>
                                <td><?php echo htmlspecialchars($frecuencias[$transaccion["frecuencia"]] ?? $transaccion["frecuencia"]); ?></td>
                                <td><?php echo htmlspecialchars($diaMostrar); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["proxima_fecha"]); ?></td>
                                <td><?php echo htmlspecialchars($transaccion["finaliza_en"] ?? "No especificado"); ?></td>
                                <td>
                                    <?php if ($transaccion["activa"]): ?>
                                        <span class="badge badge-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge badge-error">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-outlined btn-action edit-button" data-target="editTransaccionRecurrenteModal"
                                            data-id="<?php echo $transaccion["id"]; ?>"
                                            data-usuario_id="<?php echo $transaccion["usuario_id"]; ?>"
                                            data-cuenta_id="<?php echo $transaccion["cuenta_id"]; ?>"
                                            data-categoria_id="<?php echo $transaccion["categoria_id"]; ?>"
                                            data-monto="<?php echo number_format(abs($transaccion["monto"]) / 100, 2, ",", "."); ?>"
                                            data-monto-original="<?php echo $transaccion["monto"]; ?>"
                                            data-descripcion="<?php echo htmlspecialchars($transaccion["descripcion"]); ?>"
                                            data-frecuencia="<?php echo $transaccion["frecuencia"]; ?>"
                                            data-dia="<?php echo $transaccion["dia"]; ?>"
                                            data-proxima_fecha="<?php echo $transaccion["proxima_fecha"]; ?>"
                                            data-finaliza_en="<?php echo $transaccion["finaliza_en"]; ?>"
                                            data-activa="<?php echo $transaccion["activa"]; ?>">
                                        <span class="icon"><i class="material-icons">edit</i></span>
                                    </button>
                                    <a href="?delete=<?php echo $transaccion["id"]; ?>" class="btn btn-outlined btn-action"
                                       onclick="return confirm('¿Estás seguro de eliminar esta transacción recurrente?')">
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

    <!-- Modal para agregar nueva transacción recurrente -->
    <div class="modal" id="addTransaccionRecurrenteModal">
        <div class="modal-background" onclick="closeModal('addTransaccionRecurrenteModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nueva Transacción Recurrente</p>
                <button class="delete" onclick="closeModal('addTransaccionRecurrenteModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
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
                            <span class="icon">$</span>
                            <input class="input" type="text" name="monto" placeholder="Ej: 500000" required oninput="formatNumber(this)">
                            <div class="select">
                                <select name="tipo_monto" style="max-width: 120px;">
                                    <option value="ingreso">Ingreso</option>
                                    <option value="gasto">Gasto</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Descripción</label>
                        <div class="control">
                            <textarea class="textarea" name="descripcion" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Frecuencia</label>
                        <div class="control">
                            <div class="select">
                                <select name="frecuencia" required onchange="mostrarDia(this.value)">
                                    <option value="">Seleccionar Frecuencia</option>
                                    <?php foreach ($frecuencias as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field" id="diaContainer">
                        <label class="label">Día</label>
                        <div class="control">
                            <div class="select">
                                <select name="dia">
                                    <option value="">Seleccionar Día</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Próxima Fecha</label>
                        <div class="control">
                            <input class="input" type="date" name="proxima_fecha" required value="<?php echo date("Y-m-d"); ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Finaliza el (opcional)</label>
                        <div class="control">
                            <input class="input" type="date" name="finaliza_en">
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="activa" checked>
                                Activa
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addTransaccionRecurrenteModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Modal para editar transacción recurrente -->
    <div class="modal" id="editTransaccionRecurrenteModal">
        <div class="modal-background" onclick="closeModal('editTransaccionRecurrenteModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Transacción Recurrente</p>
                <button class="delete" onclick="closeModal('editTransaccionRecurrenteModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="id">
                <section class="modal-card-body">
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
                            <span class="icon">$</span>
                            <input class="input" type="text" id="edit_monto" name="monto" required oninput="formatNumber(this)">
                            <div class="select">
                                <select id="edit_tipo_monto" name="tipo_monto" style="max-width: 120px;">
                                    <option value="ingreso">Ingreso</option>
                                    <option value="gasto">Gasto</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Descripción</label>
                        <div class="control">
                            <textarea class="textarea" id="edit_descripcion" name="descripcion" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Frecuencia</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_frecuencia" name="frecuencia" required onchange="mostrarDia(this.value, 'edit')">
                                    <option value="">Seleccionar Frecuencia</option>
                                    <?php foreach ($frecuencias as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field" id="edit_diaContainer">
                        <label class="label">Día</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_dia" name="dia">
                                    <option value="">Seleccionar Día</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Próxima Fecha</label>
                        <div class="control">
                            <input class="input" type="date" id="edit_proxima_fecha" name="proxima_fecha" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Finaliza el (opcional)</label>
                        <div class="control">
                            <input class="input" type="date" id="edit_finaliza_en" name="finaliza_en">
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" id="edit_activa" name="activa">
                                Activa
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editTransaccionRecurrenteModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        // Función para formatear números con separadores de miles
        function formatNumber(input) {
            let value = input.value.replace(/[^\d]/g, '');
            if (value.length > 0) {
                value = parseInt(value, 10).toLocaleString('es-ES');
            }
            input.value = value;
        }

        // Función para mostrar el campo de día según la frecuencia
        function mostrarDia(frecuencia, prefix = '') {
            const diaContainer = document.getElementById(prefix + 'diaContainer');
            const diaSelect = document.getElementById(prefix + 'dia');

            diaContainer.style.display = (frecuencia === 'diaria') ? 'none' : 'block';
            diaSelect.innerHTML = '<option value="">Seleccionar Día</option>';

            if (frecuencia === 'semanal') {
                <?php foreach ($diasSemana as $valor => $nombre): ?>
                    diaSelect.innerHTML += `<option value="<?php echo $valor; ?>"><?php echo $nombre; ?></option>`;
                <?php endforeach; ?>
            } else if (frecuencia === 'mensual') {
                for (let i = 1; i <= 31; i++) {
                    diaSelect.innerHTML += `<option value="${i}">${i}</option>`;
                }
            }
        }

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
                document.querySelectorAll('.modal').forEach(modal => {
                    closeModal(modal);
                });
            }

            document.querySelectorAll('.modal-button').forEach(trigger => {
                const modalId = trigger.dataset.target;
                trigger.addEventListener('click', () => {
                    openModal(modalId);
                });
            });

            document.querySelectorAll('.modal-background, .delete').forEach(close => {
                close.addEventListener('click', () => {
                    closeAllModals();
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });

            document.querySelectorAll('.edit-button').forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_usuario_id').value = button.dataset.usuario_id;
                    document.getElementById('edit_cuenta_id').value = button.dataset.cuenta_id;
                    document.getElementById('edit_categoria_id').value = button.dataset.categoria_id;
                    document.getElementById('edit_monto').value = button.dataset.monto;
                    document.getElementById('edit_descripcion').value = button.dataset.descripcion;
                    document.getElementById('edit_frecuencia').value = button.dataset.frecuencia;
                    document.getElementById('edit_proxima_fecha').value = button.dataset.proxima_fecha;
                    document.getElementById('edit_finaliza_en').value = button.dataset.finaliza_en || '';
                    document.getElementById('edit_activa').checked = button.dataset.activa === '1';

                    const tipoMonto = (button.dataset.monto_original >= 0) ? 'ingreso' : 'gasto';
                    document.getElementById('edit_tipo_monto').value = tipoMonto;

                    mostrarDia(button.dataset.frecuencia, 'edit');
                    setTimeout(() => {
                        document.getElementById('edit_dia').value = button.dataset.dia;
                    }, 100);

                    openModal('editTransaccionRecurrenteModal');
                });
            });

            document.querySelector('[name="frecuencia"]').addEventListener('change', function() {
                mostrarDia(this.value);
            });
        });
    </script>
</body>
</html>
