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

// Funciones para obtener datos
function obtenerRepuestos($conn, $busqueda = '', $categoria = '') {
    $sql = "
        SELECT r.*, c.nombre_categoria, 
               (r.stock_actual <= r.stock_minimo) as stock_bajo
        FROM Repuestos r
        JOIN CategoriasRepuestos c ON r.id_categoria = c.id_categoria
        WHERE r.estado = 'activo'
    ";
    
    $params = [];
    
    if ($busqueda) {
        $sql .= " AND (r.nombre_repuesto LIKE ? OR r.codigo_repuesto LIKE ?)";
        $param = "%$busqueda%";
        $params[] = $param;
        $params[] = $param;
    }
    
    if ($categoria) {
        $sql .= " AND r.id_categoria = ?";
        $params[] = $categoria;
    }
    
    $sql .= " ORDER BY r.stock_actual ASC, r.nombre_repuesto";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerCategorias($conn) {
    $stmt = $conn->query("SELECT * FROM CategoriasRepuestos ORDER BY nombre_categoria");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerMovimientos($conn) {
    $sql = "
        SELECT * FROM (
            SELECT 
                'consumo' as tipo,
                cr.fecha_consumo as fecha,
                r.nombre_repuesto,
                cr.cantidad,
                u.nombre as usuario,
                CONCAT('OS-', YEAR(os.fecha_entrada), '-', LPAD(os.id_orden, 4, '0')) as referencia
            FROM ConsumoRepuestos cr
            JOIN Repuestos r ON cr.id_repuesto = r.id_repuesto
            JOIN Usuarios u ON cr.id_mecanico = u.id_usuario
            JOIN OrdenesServicio os ON cr.id_orden = os.id_orden
            
            UNION ALL
            
            SELECT 
                'venta' as tipo,
                vr.fecha_venta as fecha,
                r.nombre_repuesto,
                dv.cantidad,
                u.nombre as usuario,
                vr.numero_comprobante as referencia
            FROM DetalleVentas dv
            JOIN VentasRepuestos vr ON dv.id_venta = vr.id_venta
            JOIN Repuestos r ON dv.id_repuesto = r.id_repuesto
            JOIN Usuarios u ON vr.id_vendedor = u.id_usuario
        ) as movimientos
        ORDER BY fecha DESC
        LIMIT 15
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos
$busqueda = $_GET['busqueda'] ?? '';
$categoria = $_GET['categoria'] ?? '';
$repuestos = obtenerRepuestos($conn, $busqueda, $categoria);
$categorias = obtenerCategorias($conn);
$movimientos = obtenerMovimientos($conn);

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'actualizar_stock':
                try {
                    $stmt = $conn->prepare("
                        UPDATE Repuestos 
                        SET stock_actual = ?, precio_unitario = ?
                        WHERE id_repuesto = ?
                    ");
                    $stmt->execute([
                        $_POST['stock_actual'],
                        $_POST['precio_unitario'],
                        $_POST['id_repuesto']
                    ]);
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
                break;
                
            case 'crear_repuesto':
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO Repuestos (id_categoria, codigo_repuesto, nombre_repuesto, marca_repuesto, descripcion, precio_unitario, stock_actual, stock_minimo)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['id_categoria'],
                        $_POST['codigo_repuesto'],
                        $_POST['nombre_repuesto'],
                        $_POST['marca_repuesto'],
                        $_POST['descripcion'],
                        $_POST['precio_unitario'],
                        $_POST['stock_actual'],
                        $_POST['stock_minimo']
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

function formatearMoneda($monto) {
    return 'Bs ' . number_format($monto, 2, '.', ',');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Servistar</title>
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
        
        .stock-bajo {
            color: var(--danger);
            font-weight: bold;
        }
        
        .stock-normal {
            color: var(--success);
        }
        
        .stock-medio {
            color: var(--warning);
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
                <li class="active"><a href="inventario.php"><i class="fas fa-boxes"></i> Inventario</a></li>
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
                <h1><i class="fas fa-boxes"></i> Gestión de Inventario</h1>
                <div class="user-info">
                    <div class="user-avatar">CM</div>
                    <span>Carlos Mendoza (Admin)</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                    <div class="stat-value"><?php echo count($repuestos); ?></div>
                    <div class="stat-label">Total Repuestos</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($repuestos, fn($r) => $r['stock_actual'] > $r['stock_minimo'])); ?></div>
                    <div class="stat-label">Stock Normal</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($repuestos, fn($r) => $r['stock_actual'] <= $r['stock_minimo'] && $r['stock_actual'] > 0)); ?></div>
                    <div class="stat-label">Stock Bajo</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-value"><?php echo count(array_filter($repuestos, fn($r) => $r['stock_actual'] == 0)); ?></div>
                    <div class="stat-label">Sin Stock</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" style="display: contents;">
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Buscar Repuesto</label>
                        <input type="text" name="busqueda" class="form-control" placeholder="Nombre o código..." value="<?php echo htmlspecialchars($busqueda); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-filter"></i> Categoría</label>
                        <select name="categoria" class="form-control">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id_categoria']; ?>" <?php echo $categoria == $cat['id_categoria'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['nombre_categoria']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                    
                    <?php if ($busqueda || $categoria): ?>
                        <a href="inventario.php" class="btn">
                            <i class="fas fa-times"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </form>
                
                <div style="margin-left: auto;">
                    <button class="btn btn-success" onclick="abrirModalNuevoRepuesto()">
                        <i class="fas fa-plus"></i> Nuevo Repuesto
                    </button>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> Lista de Repuestos</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Repuesto</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Stock Actual</th>
                                <th>Stock Mínimo</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($repuestos) > 0): ?>
                                <?php foreach ($repuestos as $repuesto): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($repuesto['codigo_repuesto']); ?></strong></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($repuesto['nombre_repuesto']); ?></strong>
                                                <?php if ($repuesto['marca_repuesto']): ?>
                                                    <br><small><?php echo htmlspecialchars($repuesto['marca_repuesto']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($repuesto['nombre_categoria']); ?></td>
                                        <td><?php echo formatearMoneda($repuesto['precio_unitario']); ?></td>
                                        <td>
                                            <?php if ($repuesto['stock_actual'] == 0): ?>
                                                <span class="stock-bajo"><?php echo $repuesto['stock_actual']; ?></span>
                                            <?php elseif ($repuesto['stock_actual'] <= $repuesto['stock_minimo']): ?>
                                                <span class="stock-medio"><?php echo $repuesto['stock_actual']; ?></span>
                                            <?php else: ?>
                                                <span class="stock-normal"><?php echo $repuesto['stock_actual']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $repuesto['stock_minimo']; ?></td>
                                        <td>
                                            <?php if ($repuesto['stock_actual'] == 0): ?>
                                                <span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Sin Stock</span>
                                            <?php elseif ($repuesto['stock_actual'] <= $repuesto['stock_minimo']): ?>
                                                <span style="color: var(--warning);"><i class="fas fa-exclamation-triangle"></i> Stock Bajo</span>
                                            <?php else: ?>
                                                <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Normal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-warning" onclick="editarRepuesto(<?php echo $repuesto['id_repuesto']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-primary" onclick="verMovimientos(<?php echo $repuesto['id_repuesto']; ?>)">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-box-open" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No se encontraron repuestos</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Movements -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-history"></i> Movimientos Recientes</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Repuesto</th>
                                <th>Tipo</th>
                                <th>Cantidad</th>
                                <th>Usuario</th>
                                <th>Referencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($movimientos) > 0): ?>
                                <?php foreach ($movimientos as $mov): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($mov['fecha'])); ?></td>
                                        <td><?php echo htmlspecialchars($mov['nombre_repuesto']); ?></td>
                                        <td>
                                            <?php if ($mov['tipo'] == 'consumo'): ?>
                                                <span style="color: var(--warning);"><i class="fas fa-tools"></i> Consumo</span>
                                            <?php else: ?>
                                                <span style="color: var(--success);"><i class="fas fa-shopping-cart"></i> Venta</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $mov['cantidad']; ?></td>
                                        <td><?php echo htmlspecialchars($mov['usuario']); ?></td>
                                        <td><small><?php echo htmlspecialchars($mov['referencia']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px;">
                                        <i class="fas fa-history" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                        <p>No hay movimientos recientes</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Repuesto -->
    <div class="modal" id="modalNuevoRepuesto">
        <div class="modal-content">
            <span class="close-modal" onclick="cerrarModal('modalNuevoRepuesto')">&times;</span>
            <h2 class="modal-title"><i class="fas fa-plus-circle"></i> Nuevo Repuesto</h2>
            
            <form id="formNuevoRepuesto">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Código Repuesto</label>
                        <input type="text" class="form-control" name="codigo_repuesto" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Categoría</label>
                        <select class="form-control" name="id_categoria" required>
                            <option value="">Seleccionar categoría...</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id_categoria']; ?>"><?php echo htmlspecialchars($cat['nombre_categoria']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-box"></i> Nombre del Repuesto</label>
                    <input type="text" class="form-control" name="nombre_repuesto" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-industry"></i> Marca</label>
                        <input type="text" class="form-control" name="marca_repuesto">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-money-bill-wave"></i> Precio Unitario (Bs)</label>
                        <input type="number" class="form-control" name="precio_unitario" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-boxes"></i> Stock Actual</label>
                        <input type="number" class="form-control" name="stock_actual" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Stock Mínimo</label>
                        <input type="number" class="form-control" name="stock_minimo" min="1" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Descripción</label>
                    <textarea class="form-control" name="descripcion" placeholder="Descripción del repuesto..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="cerrarModal('modalNuevoRepuesto')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Repuesto
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones de modal
        function abrirModalNuevoRepuesto() {
            document.getElementById('modalNuevoRepuesto').style.display = 'block';
        }

        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function editarRepuesto(idRepuesto) {
            alert('Editar repuesto ' + idRepuesto + ' - Funcionalidad en desarrollo');
        }

        function verMovimientos(idRepuesto) {
            alert('Ver movimientos del repuesto ' + idRepuesto + ' - Funcionalidad en desarrollo');
        }

        // Formulario nuevo repuesto
        document.getElementById('formNuevoRepuesto').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'crear_repuesto');
            
            fetch('inventario.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Repuesto creado exitosamente!');
                    cerrarModal('modalNuevoRepuesto');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al crear el repuesto');
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