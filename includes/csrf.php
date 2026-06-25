<?php
/**
 * Funções auxiliares para proteção CSRF.
 * Requer que config.php já tenha sido incluído (inicia sessão e gera o token).
 */

/**
 * Valida o token CSRF recebido via POST (formulários HTML).
 * Encerra a execução com 403 em caso de falha.
 */
function csrf_validate_post(string $redirect_url = ''): void
{
    $token_recebido = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token_recebido)) {
        http_response_code(403);
        if ($redirect_url) {
            header('Location: ' . $redirect_url);
            exit;
        }
        exit('Requisição inválida.');
    }
}

/**
 * Valida o token CSRF recebido via header HTTP (fetch/AJAX).
 * Encerra a execução com JSON de erro em caso de falha.
 */
function csrf_validate_header(): void
{
    $token_recebido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token_recebido)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
        exit;
    }
}
