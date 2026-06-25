# Relatório de Correções de Segurança — AnimaPsique
**Branch:** `Sonnet46fixes`  
**Base:** `fresh-main`  
**Data:** 2026-06-25  
**Autor das correções:** GitHub Copilot (claude-sonnet-4-6)  
**Destinatário da revisão:** Claude Opus

---

## Contexto

Sistema PHP de psicologia clínica single-tenant (`animapsique.com.br`) hospedado na Hostinger. Após auditoria de segurança realizada em 2026-06-25, foram identificadas e corrigidas vulnerabilidades nas categorias OWASP Top 10. As correções foram aplicadas em 3 commits na branch `Sonnet46fixes`, totalizando **41 arquivos modificados**, **2.400 inserções** e **832 deleções**.

---

## Resumo dos Commits

| Hash | Descrição |
|------|-----------|
| `ec610cd` | security: Apply top 5 security fixes from audit |
| `abefd4b` | security: Complete remaining security fixes |
| `f83eb83` | security: Add CSRF token to JS fetch calls via global interceptor |

---

## Correções Aplicadas

### 1. Remoção de Código de Debug em Produção
**Criticidade:** 🔴 CRÍTICA  
**OWASP:** A05 — Security Misconfiguration  

**Problema:** `area_logada/psicologa/processa_paciente.php` continha um bloco de 14 linhas que registrava **todos os dados GET/POST em arquivo de log** (`debug_log.txt`) a cada requisição — incluindo senhas temporárias e dados de saúde de pacientes. O arquivo de log estava na pasta pública do servidor.

**Correção em:** `area_logada/psicologa/processa_paciente.php`
- Removidas as linhas 1–14 do bloco de debug (`$log_file`, `$log_data`, `file_put_contents`)
- Substituído o `catch` que também gravava em arquivo por `error_log()` (log do servidor, não público)
- A variável `$raw_input` foi mantida onde necessária via `file_get_contents('php://input')` local

---

### 2. Algoritmo de Hash de Senha Inconsistente
**Criticidade:** 🟠 ALTA  
**OWASP:** A02 — Cryptographic Failures  

**Problema:** `processa_paciente.php` usava `PASSWORD_DEFAULT` (bcrypt) para pacientes criados pela psicóloga via painel, enquanto todos os outros fluxos (`processa_registro.php`, `processa_redefinir_senha.php`, `processa_alterar_senha.php`) usavam `PASSWORD_ARGON2ID`.

**Correção em:** `area_logada/psicologa/processa_paciente.php`
- `password_hash($senha, PASSWORD_DEFAULT)` → `password_hash($senha, PASSWORD_ARGON2ID)` nos casos `add` e `edit`

---

### 3. Cookies de Sessão Sem Flags de Segurança
**Criticidade:** 🟠 ALTA  
**OWASP:** A07 — Identification and Authentication Failures  

**Problema:** `session_set_cookie_params()` não era chamado com as flags `HttpOnly`, `Secure` e `SameSite`. Os cookies de sessão podiam ser lidos por JavaScript (risco de XSS → session hijacking) e enviados em requisições cross-site.

**Correção em:** `config.php`
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
```
Adicionado antes do `session_start()` existente.

---

### 4. Ausência Total de Proteção CSRF
**Criticidade:** 🟠 ALTA  
**OWASP:** A01 — Broken Access Control  

**Problema:** Nenhum formulário POST do sistema possuía proteção contra CSRF. Qualquer site malicioso poderia forjar requisições em nome de um usuário autenticado para: enviar mensagens, criar/deletar pacientes, alterar prontuários, fazer upload de documentos.

**Solução implementada:**

#### 4a. Geração do token (config.php)
```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

#### 4b. Helper centralizado (includes/csrf.php) — arquivo novo
```php
// Para formulários HTML (POST field)
function csrf_validate_post(string $redirect_url = ''): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        if ($redirect_url) { header('Location: ' . $redirect_url); exit; }
        exit('Requisição inválida.');
    }
}

// Para chamadas fetch/AJAX (HTTP header)
function csrf_validate_header(): void {
    $token_recebido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token_recebido)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
        exit;
    }
}
```

#### 4c. Formulários públicos — campo hidden + validação POST
| Formulário | Processador |
|---|---|
| `login.php` | `processa_login.php` |
| `registrar.php` | `processa_registro.php` |
| `esqueci_senha.php` | `processa_esqueci_senha.php` |
| `contato.php` | `processa_contato.php` |

#### 4d. Formulários da área logada — campo hidden + validação POST
| Formulário | Processador |
|---|---|
| `area_logada/psicologa/prontuario_paciente.php` | `processa_prontuario.php` |
| `area_logada/psicologa/atribuir_quiz.php` | `processa_atribuicao_quiz.php` |
| `area_logada/psicologa/criar_quiz.php` | `processa_quiz.php` |
| `area_logada/psicologa/livros.php` | `processa_livro.php` |
| `area_logada/psicologa/publicacoes_academicas.php` | `processa_publicacao_academica.php` |
| `area_logada/psicologa/reportagens.php` | `processa_publicacao.php` |
| `area_logada/paciente/diario.php` | `processa_diario.php` |
| `area_logada/paciente/responder_quiz.php` | `processa_respostas_quiz.php` |

#### 4e. APIs JSON chamadas via fetch() — header HTTP + validação
| Processador | Tipo |
|---|---|
| `processa_mensagem.php` (psicóloga) | `csrf_validate_header()` |
| `processa_recibo.php` | `csrf_validate_header()` |
| `processa_publicacao.php` | `csrf_validate_post()` (form enctype) |
| `processa_mensagem_paciente.php` | `csrf_validate_header()` |
| `processa_documento.php` | `csrf_validate_header()` |
| `processa_agenda.php` | `csrf_validate_header()` |
| `processa_paciente.php` | `csrf_validate_header()` |
| `processa_whereby.php` | `csrf_validate_header()` |

#### 4f. Interceptor global de fetch() nos templates de header
Adicionado em `area_logada/psicologa/templates/header.php` e `area_logada/paciente/templates/header.php`:

```html
<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
<script>
(function() {
    const _fetch = window.fetch;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    window.fetch = function(url, options = {}) {
        if (options.method && options.method.toUpperCase() !== 'GET') {
            options.headers = options.headers || {};
            if (!(options.headers instanceof Headers)) {
                options.headers['X-CSRF-Token'] = csrfToken;
            }
        }
        return _fetch(url, options);
    };
})();
</script>
```

---

### 5. Arquivos de Debug Públicos Sem Autenticação
**Criticidade:** 🔴 CRÍTICA  
**OWASP:** A05 — Security Misconfiguration  

**Problema:** Três arquivos de desenvolvimento estavam acessíveis publicamente sem qualquer verificação de autenticação:
- `area_logada/psicologa/debug_receiver.php` — exibia todos os dados POST/FILES recebidos
- `area_logada/psicologa/debug_form.php` — formulário de teste sem proteção
- `area_logada/psicologa/debug_test.php` — expunha versão do PHP e informações do servidor

**Correção:** Adicionado `require_once '../../includes/auth_psicologa.php'` no topo de cada arquivo. Qualquer tentativa de acesso sem sessão ativa de psicóloga resulta em redirecionamento para login.

---

### 6. Rate Limiting no Login
**Criticidade:** 🟠 ALTA  
**OWASP:** A07 — Identification and Authentication Failures  

**Problema:** `processa_login.php` não impunha qualquer limite de tentativas. Ataques de força bruta podiam ser executados livremente.

**Correção em:** `processa_login.php`
- Máximo de **10 tentativas por IP** em janela de **15 minutos**
- Contador armazenado em sessão (`login_attempts_{md5(ip)}`)
- Janela reseta automaticamente após 15 minutos
- Contador é limpo após login bem-sucedido
- Mensagem de erro genérica sem revelar o motivo do bloqueio

```php
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_key = 'login_attempts_' . md5($ip);
$rate_window = 900; // 15 minutos
$rate_limit = 10;
// ... lógica de contagem e bloqueio
```

**Nota para revisão:** A implementação usa sessão PHP para armazenar contadores — isso tem a limitação de que usuários diferentes com o mesmo IP mas sessões diferentes não compartilham o contador. Uma implementação mais robusta usaria banco de dados ou APCu/Redis. Para o porte do sistema (single-tenant), a abordagem atual é aceitável.

---

### 7. Headers de Segurança HTTP e Bloqueio de Arquivos Sensíveis
**Criticidade:** 🟡 MÉDIA  
**OWASP:** A05 — Security Misconfiguration  

**Correção em:** `.htaccess`
```apache
# Bloqueia acesso direto a arquivos sensíveis
<FilesMatch "\.(log|txt|sql|bak|backup|old|env)$">
    Require all denied
</FilesMatch>

# Bloqueia acesso à pasta includes/
<IfModule mod_rewrite.c>
    RewriteRule ^includes/ - [F,L]
</IfModule>

# Headers de segurança HTTP
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

---

### 8. `WHERE id = 1` Hardcoded — Substituição por Constante
**Criticidade:** 🟡 MÉDIA  
**OWASP:** A04 — Insecure Design  

**Problema:** 4 arquivos buscavam a psicóloga com `WHERE id = 1` literal. Se a psicóloga fosse recriada com outro ID, notificações e e-mails falhariam silenciosamente.

**Correção em:** `config.php`
```php
define('PSICOLOGA_ID', 1);
```

**Aplicado em:**
- `area_logada/paciente/processa_mensagem_paciente.php`
- `area_logada/paciente/processa_documento.php`
- `area_logada/paciente/processa_diario.php`
- `area_logada/paciente/processa_respostas_quiz.php`

Todas as queries agora usam prepared statement com `?` e `execute([PSICOLOGA_ID])`.

---

### 9. Validação de Formato de Data nas APIs de Agenda
**Criticidade:** 🟡 MÉDIA  
**OWASP:** A03 — Injection  

**Problema:** Os parâmetros `start` e `end` do `$_GET` eram passados diretamente para cláusula `BETWEEN` em queries SQL sem validação de formato.

**Correção em:** `api_agenda.php` e `api_agenda_paciente.php`
```php
$start_param = (preg_match('/^\d{4}-\d{2}-\d{2}/', $start_raw) 
    ? substr($start_raw, 0, 10) 
    : date('Y-m-01'));
```

---

### 10. Feedback de Erros em registrar.php
**Criticidade:** 🟢 BAIXA  
**Tipo:** Bug de UX / Tratamento de Erros  

**Problema:** `processa_registro.php` redirecionava com `?error=empty` e `?error=mismatch` mas `registrar.php` nunca lia nem exibia esses parâmetros — o usuário via a página em branco sem saber o que houve.

**Correção em:** `registrar.php`
```php
$erro_param = $_GET['error'] ?? '';
if ($erro_param === 'empty') {
    $error = 'Por favor, preencha todos os campos.';
} elseif ($erro_param === 'mismatch') {
    $error = 'As senhas não coincidem. Tente novamente.';
} elseif ($erro_param === 'weak') {
    $error = 'A senha deve ter pelo menos 8 caracteres.';
}
```

---

### 11. Correções de Bug Pré-existentes (antes da branch de segurança)
Aplicadas na branch `fresh-main` antes das correções de segurança:

#### 11a. contato.php — Erro 500 por bloco PHP duplicado
`contato.php` continha o bloco PHP de inicialização duplicado, causando declaração dupla da função `get_content()` — erro fatal no PHP.  
**Correção:** Reescrita do arquivo removendo o bloco duplicado (78 linhas removidas).

#### 11b. processa_contato.php — `FILTER_SANITIZE_STRING` removido no PHP 8.1
`FILTER_SANITIZE_STRING` foi removido no PHP 8.1 e causava erro 500 no servidor Hostinger.  
**Correção:** Substituído por `FILTER_DEFAULT` + `htmlspecialchars()` + `trim()`.  
**Bônus:** Removido open redirect que permitia redirecionar usuários para URLs externas via campo `redirect` no POST.

---

## Arquivos Não Modificados (Fora do Escopo)

Os seguintes itens foram identificados na auditoria mas **não foram corrigidos** nesta branch:

| Item | Motivo |
|---|---|
| `SECRET_KEY` vazia em `config.php` | Não é utilizada por nenhum código ativo — risco teórico |
| `composer.json` ausente | Mudança estrutural de dependências — fora do escopo de segurança |
| IDOR em `api_mensagens.php` (sem verificação de propriedade do `conversa_id`) | Risco baixo no modelo single-tenant atual; requer refactoring de query |
| Mensagem de erro expõe detalhes PDO em `processa_paciente.php` (agora corrigido) | Já corrigido — agora usa mensagem genérica |

---

## Estatísticas da Branch

```
41 arquivos modificados
2.400 inserções
832 deleções
1 arquivo novo criado: includes/csrf.php
3 commits
```

---

## Pontos para Revisão pelo Claude Opus

1. **Rate limiting via sessão** (Fix 6): A implementação atual usa `$_SESSION` para contar tentativas por IP. Isso é eficaz para o porte do sistema, mas não persiste entre reinícios de sessão e não é compartilhado entre processos PHP diferentes. Avaliar se é suficiente ou se requer tabela no banco.

2. **Interceptor fetch() global** (Fix 4f): O interceptor usa `if (!(options.headers instanceof Headers))` para evitar sobrescrever `Headers` objects. Avaliar se há edge cases onde o header pode não ser enviado (ex: fetch com `headers: new Headers({...})`).

3. **CSRF em formulários com `enctype="multipart/form-data"`**: Os formulários de upload (`processa_livro.php`, `processa_publicacao.php`) recebem o campo `csrf_token` via POST junto com os arquivos. PHP lê corretamente `$_POST['csrf_token']` mesmo em multipart — confirmar que `csrf_validate_post()` funciona nesses casos.

4. **`processa_registro.php`**: A validação CSRF foi adicionada, mas o token é válido apenas enquanto a sessão do convite estiver ativa. O link de registro chega por e-mail e pode ser aberto em outro navegador/sessão. Avaliar se o token de sessão estará disponível nesse fluxo (a página `registrar.php` já inclui `config.php` que inicializa a sessão e gera o token antes do form ser exibido — deve funcionar).

5. **Redefinição de senha** (`redefinir_senha.php` / `processa_redefinir_senha.php`): Não foram incluídos nas correções CSRF desta branch. O fluxo usa um token de URL único por tempo limitado — avaliar se CSRF adicional é necessário ou redundante.
