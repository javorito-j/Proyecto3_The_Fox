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
function obtenerHerramientas($conn, $estado = '') {
    $sql = "SELECT * FROM Herramientas";
    
    if ($estado) {
        $sql .= " WHERE estado = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$estado]);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerPrestamosActivos($conn) {
    $stmt = $conn->query("
        SELECT ph.*, h.nombre_herramienta, u.nombre as mecanico, i.nombre as inspector
        FROM PrestamosHerramientas ph
        JOIN Herramientas h ON ph.id_herramienta = h.id_herramienta
        JOIN Usuarios u ON ph.id_mecanico = u.id_usuario
        JOIN Usuarios i ON ph.id_inspector = i.id_usuario
        WHERE ph.estado = 'prestado'
        ORDER BY ph.fecha_prestamo DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerMecanicos($conn) {
    $stmt = $conn->query("SELECT id_usuario, nombre FROM Usuarios WHERE id_rol = 2 AND estado = 'activo'");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerInspectores($conn) {
    $stmt = $conn->query("SELECT id_usuario, nombre FROM Usuarios WHERE id_rol = 5 AND estado = 'activo'");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$estado = $_GET['estado'] ?? '';
$herramientas = obtenerHerramientas($conn, $estado);
$prestamos = obtenerPrestamosActivos($conn);
$mecanicos = obtenerMecanicos($conn);
$inspectores = obtenerInspectores($conn);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_herramienta':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO Herramientas (codigo_herramienta, nombre_herramienta, descripcion)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['codigo_herramienta'],
                        $_POST['nombre_herramienta'],
                        $_POST['descripcion']
                    ]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'prestar_herramienta':
                try {
                    // Actualizar estado de herramienta
                    $stmt = $conn->prepare("UPDATE Herramientas SET estado = 'prestada' WHERE id_herramienta = ?");
                    $stmt->execute([$_POST['id_herramienta']]);
                    
                    // Crear préstamo
                    $stmt = $conn->prepare("
                        INSERT INTO PrestamosHerramientas (id_herramienta, id_mecanico, id_inspector, fecha_devolucion_estimada)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_herramienta'],
                        $_POST['id_mecanico'],
                        $_POST['id_inspector'],
                        $_POST['fecha_devolucion_estimada']
                    ]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'devolver_herramienta':
                try {
                    // Actualizar estado de herramienta
                    $stmt = $conn->prepare("UPDATE Herramientas SET estado = 'disponible' WHERE id_herramienta = ?");
                    $stmt->execute([$_POST['id_herramienta']]);
                    
                    // Actualizar préstamo
                    $stmt = $conn->prepare("
                        UPDATE PrestamosHerramientas 
                        SET estado = 'devuelto', fecha_devolucion_real = NOW()
                        WHERE id_prestamo = ?
                    ");
                    $stmt->execute([$_POST['id_prestamo']]);
                    
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

function getEstadoColor($estado) {
    switch($estado) {
        case 'disponible': return '#27ae60';
        case 'prestada': return '#f39c12';
        case 'mantenimiento': return '#e74c3c';
        default: return '#95a5a6';
    }
}

function getEstadoIcon($estado) {
    switch($estado) {
        case 'disponible': return 'fas fa-check-circle';
        case 'prestada': return 'fas fa-hand-holding';
        case 'mantenimiento': return 'fas fa-tools';
        default: return 'fas fa-question';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Herramientas - Servistar</title>
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
        
        .status-disponible {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
        }
        
        .status-prestada {
            background: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }
        
        .status-mantenimiento {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
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
            min-height: 80px;
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
                <li class="active"><a href="herramientas.php"><i class="fas fa-wrench"></i> Herramientas</a></li>
                <li><a href="control_calidad.php"><i class="fas fa-check-circle"></i> Control Calidad</a></li>
                <li><a href="post_venta.php"><i class="fas fa-headset"></i> Post Venta</a></li>
                <li><a href="usuarios.php"><i class="fas fa-user-cog"></i> Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-wrench"></i> Gestión de Herramientas</h1>
                <div class="user-info">
                    <div class="user-avatar">LM</div>
                    <span>Laura Martínez (Inspectora)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-wrench"></i></div>
                    <div class="stat-value"><?php echo count($herramientas); ?></div>
                    <div class="stat-label">Total Herramientas</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($herramientas, fn($h) => $h['estado'] === 'disponible')); ?></div>
                    <div class="stat-label">Disponibles</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-hand-holding"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($herramientas, fn($h) => $h['estado'] === 'prestada')); ?></div>
                    <div class="stat-label">Prestadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-tools"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($herramientas, fn($h) => $h['estado'] === 'mantenimiento')); ?></div>
                    <div class="stat-label">En Mantenimiento</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Estado</label>
                    <select class="form-control" id="filterStatus" onchange="filtrarHerramientas()">
                        <option value="">Todos los estados</option>
                        <option value="disponible" <?php echo $estado === 'disponible' ? 'selected' : ''; ?>>Disponibles</option>
                        <option value="prestada" <?php echo $estado === 'prestada' ? 'selected' : ''; ?>>Prestadas</option>
                        <option value="mantenimiento" <?php echo $estado === 'mantenimiento' ? 'selected' : ''; ?>>En Mantenimiento</option>
                    </select>
                </div>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-success" onclick="abrirModalNuevaHerramienta()">
                        <i class="fas fa-plus"></i> Nueva Herramienta
                    </button>
                    <button class="btn btn-primary" onclick="abrirModalPrestamo()">
                        <i class="fas fa-hand-holding"></i> Nuevo Préstamo
                    </button>
                </div>
            </div>

            <!-- Tools Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Lista de Herramientas</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Herramienta</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($herramientas) > 0): ?>
                                <?php foreach ($herramientas as $herramienta): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($herramienta['codigo_herramienta']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($herramienta['nombre_herramienta']); ?></td>
                                        <td><?php echo $herramienta['descripcion'] ? htmlspecialchars($herramienta['descripcion']) : '-'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $herramienta['estado']; ?>">
                                                <i class="<?php echo getEstadoIcon($herramienta['estado']); ?>"></i>
                                                <?php echo ucfirst($herramienta['estado']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($herramienta['estado'] === 'disponible'): ?>
                                                    <button class="btn btn-sm btn-primary" onclick="prestarHerramienta(<?php echo $herramienta['id_herramienta']; ?>)">
                                                        <i class="fas fa-hand-holding"></i> Prestar
                                                    </button>
                                                <?php elseif ($herramienta['estado'] === 'prestada'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="devolverHerramienta(<?php echo $herramienta['id_herramienta']; ?>)">
                                                        <i class="fas fa-undo"></i> Devolver
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-warning" onclick="editarHerramienta(<?php echo $herramienta['id_herramienta']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-wrench" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No se encontraron herramientas</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Active Loans -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-hand-holding"></i> Préstamos Activos</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Herramienta</th>
                                <th>Mecánico</th>
                                <th>Inspector</th>
                                <th>Fecha Préstamo</th>
                                <th>Fecha Estimada Devolución</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($prestamos) > 0): ?>
                                <?php foreach ($prestamos as $prestamo): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($prestamo['nombre_herramienta']); ?></td>
                                        <td><?php echo htmlspecialchars($prestamo['mecanico']); ?></td>
                                        <td><?php echo htmlspecialchars($prestamo['inspector']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($prestamo['fecha_prestamo'])); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($prestamo['fecha_devolucion_estimada'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="devolverHerramientaPrestamo(<?php echo $prestamo['id_prestamo']; ?>, <?php echo $prestamo['id_herramienta']; ?>)">
                                                <i class="fas fa-undo"></i> Devolver
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay préstamos activos</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nueva Herramienta -->
    <div class="modal" id="modalNuevaHerramienta">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevaHerramienta')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-plus-circle"></i> Nueva Herramienta</h2>
            
            <form id="formNuevaHerramienta">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Código Herramienta</label>
                        <input type="text" class="form-control" name="codigo_herramienta" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-wrench"></i> Nombre de la Herramienta</label>
                        <input type="text" class="form-control" name="nombre_herramienta" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Descripción</label>
                    <textarea class="form-control" name="descripcion" placeholder="Descripción de la herramienta..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevaHerramienta')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Herramienta
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Préstamo -->
    <div class="modal" id="modalPrestamo">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalPrestamo')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-hand-holding"></i> Nuevo Préstamo</h2>
            
            <form id="formPrestamo">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-wrench"></i> Herramienta</label>
                        <select class="form-control" name="id_herramienta" required>
                            <option value="">Seleccionar herramienta...</option>
                            <?php foreach (array_filter($herramientas, fn($h) => $h['estado'] === 'disponible') as $herramienta): ?>
                                <option value="<?php echo $herramienta['id_herramienta']; ?>">
                                    <?php echo htmlspecialchars($herramienta['codigo_herramienta'] . ' - ' . $herramienta['nombre_herramienta']); ?>
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
                                    <?php echo htmlspecialchars($mecanico['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user-shield"></i> Inspector</label>
                        <select class="form-control" name="id_inspector" required>
                            <option value="">Seleccionar inspector...</option>
                            <?php foreach ($inspectores as $inspector): ?>
                                <option value="<?php echo $inspector['id_usuario']; ?>">
                                    <?php echo htmlspecialchars($inspector['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Fecha Estimada Devolución</label>
                        <input type="date" class="form-control" name="fecha_devolucion_estimada" required>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalPrestamo')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-hand-holding"></i> Registrar Préstamo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de filtrado
        function filtrarHerramientas() {
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
        function abrirModalNuevaHerramienta() {
            document.getElementById('modalNuevaHerramienta').style.display = 'block';
        }

        function abrirModalPrestamo() {
            document.getElementById('modalPrestamo').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function prestarHerramienta(idHerramienta) {
            document.querySelector('#formPrestamo select[name="id_herramienta"]').value = idHerramienta;
            abrirModalPrestamo();
        }

        function devolverHerramienta(idHerramienta) {
            if (confirm('¿Estás seguro de que quieres marcar esta herramienta como devuelta?')) {
                const formData = new FormData();
                formData.append('action', 'devolver_herramienta');
                formData.append('id_herramienta', idHerramienta);
                
                fetch('herramientas.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Herramienta devuelta exitosamente!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al devolver la herramienta');
                });
            }
        }

        function devolverHerramientaPrestamo(idPrestamo, idHerramienta) {
            if (confirm('¿Estás seguro de que quieres marcar esta herramienta como devuelta?')) {
                const formData = new FormData();
                formData.append('action', 'devolver_herramienta');
                formData.append('id_prestamo', idPrestamo);
                formData.append('id_herramienta', idHerramienta);
                
                fetch('herramientas.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Herramienta devuelta exitosamente!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al devolver la herramienta');
                });
            }
        }

        function editarHerramienta(idHerramienta) {
            alert('Editar herramienta ' + idHerramienta + ' - Funcionalidad en desarrollo');
        }

        // Formulario nueva herramienta
        document.getElementById('formNuevaHerramienta').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_herramienta');
            
            fetch('herramientas.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Herramienta creada exitosamente!');
                    cerrarModal('modalNuevaHerramienta');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear la herramienta');
            });
        });

        // Formulario préstamo
        document.getElementById('formPrestamo').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'prestar_herramienta');
            
            fetch('herramientas.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Préstamo registrado exitosamente!');
                    cerrarModal('modalPrestamo');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al registrar el préstamo');
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