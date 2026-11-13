<?php

declare(strict_types=1);

/** Protege nomes de tabelas e colunas, que não podem usar placeholders PDO. */
function identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
        throw new InvalidArgumentException("Identificador SQL inválido: {$name}");
    }

    return "`{$name}`";
}

/** Insere um registro e devolve seu ID. */
function create(PDO $pdo, string $table, array $data): string
{
    if ($data === []) {
        throw new InvalidArgumentException('Informe ao menos um campo para inserir.');
    }

    $table = identifier($table);
    $columns = implode(', ', array_map('identifier', array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

    $statement = $pdo->prepare($sql);
    $statement->execute(array_values($data));

    return $pdo->lastInsertId();
}

/** Lista registros. Use ? no filtro e envie os valores em $params. */
function readAll(PDO $pdo, string $table, ?string $where = null, array $params = []): array
{
    $table = identifier($table);
    $sql = "SELECT * FROM {$table}";

    if ($where !== null) {
        $sql .= " WHERE {$where}";
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/** Busca o primeiro registro correspondente. */
function read(PDO $pdo, string $table, ?string $where = null, array $params = []): array|false
{
    $table = identifier($table);
    $sql = "SELECT * FROM {$table}";

    if ($where !== null) {
        $sql .= " WHERE {$where}";
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetch();
}

/** Atualiza registros e devolve a quantidade alterada. */
function update(PDO $pdo, string $table, array $data, string $where, array $params = []): int
{
    if ($data === []) {
        throw new InvalidArgumentException('Informe ao menos um campo para atualizar.');
    }

    if (trim($where) === '') {
        throw new InvalidArgumentException('Informe uma condição para atualizar.');
    }

    $table = identifier($table);
    $set = implode(', ', array_map(
        static fn (string $column): string => identifier($column) . ' = ?',
        array_keys($data),
    ));

    $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
    $statement = $pdo->prepare($sql);
    $statement->execute([...array_values($data), ...$params]);

    return $statement->rowCount();
}

/** Exclui registros e devolve a quantidade removida. */
function delete(PDO $pdo, string $table, string $where, array $params = []): int
{
    if (trim($where) === '') {
        throw new InvalidArgumentException('Informe uma condição para excluir.');
    }

    $table = identifier($table);
    $sql = "DELETE FROM {$table} WHERE {$where}";
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount();
}
