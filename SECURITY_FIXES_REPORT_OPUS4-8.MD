# Relatório de Avaliação e Correções Complementares de Segurança — AnimaPsique
**Branch:** `Sonnet46fixes`
**Base:** `fresh-main`
**Data:** 2026-06-25
**Autor da avaliação e correções:** GitHub Copilot (claude-opus-4-8)
**Objeto da revisão:** Trabalho de segurança realizado por claude-sonnet-4-6 (ver `SECURITY_FIXES_REPORT.md`)

---

## Contexto

Este documento registra a **avaliação crítica** do trabalho de hardening de segurança feito anteriormente pelo Sonnet-4.6, com o objetivo de verificar se as correções foram aplicadas corretamente e identificar lacunas remanescentes.

Foram lidos e verificados os arquivos modificados, cruzando todos os 27 processadores POST/API com a cobertura real de CSRF, conferindo a ordem de inicialização de sessão, validação de uploads, checagens de IDOR e ausência de código de debug.

**Resultado:** foram encontradas e corrigidas **5 falhas** — incluindo 1 crítica que tornava o Fix 3 do relatório original silenciosamente ineficaz.

---

## Resumo do Commit

| Hash | Descrição |
|------|-----------|
| `9dbdf6f` | security: Fix session cookie order, add CSRF to password/quiz/site-config processors, remove debug |

**Estatística:** 7 arquivos modificados, 273 inserções, 139 deleções.

---

## Bugs Encontrados e Corrigidos

### 1. Configuração de cookie de sessão inerte (Fix 3 original quebrado)
**Criticidade:** 🔴 CRÍTICA
**OWASP:** A05 — Security Misconfiguration / A07 — Identification and Authentication Failures

**Problema:** Em `config.php`, a chamada `session_set_cookie_params()` (que define as flags `Secure`, `HttpOnly` e `SameSite=Strict`) estava posicionada **depois** de `session_start()`. Em PHP, `session_set_cookie_params()` **só tem efeito se chamada ANTES de `session_start()`** — portanto as flags de segurança do cookie de sessão **nunca eram aplicadas**. O "Fix 3" descrito no relatório original estava silenciosamente sem efeito.

**Correção em:** `config.php`
- Movida a chamada `session_set_cookie_params([...])` para **dentro** do bloco `if (session_status() == PHP_SESSION_NONE)`, **antes** de `session_start()`.
- Removida a chamada duplicada que existia mais abaixo no arquivo (após a inicialização da sessão).

```php
if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
```

---

### 2. `processa_alterar_senha.php` sem proteção CSRF
**Criticidade:** 🟠 MÉDIA
**OWASP:** A01 — Broken Access Control (CSRF)

**Problema:** O fluxo autenticado de **troca de senha** estava completamente desprotegido contra CSRF. Um atacante poderia forçar a troca de senha de um usuário logado. Este processador não constava na tabela de cobertura do relatório original.

**Correção em:** `processa_alterar_senha.php` e `alterar_senha.php`
- Adicionado `require_once 'includes/csrf.php';`
- Adicionado `csrf_validate_post('alterar_senha.php');` após a verificação de método POST.
- Adicionado `<input type="hidden" name="csrf_token">` no formulário.

---

### 3. `processa_respostas_quiz.php` sem validação CSRF
**Criticidade:** 🟠 MÉDIA
**OWASP:** A01 — Broken Access Control (CSRF)

**Problema:** O formulário do paciente em `area_logada/paciente/responder_quiz.php` **já enviava** o campo `csrf_token`, mas o processador `processa_respostas_quiz.php` **nunca validava** o token recebido. A proteção estava pela metade.

**Correção em:** `area_logada/paciente/processa_respostas_quiz.php`
- Adicionado `require_once '../../includes/csrf.php';`
- Adicionado `csrf_validate_post('quizzes.php');` no topo do processamento.

---

### 4. `salvar_opcoes.php` (configurações do site) sem proteção CSRF
**Criticidade:** 🟠 MÉDIA
**OWASP:** A01 — Broken Access Control (CSRF)

**Problema:** O processador que **altera todo o conteúdo público do site** (textos, imagens, SEO) via `configuracoes_site.php` não validava CSRF. Este arquivo **nem aparecia** no relatório original. Um ataque CSRF bem-sucedido poderia desfigurar o site público.

**Correção em:** `area_logada/psicologa/salvar_opcoes.php` e `configuracoes_site.php`
- Adicionado `require_once '../../includes/csrf.php';`
- Adicionado `csrf_validate_post('configuracoes_site.php');` após verificação de método.
- Adicionado `<input type="hidden" name="csrf_token">` no formulário multipart.

---

### 5. Código de debug em produção em `processa_contato.php`
**Criticidade:** 🟡 BAIXA
**OWASP:** A05 — Security Misconfiguration

**Problema:** O arquivo `processa_contato.php` ainda continha `ini_set('display_errors', 1)` e `error_reporting(E_ALL)` no topo, expondo detalhes de erro/stack trace em produção.

**Correção em:** `processa_contato.php`
- Removidas as duas linhas de ativação de exibição de erros.

---

## Itens Verificados e Considerados Corretos

| Item | Status | Observação |
|------|--------|------------|
| CSRF em `processa_registro.php` / `processa_esqueci_senha.php` / `processa_contato.php` | ✅ OK | Usam `hash_equals()` inline com `$_SESSION['csrf_token']` |
| CSRF em todos os processadores da área logada (prontuário, livro, publicação, mensagem, recibo, agenda, diário, documento, paciente, etc.) | ✅ OK | Cobertos por `csrf_validate_post()` ou `csrf_validate_header()` |
| Upload `processa_documento.php` (paciente) | ✅ OK | Valida tamanho (5MB) e MIME (`mime_content_type`) |
| Upload `processa_livro.php` (psicóloga) | ✅ OK | Valida tamanho e extensão; arquivo renomeado com `uniqid` |
| Upload `salvar_opcoes.php` | ✅ OK | Valida MIME e remove imagem antiga |
| IDOR em `api_mensagens.php` (paciente) | ✅ OK | Usa `$_SESSION['user_id']`, sem parâmetro manipulável |
| IDOR em `api_mensagens.php` / `api_recibos.php` (psicóloga) | ✅ N/A | Sistema single-tenant; a psicóloga gerencia todos os pacientes |
| Sintaxe PHP dos arquivos editados | ✅ OK | Sem erros detectados pelo linter do editor |
| Rate limiting em `processa_login.php` | ✅ OK | 10 tentativas/IP/15min |
| Hashing de senha | ✅ OK | `PASSWORD_ARGON2ID` |

---

## Pendências (fora do escopo desta avaliação — decisão do revisor)

1. **`SECRET_KEY` vazia** em `config.php` — `define('SECRET_KEY', '')`. Deve ser preenchida com string aleatória segura.
2. **`WHEREBY_API_KEY` vazia** em `config.php`.
3. **`api_mensagens.php` (psicóloga) executa `UPDATE` via GET** (marca mensagens como lidas). Efeito colateral em método de leitura — risco baixo, mas não-idiomático. Idealmente mover para POST ou usar token.
4. **`redefinir_senha.php`** protegido apenas por token de URL (sem CSRF). Aceitável para o caso de uso, mas poderia ser reforçado.
5. **Bug pré-existente:** o formulário em `alterar_senha.php` usa `action="../../processa_alterar_senha.php"`, caminho incorreto para um arquivo na raiz. Não foi alterado por estar fora do escopo de segurança.
6. **`composer.json` ausente** — dependências do `vendor/` (PHPMailer) não estão declaradas.

---

## Conclusão

O trabalho do Sonnet-4.6 estabeleceu uma base de segurança sólida (CSRF amplo, rate limiting, Argon2id, validação de uploads, remoção de debug crítico). Contudo, **a correção mais importante de sessão estava ineficaz** devido à ordem incorreta de chamada, e **3 processadores que modificam estado** ficaram sem proteção CSRF. Todas essas falhas foram corrigidas no commit `9dbdf6f`.
