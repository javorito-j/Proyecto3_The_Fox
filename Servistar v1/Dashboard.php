<?php
// Configuración de la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ServistarDB";

// Conectar a la base de datos
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Funciones para obtener datos
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
        case 'pendiente': return 'var(--danger)';
        case 'en_proceso': return 'var(--warning)';
        case 'completado': return 'var(--success)';
        default: return 'var(--dark)';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Servistar</title>
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #34495e;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f6fa;
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
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
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
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--secondary);
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
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
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        /* Charts and Tables */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-container, .table-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            margin-bottom: 15px;
            color: var(--dark);
            font-size: 1.2rem;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            background: white;
            border: 2px solid var(--light);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            border-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .action-icon {
            font-size: 1.5rem;
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
            border-bottom: 1px solid #ecf0f1;
        }
        
        .data-table th {
            background: var(--light);
            font-weight: 600;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <h2>🏎️ Servistar</h2>
            </div>
            <ul class="nav-links">
                <li class="active"><a href="Dashboard.php">📊 Dashboard</a></li>
                <li><a href="ordenes_servicio.php">🔧 Órdenes de Servicio</a></li>
                <li><a href="clientes.php">👥 Clientes</a></li>
                <li><a href="inventario.php">📦 Inventario</a></li>
                <li><a href="ventas_pagos.php">💰 Ventas y Pagos</a></li>
                <li><a href="herramientas.php">🛠️ Herramientas</a></li>
                <li><a href="control_calidad.php">✅ Control Calidad</a></li>
                <li><a href="post_venta.php">📞 Post Venta</a></li>
                <li><a href="usuarios.php">👤 Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Dashboard Principal</h1>
                <div class="user-info">
                    <div class="user-avatar">CM</div>
                    <span>Carlos Mendoza (Admin)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card" onclick="redirectTo('ordenes_servicio.php')">
                    <div class="stat-label">Órdenes Pendientes</div>
                    <div class="stat-value"><?php echo $ordenesPendientes; ?></div>
                    <small>+2 desde ayer</small>
                </div>
                <div class="stat-card warning" onclick="redirectTo('inventario.php')">
                    <div class="stat-label">Repuestos Stock Bajo</div>
                    <div class="stat-value"><?php echo $repuestosStockBajo; ?></div>
                    <small>Necesitan atención</small>
                </div>
                <div class="stat-card success" onclick="redirectTo('ventas_pagos.php')">
                    <div class="stat-label">Ventas del Mes</div>
                    <div class="stat-value"><?php echo formatearMoneda($ventasMes); ?></div>
                    <small>+15% vs mes anterior</small>
                </div>
                <div class="stat-card danger" onclick="redirectTo('herramientas.php')">
                    <div class="stat-label">Herramientas Pendientes</div>
                    <div class="stat-value"><?php echo $herramientasPendientes; ?></div>
                    <small>Por devolver</small>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Quick Actions -->
                    <div class="chart-container">
                        <h3 class="section-title">Acciones Rápidas</h3>
                        <div class="quick-actions">
                            <div class="action-btn" onclick="redirectTo('nueva_orden.php')">
                                <div class="action-icon">🔧</div>
                                <div>Nueva Orden</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('registrar_pago.php')">
                                <div class="action-icon">💰</div>
                                <div>Registrar Pago</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('inventario.php')">
                                <div class="action-icon">📦</div>
                                <div>Consultar Inventario</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('nuevo_cliente.php')">
                                <div class="action-icon">👥</div>
                                <div>Nuevo Cliente</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('prestamo_herramientas.php')">
                                <div class="action-icon">🛠️</div>
                                <div>Préstamo Herramientas</div>
                            </div>
                            <div class="action-btn" onclick="redirectTo('control_calidad.php')">
                                <div class="action-icon">✅</div>
                                <div>Control Calidad</div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Orders -->
                    <div class="table-container" style="margin-top: 20px;">
                        <h3 class="section-title">Órdenes Recientes</h3>
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
                                            <td><span style="color: <?php echo getStatusColor($orden['estado']); ?>;"><?php echo $orden['estado']; ?></span></td>
                                            <td><?php echo $orden['mecanico']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                No hay órdenes recientes
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Notifications -->
                    <div class="table-container">
                        <h3 class="section-title">Notificaciones</h3>
                        <?php if (count($notificaciones) > 0): ?>
                            <?php foreach ($notificaciones as $notif): ?>
                                <?php
                                $iconClass = 'info';
                                $iconText = 'i';
                                
                                if ($notif['tipo'] === 'stock_bajo') {
                                    $iconClass = 'danger';
                                    $iconText = '!';
                                } else if ($notif['tipo'] === 'herramienta_atrasada') {
                                    $iconClass = 'warning';
                                    $iconText = '!';
                                }
                                ?>
                                <div class="notification-item">
                                    <div class="notification-icon <?php echo $iconClass; ?>"><?php echo $iconText; ?></div>
                                    <div>
                                        <strong><?php echo $notif['titulo']; ?></strong>
                                        <div style="font-size: 0.8rem; color: #7f8c8d;"><?php echo $notif['descripcion']; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                No hay notificaciones nuevas
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Low Stock Alert -->
                    <div class="table-container" style="margin-top: 20px;">
                        <h3 class="section-title">Repuestos Stock Bajo</h3>
                        <?php if (count($repuestosBajoStock) > 0): ?>
                            <?php foreach ($repuestosBajoStock as $repuesto): ?>
                                <?php
                                $color = $repuesto['stock_actual'] <= 2 ? 'var(--danger)' : 'var(--warning)';
                                $icon = '📦';
                                if (strpos($repuesto['nombre_repuesto'], 'Batería') !== false) $icon = '🔋';
                                else if (strpos($repuesto['nombre_repuesto'], 'Amortiguador') !== false) $icon = '🛞';
                                else if (strpos($repuesto['nombre_repuesto'], 'Bujía') !== false) $icon = '⚡';
                                else if (strpos($repuesto['nombre_repuesto'], 'Freno') !== false) $icon = '🛑';
                                ?>
                                <div class="notification-item">
                                    <div><?php echo $icon . ' ' . $repuesto['nombre_repuesto']; ?></div>
                                    <div style="margin-left: auto; color: <?php echo $color; ?>;"><?php echo $repuesto['stock_actual']; ?> uds</div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                No hay repuestos con stock bajo
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