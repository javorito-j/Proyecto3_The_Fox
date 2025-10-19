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
function obtenerSeguimientos($conn, $filtro = '') {
    $sql = "
        SELECT 
            spv.*,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            c.nombre as cliente,
            c.telefono,
            c.email,
            u.nombre as gestor,
            DATEDIFF(spv.proximo_contacto, CURDATE()) as dias_restantes
        FROM SeguimientoPostVenta spv
        JOIN Vehiculos v ON spv.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        JOIN Usuarios u ON spv.id_gestor = u.id_usuario
    ";
    
    if ($filtro === 'pendientes') {
        $sql .= " WHERE spv.proximo_contacto >= CURDATE()";
    } elseif ($filtro === 'atrasados') {
        $sql .= " WHERE spv.proximo_contacto < CURDATE()";
    }
    
    $sql .= " ORDER BY spv.proximo_contacto ASC, spv.fecha_contacto DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerVehiculosRecientes($conn) {
    $stmt = $conn->query("
        SELECT 
            v.id_vehiculo,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            c.nombre as cliente,
            c.telefono,
            c.email,
            MAX(os.fecha_salida_real) as ultima_visita
        FROM Vehiculos v
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        JOIN OrdenesServicio os ON v.id_vehiculo = os.id_vehiculo
        WHERE os.estado = 'completado'
        GROUP BY v.id_vehiculo
        ORDER BY ultima_visita DESC
        LIMIT 20
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$filtro = $_GET['filtro'] ?? '';
$seguimientos = obtenerSeguimientos($conn, $filtro);
$vehiculosRecientes = obtenerVehiculosRecientes($conn);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_seguimiento':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO SeguimientoPostVenta (id_vehiculo, id_gestor, tipo_contacto, observaciones, proximo_contacto)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_vehiculo'],
                        $_POST['id_gestor'],
                        $_POST['tipo_contacto'],
                        $_POST['observaciones'],
                        $_POST['proximo_contacto']
                    ]);
                    
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

function getTipoContactoIcon($tipo) {
    switch($tipo) {
        case 'llamada': return 'fas fa-phone';
        case 'email': return 'fas fa-envelope';
        case 'presencial': return 'fas fa-user';
        default: return 'fas fa-question';
    }
}

function getEstadoProximoContacto($diasRestantes) {
    if ($diasRestantes < 0) return ['color' => '#e74c3c', 'text' => 'Atrasado'];
    if ($diasRestantes <= 2) return ['color' => '#f39c12', 'text' => 'Próximo'];
    return ['color' => '#27ae60', 'text' => 'En plazo'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Venta - Servistar</title>
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
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
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
        
        /* Contact Type Badges */
        .contact-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .contact-llamada {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }
        
        .contact-email {
            background: rgba(155, 89, 182, 0.2);
            color: #9b59b6;
        }
        
        .contact-presencial {
            background: rgba(241, 196, 15, 0.2);
            color: #f1c40f;
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
                <li><a href="ordenes_servicio.php"><i class="fas fa-tools"></i> Órdenes de Servicio</a></li>
                <li><a href="clientes.php"><i class="fas fa-users"></i> Clientes</a></li>
                <li><a href="inventario.php"><i class="fas fa-boxes"></i> Inventario</a></li>
                <li><a href="ventas_pagos.php"><i class="fas fa-money-bill-wave"></i> Ventas y Pagos</a></li>
                <li><a href="herramientas.php"><i class="fas fa-wrench"></i> Herramientas</a></li>
                <li><a href="control_calidad.php"><i class="fas fa-check-circle"></i> Control Calidad</a></li>
                <li class="active"><a href="post_venta.php"><i class="fas fa-headset"></i> Post Venta</a></li>
                <li><a href="usuarios.php"><i class="fas fa-user-cog"></i> Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-headset"></i> Seguimiento Post Venta</h1>
                <div class="user-info">
                    <div class="user-avatar">DF</div>
                    <span>Diego Fernández (Gestor)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-value"><?php echo count($seguimientos); ?></div>
                    <div class="stat-label">Total Seguimientos</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($seguimientos, fn($s) => $s['dias_restantes'] >= 0 && $s['dias_restantes'] > 2)); ?></div>
                    <div class="stat-label">En Plazo</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($seguimientos, fn($s) => $s['dias_restantes'] >= 0 && $s['dias_restantes'] <= 2)); ?></div>
                    <div class="stat-label">Próximos</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($seguimientos, fn($s) => $s['dias_restantes'] < 0)); ?></div>
                    <div class="stat-label">Atrasados</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Estado</label>
                    <select class="form-control" id="filterStatus" onchange="filtrarSeguimientos()">
                        <option value="">Todos los seguimientos</option>
                        <option value="pendientes" <?php echo $filtro === 'pendientes' ? 'selected' : ''; ?>>Pendientes</option>
                        <option value="atrasados" <?php echo $filtro === 'atrasados' ? 'selected' : ''; ?>>Atrasados</option>
                    </select>
                </div>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-success" onclick="abrirModalNuevoSeguimiento()">
                        <i class="fas fa-plus"></i> Nuevo Seguimiento
                    </button>
                </div>
            </div>

            <!-- Follow-ups Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Seguimientos de Clientes</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Vehículo</th>
                                <th>Contacto</th>
                                <th>Último Contacto</th>
                                <th>Próximo Contacto</th>
                                <th>Estado</th>
                                <th>Gestor</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($seguimientos) > 0): ?>
                                <?php foreach ($seguimientos as $seguimiento): ?>
                                    <?php 
                                    $estado = getEstadoProximoContacto($seguimiento['dias_restantes']);
                                    $estadoColor = $estado['color'];
                                    $estadoTexto = $estado['text'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($seguimiento['cliente']); ?></strong>
                                                <?php if ($seguimiento['telefono']): ?>
                                                    <br><small><i class="fas fa-phone"></i> <?php echo htmlspecialchars($seguimiento['telefono']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($seguimiento['vehiculo']); ?></td>
                                        <td>
                                            <span class="contact-badge contact-<?php echo $seguimiento['tipo_contacto']; ?>">
                                                <i class="<?php echo getTipoContactoIcon($seguimiento['tipo_contacto']); ?>"></i>
                                                <?php echo ucfirst($seguimiento['tipo_contacto']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($seguimiento['fecha_contacto'])); ?></td>
                                        <td>
                                            <strong><?php echo date('d/m/Y', strtotime($seguimiento['proximo_contacto'])); ?></strong>
                                            <br>
                                            <small style="color: <?php echo $estadoColor; ?>;">
                                                <?php 
                                                if ($seguimiento['dias_restantes'] < 0) {
                                                    echo abs($seguimiento['dias_restantes']) . ' días de retraso';
                                                } elseif ($seguimiento['dias_restantes'] == 0) {
                                                    echo 'Hoy';
                                                } else {
                                                    echo $seguimiento['dias_restantes'] . ' días';
                                                }
                                                ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge" style="background: rgba(<?php echo hexdec(substr($estadoColor, 1, 2)); ?>, <?php echo hexdec(substr($estadoColor, 3, 2)); ?>, <?php echo hexdec(substr($estadoColor, 5, 2)); ?>, 0.2); color: <?php echo $estadoColor; ?>;">
                                                <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                                                <?php echo $estadoTexto; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($seguimiento['gestor']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" onclick="verDetalleSeguimiento(<?php echo $seguimiento['id_seguimiento']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editarSeguimiento(<?php echo $seguimiento['id_seguimiento']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="realizarContacto(<?php echo $seguimiento['id_seguimiento']; ?>)">
                                                    <i class="fas fa-phone"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-headset" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No se encontraron seguimientos</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Vehicles -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-car"></i> Vehículos Recientes (Sin Seguimiento)</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Vehículo</th>
                                <th>Cliente</th>
                                <th>Contacto</th>
                                <th>Última Visita</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($vehiculosRecientes) > 0): ?>
                                <?php foreach ($vehiculosRecientes as $vehiculo): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($vehiculo['vehiculo']); ?></td>
                                        <td><?php echo htmlspecialchars($vehiculo['cliente']); ?></td>
                                        <td>
                                            <div>
                                                <?php if ($vehiculo['telefono']): ?>
                                                    <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($vehiculo['telefono']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($vehiculo['email']): ?>
                                                    <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($vehiculo['email']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($vehiculo['ultima_visita'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="crearSeguimientoVehiculo(<?php echo $vehiculo['id_vehiculo']; ?>)">
                                                <i class="fas fa-headset"></i> Seguimiento
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay vehículos recientes sin seguimiento</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Seguimiento -->
    <div class="modal" id="modalNuevoSeguimiento">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoSeguimiento')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-headset"></i> Nuevo Seguimiento</h2>
            
            <form id="formNuevoSeguimiento">
                <input type="hidden" name="id_gestor" value="7"> <!-- ID del gestor actual -->
                
                <div class="form-group">
                    <label><i class="fas fa-car"></i> Vehículo</label>
                    <select class="form-control" name="id_vehiculo" required id="selectVehiculo">
                        <option value="">Seleccionar vehículo...</option>
                        <?php foreach ($vehiculosRecientes as $vehiculo): ?>
                            <option value="<?php echo $vehiculo['id_vehiculo']; ?>">
                                <?php echo htmlspecialchars($vehiculo['vehiculo'] . ' - ' . $vehiculo['cliente']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-comments"></i> Tipo de Contacto</label>
                        <select class="form-control" name="tipo_contacto" required>
                            <option value="">Seleccionar tipo...</option>
                            <option value="llamada">Llamada</option>
                            <option value="email">Email</option>
                            <option value="presencial">Presencial</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Próximo Contacto</label>
                        <input type="date" class="form-control" name="proximo_contacto" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Observaciones</label>
                    <textarea class="form-control" name="observaciones" placeholder="Observaciones del contacto..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoSeguimiento')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Seguimiento
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de filtrado
        function filtrarSeguimientos() {
            const status = document.getElementById('filterStatus').value;
            const url = new URL(window.location);
            if (status) {
                url.searchParams.set('filtro', status);
            } else {
                url.searchParams.delete('filtro');
            }
            window.location.href = url.toString();
        }

        // Funciones de modal
        function abrirModalNuevoSeguimiento() {
            document.getElementById('modalNuevoSeguimiento').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function crearSeguimientoVehiculo(idVehiculo) {
            document.getElementById('selectVehiculo').value = idVehiculo;
            document.getElementById('modalNuevoSeguimiento').style.display = 'block';
        }

        function verDetalleSeguimiento(idSeguimiento) {
            alert('Ver detalle del seguimiento ' + idSeguimiento + ' - Funcionalidad en desarrollo');
        }

        function editarSeguimiento(idSeguimiento) {
            alert('Editar seguimiento ' + idSeguimiento + ' - Funcionalidad en desarrollo');
        }

        function realizarContacto(idSeguimiento) {
            alert('Realizar contacto para seguimiento ' + idSeguimiento + ' - Funcionalidad en desarrollo');
        }

        // Formulario nuevo seguimiento
        document.getElementById('formNuevoSeguimiento').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_seguimiento');
            
            fetch('post_venta.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Seguimiento creado exitosamente!');
                    cerrarModal('modalNuevoSeguimiento');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear el seguimiento');
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

        // Establecer fecha mínima para próximo contacto (hoy)
        document.querySelector('input[name="proximo_contacto"]').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
<?php
// Cerrar conexión
$conn = null;
?>