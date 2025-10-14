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
        
        /* Tabs */
        .tabs {
            display: flex;
            background: var(--card-bg);
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            justify-content: center;
        }
        
        .tab.active {
            background: var(--secondary);
            color: white;
        }
        
        .tab:hover:not(.active) {
            background: var(--hover-bg);
        }
        
        /* Content Sections */
        .content-section {
            display: none;
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .content-section.active {
            display: block;
        }
        
        .section-title {
            margin-bottom: 20px;
            color: var(--text);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
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
            color: var(--text);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--light);
            border-radius: 5px;
            font-size: 14px;
            background: var(--dark);
            color: var(--text);
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-control:read-only {
            background: var(--light);
            color: var(--text-secondary);
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 8px;
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
        
        /* Buttons */
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
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
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        /* Search and Filters */
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-input {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--light);
            border-radius: 5px;
            background: var(--dark);
            color: var(--text);
        }
        
        /* Payment Summary */
        .payment-summary {
            background: var(--light);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid var(--secondary);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--hover-bg);
        }
        
        .summary-total {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--success);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .status-paid {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
        }
        
        .status-pending {
            background: rgba(243, 156, 18, 0.2);
            color: #f39c12;
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
            max-width: 500px;
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

        .stock-bajo {
            color: var(--danger);
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.primary {
            border-left-color: var(--secondary);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
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

        /* Cart Items */
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--light);
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .tabs {
                flex-direction: column;
            }
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
                <li class="active"><a href="ventas_pagos.php"><i class="fas fa-money-bill-wave"></i> Ventas y Pagos</a></li>
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
                <h1><i class="fas fa-money-bill-wave"></i> Ventas y Pagos</h1>
                <div class="user-info">
                    <div class="user-avatar">AL</div>
                    <span>Ana López (Cajero)</span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" data-tab="pagos">
                    <i class="fas fa-credit-card"></i> Registro de Pagos
                </div>
                <div class="tab" data-tab="ventas">
                    <i class="fas fa-shopping-cart"></i> Ventas de Repuestos
                </div>
                <div class="tab" data-tab="historial">
                    <i class="fas fa-history"></i> Historial
                </div>
                <div class="tab" data-tab="reportes">
                    <i class="fas fa-chart-bar"></i> Reportes
                </div>
            </div>

            <!-- Registro de Pagos -->
            <div class="content-section active" id="pagos">
                <h2 class="section-title"><i class="fas fa-credit-card"></i> Registro de Pagos de Servicios</h2>
                
                <div class="form-group">
                    <label><i class="fas fa-clipboard-list"></i> Seleccionar Orden de Servicio</label>
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
                        <label><i class="fas fa-user"></i> Cliente</label>
                        <input type="text" class="form-control" id="clientName" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Vehículo</label>
                        <input type="text" class="form-control" id="vehicleInfo" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-money-bill-wave"></i> Total a Pagar (Bs)</label>
                    <input type="text" class="form-control" id="totalAmount" readonly style="font-size: 1.2rem; font-weight: bold; color: var(--success);" value="Bs 0.00">
                </div>

                <!-- Información de Pago -->
                <div class="payment-summary">
                    <h3 style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Información de Pago
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Método de Pago</label>
                            <select class="form-control" id="paymentMethod">
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta de Crédito/Débito</option>
                                <option value="transferencia">Transferencia Bancaria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-cash-register"></i> Monto Recibido (Bs)</label>
                            <input type="number" class="form-control" id="amountReceived" step="0.01" oninput="calcularCambio()">
                        </div>
                    </div>
                    
                    <div id="changeSection" style="display: none;">
                        <div class="summary-row">
                            <span><i class="fas fa-exchange-alt"></i> Cambio:</span>
                            <span id="changeAmount" style="color: var(--success); font-weight: bold;">Bs 0.00</span>
                        </div>
                    </div>

                    <button class="btn btn-success" style="margin-top: 15px; width: 100%;" onclick="processPayment()">
                        <i class="fas fa-credit-card"></i> Procesar Pago y Generar Recibo
                    </button>
                </div>
            </div>

            <!-- Ventas de Repuestos -->
            <div class="content-section" id="ventas">
                <h2 class="section-title"><i class="fas fa-shopping-cart"></i> Venta de Repuestos</h2>
                
                <div class="search-box">
                    <input type="text" class="search-input" id="searchRepuesto" placeholder="Buscar repuesto por código o nombre..." onkeyup="buscarRepuestos()">
                    <button class="btn btn-primary" onclick="buscarRepuestos()">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Cliente</label>
                        <select class="form-control" id="saleClient">
                            <option value="">Seleccionar cliente...</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?php echo $cliente['id_cliente']; ?>"><?php echo $cliente['nombre']; ?></option>
                            <?php endforeach; ?>
                            <option value="0">Venta al contado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Vendedor</label>
                        <input type="text" class="form-control" value="Ana López" readonly>
                    </div>
                </div>

                <!-- Lista de Repuestos -->
                <h3 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-boxes"></i> Repuestos Disponibles
                </h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-box"></i> Repuesto</th>
                                <th><i class="fas fa-tag"></i> Precio</th>
                                <th><i class="fas fa-cubes"></i> Stock</th>
                                <th><i class="fas fa-sort-amount-up"></i> Cantidad</th>
                                <th><i class="fas fa-cog"></i> Acción</th>
                            </tr>
                        </thead>
                        <tbody id="repuestosList">
                            <?php foreach ($repuestos as $repuesto): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-cog"></i>
                                            <?php echo $repuesto['nombre_repuesto']; ?>
                                            <?php if ($repuesto['stock_actual'] <= $repuesto['stock_minimo']): ?>
                                                <span class="stock-bajo">
                                                    <i class="fas fa-exclamation-triangle"></i> Stock bajo
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo formatearMoneda($repuesto['precio_unitario']); ?></td>
                                    <td>
                                        <span style="display: flex; align-items: center; gap: 4px;">
                                            <i class="fas fa-cubes"></i> <?php echo $repuesto['stock_actual']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number" id="cantidad_<?php echo $repuesto['id_repuesto']; ?>" 
                                               class="form-control" style="width: 80px;" min="1" 
                                               max="<?php echo $repuesto['stock_actual']; ?>" value="1">
                                    </td>
                                    <td>
                                        <button class="btn btn-primary" onclick="agregarAlCarrito(<?php echo $repuesto['id_repuesto']; ?>, '<?php echo $repuesto['nombre_repuesto']; ?>', <?php echo $repuesto['precio_unitario']; ?>)">
                                            <i class="fas fa-plus"></i> Agregar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Carrito de Venta -->
                <h3 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-shopping-cart"></i> Carrito de Venta
                </h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-box"></i> Repuesto</th>
                                <th><i class="fas fa-tag"></i> Precio Unitario</th>
                                <th><i class="fas fa-sort-amount-up"></i> Cantidad</th>
                                <th><i class="fas fa-calculator"></i> Subtotal</th>
                                <th><i class="fas fa-cog"></i> Acciones</th>
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
                        <span><i class="fas fa-receipt"></i> Subtotal:</span>
                        <span id="subtotalVenta">Bs 0.00</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span><i class="fas fa-money-bill-wave"></i> Total:</span>
                        <span id="totalVenta">Bs 0.00</span>
                    </div>
                    
                    <button class="btn btn-success" style="margin-top: 15px; width: 100%;" onclick="processSale()">
                        <i class="fas fa-file-invoice"></i> Finalizar Venta
                    </button>
                </div>
            </div>

            <!-- Historial -->
            <div class="content-section" id="historial">
                <h2 class="section-title"><i class="fas fa-history"></i> Historial de Transacciones</h2>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-file-alt"></i> Tipo</th>
                                <th><i class="fas fa-hashtag"></i> N° Comprobante</th>
                                <th><i class="fas fa-user"></i> Cliente</th>
                                <th><i class="fas fa-car"></i> Vehículo</th>
                                <th><i class="fas fa-money-bill-wave"></i> Monto</th>
                                <th><i class="fas fa-calendar"></i> Fecha</th>
                                <th><i class="fas fa-credit-card"></i> Método</th>
                                <th><i class="fas fa-info-circle"></i> Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historialTransacciones as $transaccion): ?>
                                <tr>
                                    <td>
                                        <?php if ($transaccion['tipo'] == 'pago'): ?>
                                            <span style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fas fa-credit-card"></i> Pago
                                            </span>
                                        <?php else: ?>
                                            <span style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fas fa-shopping-cart"></i> Venta
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $transaccion['comprobante']; ?></td>
                                    <td><?php echo $transaccion['cliente']; ?></td>
                                    <td><?php echo $transaccion['vehiculo']; ?></td>
                                    <td><?php echo formatearMoneda($transaccion['monto']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($transaccion['fecha'])); ?></td>
                                    <td>
                                        <span style="text-transform: capitalize;">
                                            <?php echo $transaccion['metodo']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $transaccion['estado'] == 'completado' ? 'status-paid' : 'status-pending'; ?>">
                                            <?php if($transaccion['estado'] == 'completado'): ?>
                                                <i class="fas fa-check-circle"></i> Pagado
                                            <?php else: ?>
                                                <i class="fas fa-clock"></i> Pendiente
                                            <?php endif; ?>
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
                <h2 class="section-title"><i class="fas fa-chart-bar"></i> Reportes de Ventas y Pagos</h2>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Fecha Desde</label>
                        <input type="date" class="form-control" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Fecha Hasta</label>
                        <input type="date" class="form-control" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <button class="btn btn-success" style="margin-top: 20px;" onclick="generarReporte()">
                    <i class="fas fa-chart-bar"></i> Generar Reporte
                </button>

                <!-- Resumen de Estadísticas -->
                <div class="stats-grid">
                    <div class="stat-card success">
                        <i class="fas fa-chart-line" style="font-size: 2rem; color: var(--success);"></i>
                        <div class="stat-value"><?php echo formatearMoneda($estadisticas['ventas_mes']); ?></div>
                        <div class="stat-label">Ventas del Mes</div>
                    </div>
                    <div class="stat-card primary">
                        <i class="fas fa-exchange-alt" style="font-size: 2rem; color: var(--secondary);"></i>
                        <div class="stat-value"><?php echo $estadisticas['transacciones']; ?></div>
                        <div class="stat-label">Transacciones</div>
                    </div>
                    <div class="stat-card warning">
                        <i class="fas fa-clock" style="font-size: 2rem; color: var(--warning);"></i>
                        <div class="stat-value"><?php echo formatearMoneda($estadisticas['pendientes_cobro']); ?></div>
                        <div class="stat-label">Pendientes de Cobro</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Recibo -->
    <div class="modal" id="receiptModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 style="text-align: center; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <i class="fas fa-file-invoice"></i> Recibo de Pago
            </h2>
            <div style="text-align: center; margin-bottom: 20px;">
                <strong id="numeroRecibo" style="font-size: 1.1rem;">REC-2024-0001</strong>
            </div>
            
            <div style="margin-bottom: 15px; text-align: center;">
                <strong>Taller Automotriz Servistar</strong><br>
                <span style="color: var(--text-secondary);">Av. Automotor #123, La Paz</span><br>
                <span style="color: var(--text-secondary);">Tel: 777-12345 | NIT: 123456789</span>
            </div>
            
            <div style="border: 1px solid var(--light); padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                    <i class="fas fa-user"></i> <strong>Cliente:</strong> <span id="clienteRecibo">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                    <i class="fas fa-car"></i> <strong>Vehículo:</strong> <span id="vehiculoRecibo">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-calendar"></i> <strong>Fecha:</strong> <span id="fechaRecibo"><?php echo date('d/m/Y H:i'); ?></span>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px dashed var(--light);">
                        <i class="fas fa-tools"></i> Servicios:
                    </td>
                    <td style="padding: 8px; border-bottom: 1px dashed var(--light); text-align: right;" id="montoRecibo">Bs 0.00</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px dashed var(--light);">
                        <i class="fas fa-credit-card"></i> Método de Pago:
                    </td>
                    <td style="padding: 8px; border-bottom: 1px dashed var(--light); text-align: right;" id="metodoRecibo">Efectivo</td>
                </tr>
                <tr>
                    <td style="padding: 8px;"><strong>Total:</strong></td>
                    <td style="padding: 8px; text-align: right;"><strong id="totalRecibo">Bs 0.00</strong></td>
                </tr>
            </table>
            
            <div style="text-align: center; margin-top: 20px; color: var(--text-secondary);">
                <i class="fas fa-heart"></i> ¡Gracias por su preferencia!<br>
                <small>Recibo generado automáticamente</small>
            </div>
            
            <button class="btn btn-primary" style="width: 100%; margin-top: 20px;" onclick="printReceipt()">
                <i class="fas fa-print"></i> Imprimir Recibo
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
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-cog"></i> ${item.nombre}
                            </div>
                        </td>
                        <td>Bs ${item.precio.toFixed(2)}</td>
                        <td>
                            <input type="number" value="${item.cantidad}" min="1" 
                                   onchange="actualizarCantidad(${index}, this.value)" 
                                   style="width: 80px;" class="form-control">
                        </td>
                        <td>Bs ${itemSubtotal.toFixed(2)}</td>
                        <td>
                            <button class="btn btn-danger" style="padding: 5px 10px;" onclick="eliminarDelCarrito(${index})">
                                <i class="fas fa-trash"></i>
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

        // Funciones auxiliares
        function closeModal() {
            document.getElementById('receiptModal').style.display = 'none';
        }

        function printReceipt() {
            window.print();
        }

        function generarReporte() {
            alert('Función de generar reporte en desarrollo...');
        }
    </script>
</body>
</html>
<?php
// Cerrar conexión
$conn = null;
?>