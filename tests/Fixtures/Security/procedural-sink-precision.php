<?php
class VulnerableController
{
    public function find(mysqli $conn, string $id)
    {
        $query = "SELECT * FROM users WHERE id = '$id'";

        return mysqli_query($conn, $query);
    }

    public function ping(string $target): string
    {
        return (string) shell_exec('ping -c 1 ' . $target);
    }
}

function exerciseAdditionalProceduralSqlSinks(mixed $pgConnection, mixed $sqlServerConnection, mixed $oracleConnection, string $id): void
{
    $query = "SELECT * FROM users WHERE id = '$id'";

    PG_QUERY($pgConnection, $query);
    mysql_query($query);
    sqlsrv_query($sqlServerConnection, $query);
    oci_parse($oracleConnection, $query);
}

function executeParameterizedProceduralSql(mysqli $conn, mixed $sqlServerConnection, string $id): void
{
    $statement = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $statement->bind_param('s', $id);
    $statement->execute();

    sqlsrv_query($sqlServerConnection, 'SELECT * FROM users WHERE id = ?', [$id]);
    mysqli_query($conn, 'SELECT * FROM users WHERE id = ?');
}

function ignoreReassignedProceduralSql(mysqli $conn, string $id): void
{
    $query = "SELECT * FROM users WHERE id = '$id'";
    $query = 'SELECT * FROM users WHERE active = 1';

    mysqli_query($conn, $query);
}

function ignoreNestedScopeQueryAssignment(mysqli $conn, string $id): Closure
{
    $query = "SELECT * FROM users WHERE id = '$id'";

    return static fn(): mixed => mysqli_query($conn, $query);
}

function runProcessWithArgumentVector(string $target): Symfony\Component\Process\Process
{
    return new Symfony\Component\Process\Process(['ping', '-c', '1', $target]);
}

function exerciseNamedProceduralSinks(mysqli $conn, mixed $pgConnection, string $id, string $target): void
{
    mysqli_query(query: "SELECT * FROM users WHERE id = '$id'", mysql: $conn);
    pg_query(query: "SELECT * FROM users WHERE id = '$id'", connection: $pgConnection);
    exec(result_code: $resultCode, output: $output, command: 'ping -c 1 ' . $target);
}

function ignoreDestructuredQueryReassignment(mysqli $conn, string $id): void
{
    $query = "SELECT * FROM users WHERE id = '$id'";
    [$query] = ['SELECT * FROM users WHERE active = 1'];

    mysqli_query($conn, $query);
}

function exerciseProcOpenArgumentVectors(string $target): void
{
    proc_open(command: ['ping', '--host=' . $target], descriptor_spec: [], pipes: $directPipes);
    proc_open(command: ['/bin/sh', '-c', 'ping -c 1 ' . $target], descriptor_spec: [], pipes: $shellPipes);
    proc_open(command: ['/bin/sh', '-lc', 'ping -c 1 ' . $target], descriptor_spec: [], pipes: $loginShellPipes);
}

function escapeSplicedSinkValues(mysqli $conn, object $dbi, string $file, string $flag, string $pid, string $id): void
{
    shell_exec('convert ' . escapeshellarg($file) . ' out.png');
    shell_exec('convert ' . escapeshellarg($file) . ' ' . $flag);
    shell_exec(sprintf('file %s', escapeshellarg(('-' === $file[0] ? './' : '') . $file)));
    exec('kill ' . (int) $pid);
    exec('kill ' . $pid);
    shell_exec('convert ' . escapeshellcmd($file));
    mysqli_query($conn, 'SELECT * FROM users WHERE id = ' . (int) $id);
    mysqli_query($conn, 'SELECT * FROM ' . Util::backquote($id) . ' WHERE name = ' . $dbi->quoteString($id));
    mysqli_query($conn, 'SELECT * FROM users WHERE name LIKE ' . $dbi->quoteString('%' . $id . '%'));
    mysqli_query($conn, 'SELECT * FROM ' . Util::backquote($id) . ' WHERE id = ' . $id);
    mysqli_query($conn, "SELECT * FROM users WHERE name = '" . $dbi->quote($id) . "'");
    exec(sprintf('convert ' . escapeshellarg($file) . ' -resize %s out.png', $flag));
    exec('convert "' . escapeshellarg($file) . '" out.png');
    mysqli_query($conn, sprintf('SELECT * FROM ' . Util::backquote($id) . " WHERE name = '%s'", $id));
    mysqli_query($conn, "SELECT * FROM users WHERE name = '" . $dbi->quoteString($id) . "'");
}
