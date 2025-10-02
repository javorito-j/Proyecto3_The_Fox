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

// Función para formatear moneda
function formatearMoneda($monto) {
    return 'Bs ' . number_format($monto, 2, '.', ',');
}

// Función para obtener repuestos
function obtenerRepuestos($conn) {
    $stmt = $conn->query("SELECT * FROM Repuestos WHERE estado = 'activo'");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener clientes
function obtenerClientes($conn) {
    $stmt = $conn->query("SELECT * FROM Clientes");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener órdenes pendientes de pago
function obtenerOrdenesPendientes($conn) {
    $stmt = $conn->query("
        SELECT os.*, c.nombre as cliente, CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo
        FROM OrdenesServicio os
        JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        WHERE os.estado = 'completado' 
        AND os.id_orden NOT IN (SELECT id_orden FROM Pagos WHERE estado = 'completado')
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener historial de transacciones
function obtenerHistorialTransacciones($conn) {
    $stmt = $conn->query("
        SELECT 
            'pago' as tipo,
            p.numero_recibo as comprobante,
            c.nombre as cliente,
            CONCAT(v.marca, ' ', v.modelo, ' - ', v.placa) as vehiculo,
            p.monto_total as monto,
            p.fecha_pago as fecha,
            p.metodo_pago as metodo,
            p.estado
        FROM Pagos p
        JOIN OrdenesServicio os ON p.id_orden = os.id_orden
        JOIN Vehiculos v ON os.id_vehiculo = v.id_vehiculo
        JOIN Clientes c ON v.id_cliente = c.id_cliente
        
        UNION ALL
        
        SELECT 
            'venta' as tipo,
            vr.numero_comprobante as comprobante,
            COALESCE(c.nombre, 'Venta al contado') as cliente,
            '-' as vehiculo,
            vr.monto_total as monto,
            vr.fecha_venta as fecha,
            'efectivo' as metodo,
            'completado' as estado
        FROM VentasRepuestos vr
        LEFT JOIN Clientes c ON vr.id_cliente = c.id_cliente
        ORDER BY fecha DESC
        LIMIT 50
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener estadísticas de ventas
function obtenerEstadisticasVentas($conn) {
    $stmt = $conn->query("
        SELECT 
            COALESCE(SUM(monto_total), 0) as ventas_mes,
            COUNT(*) as transacciones,
            COALESCE((
                SELECT SUM(monto_total) 
                FROM Pagos 
                WHERE estado = 'pendiente'
            ), 0) as pendientes_cobro
        FROM (
            SELECT monto_total FROM Pagos 
            WHERE MONTH(fecha_pago) = MONTH(CURRENT_DATE()) 
            AND YEAR(fecha_pago) = YEAR(CURRENT_DATE())
            UNION ALL
            SELECT monto_total FROM VentasRepuestos
            WHERE MONTH(fecha_venta) = MONTH(CURRENT_DATE()) 
            AND YEAR(fecha_venta) = YEAR(CURRENT_DATE())
        ) as ventas_totales
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener datos
$repuestos = obtenerRepuestos($conn);
$clientes = obtenerClientes($conn);
$ordenesPendientes = obtenerOrdenesPendientes($conn);
$historialTransacciones = obtenerHistorialTransacciones($conn);
$estadisticas = obtenerEstadisticasVentas($conn);

// Procesar pago (solo si se envió el formulario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'procesar_pago') {
        try {
            $id_orden = $_POST['id_orden'] ?? null;
            $metodo_pago = $_POST['metodo_pago'] ?? 'efectivo';
            $monto_total = $_POST['monto_total'] ?? 0;
            
            if (!$id_orden || $monto_total <= 0) {
                echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
                exit;
            }
            
            // Generar número de recibo
            $numero_recibo = 'REC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("
                INSERT INTO Pagos (id_orden, id_cajero, numero_recibo, monto_total, metodo_pago, estado)
                VALUES (?, ?, ?, ?, ?, 'completado')
            ");
            $stmt->execute([$id_orden, 4, $numero_recibo, $monto_total, $metodo_pago]);
            
            echo json_encode(['success' => true, 'recibo' => $numero_recibo]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Procesar venta
    if ($_POST['action'] == 'procesar_venta') {
        try {
            $id_cliente = $_POST['id_cliente'] ?? null;
            
            // Obtener los repuestos del carrito - FORMA CORRECTA
            $repuestos_json = $_POST['repuestos'] ?? '[]';
            
            // Debug: ver qué estamos recibiendo
            error_log("JSON recibido: " . $repuestos_json);
            
            $repuestos_data = json_decode($repuestos_json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Error JSON: " . json_last_error_msg());
                throw new Exception('Error al decodificar los datos del carrito: ' . json_last_error_msg());
            }
            
            if (empty($repuestos_data)) {
                throw new Exception('No hay repuestos en el carrito');
            }
            
            error_log("Repuestos decodificados: " . print_r($repuestos_data, true));
            
            // Iniciar transacción para asegurar consistencia
            $conn->beginTransaction();
            
            // Calcular total y verificar stock
            $monto_total = 0;
            foreach ($repuestos_data as $repuesto) {
                // Verificar que el repuesto tenga stock suficiente
                $stmt = $conn->prepare("SELECT nombre_repuesto, stock_actual FROM Repuestos WHERE id_repuesto = ?");
                $stmt->execute([$repuesto['id']]);
                $stock_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$stock_info) {
                    throw new Exception("Repuesto no encontrado: ID " . $repuesto['id']);
                }
                
                if ($stock_info['stock_actual'] < $repuesto['cantidad']) {
                    throw new Exception("Stock insuficiente para: " . $stock_info['nombre_repuesto'] . 
                                    " (Stock: " . $stock_info['stock_actual'] . ", Solicitado: " . $repuesto['cantidad'] . ")");
                }
                
                $monto_total += $repuesto['precio'] * $repuesto['cantidad'];
            }
            
            // Generar número de comprobante
            $numero_comprobante = 'VENTA-' . date('Y-m-d') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insertar venta
            $stmt = $conn->prepare("
                INSERT INTO VentasRepuestos (id_vendedor, id_cliente, numero_comprobante, monto_total, fecha_venta)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([4, $id_cliente ?: null, $numero_comprobante, $monto_total]);
            $id_venta = $conn->lastInsertId();
            
            // Insertar detalles de venta y actualizar stock
            foreach ($repuestos_data as $repuesto) {
                $subtotal = $repuesto['precio'] * $repuesto['cantidad'];
                
                // Insertar en DetalleVentas
                $stmt = $conn->prepare("
                    INSERT INTO DetalleVentas (id_venta, id_repuesto, cantidad, precio_unitario, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id_venta, $repuesto['id'], $repuesto['cantidad'], $repuesto['precio'], $subtotal]);
                
                // Actualizar stock
                $stmt = $conn->prepare("
                    UPDATE Repuestos 
                    SET stock_actual = stock_actual - ? 
                    WHERE id_repuesto = ?
                ");
                $stmt->execute([$repuesto['cantidad'], $repuesto['id']]);
                
                // Verificar si queda stock bajo después de la venta
                $stmt = $conn->prepare("
                    SELECT nombre_repuesto, stock_actual, stock_minimo 
                    FROM Repuestos 
                    WHERE id_repuesto = ? AND stock_actual <= stock_minimo
                ");
                $stmt->execute([$repuesto['id']]);
                $stock_bajo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($stock_bajo) {
                    // Crear notificación de stock bajo
                    $stmt = $conn->prepare("
                        INSERT INTO Notificaciones (id_usuario, tipo_notificacion, mensaje)
                        VALUES (?, 'stock_bajo', ?)
                    ");
                    $mensaje = "Stock bajo: " . $stock_bajo['nombre_repuesto'] . " - " . $stock_bajo['stock_actual'] . " unidades";
                    $stmt->execute([1, $mensaje]);
                }
            }
            
            // Confirmar transacción
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'comprobante' => $numero_comprobante,
                'total' => $monto_total,
                'message' => 'Venta registrada correctamente'
            ]);
            exit;
            
        } catch (Exception $e) {
            // Revertir transacción en caso de error
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            
            error_log("Error en venta: " . $e->getMessage());
            
            echo json_encode([
                'success' => false, 
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas y Pagos - Servistar</title>
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
        
        /* Tabs */
        .tabs {
            display: flex;
            background: white;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: var(--secondary);
            color: white;
        }
        
        /* Content Sections */
        .content-section {
            display: none;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .content-section.active {
            display: block;
        }
        
        .section-title {
            margin-bottom: 20px;
            color: var(--dark);
            font-size: 1.3rem;
        }
        
        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
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
            color: var(--dark);
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: var(--secondary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
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
        
        /* Search and Filters */
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        /* Payment Summary */
        .payment-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .summary-total {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--success);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 500px;
            position: relative;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .stock-bajo {
            color: var(--danger);
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <h2>🏎️ Servistar</h2>
            </div>
            <ul class="nav-links">
                <li><a href="dashboard.php">📊 Dashboard</a></li>
                <li><a href="ordenes_servicio.php">🔧 Órdenes de Servicio</a></li>
                <li><a href="clientes.php">👥 Clientes</a></li>
                <li><a href="inventario.php">📦 Inventario</a></li>
                <li class="active"><a href="ventas_pagos.php">💰 Ventas y Pagos</a></li>
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
                <h1>Ventas y Pagos</h1>
                <div class="user-info">
                    <div class="user-avatar">AL</div>
                    <span>Ana López (Cajero)</span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" data-tab="pagos">💳 Registro de Pagos</div>
                <div class="tab" data-tab="ventas">📦 Ventas de Repuestos</div>
                <div class="tab" data-tab="historial">📋 Historial</div>
                <div class="tab" data-tab="reportes">📊 Reportes</div>
            </div>

            <!-- Registro de Pagos -->
            <div class="content-section active" id="pagos">
                <h2 class="section-title">Registro de Pagos de Servicios</h2>
                
                <div class="form-group">
                    <label>Seleccionar Orden de Servicio</label>
                    <select class="form-control" id="selectOrder" onchange="cargarOrden(this.value)">
                        <option value="">Seleccionar orden...</option>
                        <?php foreach ($ordenesPendientes as $orden): ?>
                            <option value="<?php echo $orden['id_orden']; ?>" data-cliente="<?php echo $orden['cliente']; ?>" data-vehiculo="<?php echo $orden['vehiculo']; ?>">
                                OS-<?php echo $orden['id_orden']; ?> - <?php echo $orden['vehiculo']; ?> - <?php echo $orden['cliente']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Cliente</label>
                        <input type="text" class="form-control" id="clientName" readonly>
                    </div>
                    <div class="form-group">
                        <label>Vehículo</label>
                        <input type="text" class="form-control" id="vehicleInfo" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label>Total a Pagar (Bs)</label>
                    <input type="text" class="form-control" id="totalAmount" readonly style="font-size: 1.2rem; font-weight: bold; color: var(--success);" value="Bs 0.00">
                </div>

                <!-- Información de Pago -->
                <div class="payment-summary">
                    <h3 style="margin-bottom: 15px;">Información de Pago</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Método de Pago</label>
                            <select class="form-control" id="paymentMethod">
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta de Crédito/Débito</option>
                                <option value="transferencia">Transferencia Bancaria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Monto Recibido (Bs)</label>
                            <input type="number" class="form-control" id="amountReceived" step="0.01" oninput="calcularCambio()">
                        </div>
                    </div>
                    
                    <div id="changeSection" style="display: none;">
                        <div class="summary-row">
                            <span>Cambio:</span>
                            <span id="changeAmount" style="color: var(--success); font-weight: bold;">Bs 0.00</span>
                        </div>
                    </div>

                    <button class="btn btn-success" style="margin-top: 15px; width: 100%;" onclick="processPayment()">
                        💳 Procesar Pago y Generar Recibo
                    </button>
                </div>
            </div>

            <!-- Ventas de Repuestos -->
            <div class="content-section" id="ventas">
                <h2 class="section-title">Venta de Repuestos</h2>
                
                <div class="search-box">
                    <input type="text" class="search-input" id="searchRepuesto" placeholder="Buscar repuesto por código o nombre..." onkeyup="buscarRepuestos()">
                    <button class="btn btn-primary" onclick="buscarRepuestos()">🔍 Buscar</button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Cliente</label>
                        <select class="form-control" id="saleClient">
                            <option value="">Seleccionar cliente...</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?php echo $cliente['id_cliente']; ?>"><?php echo $cliente['nombre']; ?></option>
                            <?php endforeach; ?>
                            <option value="0">Venta al contado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vendedor</label>
                        <input type="text" class="form-control" value="Ana López" readonly>
                    </div>
                </div>

                <!-- Lista de Repuestos -->
                <h3 style="margin: 20px 0 10px 0;">Repuestos Disponibles</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Repuesto</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Cantidad</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="repuestosList">
                            <?php foreach ($repuestos as $repuesto): ?>
                                <tr>
                                    <td>
                                        <?php echo $repuesto['nombre_repuesto']; ?>
                                        <?php if ($repuesto['stock_actual'] <= $repuesto['stock_minimo']): ?>
                                            <span class="stock-bajo"> (Stock bajo)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatearMoneda($repuesto['precio_unitario']); ?></td>
                                    <td><?php echo $repuesto['stock_actual']; ?></td>
                                    <td>
                                        <input type="number" id="cantidad_<?php echo $repuesto['id_repuesto']; ?>" 
                                               class="form-control" style="width: 80px;" min="1" 
                                               max="<?php echo $repuesto['stock_actual']; ?>" value="1">
                                    </td>
                                    <td>
                                        <button class="btn btn-primary" onclick="agregarAlCarrito(<?php echo $repuesto['id_repuesto']; ?>, '<?php echo $repuesto['nombre_repuesto']; ?>', <?php echo $repuesto['precio_unitario']; ?>)">
                                            ➕ Agregar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Carrito de Venta -->
                <h3 style="margin: 20px 0 10px 0;">Carrito de Venta</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Repuesto</th>
                                <th>Precio Unitario</th>
                                <th>Cantidad</th>
                                <th>Subtotal</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="saleCart">
                            <!-- Carrito se llena dinámicamente -->
                        </tbody>
                    </table>
                </div>

                <!-- Resumen de Venta -->
                <div class="payment-summary">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span id="subtotalVenta">Bs 0.00</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span>Total:</span>
                        <span id="totalVenta">Bs 0.00</span>
                    </div>
                    
                    <button class="btn btn-success" style="margin-top: 15px; width: 100%;" onclick="processSale()">
                        🧾 Finalizar Venta
                    </button>
                </div>
            </div>

            <!-- Historial -->
            <div class="content-section" id="historial">
                <h2 class="section-title">Historial de Transacciones</h2>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>N° Comprobante</th>
                                <th>Cliente</th>
                                <th>Vehículo</th>
                                <th>Monto</th>
                                <th>Fecha</th>
                                <th>Método</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historialTransacciones as $transaccion): ?>
                                <tr>
                                    <td><?php echo $transaccion['tipo'] == 'pago' ? '💳 Pago' : '📦 Venta'; ?></td>
                                    <td><?php echo $transaccion['comprobante']; ?></td>
                                    <td><?php echo $transaccion['cliente']; ?></td>
                                    <td><?php echo $transaccion['vehiculo']; ?></td>
                                    <td><?php echo formatearMoneda($transaccion['monto']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($transaccion['fecha'])); ?></td>
                                    <td><?php echo ucfirst($transaccion['metodo']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $transaccion['estado'] == 'completado' ? 'status-paid' : 'status-pending'; ?>">
                                            <?php echo $transaccion['estado'] == 'completado' ? 'Pagado' : 'Pendiente'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reportes -->
            <div class="content-section" id="reportes">
                <h2 class="section-title">Reportes de Ventas y Pagos</h2>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Fecha Desde</label>
                        <input type="date" class="form-control" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Fecha Hasta</label>
                        <input type="date" class="form-control" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <button class="btn btn-success" style="margin-top: 20px;" onclick="generarReporte()">
                    📊 Generar Reporte
                </button>

                <!-- Resumen de Estadísticas -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 30px;">
                    <div style="background: white; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid var(--success);">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--success);"><?php echo formatearMoneda($estadisticas['ventas_mes']); ?></div>
                        <div style="color: #7f8c8d;">Ventas del Mes</div>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid var(--secondary);">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--secondary);"><?php echo $estadisticas['transacciones']; ?></div>
                        <div style="color: #7f8c8d;">Transacciones</div>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid var(--warning);">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--warning);"><?php echo formatearMoneda($estadisticas['pendientes_cobro']); ?></div>
                        <div style="color: #7f8c8d;">Pendientes de Cobro</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Recibo -->
    <div class="modal" id="receiptModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 style="text-align: center; margin-bottom: 20px;">Recibo de Pago</h2>
            <div style="text-align: center; margin-bottom: 20px;">
                <strong id="numeroRecibo">REC-2024-0001</strong>
            </div>
            
            <div style="margin-bottom: 15px;">
                <strong>Taller Automotriz Servistar</strong><br>
                Av. Automotor #123, La Paz<br>
                Tel: 777-12345 | NIT: 123456789
            </div>
            
            <div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <strong>Cliente:</strong> <span id="clienteRecibo">-</span><br>
                <strong>Vehículo:</strong> <span id="vehiculoRecibo">-</span><br>
                <strong>Fecha:</strong> <span id="fechaRecibo"><?php echo date('d/m/Y H:i'); ?></span>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                <tr>
                    <td style="padding: 5px; border-bottom: 1px dashed #ddd;">Servicios:</td>
                    <td style="padding: 5px; border-bottom: 1px dashed #ddd; text-align: right;" id="montoRecibo">Bs 0.00</td>
                </tr>
                <tr>
                    <td style="padding: 5px; border-bottom: 1px dashed #ddd;">Método de Pago:</td>
                    <td style="padding: 5px; border-bottom: 1px dashed #ddd; text-align: right;" id="metodoRecibo">Efectivo</td>
                </tr>
                <tr>
                    <td style="padding: 5px;"><strong>Total:</strong></td>
                    <td style="padding: 5px; text-align: right;"><strong id="totalRecibo">Bs 0.00</strong></td>
                </tr>
            </table>
            
            <div style="text-align: center; margin-top: 20px; color: #7f8c8d;">
                ¡Gracias por su preferencia!<br>
                Recibo generado automáticamente
            </div>
            
            <button class="btn btn-primary" style="width: 100%; margin-top: 20px;" onclick="printReceipt()">
                🖨️ Imprimir Recibo
            </button>
        </div>
    </div>

    <script>
        let carrito = [];
        let ordenActual = null;

        // Navegación entre pestañas
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Cargar datos de orden
        function cargarOrden(idOrden) {
            if (!idOrden) return;
            
            const option = document.querySelector(`#selectOrder option[value="${idOrden}"]`);
            if (option) {
                document.getElementById('clientName').value = option.getAttribute('data-cliente');
                document.getElementById('vehicleInfo').value = option.getAttribute('data-vehiculo');
                document.getElementById('totalAmount').value = 'Bs 395.00'; // En una app real, esto vendría de la BD
                ordenActual = idOrden;
            }
        }

        // Calcular cambio
        function calcularCambio() {
            const total = 395; // Este valor vendría de la base de datos
            const received = parseFloat(document.getElementById('amountReceived').value) || 0;
            const change = received - total;
            
            const changeSection = document.getElementById('changeSection');
            const changeAmount = document.getElementById('changeAmount');
            
            if (change >= 0) {
                changeSection.style.display = 'block';
                changeAmount.textContent = `Bs ${change.toFixed(2)}`;
            } else {
                changeSection.style.display = 'none';
            }
        }

        // Buscar repuestos
        function buscarRepuestos() {
            const searchTerm = document.getElementById('searchRepuesto').value.toLowerCase();
            const rows = document.querySelectorAll('#repuestosList tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        // Agregar al carrito
        function agregarAlCarrito(id, nombre, precio) {
            const cantidadInput = document.getElementById(`cantidad_${id}`);
            const cantidad = parseInt(cantidadInput.value) || 1;
            
            if (cantidad <= 0) {
                alert('La cantidad debe ser mayor a 0');
                return;
            }

            // Verificar stock disponible
            const stockDisponible = parseInt(cantidadInput.max);
            if (cantidad > stockDisponible) {
                alert('No hay suficiente stock disponible');
                return;
            }

            // Verificar si ya está en el carrito
            const index = carrito.findIndex(item => item.id === id);
            if (index > -1) {
                const nuevaCantidad = carrito[index].cantidad + cantidad;
                if (nuevaCantidad > stockDisponible) {
                    alert('No hay suficiente stock disponible');
                    return;
                }
                carrito[index].cantidad = nuevaCantidad;
            } else {
                carrito.push({
                    id: id,
                    nombre: nombre,
                    precio: precio,
                    cantidad: cantidad
                });
            }

            actualizarCarrito();
            cantidadInput.value = 1;
            
            console.log('Carrito actual:', carrito); // Para debug
        }

        // Actualizar carrito
        function actualizarCarrito() {
            const tbody = document.getElementById('saleCart');
            const subtotalElement = document.getElementById('subtotalVenta');
            const totalElement = document.getElementById('totalVenta');
            
            let subtotal = 0;
            let html = '';
            
            carrito.forEach((item, index) => {
                const itemSubtotal = item.precio * item.cantidad;
                subtotal += itemSubtotal;
                
                html += `
                    <tr>
                        <td>${item.nombre}</td>
                        <td>Bs ${item.precio.toFixed(2)}</td>
                        <td>
                            <input type="number" value="${item.cantidad}" min="1" 
                                   onchange="actualizarCantidad(${index}, this.value)" 
                                   style="width: 80px;" class="form-control">
                        </td>
                        <td>Bs ${itemSubtotal.toFixed(2)}</td>
                        <td>
                            <button class="btn btn-danger" style="padding: 5px 10px;" onclick="eliminarDelCarrito(${index})">
                                🗑️
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            subtotalElement.textContent = `Bs ${subtotal.toFixed(2)}`;
            totalElement.textContent = `Bs ${subtotal.toFixed(2)}`;
        }

        // Actualizar cantidad en carrito
        function actualizarCantidad(index, nuevaCantidad) {
            const cantidad = parseInt(nuevaCantidad) || 1;
            if (cantidad > 0) {
                carrito[index].cantidad = cantidad;
                actualizarCarrito();
            }
        }

        // Eliminar del carrito
        function eliminarDelCarrito(index) {
            carrito.splice(index, 1);
            actualizarCarrito();
        }
                // Procesar pago
        function processPayment() {
            if (!ordenActual) {
                alert('Por favor seleccione una orden de servicio');
                return;
            }

            const metodoPago = document.getElementById('paymentMethod').value;
            const montoRecibido = parseFloat(document.getElementById('amountReceived').value) || 0;
            const montoTotal = 395; // En una app real, este monto vendría de la BD
            
            if (montoRecibido < montoTotal) {
                alert('El monto recibido es insuficiente');
                return;
            }

            // Enviar datos al servidor via AJAX
            const formData = new FormData();
            formData.append('action', 'procesar_pago');
            formData.append('id_orden', ordenActual);
            formData.append('metodo_pago', metodoPago);
            formData.append('monto_total', montoTotal);

            fetch('ventas_pagos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar recibo
                    document.getElementById('numeroRecibo').textContent = data.recibo;
                    document.getElementById('clienteRecibo').textContent = document.getElementById('clientName').value;
                    document.getElementById('vehiculoRecibo').textContent = document.getElementById('vehicleInfo').value;
                    document.getElementById('montoRecibo').textContent = document.getElementById('totalAmount').value;
                    document.getElementById('metodoRecibo').textContent = metodoPago.charAt(0).toUpperCase() + metodoPago.slice(1);
                    document.getElementById('totalRecibo').textContent = document.getElementById('totalAmount').value;
                    
                    const modal = document.getElementById('receiptModal');
                    modal.style.display = 'block';
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al procesar el pago');
            });
        }
        // Procesar venta
        function processSale() {
            if (carrito.length === 0) {
                alert('El carrito está vacío');
                return;
            }

            const idCliente = document.getElementById('saleClient').value;
            if (!idCliente) {
                alert('Por favor seleccione un cliente');
                return;
            }

            console.log('Carrito a enviar:', carrito); // Para ver qué se está enviando

            // Crear FormData para enviar correctamente
            const formData = new FormData();
            formData.append('action', 'procesar_venta');
            formData.append('id_cliente', idCliente);
            formData.append('repuestos', JSON.stringify(carrito));

            // Enviar datos al servidor via AJAX
            fetch('ventas_pagos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Respuesta del servidor:', response);
                return response.json();
            })
            .then(data => {
                console.log('Datos recibidos:', data);
                if (data.success) {
                    alert(`✅ Venta procesada exitosamente!\nComprobante: ${data.comprobante}`);
                    carrito = [];
                    actualizarCarrito();
                    document.getElementById('saleClient').value = '';
                    
                    // Recargar la página para actualizar stock
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    alert('❌ Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error completo:', error);
                alert('❌ Error de conexión: ' + error.message);
            });
        }
    </script>
</body>
</html>
<?php
// Cerrar conexión
$conn = null;
?>