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
function obtenerClientes($conn, $busqueda = '') {
    $sql = "
        SELECT 
            c.*,
            COUNT(v.id_vehiculo) as total_vehiculos,
            MAX(os.fecha_entrada) as ultima_visita
        FROM Clientes c
        LEFT JOIN Vehiculos v ON c.id_cliente = v.id_cliente
        LEFT JOIN OrdenesServicio os ON v.id_vehiculo = os.id_vehiculo
    ";
    
    if ($busqueda) {
        $sql .= " WHERE c.nombre LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?";
        $stmt = $conn->prepare($sql);
        $param = "%$busqueda%";
        $stmt->execute([$param, $param, $param]);
    } else {
        $sql .= " GROUP BY c.id_cliente ORDER BY c.fecha_registro DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerVehiculosPorCliente($conn, $id_cliente) {
    $stmt = $conn->prepare("
        SELECT v.*, COUNT(os.id_orden) as total_ordenes
        FROM Vehiculos v
        LEFT JOIN OrdenesServicio os ON v.id_vehiculo = os.id_vehiculo
        WHERE v.id_cliente = ?
        GROUP BY v.id_vehiculo
    ");
    $stmt->execute([$id_cliente]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$busqueda = $_GET['busqueda'] ?? '';
$clientes = obtenerClientes($conn, $busqueda);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear_cliente':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO Clientes (nombre, email, telefono, direccion)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['nombre'],
                        $_POST['email'],
                        $_POST['telefono'],
                        $_POST['direccion']
                    ]);
                    $id_cliente = $conn->lastInsertId();
                    
                    echo json_encode(['success' => true, 'id_cliente' => $id_cliente]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'crear_vehiculo':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO Vehiculos (id_cliente, placa, marca, modelo, año, color, vin)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_cliente'],
                        $_POST['placa'],
                        $_POST['marca'],
                        $_POST['modelo'],
                        $_POST['año'],
                        $_POST['color'],
                        $_POST['vin']
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

function formatearFecha($fecha) {
    if (!$fecha) return 'Nunca';
    return date('d/m/Y', strtotime($fecha));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Servistar</title>
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
        
        .stat-card.success {
            border-left-color: var(--success);
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
        
        /* Search and Actions */
        .search-actions {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            display: flex;
            gap: 10px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px;
            border: 1px solid var(--light);
            border-radius: 5px;
            background: var(--dark);
            color: var(--text);
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
        
        /* Client Cards */
        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .client-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-left: 4px solid var(--secondary);
            transition: all 0.3s ease;
        }
        
        .client-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        
        .client-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .client-name {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .client-contact {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .client-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 15px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: var(--light);
            border-radius: 5px;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--secondary);
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            margin-top: 15px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
            flex: 1;
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
                <li class="active"><a href="clientes.php"><i class="fas fa-users"></i> Clientes</a></li>
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
                <h1><i class="fas fa-users"></i> Gestión de Clientes</h1>
                <div class="user-info">
                    <div class="user-avatar">CM</div>
                    <span>Carlos Mendoza (Admin)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo count($clientes); ?></div>
                    <div class="stat-label">Total Clientes</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-car"></i></div>
                    <div class="stat-value"><?php echo array_sum(array_column($clientes, 'total_vehiculos')); ?></div>
                    <div class="stat-label">Vehículos Registrados</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($clientes, function($c) { 
                        return $c['ultima_visita'] && strtotime($c['ultima_visita']) > strtotime('-30 days'); 
                    })); ?></div>
                    <div class="stat-label">Clientes Activos (30 días)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($clientes, function($c) { 
                        return strtotime($c['fecha_registro']) > strtotime('-7 days'); 
                    })); ?></div>
                    <div class="stat-label">Nuevos (7 días)</div>
                </div>
            </div>

            <!-- Search and Actions -->
            <div class="search-actions">
                <form method="GET" class="search-box">
                    <input type="text" name="busqueda" class="search-input" placeholder="Buscar cliente por nombre, email o teléfono..." value="<?php echo htmlspecialchars($busqueda); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                </form>
                
                <button class="btn btn-success" onclick="abrirModalNuevoCliente()">
                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                </button>
                
                <?php if ($busqueda): ?>
                    <a href="clientes.php" class="btn">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                <?php endif; ?>
            </div>

            <!-- Clients Grid -->
            <div class="clients-grid">
                <?php if (count($clientes) > 0): ?>
                    <?php foreach ($clientes as $cliente): ?>
                        <div class="client-card">
                            <div class="client-header">
                                <div>
                                    <div class="client-name"><?php echo htmlspecialchars($cliente['nombre']); ?></div>
                                    <div class="client-contact">
                                        <div><i class="fas fa-envelope"></i> <?php echo $cliente['email'] ? htmlspecialchars($cliente['email']) : 'Sin email'; ?></div>
                                        <div><i class="fas fa-phone"></i> <?php echo $cliente['telefono'] ? htmlspecialchars($cliente['telefono']) : 'Sin teléfono'; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($cliente['direccion']): ?>
                                <div style="margin-bottom: 10px;">
                                    <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($cliente['direccion']); ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="client-stats">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $cliente['total_vehiculos']; ?></div>
                                    <div class="stat-label">Vehículos</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo formatearFecha($cliente['ultima_visita']); ?></div>
                                    <div class="stat-label">Última Visita</div>
                                </div>
                            </div>
                            
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-primary" onclick="verDetalleCliente(<?php echo $cliente['id_cliente']; ?>)">
                                    <i class="fas fa-eye"></i> Ver
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="editarCliente(<?php echo $cliente['id_cliente']; ?>)">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-sm btn-success" onclick="agregarVehiculo(<?php echo $cliente['id_cliente']; ?>)">
                                    <i class="fas fa-car"></i> Vehículo
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                        <i class="fas fa-users" style="font-size: 4rem; color: var(--text-secondary); margin-bottom: 20px;"></i>
                        <h3>No se encontraron clientes</h3>
                        <p><?php echo $busqueda ? 'Intenta con otros términos de búsqueda' : 'Comienza agregando tu primer cliente'; ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Cliente -->
    <div class="modal" id="modalNuevoCliente">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoCliente')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-user-plus"></i> Nuevo Cliente</h2>
            
            <form id="formNuevoCliente">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nombre Completo</label>
                        <input type="text" class="form-control" name="nombre" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Teléfono</label>
                        <input type="tel" class="form-control" name="telefono">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Dirección</label>
                        <input type="text" class="form-control" name="direccion">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoCliente')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cliente
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Nuevo Vehículo -->
    <div class="modal" id="modalNuevoVehiculo">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoVehiculo')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-car"></i> Nuevo Vehículo</h2>
            
            <form id="formNuevoVehiculo">
                <input type="hidden" name="id_cliente" id="id_cliente_vehiculo">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Placa</label>
                        <input type="text" class="form-control" name="placa" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Marca</label>
                        <input type="text" class="form-control" name="marca" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Modelo</label>
                        <input type="text" class="form-control" name="modelo" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Año</label>
                        <input type="number" class="form-control" name="año" min="1990" max="<?php echo date('Y'); ?>" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-palette"></i> Color</label>
                        <input type="text" class="form-control" name="color">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> VIN</label>
                        <input type="text" class="form-control" name="vin">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoVehiculo')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Vehículo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de modal
        function abrirModalNuevoCliente() {
            document.getElementById('modalNuevoCliente').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function agregarVehiculo(idCliente) {
            document.getElementById('id_cliente_vehiculo').value = idCliente;
            document.getElementById('modalNuevoVehiculo').style.display = 'block';
        }

        function verDetalleCliente(idCliente) {
            alert('Detalle del cliente ' + idCliente + ' - Funcionalidad en desarrollo');
        }

        function editarCliente(idCliente) {
            alert('Editar cliente ' + idCliente + ' - Funcionalidad en desarrollo');
        }

        // Formulario nuevo cliente
        document.getElementById('formNuevoCliente').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_cliente');
            
            fetch('clientes.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Cliente creado exitosamente!');
                    cerrarModal('modalNuevoCliente');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear el cliente');
            });
        });

        // Formulario nuevo vehículo
        document.getElementById('formNuevoVehiculo').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_vehiculo');
            
            fetch('clientes.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Vehículo agregado exitosamente!');
                    cerrarModal('modalNuevoVehiculo');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al agregar el vehículo');
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