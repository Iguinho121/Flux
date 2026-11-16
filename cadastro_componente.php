<?php
require 'conexao.php';

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
    'projeto_id'            => '',
    'quantidade_projeto'    => '',
];

/* ================= PROCESSAMENTO DO FORMULÁRIO ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($dados as $campo => $valor) {
        $dados[$campo] = trim($_POST[$campo] ?? '');
    }
    $dados['codigo'] = strtoupper($dados['codigo']);

    // Validações básicas
    if ($dados['nome'] === '')   $erros[] = 'Informe o nome do componente.';
    if ($dados['codigo'] === '') $erros[] = 'Informe o código do componente.';
    if (!ctype_digit($dados['categoria_id']))  $erros[] = 'Selecione uma categoria.';
    if (!ctype_digit($dados['fornecedor_id'])) $erros[] = 'Selecione um fornecedor.';
    if (!ctype_digit($dados['condicao_id']))   $erros[] = 'Selecione a condição da peça.';

    if (!ctype_digit($dados['quantidade_total']))      $erros[] = 'Quantidade total deve ser um número inteiro (0 ou mais).';
    if (!ctype_digit($dados['quantidade_disponivel'])) $erros[] = 'Quantidade disponível deve ser um número inteiro (0 ou mais).';
    if (!ctype_digit($dados['estoque_minimo']))        $erros[] = 'Estoque mínimo deve ser um número inteiro (0 ou mais).';

    if (ctype_digit($dados['quantidade_total']) && ctype_digit($dados['quantidade_disponivel'])
        && (int) $dados['quantidade_disponivel'] > (int) $dados['quantidade_total']) {
        $erros[] = 'A quantidade disponível não pode ser maior que a quantidade total.';
    }

    // Vínculo com projeto (opcional)
    if ($dados['projeto_id'] !== '') {
        if (!ctype_digit($dados['quantidade_projeto']) || (int) $dados['quantidade_projeto'] < 1) {
            $erros[] = 'Informe quantas unidades o projeto vai usar (mínimo 1).';
        } elseif (ctype_digit($dados['quantidade_total'])
            && (int) $dados['quantidade_projeto'] > (int) $dados['quantidade_total']) {
            $erros[] = 'O projeto não pode usar mais unidades do que a quantidade total.';
        }
    }

    // Salva no banco
    if (empty($erros)) {
        try {
            $pdo->beginTransaction();

            $sql = 'INSERT INTO componentes
                        (nome, descricao, codigo, categoria_id, fornecedor_id, condicao_id,
                         quantidade_total, quantidade_disponivel, estoque_minimo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
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

            $componenteId = $pdo->lastInsertId();

            if ($dados['projeto_id'] !== '') {
                $stmt = $pdo->prepare('INSERT INTO projeto_componentes (projeto_id, componente_id, quantidade) VALUES (?, ?, ?)');
                $stmt->execute([(int) $dados['projeto_id'], $componenteId, (int) $dados['quantidade_projeto']]);
            }

            $pdo->commit();

            // Redireciona para evitar reenvio do formulário ao atualizar a página
            header('Location: componentes.php?sucesso=' . urlencode($dados['codigo']));
            exit;

        } catch (PDOException $ex) {
            $pdo->rollBack();
            if ($ex->getCode() == 23000) {
                $erros[] = 'Já existe um componente com esse código, ou algum item selecionado não existe mais.';
            } else {
                $erros[] = 'Erro ao salvar: ' . $ex->getMessage();
            }
        }
    }
}

/* ================= DADOS PARA OS SELECTS ================= */
$categorias   = $pdo->query('SELECT id, nome FROM categorias ORDER BY nome')->fetchAll();
$fornecedores = $pdo->query('SELECT id, nome FROM fornecedores ORDER BY nome')->fetchAll();
$condicoes    = $pdo->query('SELECT id, nome, descricao FROM condicoes ORDER BY id')->fetchAll();
$projetos     = $pdo->query('SELECT id, nome, status FROM projetos ORDER BY nome')->fetchAll();

/* ================= LISTAGEM DO ESTOQUE ================= */
$sqlLista = "SELECT c.id, c.nome, c.descricao, c.codigo,
                    c.quantidade_total, c.quantidade_disponivel, c.estoque_minimo,
                    cat.nome AS categoria, f.nome AS fornecedor, co.nome AS condicao,
                    GROUP_CONCAT(CONCAT(p.nome, ' (', pc.quantidade, ' un.)') SEPARATOR ', ') AS projetos
             FROM componentes c
             LEFT JOIN categorias cat          ON cat.id = c.categoria_id
             LEFT JOIN fornecedores f          ON f.id = c.fornecedor_id
             LEFT JOIN condicoes co            ON co.id = c.condicao_id
             LEFT JOIN projeto_componentes pc  ON pc.componente_id = c.id
             LEFT JOIN projetos p              ON p.id = pc.projeto_id
             GROUP BY c.id, c.nome, c.descricao, c.codigo,
                      c.quantidade_total, c.quantidade_disponivel, c.estoque_minimo,
                      cat.nome, f.nome, co.nome
             ORDER BY c.nome";
$componentes = $pdo->query($sqlLista)->fetchAll();

// Resumo + alertas de estoque abaixo do mínimo
$totalUnidades = 0;
$abaixoMinimo  = [];
foreach ($componentes as $c) {
    $totalUnidades += (int) $c['quantidade_disponivel'];
    if ((int) $c['quantidade_disponivel'] < (int) $c['estoque_minimo']) {
        $abaixoMinimo[] = $c;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Componentes - Flux</title>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="cadastro_componentes.css">
    <link rel="stylesheet" href="componentes.css">
</head>

<body>

    <div class="container-principal container-componentes">

        <!-- COLUNA DA ESQUERDA: resumo e alertas -->
        <div class="info-esquerda">
            <div class="tag-conta">
                <span class="material-symbols-outlined">inventory_2</span> Estoque Flux
            </div>

            <h1>Cadastro de componentes</h1>
            <p class="subtitulo-info">Registre novas peças, defina o estoque mínimo e vincule componentes aos projetos
                da oficina.</p>

            <div class="resumo-estoque">
                <div class="cartao-resumo">
                    <strong><?= count($componentes) ?></strong>
                    <span>Componentes</span>
                </div>
                <div class="cartao-resumo">
                    <strong><?= $totalUnidades ?></strong>
                    <span>Unidades disponíveis</span>
                </div>
                <div class="cartao-resumo <?= count($abaixoMinimo) > 0 ? 'alerta' : '' ?>">
                    <strong><?= count($abaixoMinimo) ?></strong>
                    <span>Abaixo do mínimo</span>
                </div>
            </div>

            <div class="lista-alertas">
                <?php if (count($abaixoMinimo) > 0): ?>
                    <?php foreach ($abaixoMinimo as $item): ?>
                        <div class="card-alerta-sistema">
                            <div class="alerta-cabecalho">
                                <span class="material-symbols-outlined icone-aviso">warning</span>
                                Estoque abaixo do mínimo
                            </div>
                            <p class="alerta-corpo">
                                <strong><?= e($item['nome']) ?></strong> (<?= e($item['codigo']) ?>) tem
                                <strong><?= (int) $item['quantidade_disponivel'] ?></strong> unidade(s) disponível(is),
                                mas o mínimo é <strong><?= (int) $item['estoque_minimo'] ?></strong>.
                                Considere repor com <?= e($item['fornecedor'] ?? 'o fornecedor') ?>.
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card-alerta-sistema ok">
                        <div class="alerta-cabecalho">
                            <span class="material-symbols-outlined icone-aviso">check_circle</span>
                            Estoque em dia
                        </div>
                        <p class="alerta-corpo">Todos os componentes estão acima do estoque mínimo.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- FORMULÁRIO -->
        <form action="componentes.php" method="POST" class="form-componente" id="cadastro">

            <div class="seletor-abas">
                <a href="#cadastro" class="aba ativa">Cadastrar</a>
                <a href="#estoque" class="aba">Ver estoque</a>
            </div>

            <div class="cabecalho-form">
                <h2>Novo componente</h2>
                <p>Preencha os dados da peça que entrou na oficina.</p>
            </div>

            <?php if (isset($_GET['sucesso'])): ?>
                <div class="mensagem sucesso">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div>Componente <strong><?= e($_GET['sucesso']) ?></strong> cadastrado com sucesso!</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($erros)): ?>
                <div class="mensagem erro">
                    <span class="material-symbols-outlined">error</span>
                    <ul>
                        <?php foreach ($erros as $erro): ?>
                            <li><?= e($erro) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="campo-grupo">
                <label for="nome">Nome</label>
                <div class="input-com-icone">
                    <span class="material-symbols-outlined icone-input">memory</span>
                    <input type="text" id="nome" name="nome" placeholder="Ex: Arduino Uno R3"
                        value="<?= e($dados['nome']) ?>" required>
                </div>
            </div>

            <div class="campo-grupo">
                <label for="codigo">Código</label>
                <div class="input-com-icone">
                    <span class="material-symbols-outlined icone-input">tag</span>
                    <input type="text" id="codigo" name="codigo" placeholder="Ex: ARD-002" maxlength="20"
                        value="<?= e($dados['codigo']) ?>" required>
                </div>
            </div>

            <div class="campo-grupo">
                <label for="descricao">Descrição</label>
                <div class="input-com-icone area">
                    <span class="material-symbols-outlined icone-input">notes</span>
                    <textarea id="descricao" name="descricao" rows="2"
                        placeholder="Ex: Placa microcontroladora ATmega328P"><?= e($dados['descricao']) ?></textarea>
                </div>
            </div>

            <div class="linha-campos">
                <div class="campo-grupo">
                    <label for="categoria_id">Categoria</label>
                    <div class="input-com-icone">
                        <span class="material-symbols-outlined icone-input">category</span>
                        <select id="categoria_id" name="categoria_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= selecionado($dados['categoria_id'], $cat['id']) ?>>
                                    <?= e($cat['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="material-symbols-outlined icone-seta">expand_more</span>
                    </div>
                </div>

                <div class="campo-grupo">
                    <label for="condicao_id">Condição</label>
                    <div class="input-com-icone">
                        <span class="material-symbols-outlined icone-input">verified</span>
                        <select id="condicao_id" name="condicao_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($condicoes as $cond): ?>
                                <option value="<?= $cond['id'] ?>" title="<?= e($cond['descricao']) ?>"
                                    <?= selecionado($dados['condicao_id'], $cond['id']) ?>>
                                    <?= e($cond['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="material-symbols-outlined icone-seta">expand_more</span>
                    </div>
                </div>
            </div>

            <div class="campo-grupo">
                <label for="fornecedor_id">Fornecedor</label>
                <div class="input-com-icone">
                    <span class="material-symbols-outlined icone-input">local_shipping</span>
                    <select id="fornecedor_id" name="fornecedor_id" required>
                        <option value="">Selecione o fornecedor</option>
                        <?php foreach ($fornecedores as $forn): ?>
                            <option value="<?= $forn['id'] ?>" <?= selecionado($dados['fornecedor_id'], $forn['id']) ?>>
                                <?= e($forn['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="material-symbols-outlined icone-seta">expand_more</span>
                </div>
            </div>

            <div class="linha-campos tres">
                <div class="campo-grupo">
                    <label for="quantidade_total">Total</label>
                    <input type="number" id="quantidade_total" name="quantidade_total" min="0" placeholder="0"
                        value="<?= e($dados['quantidade_total']) ?>" required>
                </div>
                <div class="campo-grupo">
                    <label for="quantidade_disponivel">Disponível</label>
                    <input type="number" id="quantidade_disponivel" name="quantidade_disponivel" min="0"
                        placeholder="0" value="<?= e($dados['quantidade_disponivel']) ?>" required>
                </div>
                <div class="campo-grupo">
                    <label for="estoque_minimo">Mínimo</label>
                    <input type="number" id="estoque_minimo" name="estoque_minimo" min="0" placeholder="0"
                        value="<?= e($dados['estoque_minimo']) ?>" required>
                </div>
            </div>
            <p class="dica">Se o disponível ficar abaixo do mínimo, o componente aparece nos alertas.</p>

            <div class="divisor-secao">Vincular a projeto (opcional)</div>

            <div class="linha-campos">
                <div class="campo-grupo">
                    <label for="projeto_id">Projeto</label>
                    <div class="input-com-icone">
                        <span class="material-symbols-outlined icone-input">precision_manufacturing</span>
                        <select id="projeto_id" name="projeto_id">
                            <option value="">Nenhum</option>
                            <?php foreach ($projetos as $proj): ?>
                                <option value="<?= $proj['id'] ?>" <?= selecionado($dados['projeto_id'], $proj['id']) ?>>
                                    <?= e($proj['nome']) ?> — <?= e($proj['status']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="material-symbols-outlined icone-seta">expand_more</span>
                    </div>
                </div>
                <div class="campo-grupo">
                    <label for="quantidade_projeto">Qtd. usada</label>
                    <input type="number" id="quantidade_projeto" name="quantidade_projeto" min="1" placeholder="0"
                        value="<?= e($dados['quantidade_projeto']) ?>">
                </div>
            </div>

            <button type="submit">Cadastrar componente</button>
        </form>
    </div>

    <!-- TABELA DE ESTOQUE -->
    <section class="secao-estoque" id="estoque">
        <div class="secao-cabecalho">
            <h2><span class="material-symbols-outlined">inventory</span> Componentes cadastrados</h2>
            <p>Quantidades, condição e onde cada peça está sendo usada.</p>
        </div>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>Código</th>
                        <th>Categoria</th>
                        <th>Fornecedor</th>
                        <th>Condição</th>
                        <th>Estoque</th>
                        <th>Projetos</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($componentes)): ?>
                        <tr>
                            <td colspan="8" class="sem-dados">Nenhum componente cadastrado ainda.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($componentes as $c): ?>
                        <?php $baixo = (int) $c['quantidade_disponivel'] < (int) $c['estoque_minimo']; ?>
                        <tr class="<?= $baixo ? 'linha-alerta' : '' ?>">
                            <td class="nome-componente">
                                <strong><?= e($c['nome']) ?></strong>
                                <small><?= e($c['descricao']) ?></small>
                            </td>
                            <td><span class="codigo"><?= e($c['codigo']) ?></span></td>
                            <td><?= e($c['categoria'] ?? '—') ?></td>
                            <td><?= e($c['fornecedor'] ?? '—') ?></td>
                            <td><?= e($c['condicao'] ?? '—') ?></td>
                            <td class="estoque">
                                <strong><?= (int) $c['quantidade_disponivel'] ?></strong> / <?= (int) $c['quantidade_total'] ?>
                                <small>mín. <?= (int) $c['estoque_minimo'] ?></small>
                            </td>
                            <td class="projetos"><?= e($c['projetos'] ?? 'Nenhum') ?></td>
                            <td>
                                <?php if ($baixo): ?>
                                    <span class="badge badge-alerta">Abaixo do mínimo</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Footer -->
    <footer class="rodape-avancado">
        <div class="rodape-conteudo">

            <div class="rodape-marca">
                <div class="rodape-logo">
                    <span class="material-symbols-outlined">precision_manufacturing</span>
                    <span>Flux</span>
                </div>
                <p>Organização inteligente para oficinas de robótica. Feito para equipes e makers que querem controle
                    total de suas peças e projetos, sem surpresas.</p>
                <a href="#" class="rodape-comunidade">
                    <span class="material-symbols-outlined">forum</span> Nossa comunidade
                </a>
            </div>

            <div class="rodape-menus">
                <div class="rodape-coluna">
                    <h4>Produto</h4>
                    <a href="componentes.php">Componentes</a>
                    <a href="#">Catálogos</a>
                    <a href="#">Preços</a>
                </div>
                <div class="rodape-coluna">
                    <h4>Conta</h4>
                    <a href="login.html">Entrar</a>
                    <a href="cadastro.html">Criar conta</a>
                </div>
                <div class="rodape-coluna">
                    <h4>Legal</h4>
                    <a href="#">Privacidade</a>
                    <a href="#">Termos de uso</a>
                </div>
                <div class="rodape-coluna">
                    <h4>Suporte</h4>
                    <a href="#">Falar com a gente</a>
                    <a href="#">Central de Ajuda</a>
                </div>
            </div>
        </div>
    </footer>
</body>

</html>
