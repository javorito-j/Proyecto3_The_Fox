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
function obtenerControlesCalidad($conn, $filtro = '') {
    $sql = "
        SELECT 
            cc.*,
            os.id_orden,
            CONCAT('OS-', YEAR(os.fecha_entrada), '-', LPAD(os.id_orden, 4, '0')) as numero_orden,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            c.nombre as cliente,
            u.nombre as inspector,
            COUNT(cl.id_checklist) as total_items,
            SUM(CASE WHEN cl.cumplido = 1 THEN 1 ELSE 0 END) as items_cumplidos
        FROM ControlCalidad cc
        JOIN OrdenesServicio os ON cc.id_orden = os.id_orden
        JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        JOIN Usuarios u ON cc.id_inspector = u.id_usuario
        LEFT JOIN ChecklistCalidad cl ON cc.id_control = cl.id_control
    ";
    
    if ($filtro) {
        $sql .= " WHERE cc.resultado = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$filtro]);
    } else {
        $sql .= " GROUP BY cc.id_control ORDER BY cc.fecha_verificacion DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerOrdenesPendientesVerificacion($conn) {
    $stmt = $conn->query("
        SELECT 
            os.id_orden,
            CONCAT('OS-', YEAR(os.fecha_entrada), '-', LPAD(os.id_orden, 4, '0')) as numero_orden,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            c.nombre as cliente,
            os.fecha_entrada
        FROM OrdenesServicio os
        JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        WHERE os.estado = 'completado' 
        AND os.id_orden NOT IN (SELECT id_orden FROM ControlCalidad)
        ORDER BY os.fecha_entrada DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerChecklistBase() {
    return [
        'Verificación de torque en tuercas y tornillos',
        'Prueba de funcionamiento de frenos',
        'Verificación de niveles de fluidos',
        'Inspección de sistema de suspensión',
        'Prueba de alineación y balanceo',
        'Verificación de sistema eléctrico',
        'Prueba de funcionamiento de motor',
        'Inspección de sistema de escape',
        'Verificación de luces y señalización',
        'Prueba de climatización y ventilación'
    ];
}

// Obtener datos
$filtro = $_GET['filtro'] ?? '';
$controles = obtenerControlesCalidad($conn, $filtro);
$ordenesPendientes = obtenerOrdenesPendientesVerificacion($conn);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_control':
                try {
                    $conn->beginTransaction();
                    
                    // Crear control de calidad
                    $stmt = $conn->prepare("
                        INSERT INTO ControlCalidad (id_orden, id_inspector, resultado, observaciones)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_orden'],
                        $_POST['id_inspector'],
                        $_POST['resultado'],
                        $_POST['observaciones']
                    ]);
                    $id_control = $conn->lastInsertId();
                    
                    // Insertar items del checklist
                    if (isset($_POST['checklist_items'])) {
                        foreach ($_POST['checklist_items'] as $item) {
                            $stmt = $conn->prepare("
                                INSERT INTO ChecklistCalidad (id_control, item, cumplido, observaciones)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $id_control,
                                $item['item'],
                                $item['cumplido'] ? 1 : 0,
                                $item['observaciones'] ?? ''
                            ]);
                        }
                    }
                    
                    $conn->commit();
                    echo json_encode(['success' => true, 'id_control' => $id_control]);
                    exit;
                } catch (PDOException $e) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
        }
    }
}

function getResultadoColor($resultado) {
    switch($resultado) {
        case 'aprobado': return '#27ae60';
        case 'requiere_correccion': return '#e74c3c';
        default: return '#95a5a6';
    }
}

function getResultadoIcon($resultado) {
    switch($resultado) {
        case 'aprobado': return 'fas fa-check-circle';
        case 'requiere_correccion': return 'fas fa-exclamation-triangle';
        default: return 'fas fa-question';
    }
}

function calcularPorcentajeCumplimiento($total, $cumplidos) {
    if ($total == 0) return 0;
    return round(($cumplidos / $total) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Calidad - Servistar</title>
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
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
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
            justify-content: space-between;
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
        
        .status-aprobado {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
        }
        
        .status-correccion {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        
        /* Progress Bars */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-success {
            background: var(--success);
        }
        
        .progress-warning {
            background: var(--warning);
        }
        
        .progress-danger {
            background: var(--danger);
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
            max-width: 800px;
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
            min-height: 80px;
            resize: vertical;
        }
        
        /* Checklist Items */
        .checklist-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid var(--light);
            border-radius: 5px;
            margin-bottom: 10px;
            background: var(--dark);
        }
        
        .checklist-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        .checklist-text {
            flex: 1;
        }
        
        .checklist-observaciones {
            flex: 2;
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
                <li><a href="ordenes_servicio.php"><i class="fas fa-tools"></i> Órdenes de Servicio</a></li>
                <li><a href="clientes.php"><i class="fas fa-users"></i> Clientes</a></li>
                <li><a href="inventario.php"><i class="fas fa-boxes"></i> Inventario</a></li>
                <li><a href="ventas_pagos.php"><i class="fas fa-money-bill-wave"></i> Ventas y Pagos</a></li>
                <li><a href="herramientas.php"><i class="fas fa-wrench"></i> Herramientas</a></li>
                <li class="active"><a href="control_calidad.php"><i class="fas fa-check-circle"></i> Control Calidad</a></li>
                <li><a href="post_venta.php"><i class="fas fa-headset"></i> Post Venta</a></li>
                <li><a href="usuarios.php"><i class="fas fa-user-cog"></i> Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-check-circle"></i> Control de Calidad</h1>
                <div class="user-info">
                    <div class="user-avatar">RS</div>
                    <span>Roberto Silva (Inspector)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                    <div class="stat-value"><?php echo count($controles); ?></div>
                    <div class="stat-label">Total Verificaciones</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($controles, fn($c) => $c['resultado'] === 'aprobado')); ?></div>
                    <div class="stat-label">Aprobados</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($controles, fn($c) => $c['resultado'] === 'requiere_correccion')); ?></div>
                    <div class="stat-label">Requieren Corrección</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo count($ordenesPendientes); ?></div>
                    <div class="stat-label">Pendientes de Verificación</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Resultado</label>
                    <select class="form-control" id="filterResult" onchange="filtrarControles()">
                        <option value="">Todos los resultados</option>
                        <option value="aprobado" <?php echo $filtro === 'aprobado' ? 'selected' : ''; ?>>Aprobados</option>
                        <option value="requiere_correccion" <?php echo $filtro === 'requiere_correccion' ? 'selected' : ''; ?>>Requieren Corrección</option>
                    </select>
                </div>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-success" onclick="abrirModalNuevoControl()">
                        <i class="fas fa-plus"></i> Nueva Verificación
                    </button>
                </div>
            </div>

            <!-- Quality Controls Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Verificaciones de Calidad</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Orden #</th>
                                <th>Vehículo</th>
                                <th>Cliente</th>
                                <th>Inspector</th>
                                <th>Fecha</th>
                                <th>Checklist</th>
                                <th>Resultado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($controles) > 0): ?>
                                <?php foreach ($controles as $control): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($control['numero_orden']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($control['vehiculo']); ?></td>
                                        <td><?php echo htmlspecialchars($control['cliente']); ?></td>
                                        <td><?php echo htmlspecialchars($control['inspector']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($control['fecha_verificacion'])); ?></td>
                                        <td>
                                            <div>
                                                <small><?php echo $control['items_cumplidos']; ?> de <?php echo $control['total_items']; ?> items</small>
                                                <div class="progress-bar">
                                                    <?php 
                                                    $porcentaje = calcularPorcentajeCumplimiento($control['total_items'], $control['items_cumplidos']);
                                                    $progressClass = $porcentaje >= 80 ? 'progress-success' : ($porcentaje >= 60 ? 'progress-warning' : 'progress-danger');
                                                    ?>
                                                    <div class="progress-fill <?php echo $progressClass; ?>" style="width: <?php echo $porcentaje; ?>%"></div>
                                                </div>
                                                <small><?php echo $porcentaje; ?>% cumplimiento</small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $control['resultado']; ?>">
                                                <i class="<?php echo getResultadoIcon($control['resultado']); ?>"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $control['resultado'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" onclick="verDetalleControl(<?php echo $control['id_control']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editarControl(<?php echo $control['id_control']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-clipboard-check" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No se encontraron verificaciones de calidad</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pending Verifications -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-clock"></i> Órdenes Pendientes de Verificación</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Orden #</th>
                                <th>Vehículo</th>
                                <th>Cliente</th>
                                <th>Fecha Entrada</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ordenesPendientes) > 0): ?>
                                <?php foreach ($ordenesPendientes as $orden): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($orden['numero_orden']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($orden['vehiculo']); ?></td>
                                        <td><?php echo htmlspecialchars($orden['cliente']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($orden['fecha_entrada'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="crearControlCalidad(<?php echo $orden['id_orden']; ?>)">
                                                <i class="fas fa-clipboard-check"></i> Verificar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay órdenes pendientes de verificación</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Control -->
    <div class="modal" id="modalNuevoControl">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoControl')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-clipboard-check"></i> Nueva Verificación de Calidad</h2>
            
            <form id="formNuevoControl">
                <input type="hidden" name="id_inspector" value="5"> <!-- ID del inspector actual -->
                
                <div class="form-group">
                    <label><i class="fas fa-tools"></i> Orden de Servicio</label>
                    <select class="form-control" name="id_orden" required id="selectOrden">
                        <option value="">Seleccionar orden...</option>
                        <?php foreach ($ordenesPendientes as $orden): ?>
                            <option value="<?php echo $orden['id_orden']; ?>">
                                <?php echo htmlspecialchars($orden['numero_orden'] . ' - ' . $orden['vehiculo'] . ' (' . $orden['cliente'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-clipboard-list"></i> Checklist de Verificación</label>
                    <div id="checklistContainer">
                        <!-- Checklist items se generan dinámicamente -->
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-flag"></i> Resultado</label>
                        <select class="form-control" name="resultado" required>
                            <option value="">Seleccionar resultado...</option>
                            <option value="aprobado">Aprobado</option>
                            <option value="requiere_correccion">Requiere Corrección</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Observaciones Generales</label>
                    <textarea class="form-control" name="observaciones" placeholder="Observaciones adicionales..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoControl')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Verificación
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de filtrado
        function filtrarControles() {
            const result = document.getElementById('filterResult').value;
            const url = new URL(window.location);
            if (result) {
                url.searchParams.set('filtro', result);
            } else {
                url.searchParams.delete('filtro');
            }
            window.location.href = url.toString();
        }

        // Funciones de modal
        function abrirModalNuevoControl() {
            generarChecklist();
            document.getElementById('modalNuevoControl').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function crearControlCalidad(idOrden) {
            document.getElementById('selectOrden').value = idOrden;
            generarChecklist();
            document.getElementById('modalNuevoControl').style.display = 'block';
        }

        function verDetalleControl(idControl) {
            alert('Ver detalle del control ' + idControl + ' - Funcionalidad en desarrollo');
        }

        function editarControl(idControl) {
            alert('Editar control ' + idControl + ' - Funcionalidad en desarrollo');
        }

        // Generar checklist dinámico
        function generarChecklist() {
            const checklistContainer = document.getElementById('checklistContainer');
            const checklistItems = [
                'Verificación de torque en tuercas y tornillos',
                'Prueba de funcionamiento de frenos',
                'Verificación de niveles de fluidos',
                'Inspección de sistema de suspensión',
                'Prueba de alineación y balanceo',
                'Verificación de sistema eléctrico',
                'Prueba de funcionamiento de motor',
                'Inspección de sistema de escape',
                'Verificación de luces y señalización',
                'Prueba de climatización y ventilación'
            ];
            
            let html = '';
            checklistItems.forEach((item, index) => {
                html += `
                    <div class="checklist-item">
                        <input type="checkbox" name="checklist_items[${index}][cumplido]" value="1">
                        <div class="checklist-text">${item}</div>
                        <input type="hidden" name="checklist_items[${index}][item]" value="${item}">
                        <input type="text" class="form-control checklist-observaciones" 
                               name="checklist_items[${index}][observaciones]" 
                               placeholder="Observaciones...">
                    </div>
                `;
            });
            
            checklistContainer.innerHTML = html;
        }

        // Formulario nuevo control
        document.getElementById('formNuevoControl').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_control');
            
            fetch('control_calidad.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Verificación de calidad creada exitosamente!');
                    cerrarModal('modalNuevoControl');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear la verificación');
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