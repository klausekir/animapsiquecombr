<?php
require_once '../../includes/auth_psicologa.php';

// Define o cabeçalho como JSON para consistência
header('Content-Type: application/json');

// Cria uma resposta de sucesso simples
$response = [
    'success' => true,
    'message' => 'O ambiente PHP está a funcionar corretamente neste diretório.',
    'php_version' => phpversion()
];

// Imprime a resposta em formato JSON
echo json_encode($response);
?>
