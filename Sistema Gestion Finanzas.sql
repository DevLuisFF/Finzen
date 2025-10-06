-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 06-10-2025 a las 06:57:47
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `finzen`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias`
--

CREATE TABLE `categorias` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `tipo` enum('ingreso','gasto') NOT NULL,
  `icono` varchar(50) DEFAULT 'category',
  `color` char(7) DEFAULT '#1976D2',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Categorías para clasificar transacciones';

--
-- Volcado de datos para la tabla `categorias`
--

INSERT INTO `categorias` (`id`, `usuario_id`, `nombre`, `tipo`, `icono`, `color`, `creado_en`, `actualizado_en`) VALUES
(2, 1, 'test', 'ingreso', 'bi-cash-coin', '#8AC24A', '2025-09-15 01:07:00', '2025-09-28 17:38:00'),
(3, 1, 'transporte', 'gasto', 'bi-car-front', '#FF6384', '2025-09-28 16:35:58', '2025-09-28 17:37:48'),
(4, 1, 'servicios', 'gasto', 'bi-phone', '#FF6384', '2025-09-29 18:03:46', '2025-09-30 02:44:53'),
(6, 1, 'test3', 'ingreso', 'bi-cash-coin', '#FF6384', '2025-10-01 23:02:40', '2025-10-01 23:02:40'),
(7, 1, 'Alquiler', 'gasto', 'bi-house', '#9966FF', '2025-10-01 23:17:59', '2025-10-01 23:17:59'),
(8, 3, 'Salario', 'ingreso', 'bi-cash-coin', '#8AC24A', '2025-10-06 02:59:05', '2025-10-06 02:59:05'),
(9, 3, 'Transporte Publico', 'gasto', 'bi-car-front', '#FF6384', '2025-10-06 04:23:47', '2025-10-06 04:23:56');

--
-- Disparadores `categorias`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_categorias` BEFORE UPDATE ON `categorias` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_categorias_duplicadas` BEFORE INSERT ON `categorias` FOR EACH ROW BEGIN
    DECLARE categoria_existente INT;
    
    SELECT COUNT(*) INTO categoria_existente 
    FROM categorias 
    WHERE usuario_id = NEW.usuario_id 
    AND nombre = NEW.nombre 
    AND tipo = NEW.tipo;
    
    IF categoria_existente > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Ya existe una categoría con este nombre y tipo para el usuario';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_eliminar_categoria_con_transacciones` BEFORE DELETE ON `categorias` FOR EACH ROW BEGIN
    DECLARE transacciones_count INT;
    
    SELECT COUNT(*) INTO transacciones_count 
    FROM transacciones 
    WHERE categoria_id = OLD.id;
    
    IF transacciones_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede eliminar una categoría con transacciones asociadas';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_formato_color` BEFORE INSERT ON `categorias` FOR EACH ROW BEGIN
    IF NEW.color NOT REGEXP '^#[0-9A-Fa-f]{6}$' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El color debe estar en formato hexadecimal (#RRGGBB)';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `presupuestos`
--

CREATE TABLE `presupuestos` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `categoria_id` int(10) UNSIGNED NOT NULL,
  `monto` bigint(20) NOT NULL COMMENT 'Valor en centavos/unidad mínima',
  `periodo` enum('mensual','anual') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `notificacion` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `presupuestos`
--

INSERT INTO `presupuestos` (`id`, `usuario_id`, `categoria_id`, `monto`, `periodo`, `fecha_inicio`, `fecha_fin`, `notificacion`, `creado_en`, `actualizado_en`) VALUES
(6, 1, 3, 200000, 'mensual', '2025-09-30', NULL, 1, '2025-09-30 00:04:23', '2025-09-30 03:14:10'),
(7, 1, 4, 500000, 'mensual', '2025-09-30', NULL, 1, '2025-09-30 00:37:08', '2025-09-30 00:37:08'),
(8, 3, 9, 10000000, 'mensual', '2025-10-06', '2025-11-06', 1, '2025-10-06 04:47:53', '2025-10-06 04:47:53');

--
-- Disparadores `presupuestos`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_presupuestos` BEFORE UPDATE ON `presupuestos` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_establecer_fecha_fin_presupuesto` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.periodo = 'mensual' AND NEW.fecha_fin IS NULL THEN
        SET NEW.fecha_fin = DATE_ADD(NEW.fecha_inicio, INTERVAL 1 MONTH);
    ELSEIF NEW.periodo = 'anual' AND NEW.fecha_fin IS NULL THEN
        SET NEW.fecha_fin = DATE_ADD(NEW.fecha_inicio, INTERVAL 1 YEAR);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_actualizacion_presupuesto` BEFORE UPDATE ON `presupuestos` FOR EACH ROW BEGIN
    DECLARE gasto_actual BIGINT;
    
    -- Si se reduce el presupuesto, verificar que no sea menor al gasto actual
    IF NEW.monto < OLD.monto THEN
        -- Calcular gasto actual en el período
        SELECT COALESCE(SUM(t.monto), 0) INTO gasto_actual
        FROM transacciones t
        INNER JOIN categorias c ON t.categoria_id = c.id
        WHERE t.usuario_id = NEW.usuario_id
        AND t.categoria_id = NEW.categoria_id
        AND c.tipo = 'gasto'
        AND t.fecha BETWEEN NEW.fecha_inicio AND 
            COALESCE(NEW.fecha_fin, DATE_ADD(NEW.fecha_inicio, INTERVAL 1 MONTH));
        
        IF gasto_actual > NEW.monto THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'No se puede reducir el presupuesto por debajo del gasto actual registrado';
        END IF;
    END IF;
    
    -- Prevenir cambios de categoría en presupuestos existentes
    IF OLD.categoria_id != NEW.categoria_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede cambiar la categoría de un presupuesto existente';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_fechas_presupuesto` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.fecha_fin IS NOT NULL AND NEW.fecha_inicio > NEW.fecha_fin THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La fecha de inicio no puede ser mayor a la fecha fin';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_presupuesto_positivo` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.monto <= 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El presupuesto debe ser mayor a 0';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_presupuesto_usuario_categoria` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    DECLARE categoria_tipo ENUM('ingreso','gasto');
    DECLARE categoria_usuario_id INT;
    
    -- Verificar que la categoría pertenece al usuario
    SELECT usuario_id, tipo INTO categoria_usuario_id, categoria_tipo
    FROM categorias 
    WHERE id = NEW.categoria_id;
    
    IF categoria_usuario_id != NEW.usuario_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La categoría no pertenece al usuario';
    END IF;
    
    -- Solo permitir presupuestos para categorías de gasto
    IF categoria_tipo != 'gasto' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Solo se pueden crear presupuestos para categorías de gasto';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_presupuesto_vs_saldo` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    DECLARE saldo_actual BIGINT;
    DECLARE presupuesto_total BIGINT;
    
    -- Obtener saldo actual del usuario
    SELECT `saldo` INTO saldo_actual 
    FROM `usuarios` 
    WHERE `id` = NEW.`usuario_id`;
    
    -- Calcular presupuesto total mensual actual del usuario
    SELECT COALESCE(SUM(monto), 0) INTO presupuesto_total
    FROM presupuestos 
    WHERE usuario_id = NEW.usuario_id 
    AND periodo = 'mensual'
    AND (fecha_fin IS NULL OR fecha_fin >= CURDATE());
    
    -- Sumar el nuevo presupuesto
    SET presupuesto_total = presupuesto_total + NEW.monto;
    
    -- Validar que el presupuesto total no exceda significativamente el saldo
    -- (Ajusta el porcentaje según tus necesidades)
    IF presupuesto_total > saldo_actual * 2 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El presupuesto total excede significativamente su saldo actual';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `nombre` varchar(20) NOT NULL COMMENT 'admin|usuario',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Roles de usuarios en el sistema';

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `creado_en`, `actualizado_en`) VALUES
(1, 'admin', '2025-09-15 01:18:05', NULL),
(2, 'usuario', '2025-09-15 01:18:05', NULL);

--
-- Disparadores `roles`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_roles` BEFORE UPDATE ON `roles` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_eliminar_rol_en_uso` BEFORE DELETE ON `roles` FOR EACH ROW BEGIN
    DECLARE usuarios_count INT;
    
    SELECT COUNT(*) INTO usuarios_count 
    FROM usuarios 
    WHERE rol_id = OLD.id;
    
    IF usuarios_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede eliminar un rol que está siendo usado por usuarios';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transacciones`
--

CREATE TABLE `transacciones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `categoria_id` int(10) UNSIGNED NOT NULL,
  `monto` bigint(20) NOT NULL COMMENT 'Valor en centavos/unidad mínima',
  `descripcion` varchar(255) DEFAULT NULL,
  `fecha` date NOT NULL,
  `recurrente` tinyint(1) DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `transacciones`
--

INSERT INTO `transacciones` (`id`, `usuario_id`, `categoria_id`, `monto`, `descripcion`, `fecha`, `recurrente`, `creado_en`, `actualizado_en`) VALUES
(12, 3, 8, 300000000, 'Salario mensual', '2025-10-06', 0, '2025-10-06 03:13:07', '2025-10-06 03:13:07');

--
-- Disparadores `transacciones`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_delete` AFTER DELETE ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = OLD.`categoria_id`;
    
    IF tipo_categoria = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - OLD.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = OLD.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + OLD.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = OLD.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_insert` AFTER INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = NEW.`categoria_id`;
    
    IF tipo_categoria = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_update` AFTER UPDATE ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria_old ENUM('ingreso','gasto');
    DECLARE tipo_categoria_new ENUM('ingreso','gasto');
    DECLARE saldo_actual BIGINT;
    
    -- Solo procesar si hay cambios relevantes
    IF OLD.monto != NEW.monto OR OLD.categoria_id != NEW.categoria_id OR OLD.usuario_id != NEW.usuario_id THEN
        
        -- Obtener tipos de categorías
        SELECT `tipo` INTO tipo_categoria_old FROM `categorias` WHERE `id` = OLD.`categoria_id`;
        SELECT `tipo` INTO tipo_categoria_new FROM `categorias` WHERE `id` = NEW.`categoria_id`;
        
        -- Obtener saldo actual
        SELECT `saldo` INTO saldo_actual FROM `usuarios` WHERE `id` = OLD.`usuario_id`;
        
        -- Revertir transacción anterior
        IF tipo_categoria_old = 'ingreso' THEN
            SET saldo_actual = saldo_actual - OLD.monto;
        ELSE
            SET saldo_actual = saldo_actual + OLD.monto;
        END IF;
        
        -- Aplicar nueva transacción
        IF tipo_categoria_new = 'ingreso' THEN
            SET saldo_actual = saldo_actual + NEW.monto;
        ELSE
            SET saldo_actual = saldo_actual - NEW.monto;
        END IF;
        
        -- Actualizar saldo del usuario
        UPDATE `usuarios` 
        SET `saldo` = saldo_actual,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_limitar_descripcion` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    IF LENGTH(NEW.descripcion) > 255 THEN
        SET NEW.descripcion = CONCAT(SUBSTRING(NEW.descripcion, 1, 252), '...');
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_monitor_gastos_elevados` AFTER INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    DECLARE saldo_actual BIGINT;
    DECLARE porcentaje_gasto DECIMAL(5,2);
    
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = NEW.`categoria_id`;
    
    IF tipo_categoria = 'gasto' THEN
        -- Obtener saldo actual después de la transacción
        SELECT `saldo` INTO saldo_actual 
        FROM `usuarios` 
        WHERE `id` = NEW.`usuario_id`;
        
        -- Calcular porcentaje del gasto respecto al saldo anterior
        SET porcentaje_gasto = (NEW.monto / (saldo_actual + NEW.monto)) * 100;
        
        -- Alertar si el gasto representa más del 50% del saldo anterior
        IF porcentaje_gasto > 50 THEN
            -- Aquí podrías implementar notificaciones o logging
            -- Por ahora solo prevenimos gastos extremadamente altos
            IF porcentaje_gasto > 90 THEN
                SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Gasto demasiado elevado en relación a su saldo';
            END IF;
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_monitor_ingresos_anomalos` AFTER INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE categoria_tipo ENUM('ingreso','gasto');
    DECLARE promedio_ingresos_mensual BIGINT;
    DECLARE total_ingresos_mes BIGINT;
    
    SELECT tipo INTO categoria_tipo FROM categorias WHERE id = NEW.categoria_id;
    
    IF categoria_tipo = 'ingreso' THEN
        -- Calcular promedio de ingresos de los últimos 3 meses
        SELECT COALESCE(AVG(monto), 0) INTO promedio_ingresos_mensual
        FROM transacciones t
        INNER JOIN categorias c ON t.categoria_id = c.id
        WHERE t.usuario_id = NEW.usuario_id
        AND c.tipo = 'ingreso'
        AND t.fecha BETWEEN DATE_SUB(NEW.fecha, INTERVAL 3 MONTH) AND NEW.fecha;
        
        -- Alertar si el ingreso es 5 veces mayor al promedio
        IF promedio_ingresos_mensual > 0 AND NEW.monto > (promedio_ingresos_mensual * 5) THEN
            -- Podrías implementar notificación o logging aquí
            SET @ingreso_anomalo = 'Ingreso inusualmente alto detectado';
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_transacciones_duplicadas` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE transaccion_duplicada INT;
    
    -- Verificar transacción similar en los últimos 5 minutos
    SELECT COUNT(*) INTO transaccion_duplicada
    FROM transacciones 
    WHERE usuario_id = NEW.usuario_id 
    AND categoria_id = NEW.categoria_id 
    AND monto = NEW.monto 
    AND fecha = NEW.fecha
    AND descripcion = NEW.descripcion
    AND creado_en >= DATE_SUB(NOW(), INTERVAL 5 MINUTE);
    
    IF transaccion_duplicada > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Transacción duplicada detectada en los últimos 5 minutos';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_actualizacion_transaccion` BEFORE UPDATE ON `transacciones` FOR EACH ROW BEGIN
    -- Prevenir cambios de usuario_id en transacciones existentes
    IF OLD.usuario_id != NEW.usuario_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede cambiar el usuario de una transacción existente';
    END IF;
    
    -- Validar que la fecha no sea futura
    IF NEW.fecha > CURDATE() THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La fecha de la transacción no puede ser futura';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_fecha_transaccion` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    -- No permitir transacciones con más de 1 año de antigüedad
    IF NEW.fecha < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se pueden registrar transacciones con más de 1 año de antigüedad';
    END IF;
    
    -- No permitir transacciones futuras (más de 7 días)
    IF NEW.fecha > DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se pueden registrar transacciones con más de 7 días de anticipación';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_monto_positivo` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    IF NEW.monto <= 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El monto debe ser mayor a 0';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_saldo_actualizacion_gasto` BEFORE UPDATE ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria_old ENUM('ingreso','gasto');
    DECLARE tipo_categoria_new ENUM('ingreso','gasto');
    DECLARE saldo_actual BIGINT;
    DECLARE saldo_temporal BIGINT;
    
    -- Obtener tipos de categorías
    SELECT `tipo` INTO tipo_categoria_old FROM `categorias` WHERE `id` = OLD.`categoria_id`;
    SELECT `tipo` INTO tipo_categoria_new FROM `categorias` WHERE `id` = NEW.`categoria_id`;
    
    -- Obtener saldo actual
    SELECT `saldo` INTO saldo_actual FROM `usuarios` WHERE `id` = NEW.`usuario_id`;
    
    -- Calcular saldo temporal después de revertir la transacción anterior
    IF tipo_categoria_old = 'ingreso' THEN
        SET saldo_temporal = saldo_actual - OLD.monto;
    ELSE
        SET saldo_temporal = saldo_actual + OLD.monto;
    END IF;
    
    -- Validar que la nueva transacción no exceda el saldo
    IF tipo_categoria_new = 'gasto' AND NEW.monto > saldo_temporal THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Saldo insuficiente para actualizar a este gasto';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_saldo_antes_gasto` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    DECLARE saldo_actual BIGINT;
    
    -- Obtener el tipo de categoría
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = NEW.`categoria_id`;
    
    -- Obtener saldo actual del usuario
    SELECT `saldo` INTO saldo_actual 
    FROM `usuarios` 
    WHERE `id` = NEW.`usuario_id`;
    
    -- Validar que no se exceda el saldo en gastos
    IF tipo_categoria = 'gasto' AND NEW.monto > saldo_actual THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Saldo insuficiente para realizar este gasto';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_usuario_categoria_transaccion` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE categoria_usuario_id INT;
    DECLARE categoria_tipo ENUM('ingreso','gasto');
    
    -- Verificar que la categoría pertenece al usuario y obtener su tipo
    SELECT usuario_id, tipo INTO categoria_usuario_id, categoria_tipo
    FROM categorias 
    WHERE id = NEW.categoria_id;
    
    IF categoria_usuario_id != NEW.usuario_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La categoría no pertenece al usuario';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_verificar_presupuesto_al_gastar` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE presupuesto_mensual BIGINT;
    DECLARE gasto_mensual_actual BIGINT;
    DECLARE porcentaje_uso DECIMAL(5,2);
    DECLARE categoria_tipo ENUM('ingreso','gasto');
    
    -- Solo verificar para gastos
    SELECT tipo INTO categoria_tipo FROM categorias WHERE id = NEW.categoria_id;
    
    IF categoria_tipo = 'gasto' THEN
        -- Obtener presupuesto mensual para esta categoría
        SELECT COALESCE(SUM(monto), 0) INTO presupuesto_mensual
        FROM presupuestos 
        WHERE usuario_id = NEW.usuario_id 
        AND categoria_id = NEW.categoria_id
        AND periodo = 'mensual'
        AND fecha_inicio <= NEW.fecha
        AND (fecha_fin IS NULL OR fecha_fin >= NEW.fecha);
        
        -- Si existe presupuesto, verificar uso
        IF presupuesto_mensual > 0 THEN
            -- Calcular gasto mensual actual
            SELECT COALESCE(SUM(monto), 0) INTO gasto_mensual_actual
            FROM transacciones t
            INNER JOIN categorias c ON t.categoria_id = c.id
            WHERE t.usuario_id = NEW.usuario_id
            AND t.categoria_id = NEW.categoria_id
            AND c.tipo = 'gasto'
            AND YEAR(t.fecha) = YEAR(NEW.fecha)
            AND MONTH(t.fecha) = MONTH(NEW.fecha);
            
            -- Calcular nuevo gasto total
            SET gasto_mensual_actual = gasto_mensual_actual + NEW.monto;
            SET porcentaje_uso = (gasto_mensual_actual / presupuesto_mensual) * 100;
            
            -- Prevenir si excede el presupuesto
            IF gasto_mensual_actual > presupuesto_mensual THEN
                SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Esta transacción excede el presupuesto mensual asignado para esta categoría';
            -- Advertencia si está cerca del límite (80%)
            ELSEIF porcentaje_uso > 80 THEN
                -- Solo advertencia, no bloquea la transacción
                -- Podrías implementar logging aquí
                SET @presupuesto_warning = CONCAT('Advertencia: Has usado el ', 
                    ROUND(porcentaje_uso), '% de tu presupuesto mensual para esta categoría');
            END IF;
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre_usuario` varchar(100) NOT NULL,
  `correo_electronico` varchar(150) NOT NULL,
  `hash_contraseña` varchar(255) NOT NULL,
  `rol_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `saldo` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Valor en centavos/unidad mínima',
  `moneda` char(3) DEFAULT 'PYG',
  `activo` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre_usuario`, `correo_electronico`, `hash_contraseña`, `rol_id`, `saldo`, `moneda`, `activo`, `creado_en`, `actualizado_en`) VALUES
(1, 'luis', 'luis12ferreirafranco@gmail.com', '$2y$10$gB6b7G1T2pbwdFa6zQ6DIO03QV5bRLuOQPvY4HcP72pxEWMRo/0AK', 2, 0, 'PYG', 1, '2025-09-15 01:18:33', '2025-10-03 22:30:45'),
(2, 'testuser', 'testuser@gmail.com', '$2y$12$YZy9AAVO2zTD4RZcTFYnx.6FYAPCJWFWvfq68ifU4/H5R8SoIsTd2', 1, 0, 'PYG', 1, '2025-09-15 02:07:33', '2025-10-03 22:30:45'),
(3, 'demo', 'demoemail@gmail.com', '$2y$10$1n9MPXZnFKf.kNBZaC28repW//jfwnmiH.ouemmKAcFRbXMZv2aL2', 2, 300000000, 'PYG', 1, '2025-09-29 17:05:41', '2025-10-06 03:13:07');

--
-- Disparadores `usuarios`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_inicial` AFTER UPDATE ON `usuarios` FOR EACH ROW BEGIN
    -- Si el saldo se actualiza manualmente, verificar consistencia con transacciones
    IF OLD.saldo != NEW.saldo AND NEW.saldo < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El saldo no puede ser negativo';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_usuarios` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_saldo_negativo` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    IF NEW.saldo < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El saldo no puede ser negativo';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_email` BEFORE INSERT ON `usuarios` FOR EACH ROW BEGIN
    IF NEW.correo_electronico NOT LIKE '%_@__%.__%' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Formato de email inválido';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_transferencia_saldo` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    DECLARE total_gastos_pendientes BIGINT;
    
    -- Si el saldo está disminuyendo significativamente, verificar gastos pendientes
    IF NEW.saldo < OLD.saldo AND (OLD.saldo - NEW.saldo) > 1000000 THEN
        -- Calcular gastos pendientes en categorías de gasto
        SELECT COALESCE(SUM(t.monto), 0) INTO total_gastos_pendientes
        FROM transacciones t
        INNER JOIN categorias c ON t.categoria_id = c.id
        WHERE t.usuario_id = NEW.id
        AND c.tipo = 'gasto'
        AND t.fecha <= CURDATE();
        
        -- Validar que después de la transferencia quede saldo para gastos pendientes
        IF NEW.saldo < total_gastos_pendientes THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Saldo insuficiente para cubrir gastos pendientes después de la transferencia';
        END IF;
    END IF;
END
$$
DELIMITER ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_nombre_tipo` (`usuario_id`,`nombre`,`tipo`),
  ADD KEY `idx_usuario_tipo_categoria` (`usuario_id`,`tipo`);

--
-- Indices de la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_presupuesto_usuario_categoria` (`usuario_id`,`categoria_id`,`periodo`),
  ADD KEY `fk_presupuesto_categoria` (`categoria_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `transacciones`
--
ALTER TABLE `transacciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transacciones_fecha` (`fecha`),
  ADD KEY `idx_transacciones_categoria` (`categoria_id`),
  ADD KEY `idx_transacciones_cuenta_fecha` (`fecha`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_usuario` (`nombre_usuario`),
  ADD UNIQUE KEY `correo_electronico` (`correo_electronico`),
  ADD KEY `idx_usuario_rol` (`rol_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `transacciones`
--
ALTER TABLE `transacciones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD CONSTRAINT `fk_presupuesto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `transacciones`
--
ALTER TABLE `transacciones`
  ADD CONSTRAINT `fk_transaccion_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
