<?php
header('Content-Type: application/json');

$base_path = realpath(dirname(__FILE__) . '/../../');
require_once $base_path . '/config.php';

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once $base_path . '/includes/db.php';

$paciente_id = $_SESSION['user_id'];

try {
    $pdo = conectar();
    $eventos = [];
    
    $start_raw = $_GET['start'] ?? '';
    $end_raw   = $_GET['end'] ?? '';

    // Valida formato de data para evitar injeção via BETWEEN
    $start_date_str = (preg_match('/^\d{4}-\d{2}-\d{2}/', $start_raw) ? substr($start_raw, 0, 10) : date('Y-m-01'));
    $end_date_str   = (preg_match('/^\d{4}-\d{2}-\d{2}/', $end_raw)   ? substr($end_raw,   0, 10) : date('Y-m-t'));

    // 1. Busca eventos individuais do paciente no período visível
    $sql_individuais = "
        SELECT id, data_hora_inicio, data_hora_fim, status 
        FROM agenda 
        WHERE paciente_id = ? AND data_hora_inicio BETWEEN ? AND ?
    ";
    $stmt_individuais = $pdo->prepare($sql_individuais);
    $stmt_individuais->execute([$paciente_id, $start_date_str, $end_date_str]);
    
    while ($agendamento = $stmt_individuais->fetch(PDO::FETCH_ASSOC)) {
        $cor = '#3b82f6'; // Azul para eventos normais
        if ($agendamento['status'] === 'cancelado') {
            $cor = '#ef4444'; // Vermelho
        }
        $eventos[] = [
            'id' => $agendamento['id'],
            'title' => 'Sessão Agendada',
            'start' => $agendamento['data_hora_inicio'],
            'end' => $agendamento['data_hora_fim'],
            'color' => $cor
        ];
    }

    // 2. Gera eventos a partir das regras de recorrência do paciente
    $start_date_sql = (new DateTime($start_date_str))->format('Y-m-d');
    $sql_recorrencias = "SELECT * FROM agenda_recorrencias WHERE paciente_id = ? AND data_fim_recorrencia >= ?";
    $stmt_recorrencias = $pdo->prepare($sql_recorrencias);
    $stmt_recorrencias->execute([$paciente_id, $start_date_sql]);
    
    $timezone = new DateTimeZone('America/Sao_Paulo');

    while ($regra = $stmt_recorrencias->fetch(PDO::FETCH_ASSOC)) {
        $data_inicio_loop = max(new DateTime($regra['data_inicio_recorrencia'], $timezone), new DateTime($start_date_str, $timezone));
        $data_fim_loop = min(new DateTime($regra['data_fim_recorrencia'], $timezone), new DateTime($end_date_str, $timezone));

        $data_atual = $data_inicio_loop;

        while ($data_atual <= $data_fim_loop) {
            if ($data_atual->format('w') == $regra['dia_semana']) {
                $eventos[] = [
                    'id' => 'rec_' . $regra['id'] . '_' . $data_atual->format('Ymd'),
                    'title' => 'Sessão Agendada',
                    'start' => $data_atual->format('Y-m-d') . ' ' . $regra['hora_inicio'],
                    'end' => $data_atual->format('Y-m-d') . ' ' . $regra['hora_fim'],
                    'color' => '#16a34a' // Verde para recorrentes
                ];
            }
            $data_atual->modify('+1 day');
        }
    }

    echo json_encode($eventos);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro na API de agenda do paciente: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
