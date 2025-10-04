<?php
session_start();

// Verificar si hay mensajes de error o éxito
$recover_error = $_SESSION['recover_error'] ?? '';
$recover_success = $_SESSION['recover_success'] ?? '';
$form_data = $_SESSION['form_data'] ?? [];

// Limpiar mensajes después de mostrarlos
unset($_SESSION['recover_error']);
unset($_SESSION['recover_success']);
unset($_SESSION['form_data']);

// Si ya está logueado, redirigir
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    switch ($_SESSION['rol_id'] ?? 2) {
        case 1: header('Location: admin/index.php'); break;
        case 2: header('Location: user/index.php'); break;
        default: header('Location: user/index.php'); break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña | Finzen</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .recovery-container {
            width: 100%;
            max-width: 450px;
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .recovery-icon {
            font-size: 3.5rem;
            color: #0d6efd;
        }
        
        .form-control {
            border-radius: 50px;
            padding: 0.75rem 1.5rem;
        }
        
        .btn {
            border-radius: 50px;
            padding: 0.75rem 1.5rem;
        }
        
        .password-strength {
            height: 5px;
            margin-top: 8px;
            background-color: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.4s ease, background-color 0.4s ease;
            border-radius: 5px;
        }
        
        .password-hints li {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }
        
        .password-hints li.valid {
            color: #198754;
        }
        
        .toggle-password {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 5;
            color: #6c757d;
        }
        
        .password-container {
            position: relative;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }
        
        .shake-animation {
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .success-animation {
            animation: success 0.6s ease-in-out;
        }
        
        @keyframes success {
            0% { transform: scale(0.95); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .form-control.is-valid {
            border-color: #198754;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
        }
    </style>
</head>
<body>
    <div class="recovery-container">
        <div class="card">
            <div class="card-body">
                <div class="text-center mb-4">
                    <i class="bi bi-key recovery-icon"></i>
                    <h2 class="mt-3 mb-1">Recuperar Contraseña</h2>
                    <p class="text-muted">Ingresa tu nombre de usuario y establece una nueva contraseña</p>
                </div>

                <!-- Mensajes de error -->
                <?php if (!empty($recover_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show shake-animation" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo $recover_error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Mensajes de éxito (si se redirige desde otro proceso) -->
                <?php if (!empty($recover_success)): ?>
                    <div class="alert alert-success alert-dismissible fade show success-animation" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo $recover_success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form id="recoveryForm" method="POST" action="../auth/recover.php" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Nombre de Usuario</label>
                        <input type="text" class="form-control rounded-pill" name="username" id="username" required 
                               placeholder="Tu nombre de usuario"
                               value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>">
                        <div class="invalid-feedback">
                            Por favor ingresa tu nombre de usuario
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="newPassword" class="form-label">Nueva Contraseña</label>
                        <div class="password-container">
                            <input type="password" class="form-control rounded-pill" name="newPassword" id="newPassword" required 
                                   placeholder="Mínimo 8 caracteres, una mayúscula y un número">
                            <i class="bi bi-eye toggle-password" id="togglePassword"></i>
                        </div>
                        <div class="password-strength mt-2">
                            <div class="password-strength-bar" id="passwordStrength"></div>
                        </div>
                        <div class="password-hints mt-2" id="passwordHints">
                            <ul class="list-unstyled">
                                <li id="lengthHint"><i class="bi bi-circle"></i> Al menos 8 caracteres</li>
                                <li id="uppercaseHint"><i class="bi bi-circle"></i> Al menos una mayúscula</li>
                                <li id="numberHint"><i class="bi bi-circle"></i> Al menos un número</li>
                            </ul>
                        </div>
                        <div class="invalid-feedback">
                            La contraseña debe cumplir con todos los requisitos
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirmPassword" class="form-label">Confirmar Nueva Contraseña</label>
                        <div class="password-container">
                            <input type="password" class="form-control rounded-pill" name="confirmPassword" id="confirmPassword" required 
                                   placeholder="Repite tu nueva contraseña">
                            <i class="bi bi-eye toggle-password" id="toggleConfirmPassword"></i>
                        </div>
                        <div class="invalid-feedback">
                            Las contraseñas no coinciden
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill" id="submitBtn">
                        <i class="bi bi-key me-2"></i>
                        Actualizar Contraseña
                    </button>
                </form>

                <div class="text-center mt-4">
                    <a href="index.php" class="text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const recoveryForm = document.getElementById('recoveryForm');
            const usernameInput = document.getElementById('username');
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const passwordStrengthBar = document.getElementById('passwordStrength');
            const submitBtn = document.getElementById('submitBtn');

            // Mostrar/ocultar contraseña
            [togglePassword, toggleConfirmPassword].forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    this.classList.toggle('bi-eye');
                    this.classList.toggle('bi-eye-slash');
                });
            });

            // Validación de fortaleza de contraseña
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;

                // Resetear hints
                document.querySelectorAll('#passwordHints li').forEach(li => {
                    li.classList.remove('valid');
                    li.querySelector('i').className = 'bi bi-circle';
                });

                // Verificar requisitos
                const hasMinLength = password.length >= 8;
                const hasUpperCase = /[A-Z]/.test(password);
                const hasNumber = /[0-9]/.test(password);

                // Actualizar hints
                if (hasMinLength) {
                    document.getElementById('lengthHint').classList.add('valid');
                    document.getElementById('lengthHint').querySelector('i').className = 'bi bi-check-circle';
                }
                if (hasUpperCase) {
                    document.getElementById('uppercaseHint').classList.add('valid');
                    document.getElementById('uppercaseHint').querySelector('i').className = 'bi bi-check-circle';
                }
                if (hasNumber) {
                    document.getElementById('numberHint').classList.add('valid');
                    document.getElementById('numberHint').querySelector('i').className = 'bi bi-check-circle';
                }

                // Calcular fortaleza
                if (hasMinLength) strength += 1;
                if (hasUpperCase) strength += 1;
                if (hasNumber) strength += 1;

                // Actualizar barra
                const width = (strength / 3) * 100;
                passwordStrengthBar.style.width = width + '%';

                // Color de la barra
                if (strength <= 1) {
                    passwordStrengthBar.style.backgroundColor = '#dc3545';
                } else if (strength <= 2) {
                    passwordStrengthBar.style.backgroundColor = '#ffc107';
                } else {
                    passwordStrengthBar.style.backgroundColor = '#198754';
                }

                // Validación visual en tiempo real
                if (hasMinLength && hasUpperCase && hasNumber) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });

            // Confirmación de contraseña en tiempo real
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value !== newPasswordInput.value && newPasswordInput.value) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                } else if (this.value === newPasswordInput.value && newPasswordInput.value) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-invalid', 'is-valid');
                }
            });

            // Validación en tiempo real para usuario
            usernameInput.addEventListener('input', function() {
                if (this.value.trim().length >= 3) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });

            // Validación del formulario al enviar
            recoveryForm.addEventListener('submit', function(event) {
                let hasErrors = false;

                // Validar usuario
                if (usernameInput.value.trim() === '') {
                    usernameInput.classList.add('is-invalid');
                    usernameInput.classList.remove('is-valid');
                    hasErrors = true;
                }

                // Validar contraseña
                const password = newPasswordInput.value;
                const hasMinLength = password.length >= 8;
                const hasUpperCase = /[A-Z]/.test(password);
                const hasNumber = /[0-9]/.test(password);

                if (!hasMinLength || !hasUpperCase || !hasNumber) {
                    newPasswordInput.classList.add('is-invalid');
                    newPasswordInput.classList.remove('is-valid');
                    hasErrors = true;
                }

                // Validar confirmación
                if (confirmPasswordInput.value !== newPasswordInput.value) {
                    confirmPasswordInput.classList.add('is-invalid');
                    confirmPasswordInput.classList.remove('is-valid');
                    hasErrors = true;
                }

                // Si hay errores, prevenir envío
                if (hasErrors) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    // Agregar animación shake a campos inválidos
                    const invalidFields = recoveryForm.querySelectorAll('.is-invalid');
                    invalidFields.forEach(field => {
                        field.classList.add('shake-animation');
                        setTimeout(() => field.classList.remove('shake-animation'), 500);
                    });
                } else {
                    // Deshabilitar botón y mostrar loading
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Actualizando contraseña...';
                }
                
                recoveryForm.classList.add('was-validated');
            });

            // Auto-focus en el campo de usuario
            usernameInput.focus();
        });
    </script>
</body>
</html>