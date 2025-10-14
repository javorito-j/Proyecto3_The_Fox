<?php
// Configuración de la base de datos
$servername = "localhost";
$username = "root";
$password = "2244";
$dbname = "ServistarDB";

// Conectar a la base de datos
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Funciones para obtener datos (sin cambios)
function obtenerOrdenesPendientes($conn) {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM OrdenesServicio WHERE estado IN ('pendiente', 'en_proceso')");
    return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

function obtenerRepuestosStockBajo($conn) {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM Repuestos WHERE stock_actual <= stock_minimo");
    return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

function obtenerVentasMes($conn) {
    $stmt = $conn->query("SELECT COALESCE(SUM(monto_total), 0) as total FROM Pagos WHERE MONTH(fecha_pago) = MONTH(CURRENT_DATE()) AND YEAR(fecha_pago) = YEAR(CURRENT_DATE())");
    return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

function obtenerHerramientasPendientes($conn) {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM PrestamosHerramientas WHERE estado = 'prestado' AND fecha_devolucion_real IS NULL");
    return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

function obtenerOrdenesRecientes($conn) {
    $stmt = $conn->query("
        SELECT os.id_orden, 
               CONCAT('OS-', YEAR(os.fecha_entrada), '-', LPAD(os.id_orden, 4, '0')) as numero_orden,
               CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
               os.estado, 
               COALESCE(u.nombre, '-') as mecanico
        FROM OrdenesServicio os
        LEFT JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        LEFT JOIN Usuarios u ON os.id_mecanico = u.id_usuario
        ORDER BY os.fecha_entrada DESC LIMIT 5
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerNotificaciones($conn) {
    $stmt = $conn->query("
        SELECT tipo_notificacion as tipo, mensaje as titulo, mensaje as descripcion
        FROM Notificaciones 
        WHERE leida = 0 
        ORDER BY fecha_generada DESC LIMIT 5
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerRepuestosBajoStock($conn) {
    $stmt = $conn->query("
        SELECT nombre_repuesto, stock_actual, stock_minimo
        FROM Repuestos 
        WHERE stock_actual <= stock_minimo 
        ORDER BY stock_actual ASC LIMIT 5
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$ordenesPendientes = obtenerOrdenesPendientes($conn);
$repuestosStockBajo = obtenerRepuestosStockBajo($conn);
$ventasMes = obtenerVentasMes($conn);
$herramientasPendientes = obtenerHerramientasPendientes($conn);
$ordenesRecientes = obtenerOrdenesRecientes($conn);
$notificaciones = obtenerNotificaciones($conn);
$repuestosBajoStock = obtenerRepuestosBajoStock($conn);

function formatearMoneda($monto) {
    return 'Bs ' . number_format($monto, 2, '.', ',');
}

function getStatusColor($estado) {
    switch($estado) {
        case 'pendiente': return '#e74c3c';
        case 'en_proceso': return '#f39c12';
        case 'completado': return '#27ae60';
        default: return '#95a5a6';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Servistar</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a1d29;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #2c3e50;
            --dark: #121826;
            --text: #ecf0f1;
            --text-secondary: #bdc3c7;
            --card-bg: #222736;
            --hover-bg: #2a3042;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--dark);
            color: var(--text);
        }
        
        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            background: var(--primary);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        }
        
        .logo {
            text-align: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .logo h2 {
            color: white;
            font-size: 1.5rem;
        }
        
        .nav-links {
            list-style: none;
            margin-top: 20px;
        }
        
        .nav-links li {
            padding: 12px 20px;
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }
        
        .nav-links li.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary);
        }
        
        .nav-links li:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Main Content */
        .main-content {
            padding: 20px;
            background: var(--dark);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--light);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-left: 4px solid var(--secondary);
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .stat-icon {
            font-size: 1.8rem;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .stat-footer {
            margin-top: auto;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        /* Charts and Tables */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-container, .table-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .section-title {
            margin-bottom: 15px;
            color: var(--text);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            background: var(--card-bg);
            border: 2px solid var(--light);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            border-color: var(--secondary);
            background: var(--hover-bg);
            transform: translateY(-2px);
        }
        
        .action-icon {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: var(--secondary);
        }
        
        /* Notifications */
        .notification-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--light);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
        }
        
        .notification-icon.warning {
            background: var(--warning);
        }
        
        .notification-icon.danger {
            background: var(--danger);
        }
        
        .notification-icon.info {
            background: var(--secondary);
        }

        /* Table styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--light);
        }
        
        .data-table th {
            background: var(--light);
            font-weight: 600;
            color: var(--text);
        }
        
        .data-table tr:hover {
            background: var(--hover-bg);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <h2><i class="fas fa-car"></i> Servistar</h2>
            </div>
            <ul class="nav-links">
                <li class="active"><a href="Dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="ordenes_servicio.php"><i class="fas fa-tools"></i> Órdenes de Servicio</a></li>
                <li><a href="clientes.php"><i class="fas fa-users"></i> Clientes</a></li>
                <li><a href="inventario.php"><i class="fas fa-boxes"></i> Inventario</a></li>
                <li><a href="ventas_pagos.php"><i class="fas fa-money-bill-wave"></i> Ventas y Pagos</a></li>
                <li><a href="herramientas.php"><i class="fas fa-wrench"></i> Herramientas</a></li>
                <li><a href="control_calidad.php"><i class="fas fa-check-circle"></i> Control Calidad</a></li>
                <li><a href="post_venta.php"><i class="fas fa-headset"></i> Post Venta</a></li>
                <li><a href="usuarios.php"><i class="fas fa-user-cog"></i> Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-tachometer-alt"></i> Dashboard Principal</h1>
                <div class="user-info">
                    <div class="user-avatar">CM</div>
                    <span>Carlos Mendoza (Admin)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card" onclick="redirectTo('ordenes_servicio.php')">
                    <div class="stat-header">
                        <div class="stat-label">Órdenes Pendientes</div>
                        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $ordenesPendientes; ?></div>
                    <div class="stat-footer"><i class="fas fa-arrow-up"></i> +2 desde ayer</div>
                </div>
                <div class="stat-card warning" onclick="redirectTo('inventario.php')">
                    <div class="stat-header">
                        <div class="stat-label">Repuestos Stock Bajo</div>
                        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $repuestosStockBajo; ?></div>
                    <div class="stat-footer">Necesitan atención</div>
                </div>
                <div class="stat-card success" onclick="redirectTo('ventas_pagos.php')">
                    <div class="stat-header">
                        <div class="stat-label">Ventas del Mes</div>
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="stat-value"><?php echo formatearMoneda($ventasMes); ?></div>
                    <div class="stat-footer"><i class="fas fa-arrow-up"></i> +15% vs mes anterior</div>
                </div>
                <div class="stat-card danger" onclick="redirectTo('herramientas.php')">
                    <div class="stat-header">
                        <div class="stat-label">Herramientas Pendientes</div>
                        <div class="stat-icon"><i class="fas fa-tools"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $herramientasPendientes; ?></div>
                    <div class="stat-footer">Por devolver</div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Quick Actions -->
                    <div class="chart-container">
                        <h3 class="section-title"><i class="fas fa-bolt"></i> Acciones Rápidas</h3>
                        <div class="quick-actions">
                            <div class="action-btn" onclick="redirectTo('nueva_orden.php')">
                                <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                                <div>Nueva Orden</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('ventas_pagos.php')">
                                <div class="action-icon"><i class="fas fa-money-bill"></i></div>
                                <div>Registrar Pago</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('inventario.php')">
                                <div class="action-icon"><i class="fas fa-box-open"></i></div>
                                <div>Consultar Inventario</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('clientes.php')">
                                <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                                <div>Nuevo Cliente</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('herramientas.php')">
                                <div class="action-icon"><i class="fas fa-hammer"></i></div>
                                <div>Préstamo Herramientas</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('control_calidad.php')">
                                <div class="action-icon"><i class="fas fa-clipboard-check"></i></div>
                                <div>Control Calidad</div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Orders -->
                    <div class="table-container" style="margin-top: 20px;">
                        <h3 class="section-title"><i class="fas fa-history"></i> Órdenes Recientes</h3>
                        <?php if (count($ordenesRecientes) > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Orden #</th>
                                        <th>Vehículo</th>
                                        <th>Estado</th>
                                        <th>Mecánico</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ordenesRecientes as $orden): ?>
                                        <tr style="cursor: pointer;" onclick="redirectTo('detalle_orden.php?id=<?php echo $orden['id_orden']; ?>')">
                                            <td><?php echo $orden['numero_orden']; ?></td>
                                            <td><?php echo $orden['vehiculo']; ?></td>
                                            <td>
                                                <span style="color: <?php echo getStatusColor($orden['estado']); ?>;">
                                                    <?php if($orden['estado'] == 'pendiente'): ?>
                                                        <i class="fas fa-clock"></i>
                                                    <?php elseif($orden['estado'] == 'en_proceso'): ?>
                                                        <i class="fas fa-tools"></i>
                                                    <?php elseif($orden['estado'] == 'completado'): ?>
                                                        <i class="fas fa-check-circle"></i>
                                                    <?php endif; ?>
                                                    <?php echo $orden['estado']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $orden['mecanico']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>No hay órdenes recientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Notifications -->
                    <div class="table-container">
                        <h3 class="section-title"><i class="fas fa-bell"></i> Notificaciones</h3>
                        <?php if (count($notificaciones) > 0): ?>
                            <?php foreach ($notificaciones as $notif): ?>
                                <?php
                                $iconClass = 'info';
                                $iconText = '<i class="fas fa-info"></i>';
                                
                                if ($notif['tipo'] === 'stock_bajo') {
                                    $iconClass = 'danger';
                                    $iconText = '<i class="fas fa-exclamation"></i>';
                                } else if ($notif['tipo'] === 'herramienta_atrasada') {
                                    $iconClass = 'warning';
                                    $iconText = '<i class="fas fa-exclamation-triangle"></i>';
                                }
                                ?>
                                <div class="notification-item">
                                    <div class="notification-icon <?php echo $iconClass; ?>"><?php echo $iconText; ?></div>
                                    <div>
                                        <strong><?php echo $notif['titulo']; ?></strong>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo $notif['descripcion']; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                                <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>No hay notificaciones nuevas</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Low Stock Alert -->
                    <div class="table-container" style="margin-top: 20px;">
                        <h3 class="section-title"><i class="fas fa-boxes"></i> Repuestos Stock Bajo</h3>
                        <?php if (count($repuestosBajoStock) > 0): ?>
                            <?php foreach ($repuestosBajoStock as $repuesto): ?>
                                <?php
                                $color = $repuesto['stock_actual'] <= 2 ? 'var(--danger)' : 'var(--warning)';
                                $icon = '<i class="fas fa-box"></i>';
                                if (strpos($repuesto['nombre_repuesto'], 'Batería') !== false) $icon = '<i class="fas fa-battery-quarter"></i>';
                                else if (strpos($repuesto['nombre_repuesto'], 'Amortiguador') !== false) $icon = '<i class="fas fa-car-side"></i>';
                                else if (strpos($repuesto['nombre_repuesto'], 'Bujía') !== false) $icon = '<i class="fas fa-bolt"></i>';
                                else if (strpos($repuesto['nombre_repuesto'], 'Freno') !== false) $icon = '<i class="fas fa-stop-circle"></i>';
                                ?>
                                <div class="notification-item">
                                    <div><?php echo $icon . ' ' . $repuesto['nombre_repuesto']; ?></div>
                                    <div style="margin-left: auto; color: <?php echo $color; ?>;">
                                        <i class="fas fa-boxes"></i> <?php echo $repuesto['stock_actual']; ?> uds
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>No hay repuestos con stock bajo</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Función para redireccionar
        function redirectTo(url) {
            window.location.href = url;
        }

        // Actualizar dashboard cada 30 segundos
        setInterval(function() {
            location.reload();
        }, 30000);

        // Ejemplo de interacción con los botones de acción
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.querySelector('div:last-child').textContent;
                console.log(`Acción seleccionada: ${action}`);
            });
        });
    </script>
</body>
</html>
<?php
// Cerrar conexión
$conn = null;
?>