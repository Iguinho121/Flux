<?php
// A sessão mantém o token do formulário e a confirmação após o cadastro.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['csrf_cadastro_componente'])) {
    $_SESSION['csrf_cadastro_componente'] = bin2hex(random_bytes(32));
}
$codigoCadastrado = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $codigoCadastrado = $_SESSION['componente_cadastrado'] ?? '';
    unset($_SESSION['componente_cadastrado']);
}
header('Cache-Control: no-store');

/* ---------- Configuração ---------- */

$paginaHistorico   = 'historico.php';
$paginaAtual       = basename(__FILE__);   // nome deste arquivo
$paginaComponentes = 'componentes.php';    // página da lista de componentes (botões "Voltar" e "Cancelar")

/* ---------- Funções auxiliares ---------- */

// Evita XSS ao imprimir dados na tela
function e($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Mantém a opção escolhida no <select> quando o formulário volta com erro
function selecionado($valorAtual, $valorOpcao)
{
    return (string) $valorAtual === (string) $valorOpcao ? 'selected' : '';
}

// Valida inteiros sem aceitar negativos, decimais ou valores que excedam o PHP.
function inteiroValido($valor, $minimo = 0)
{
    if (!ctype_digit($valor)) {
        return false;
    }
    $normalizado = ltrim($valor, '0');
    return filter_var($normalizado === '' ? '0' : $normalizado, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $minimo]]) !== false;
}

// Ícones do formulário
function icone($nome)
{
    return '<span class="material-symbols-outlined icone" aria-hidden="true">' . e($nome) . '</span>';
}

/* ---------- Processamento do formulário ---------- */

$erros = [];
$dados = [
    'nome'                  => '',
    'descricao'             => '',
    'codigo'                => '',
    'categoria_id'          => '',
    'fornecedor_id'         => '',
    'condicao_id'           => '',
    'quantidade_total'      => '',
    'quantidade_disponivel' => '',
    'estoque_minimo'        => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_cadastro_componente'], $token)) {
        $erros[] = 'Formulário expirado ou inválido. Atualize a página e tente novamente.';
    }

    foreach ($dados as $campo => $valor) {
        $entrada = $_POST[$campo] ?? '';
        if (!is_string($entrada)) {
            $erros[] = 'Valor inválido no campo: ' . $campo . '.';
            $entrada = '';
        }
        $dados[$campo] = trim($entrada);
    }
    $dados['codigo'] = strtoupper($dados['codigo']);

    if ($dados['nome'] === '')   $erros[] = 'Informe o nome do componente.';
    if ($dados['codigo'] === '') $erros[] = 'Informe o código do componente.';
    if (preg_match('/^.{0,20}$/us', $dados['codigo']) !== 1) {
        $erros[] = 'O código deve conter até 20 caracteres válidos.';
    }
    if (!inteiroValido($dados['categoria_id'], 1))  $erros[] = 'Selecione uma categoria.';
    if (!inteiroValido($dados['condicao_id'], 1))   $erros[] = 'Selecione a condição da peça.';
    if (!inteiroValido($dados['fornecedor_id'], 1)) $erros[] = 'Selecione um fornecedor.';

    if (!inteiroValido($dados['quantidade_total']))      $erros[] = 'Quantidade total deve ser um número inteiro (0 ou mais).';
    if (!inteiroValido($dados['quantidade_disponivel'])) $erros[] = 'Quantidade disponível deve ser um número inteiro (0 ou mais).';
    if (!inteiroValido($dados['estoque_minimo']))        $erros[] = 'Estoque mínimo deve ser um número inteiro (0 ou mais).';

    if (inteiroValido($dados['quantidade_total']) && inteiroValido($dados['quantidade_disponivel'])
        && (int) $dados['quantidade_disponivel'] > (int) $dados['quantidade_total']) {
        $erros[] = 'A quantidade disponível não pode ser maior que a quantidade total.';
    }

    if (empty($erros)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO componentes
                    (nome, descricao, codigo, categoria_id, fornecedor_id, condicao_id,
                     quantidade_total, quantidade_disponivel, estoque_minimo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $dados['nome'],
                $dados['descricao'],
                $dados['codigo'],
                (int) $dados['categoria_id'],
                (int) $dados['fornecedor_id'],
                (int) $dados['condicao_id'],
                (int) $dados['quantidade_total'],
                (int) $dados['quantidade_disponivel'],
                (int) $dados['estoque_minimo'],
            ]);

            // Confirma a gravação antes de permitir a mensagem de sucesso.
            $pdo->commit();

            // POST/Redirect/GET: atualizar a confirmação não repete o INSERT.
            $_SESSION['componente_cadastrado'] = $dados['codigo'];
            header('Location: ' . rawurlencode($paginaAtual), true, 303);
            exit;

        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() == 23000) {
                $erros[] = 'Já existe um componente com esse código, ou algum item selecionado não existe.';
            } else {
                error_log('Erro ao cadastrar componente: ' . $ex->getMessage());
                $erros[] = 'Não foi possível salvar o componente. Tente novamente.';
            }
        }
    }
}

/* ---------- Opções dos campos de seleção ---------- */

$categorias = $condicoes = $fornecedores = [];
if ($codigoCadastrado === '') {
    $categorias   = $pdo->query('SELECT id, nome FROM categorias ORDER BY nome')->fetchAll();
    $condicoes    = $pdo->query('SELECT id, nome, descricao FROM condicoes ORDER BY id')->fetchAll();
    $fornecedores = $pdo->query('SELECT id, nome FROM fornecedores ORDER BY nome')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar componente | RoboParts</title>
    <?php if ($codigoCadastrado !== ''): ?>
        <!-- Redireciona somente após gravação confirmada, inclusive sem JavaScript. -->
        <meta http-equiv="refresh" content="4;url=<?= e($paginaHistorico) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="stylesheet" href="cadastro_componente.css">
</head>

<body>

    <div class="app-robo">

        <!-- Navegação: as demais páginas não são implementadas neste arquivo. -->
        <nav class="sidebar">

            <div class="sidebar-brand">
                <?= icone('precision_manufacturing') ?>
                <span>RoboParts</span>
            </div>

            <ul class="sidebar-menu">
                <li><a href="#"><?= icone('home') ?>Dashboard</a></li>
                <li class="active"><a href="<?= e($paginaComponentes) ?>"><?= icone('category') ?>Componentes</a></li>
                <li><a href="#"><?= icone('sync_alt') ?>Empréstimo</a></li>
                <li><a href="#"><?= icone('folder') ?>Projetos</a></li>
                <li><a href="<?= e($paginaHistorico) ?>"><?= icone('history') ?>Histórico</a></li>
                <li><a href="catalogo.html"><?= icone('inventory_2') ?>Kits e Catálogos</a></li>
            </ul>

            <div class="sidebar-rodape">
                <div class="sidebar-card">
                    <?= icone('smart_toy') ?>
                    <strong>RoboParts</strong>
                    <p>Inovação em cada componente.</p>
                </div>
                <p class="sidebar-status"><span class="ponto-online"></span>Sistema Operacional v1.0</p>
            </div>

        </nav>

        <main class="principal">
            <div class="conteudo">

                <header class="cabecalho">
                    <div>
                        <p class="breadcrumb">RoboParts / Componentes / Cadastro</p>
                        <h1 class="text-cabecalho">Cadastrar Componente</h1>
                        <p class="text2-cabecalho">Preencha os dados para cadastrar um novo componente.</p>
                    </div>

                    <a href="<?= e($paginaComponentes) ?>" class="botao botao-secundario">
                        <?= icone('arrow_back') ?>
                        Voltar para componentes
                    </a>
                </header>

                <?php if ($codigoCadastrado !== ''): ?>
                    <div class="mensagem mensagem-sucesso confirmacao-cadastro" role="status" aria-live="polite">
                        <?= icone('check_circle') ?>
                        <div>
                            <strong>Componente <?= e($codigoCadastrado) ?> cadastrado no banco com sucesso!</strong>
                            <p>Você será redirecionado para o histórico em 4 segundos.</p>
                            <a class="botao botao-primario" href="<?= e($paginaHistorico) ?>">Ir para o histórico agora</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($erros)): ?>
                    <div class="mensagem mensagem-erro" role="alert">
                        <?= icone('error') ?>
                        <div>
                            <strong>Não foi possível cadastrar:</strong>
                            <ul>
                                <?php foreach ($erros as $erro): ?>
                                    <li><?= e($erro) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($codigoCadastrado === ''): ?>
                <form action="<?= e($paginaAtual) ?>" method="POST" class="form-cadastro">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_cadastro_componente']) ?>">

                    <div class="campo campo-nome">
                                    <label for="nome">Nome <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <input type="text" id="nome" name="nome" placeholder="Ex: Arduino Uno R3"
                                        value="<?= e($dados['nome']) ?>" required autofocus>
                                </div>

                    <div class="campo campo-codigo">
                                    <label for="codigo">Código <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <input type="text" id="codigo" name="codigo" class="campo-codigo" placeholder="Ex: ARD-002"
                                        maxlength="20" value="<?= e($dados['codigo']) ?>" required>
                                    <small class="dica">Deve ser único. É salvo em maiúsculas.</small>
                                </div>

                    <div class="campo campo-categoria_id">
                                    <label for="categoria_id">Categoria <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <select id="categoria_id" name="categoria_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($categorias as $cat): ?>
                                            <option value="<?= e($cat['id']) ?>" <?= selecionado($dados['categoria_id'], $cat['id']) ?>>
                                                <?= e($cat['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                    <div class="campo campo-descricao">
                                <label for="descricao">Descrição</label>
                                <textarea id="descricao" name="descricao" rows="3"
                                    placeholder="Ex: Placa microcontroladora ATmega328P"><?= e($dados['descricao']) ?></textarea>
                            </div>

                    <div class="campo campo-condicao_id">
                                    <label for="condicao_id">Condição <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <select id="condicao_id" name="condicao_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($condicoes as $cond): ?>
                                            <option value="<?= e($cond['id']) ?>" title="<?= e($cond['descricao']) ?>"
                                                <?= selecionado($dados['condicao_id'], $cond['id']) ?>>
                                                <?= e($cond['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                    <div class="campo campo-fornecedor_id">
                                    <label for="fornecedor_id">Fornecedor <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <select id="fornecedor_id" name="fornecedor_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($fornecedores as $forn): ?>
                                            <option value="<?= e($forn['id']) ?>" <?= selecionado($dados['fornecedor_id'], $forn['id']) ?>>
                                                <?= e($forn['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                    <div class="campo campo-quantidade_total">
                                    <label for="quantidade_total">Quantidade total <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <input type="number" id="quantidade_total" name="quantidade_total" min="0" step="1"
                                        placeholder="0" value="<?= e($dados['quantidade_total']) ?>" required>
                                    <small class="dica">Todas as unidades da peça.</small>
                                </div>

                    <div class="campo campo-quantidade_disponivel">
                                    <label for="quantidade_disponivel">Disponível <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <input type="number" id="quantidade_disponivel" name="quantidade_disponivel" min="0" step="1"
                                        placeholder="0" value="<?= e($dados['quantidade_disponivel']) ?>" required>
                                    <small class="dica">Não pode passar da quantidade total.</small>
                                </div>

                    <div class="campo campo-estoque_minimo">
                                    <label for="estoque_minimo">Estoque mínimo <span class="obrigatorio" aria-hidden="true">*</span></label>
                                    <input type="number" id="estoque_minimo" name="estoque_minimo" min="0" step="1"
                                        placeholder="0" value="<?= e($dados['estoque_minimo']) ?>" required>
                                    <small class="dica">Limite mínimo desejado para o estoque.</small>
                                </div>

                    <div class="form-rodape">
                        <p class="rodape-dica"><span class="obrigatorio">*</span> Campos obrigatórios</p>

                        <div class="acoes">
                            <a href="<?= e($paginaComponentes) ?>" class="botao botao-secundario">Cancelar</a>
                            <button type="submit" class="botao botao-primario">
                                <?= icone('add') ?>
                                Cadastrar componente
                            </button>
                        </div>
                    </div>

                </form>
                <?php endif; ?>

            </div>
        </main>
    </div>

</body>

</html>
