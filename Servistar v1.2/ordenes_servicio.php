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
function obtenerOrdenesServicio($conn, $filtro_estado = '') {
    $sql = "
        SELECT 
            os.id_orden,
            os.estado,
            os.fecha_entrada,
            os.fecha_salida_estimada,
            os.fecha_salida_real,
            os.descripcion_problema,
            CONCAT('OS-', YEAR(os.fecha_entrada), '-', LPAD(os.id_orden, 4, '0')) as numero_orden,
            c.nombre as cliente,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            u.nombre as mecanico,
            (SELECT COUNT(*) FROM ControlCalidad cc WHERE cc.id_orden = os.id_orden) as tiene_control_calidad
        FROM OrdenesServicio os
        JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        JOIN Usuarios u ON os.id_mecanico = u.id_usuario
    ";
    
    if ($filtro_estado) {
        $sql .= " WHERE os.estado = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$filtro_estado]);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerMecanicos($conn) {
    $stmt = $conn->query("
        SELECT id_usuario, nombre 
        FROM Usuarios 
        WHERE id_rol = 2 AND estado = 'activo'
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerVehiculos($conn) {
    $stmt = $conn->query("
        SELECT v.id_vehiculo, v.placa, v.marca, v.modelo, c.nombre as cliente
        FROM Vehiculos v
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        ORDER BY c.nombre, v.placa
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerRepuestos($conn) {
    $stmt = $conn->query("
        SELECT id_repuesto, nombre_repuesto, precio_unitario, stock_actual
        FROM Repuestos 
        WHERE estado = 'activo' AND stock_actual > 0
        ORDER BY nombre_repuesto
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerRepuestosPorOrden($conn, $id_orden) {
    $stmt = $conn->prepare("
        SELECT cr.*, r.nombre_repuesto, r.precio_unitario
        FROM ConsumoRepuestos cr
        JOIN Repuestos r ON cr.id_repuesto = r.id_repuesto
        WHERE cr.id_orden = ?
    ");
    $stmt->execute([$id_orden]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$mecanicos = obtenerMecanicos($conn);
$vehiculos = obtenerVehiculos($conn);
$repuestos = obtenerRepuestos($conn);

// Procesar filtros
$filtro_estado = $_GET['estado'] ?? '';
$ordenes = obtenerOrdenesServicio($conn, $filtro_estado);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_orden':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO OrdenesServicio (id_vehiculo, id_mecanico, descripcion_problema, fecha_salida_estimada)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_vehiculo'],
                        $_POST['id_mecanico'],
                        $_POST['descripcion_problema'],
                        $_POST['fecha_salida_estimada']
                    ]);
                    $id_orden = $conn->lastInsertId();
                    
                    echo json_encode(['success' => true, 'id_orden' => $id_orden]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'actualizar_estado':
                try {
                    $stmt = $conn->prepare("
                        UPDATE OrdenesServicio 
                        SET estado = ?, fecha_salida_real = ?
                        WHERE id_orden = ?
                    ");
                    $fecha_salida = ($_POST['estado'] == 'completado') ? date('Y-m-d H:i:s') : null;
                    $stmt->execute([$_POST['estado'], $fecha_salida, $_POST['id_orden']]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'agregar_repuesto':
                try {
                    // Verificar stock
                    $stmt = $conn->prepare("SELECT stock_actual FROM Repuestos WHERE id_repuesto = ?");
                    $stmt->execute([$_POST['id_repuesto']]);
                    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($stock['stock_actual'] < $_POST['cantidad']) {
                        echo json_encode(['success' => false, 'error' => 'Stock insuficiente']);
                        exit;
                    }
                    
                    // Insertar consumo
                    $stmt = $conn->prepare("
                        INSERT INTO ConsumoRepuestos (id_orden, id_repuesto, id_mecanico, cantidad)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_orden'],
                        $_POST['id_repuesto'],
                        $_POST['id_mecanico'],
                        $_POST['cantidad']
                    ]);
                    
                    // Actualizar stock
                    $stmt = $conn->prepare("
                        UPDATE Repuestos 
                        SET stock_actual = stock_actual - ? 
                        WHERE id_repuesto = ?
                    ");
                    $stmt->execute([$_POST['cantidad'], $_POST['id_repuesto']]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
        }
    }
}

function getStatusColor($estado) {
    switch($estado) {
        case 'pendiente': return '#e74c3c';
        case 'en_proceso': return '#f39c12';
        case 'completado': return '#27ae60';
        case 'entregado': return '#3498db';
        default: return '#95a5a6';
    }
}

function getStatusIcon($estado) {
    switch($estado) {
        case 'pendiente': return 'fas fa-clock';
        case 'en_proceso': return 'fas fa-tools';
        case 'completado': return 'fas fa-check-circle';
        case 'entregado': return 'fas fa-car';
        default: return 'fas fa-question';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Órdenes de Servicio - Servistar</title>
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
        
        .container {
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-left: 4px solid var(--secondary);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
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
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        /* Filters */
        .filters {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        .form-control {
            padding: 10px;
            border: 1px solid var(--light);
            border-radius: 5px;
            background: var(--dark);
            color: var(--text);
            min-width: 150px;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--secondary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        /* Tables */
        .table-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 20px;
        }
        
        .table-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }
        
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
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-pending {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        
        .status-proceso {
            background: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }
        
        .status-completado {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
        }
        
        .status-entregado {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: var(--card-bg);
            margin: 50px auto;
            padding: 25px;
            border-radius: 10px;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }
        
        .modal-title {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text);
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--light);
            border-radius: 5px;
            background: var(--dark);
            color: var(--text);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <h2><i class="fas fa-car"></i> Servistar</h2>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="active"><a href="ordenes_servicio.php"><i class="fas fa-tools"></i> Órdenes de Servicio</a></li>
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
                <h1><i class="fas fa-tools"></i> Órdenes de Servicio</h1>
                <div class="user-info">
                    <div class="user-avatar">JP</div>
                    <span>Juan Pérez (Mecánico)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($ordenes, fn($o) => $o['estado'] === 'pendiente')); ?></div>
                    <div class="stat-label">Pendientes</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-tools"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($ordenes, fn($o) => $o['estado'] === 'en_proceso')); ?></div>
                    <div class="stat-label">En Proceso</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($ordenes, fn($o) => $o['estado'] === 'completado')); ?></div>
                    <div class="stat-label">Completadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-car"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($ordenes, fn($o) => $o['estado'] === 'entregado')); ?></div>
                    <div class="stat-label">Entregadas</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Estado</label>
                    <select class="form-control" id="filterStatus" onchange="filtrarOrdenes()">
                        <option value="">Todos los estados</option>
                        <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                        <option value="en_proceso" <?php echo $filtro_estado === 'en_proceso' ? 'selected' : ''; ?>>En Proceso</option>
                        <option value="completado" <?php echo $filtro_estado === 'completado' ? 'selected' : ''; ?>>Completados</option>
                        <option value="entregado" <?php echo $filtro_estado === 'entregado' ? 'selected' : ''; ?>>Entregados</option>
                    </select>
                </div>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-primary" onclick="abrirModalNuevaOrden()">
                        <i class="fas fa-plus"></i> Nueva Orden
                    </button>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Lista de Órdenes de Servicio</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Orden #</th>
                                <th>Vehículo</th>
                                <th>Cliente</th>
                                <th>Mecánico</th>
                                <th>Fecha Entrada</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ordenes) > 0): ?>
                                <?php foreach ($ordenes as $orden): ?>
                                    <tr>
                                        <td><strong><?php echo $orden['numero_orden']; ?></strong></td>
                                        <td><?php echo $orden['vehiculo']; ?></td>
                                        <td><?php echo $orden['cliente']; ?></td>
                                        <td><?php echo $orden['mecanico']; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($orden['fecha_entrada'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $orden['estado']; ?>">
                                                <i class="<?php echo getStatusIcon($orden['estado']); ?>"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $orden['estado'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" onclick="verDetalleOrden(<?php echo $orden['id_orden']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editarOrden(<?php echo $orden['id_orden']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($orden['estado'] !== 'entregado'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="cambiarEstado(<?php echo $orden['id_orden']; ?>)">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay órdenes de servicio registradas</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nueva Orden -->
    <div class="modal" id="modalNuevaOrden">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevaOrden')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-plus-circle"></i> Nueva Orden de Servicio</h2>
            
            <form id="formNuevaOrden">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Vehículo</label>
                        <select class="form-control" name="id_vehiculo" required>
                            <option value="">Seleccionar vehículo...</option>
                            <?php foreach ($vehiculos as $vehiculo): ?>
                                <option value="<?php echo $vehiculo['id_vehiculo']; ?>">
                                    <?php echo $vehiculo['placa'] . ' - ' . $vehiculo['marca'] . ' ' . $vehiculo['modelo'] . ' (' . $vehiculo['cliente'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Mecánico</label>
                        <select class="form-control" name="id_mecanico" required>
                            <option value="">Seleccionar mecánico...</option>
                            <?php foreach ($mecanicos as $mecanico): ?>
                                <option value="<?php echo $mecanico['id_usuario']; ?>">
                                    <?php echo $mecanico['nombre']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Fecha Estimada de Salida</label>
                    <input type="date" class="form-control" name="fecha_salida_estimada" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Descripción del Problema</label>
                    <textarea class="form-control" name="descripcion_problema" required placeholder="Describa el problema reportado por el cliente..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevaOrden')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Crear Orden
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de filtrado
        function filtrarOrdenes() {
            const status = document.getElementById('filterStatus').value;
            const url = new URL(window.location);
            if (status) {
                url.searchParams.set('estado', status);
            } else {
                url.searchParams.delete('estado');
            }
            window.location.href = url.toString();
        }

        // Funciones de modal
        function abrirModalNuevaOrden() {
            document.getElementById('modalNuevaOrden').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function verDetalleOrden(idOrden) {
            alert('Detalle de orden ' + idOrden + ' - Funcionalidad en desarrollo');
        }

        function editarOrden(idOrden) {
            alert('Editar orden ' + idOrden + ' - Funcionalidad en desarrollo');
        }

        function cambiarEstado(idOrden) {
            alert('Cambiar estado de orden ' + idOrden + ' - Funcionalidad en desarrollo');
        }

        // Formulario nueva orden
        document.getElementById('formNuevaOrden').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_orden');
            
            fetch('ordenes_servicio.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Orden creada exitosamente! ID: ' + data.id_orden);
                    cerrarModal('modalNuevaOrden');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear la orden');
            });
        });

        // Cerrar modal al hacer click fuera
        window.onclick = function(event) {
            document.querySelectorAll('.modal').forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
<?php
// Cerrar conexión
$conn = null;
?>