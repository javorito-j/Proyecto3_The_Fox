<?php
require 'Dashboard.php'; // tu conexión a la base de datos

// Ejemplo de consulta (ajústalo según tus tablas reales)
$ordenesPendientes = $conn->query("SELECT COUNT(*) AS total FROM ordenes WHERE estado='pendiente'")->fetch_assoc()['total'];
$repuestosStockBajo = $conn->query("SELECT COUNT(*) AS total FROM repuestos WHERE stock_actual <= 5")->fetch_assoc()['total'];
$ventasMes = $conn->query("SELECT SUM(total) AS total FROM ventas WHERE MONTH(fecha)=MONTH(CURDATE())")->fetch_assoc()['total'];
$herramientasPendientes = $conn->query("SELECT COUNT(*) AS total FROM herramientas WHERE estado='pendiente'")->fetch_assoc()['total'];

// Datos para el gráfico (ventas por día)
$ventas = $conn->query("
    SELECT DATE(fecha) AS fecha, SUM(total) AS total
    FROM ventas
    WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(fecha)
    ORDER BY fecha ASC
");

$labels = [];
$valores = [];
while ($fila = $ventas->fetch_assoc()) {
    $labels[] = $fila['fecha'];
    $valores[] = $fila['total'];
}

// Devuelve datos en formato JSON
echo json_encode([
    'ordenesPendientes' => $ordenesPendientes,
    'repuestosStockBajo' => $repuestosStockBajo,
    'ventasMes' => number_format($ventasMes, 2),
    'herramientasPendientes' => $herramientasPendientes,
    'labels' => $labels,
    'valores' => $valores
]);
?>