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
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 5;
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
        
        .btn-success {
            background-color: #198754;
            border-color: #198754;
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

                <form id="recoveryForm" method="POST" action="/auth/recover.php" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Nombre de Usuario</label>
                        <div class="position-relative">
                            <input type="text" class="form-control rounded-pill" name="username" id="username" required 
                                   placeholder="Tu nombre de usuario">
                        </div>
                        <div class="invalid-feedback">
                            Por favor ingresa tu nombre de usuario
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="newPassword" class="form-label">Nueva Contraseña</label>
                        <div class="position-relative">
                            <input type="password" class="form-control rounded-pill" name="newPassword" id="newPassword" required 
                                   placeholder="Mínimo 8 caracteres">
                        </div>
                        <div class="password-strength mt-2">
                            <div class="password-strength-bar" id="passwordStrength"></div>
                        </div>
                        <div class="form-text">Mínimo 8 caracteres</div>
                        <div class="invalid-feedback">
                            La contraseña debe tener al menos 8 caracteres
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirmPassword" class="form-label">Confirmar Nueva Contraseña</label>
                        <div class="position-relative">
                            <input type="password" class="form-control rounded-pill" id="confirmPassword" required 
                                   placeholder="Repite tu nueva contraseña">
                        </div>
                        <div class="invalid-feedback">
                            Las contraseñas no coinciden
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill" id="submitBtn">
                        Actualizar Contraseña
                    </button>
                </form>

                <div class="text-center mt-4">
                    <a href="index.php" class="text-decoration-none">Volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const recoveryForm = document.getElementById('recoveryForm');
            const username = document.getElementById('username');
            const newPassword = document.getElementById('newPassword');
            const confirmPassword = document.getElementById('confirmPassword');
            const passwordStrengthBar = document.getElementById('passwordStrength');
            const submitBtn = document.getElementById('submitBtn');

            // Validación de fortaleza de contraseña
            newPassword.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;

                // Calcular fortaleza
                if (password.length > 0) strength += 1;
                if (password.length >= 8) strength += 1;
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^A-Za-z0-9]/.test(password)) strength += 1;

                // Actualizar barra de fortaleza
                const width = (strength / 5) * 100;
                passwordStrengthBar.style.width = width + '%';

                // Cambiar color según fortaleza
                if (strength <= 2) {
                    passwordStrengthBar.style.backgroundColor = '#dc3545';
                } else if (strength <= 4) {
                    passwordStrengthBar.style.backgroundColor = '#ffc107';
                } else {
                    passwordStrengthBar.style.backgroundColor = '#198754';
                }
            });

            // Confirmación de contraseña en tiempo real
            confirmPassword.addEventListener('input', function() {
                if (this.value !== newPassword.value && newPassword.value) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });

            // Validación del formulario
            recoveryForm.addEventListener('submit', function(event) {
                // Validar usuario
                if (username.value.trim() === '') {
                    username.classList.add('is-invalid');
                }

                // Validar contraseña
                if (newPassword.value.length < 8) {
                    newPassword.classList.add('is-invalid');
                }

                // Validar confirmación
                if (confirmPassword.value !== newPassword.value) {
                    confirmPassword.classList.add('is-invalid');
                }

                // Si hay errores, prevenir envío
                if (recoveryForm.querySelectorAll('.is-invalid').length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                } else {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-top me-2"></i>Procesando...';
                    
                    // Simular respuesta del servidor
                    setTimeout(() => {
                        submitBtn.classList.remove('btn-primary');
                        submitBtn.classList.add('btn-success');
                        submitBtn.innerHTML = '<i class="bi bi-check me-2"></i>Contraseña actualizada';
                        
                        // Redirigir después de 2 segundos
                        setTimeout(() => {
                            window.location.href = 'index.php';
                        }, 2000);
                    }, 1500);
                }
                
                recoveryForm.classList.add('was-validated');
            });

            // Limpiar validación al escribir
            username.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
    </script>
</body>
</html>