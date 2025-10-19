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
function obtenerUsuarios($conn, $rol = '') {
    $sql = "
        SELECT 
            u.*,
            r.nombre_rol
        FROM Usuarios u
        JOIN Roles r ON u.id_rol = r.id_rol
        WHERE u.estado = 'activo'
    ";
    
    if ($rol) {
        $sql .= " AND u.id_rol = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$rol]);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerRoles($conn) {
    $stmt = $conn->query("SELECT * FROM Roles ORDER BY nombre_rol");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerLogsAcceso($conn) {
    $sql = "
        SELECT 
            la.*,
            u.nombre,
            u.email,
            r.nombre_rol
        FROM LogsAcceso la
        JOIN Usuarios u ON la.id_usuario = u.id_usuario
        JOIN Roles r ON u.id_rol = r.id_rol
        ORDER BY la.fecha_hora_login DESC
        LIMIT 15
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$rol = $_GET['rol'] ?? '';
$usuarios = obtenerUsuarios($conn, $rol);
$roles = obtenerRoles($conn);
$logs = obtenerLogsAcceso($conn);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_usuario':
                try {
                    // Hash de la contraseña (en producción usar password_hash)
                    $password_hash = md5($_POST['password']); // Solo para demo, usar password_hash en producción
                    
                    $stmt = $conn->prepare("
                        INSERT INTO Usuarios (id_rol, nombre, email, password, telefono)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_rol'],
                        $_POST['nombre'],
                        $_POST['email'],
                        $password_hash,
                        $_POST['telefono']
                    ]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'actualizar_usuario':
                try {
                    $sql = "UPDATE Usuarios SET id_rol = ?, nombre = ?, email = ?, telefono = ?";
                    $params = [
                        $_POST['id_rol'],
                        $_POST['nombre'],
                        $_POST['email'],
                        $_POST['telefono']
                    ];
                    
                    if (!empty($_POST['password'])) {
                        $sql .= ", password = ?";
                        $params[] = md5($_POST['password']); // Solo para demo
                    }
                    
                    $sql .= " WHERE id_usuario = ?";
                    $params[] = $_POST['id_usuario'];
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'desactivar_usuario':
                try {
                    $stmt = $conn->prepare("UPDATE Usuarios SET estado = 'inactivo' WHERE id_usuario = ?");
                    $stmt->execute([$_POST['id_usuario']]);
                    
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

function getRolColor($rol) {
    $colores = [
        'Administrador' => '#e74c3c',
        'Mecánico' => '#3498db',
        'Cajero' => '#27ae60',
        'Inspector Calidad' => '#f39c12',
        'Inspector Herramientas' => '#9b59b6',
        'Gestor Post Venta' => '#1abc9c'
    ];
    return $colores[$rol] ?? '#95a5a6';
}

function getRolIcon($rol) {
    $iconos = [
        'Administrador' => 'fas fa-crown',
        'Mecánico' => 'fas fa-tools',
        'Cajero' => 'fas fa-cash-register',
        'Inspector Calidad' => 'fas fa-check-circle',
        'Inspector Herramientas' => 'fas fa-wrench',
        'Gestor Post Venta' => 'fas fa-headset'
    ];
    return $iconos[$rol] ?? 'fas fa-user';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Servistar</title>
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
        
        /* Role Badges */
        .role-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
                <li><a href="post_venta.php"><i class="fas fa-headset"></i> Post Venta</a></li>
                <li class="active"><a href="usuarios.php"><i class="fas fa-user-cog"></i> Usuarios</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-user-cog"></i> Gestión de Usuarios</h1>
                <div class="user-info">
                    <div class="user-avatar">CM</div>
                    <span>Carlos Mendoza (Admin)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo count($usuarios); ?></div>
                    <div class="stat-label">Total Usuarios</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($usuarios, fn($u) => $u['id_rol'] == 1)); ?></div>
                    <div class="stat-label">Administradores</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-tools"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($usuarios, fn($u) => $u['id_rol'] == 2)); ?></div>
                    <div class="stat-label">Mecánicos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-value"><?php echo count($logs); ?></div>
                    <div class="stat-label">Accesos Hoy</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Rol</label>
                    <select class="form-control" id="filterRole" onchange="filtrarUsuarios()">
                        <option value="">Todos los roles</option>
                        <?php foreach ($roles as $rol_item): ?>
                            <option value="<?php echo $rol_item['id_rol']; ?>" <?php echo $rol == $rol_item['id_rol'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rol_item['nombre_rol']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-success" onclick="abrirModalNuevoUsuario()">
                        <i class="fas fa-user-plus"></i> Nuevo Usuario
                    </button>
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Lista de Usuarios</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Teléfono</th>
                                <th>Rol</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($usuarios) > 0): ?>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($usuario['nombre']); ?></strong>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                        <td><?php echo $usuario['telefono'] ? htmlspecialchars($usuario['telefono']) : '-'; ?></td>
                                        <td>
                                            <span class="role-badge" style="background: rgba(<?php 
                                                $color = getRolColor($usuario['nombre_rol']);
                                                echo hexdec(substr($color, 1, 2)) . ', ' . 
                                                     hexdec(substr($color, 3, 2)) . ', ' . 
                                                     hexdec(substr($color, 5, 2));
                                            ?>, 0.2); color: <?php echo $color; ?>;">
                                                <i class="<?php echo getRolIcon($usuario['nombre_rol']); ?>"></i>
                                                <?php echo htmlspecialchars($usuario['nombre_rol']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($usuario['fecha_creacion'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-warning" onclick="editarUsuario(<?php echo $usuario['id_usuario']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="desactivarUsuario(<?php echo $usuario['id_usuario']; ?>)">
                                                    <i class="fas fa-user-times"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-users" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No se encontraron usuarios</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Access Logs -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-history"></i> Historial de Accesos</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>Fecha/Hora Login</th>
                                <th>Fecha/Hora Logout</th>
                                <th>IP Conexión</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($logs) > 0): ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($log['nombre']); ?></strong>
                                                <br><small><?php echo htmlspecialchars($log['email']); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['nombre_rol']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($log['fecha_hora_login'])); ?></td>
                                        <td>
                                            <?php if ($log['fecha_hora_logout']): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($log['fecha_hora_logout'])); ?>
                                            <?php else: ?>
                                                <span style="color: var(--warning);">Sesión activa</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($log['ip_conexion']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-history" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay registros de acceso</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Usuario -->
    <div class="modal" id="modalNuevoUsuario">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoUsuario')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-user-plus"></i> Nuevo Usuario</h2>
            
            <form id="formNuevoUsuario">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nombre Completo</label>
                        <input type="text" class="form-control" name="nombre" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Contraseña</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Teléfono</label>
                        <input type="tel" class="form-control" name="telefono">
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Rol</label>
                    <select class="form-control" name="id_rol" required>
                        <option value="">Seleccionar rol...</option>
                        <?php foreach ($roles as $rol_item): ?>
                            <option value="<?php echo $rol_item['id_rol']; ?>">
                                <?php echo htmlspecialchars($rol_item['nombre_rol']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoUsuario')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de filtrado
        function filtrarUsuarios() {
            const role = document.getElementById('filterRole').value;
            const url = new URL(window.location);
            if (role) {
                url.searchParams.set('rol', role);
            } else {
                url.searchParams.delete('rol');
            }
            window.location.href = url.toString();
        }

        // Funciones de modal
        function abrirModalNuevoUsuario() {
            document.getElementById('modalNuevoUsuario').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function editarUsuario(idUsuario) {
            alert('Editar usuario ' + idUsuario + ' - Funcionalidad en desarrollo');
        }

        function desactivarUsuario(idUsuario) {
            if (confirm('¿Estás seguro de que quieres desactivar este usuario?')) {
                const formData = new FormData();
                formData.append('action', 'desactivar_usuario');
                formData.append('id_usuario', idUsuario);
                
                fetch('usuarios.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario desactivado exitosamente!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al desactivar el usuario');
                });
            }
        }

        // Formulario nuevo usuario
        document.getElementById('formNuevoUsuario').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_usuario');
            
            fetch('usuarios.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Usuario creado exitosamente!');
                    cerrarModal('modalNuevoUsuario');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear el usuario');
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