<?php
/**
 * API REST - Almoxarifado (tabela "pecas")
 *
 *  POST   /index.php            -> cadastra uma peça
 *  GET    /index.php            -> lista todas as peças
 *  GET    /index.php?id=3       -> busca uma peça pelo id (extra)
 *  PUT    /index.php?id=3       -> atualiza uma peça pelo id
 *  DELETE /index.php?id=3       -> exclui uma peça pelo id
 *
 * Também aceita o id na URL (/index.php/3) ou no corpo JSON ("id").
 */

require_once __DIR__ . '/conexao.php';

header('Content-Type: application/json; charset=utf-8');

const CATEGORIAS = ['eletrica', 'mecanica', 'hidraulica'];
const CAMPOS_OBRIGATORIOS = ['nome', 'categoria', 'fornecedor', 'quantidade', 'preco_unitario'];

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function lerCorpo(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || trim($bruto) === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        responder(400, ['sucesso' => false, 'mensagem' => 'JSON inválido no corpo da requisição.']);
    }
    return $dados;
}

function obterId(array $corpo): ?int
{
    $id = $_GET['id'] ?? null;

    if ($id === null && !empty($_SERVER['PATH_INFO'])) {
        $id = trim($_SERVER['PATH_INFO'], '/');
    }
    if ($id === null && isset($corpo['id'])) {
        $id = $corpo['id'];
    }
    if ($id === null || $id === '') {
        return null;
    }

    $id = filter_var($id, FILTER_VALIDATE_INT);
    return ($id === false || $id <= 0) ? null : $id;
}

/** Valida os dados e devolve a lista de erros (vazia se estiver tudo certo). */
function validar(array $d): array
{
    $erros = [];

    // 1) obrigatórios (não usar empty(): quantidade 0 é válida)
    foreach (CAMPOS_OBRIGATORIOS as $campo) {
        if (!array_key_exists($campo, $d) || $d[$campo] === null || trim((string)$d[$campo]) === '') {
            $erros[] = "O campo '$campo' é obrigatório.";
        }
    }
    if ($erros) {
        return $erros;
    }

    // 2) regras de negócio
    if (mb_strlen(trim((string)$d['nome'])) > 100) {
        $erros[] = "O campo 'nome' deve ter no máximo 100 caracteres.";
    }
    if (mb_strlen(trim((string)$d['fornecedor'])) > 100) {
        $erros[] = "O campo 'fornecedor' deve ter no máximo 100 caracteres.";
    }
    if (!in_array(mb_strtolower(trim((string)$d['categoria'])), CATEGORIAS, true)) {
        $erros[] = "Categoria inválida. Use: " . implode(', ', CATEGORIAS) . ".";
    }

    $qtd = filter_var($d['quantidade'], FILTER_VALIDATE_INT);
    if ($qtd === false) {
        $erros[] = "A quantidade deve ser um número inteiro.";
    } elseif ($qtd < 0) {
        $erros[] = "A quantidade não pode ser negativa.";
    }

    if (!is_numeric($d['preco_unitario'])) {
        $erros[] = "O preço unitário deve ser numérico.";
    } elseif ((float)$d['preco_unitario'] <= 0) {
        $erros[] = "O preço unitário deve ser maior que zero.";
    }

    return $erros;
}

/** Normaliza os dados já validados para gravar no banco. */
function normalizar(array $d): array
{
    return [
        ':nome'           => trim((string)$d['nome']),
        ':categoria'      => mb_strtolower(trim((string)$d['categoria'])),
        ':fornecedor'     => trim((string)$d['fornecedor']),
        ':quantidade'     => (int)$d['quantidade'],
        ':preco_unitario' => round((float)$d['preco_unitario'], 2),
    ];
}

function buscarPeca(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM pecas WHERE id = :id');
    $st->execute([':id' => $id]);
    $peca = $st->fetch();
    return $peca ?: null;
}

function formatar(array $p): array
{
    $p['id']             = (int)$p['id'];
    $p['quantidade']     = (int)$p['quantidade'];
    $p['preco_unitario'] = (float)$p['preco_unitario'];
    return $p;
}

try {
    $pdo    = conectar();
    $metodo = $_SERVER['REQUEST_METHOD'];

    switch ($metodo) {

        // ---------- CREATE ----------
        case 'POST':
            $corpo = lerCorpo();
            $erros = validar($corpo);
            if ($erros) {
                responder(422, ['sucesso' => false, 'mensagem' => 'Dados inválidos.', 'erros' => $erros]);
            }

            $st = $pdo->prepare(
                'INSERT INTO pecas (nome, categoria, fornecedor, quantidade, preco_unitario)
                 VALUES (:nome, :categoria, :fornecedor, :quantidade, :preco_unitario)'
            );
            $st->execute(normalizar($corpo));

            $novo = buscarPeca($pdo, (int)$pdo->lastInsertId('pecas_id_seq'));
            responder(201, [
                'sucesso'  => true,
                'mensagem' => 'Peça cadastrada com sucesso.',
                'peca'     => formatar($novo),
            ]);
            break;

        // ---------- READ ----------
        case 'GET':
            $id = obterId([]);

            if ($id !== null) {
                $peca = buscarPeca($pdo, $id);
                if (!$peca) {
                    responder(404, ['sucesso' => false, 'mensagem' => "Peça com id $id não encontrada."]);
                }
                responder(200, ['sucesso' => true, 'mensagem' => 'Peça encontrada.', 'peca' => formatar($peca)]);
            }

            $pecas = array_map('formatar', $pdo->query('SELECT * FROM pecas ORDER BY id')->fetchAll());
            responder(200, [
                'sucesso'  => true,
                'mensagem' => count($pecas) . ' peça(s) encontrada(s).',
                'total'    => count($pecas),
                'pecas'    => $pecas,
            ]);
            break;

        // ---------- UPDATE ----------
        case 'PUT':
            $corpo = lerCorpo();
            $id    = obterId($corpo);
            if ($id === null) {
                responder(400, ['sucesso' => false, 'mensagem' => 'Informe um id válido (ex.: ?id=3).']);
            }
            if (!buscarPeca($pdo, $id)) {
                responder(404, ['sucesso' => false, 'mensagem' => "Peça com id $id não encontrada."]);
            }

            $erros = validar($corpo);
            if ($erros) {
                responder(422, ['sucesso' => false, 'mensagem' => 'Dados inválidos.', 'erros' => $erros]);
            }

            $dados = normalizar($corpo);
            $dados[':id'] = $id;
            $st = $pdo->prepare(
                'UPDATE pecas
                    SET nome = :nome, categoria = :categoria, fornecedor = :fornecedor,
                        quantidade = :quantidade, preco_unitario = :preco_unitario
                  WHERE id = :id'
            );
            $st->execute($dados);

            responder(200, [
                'sucesso'  => true,
                'mensagem' => 'Peça atualizada com sucesso.',
                'peca'     => formatar(buscarPeca($pdo, $id)),
            ]);
            break;

        // ---------- DELETE ----------
        case 'DELETE':
            $id = obterId(lerCorpo());
            if ($id === null) {
                responder(400, ['sucesso' => false, 'mensagem' => 'Informe um id válido (ex.: ?id=3).']);
            }
            if (!buscarPeca($pdo, $id)) {
                responder(404, ['sucesso' => false, 'mensagem' => "Peça com id $id não encontrada."]);
            }

            $pdo->prepare('DELETE FROM pecas WHERE id = :id')->execute([':id' => $id]);
            responder(200, ['sucesso' => true, 'mensagem' => "Peça $id excluída com sucesso."]);
            break;

        default:
            header('Allow: GET, POST, PUT, DELETE');
            responder(405, ['sucesso' => false, 'mensagem' => "Método $metodo não permitido."]);
    }

} catch (PDOException $e) {
    responder(500, ['sucesso' => false, 'mensagem' => 'Erro no banco de dados.', 'detalhe' => $e->getMessage()]);
} catch (Throwable $e) {
    responder(500, ['sucesso' => false, 'mensagem' => 'Erro interno do servidor.', 'detalhe' => $e->getMessage()]);
}   