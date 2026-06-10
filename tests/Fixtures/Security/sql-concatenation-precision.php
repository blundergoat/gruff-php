<?php

declare(strict_types=1);

function attackShapedSqlCalls(object $wpdb, PDO $pdo, object $db, int $id, string $userVar, string $y): void
{
    $wpdb->query("SELECT * FROM {$wpdb->prefix}links WHERE id = $id");
    $wpdb->query($wpdb->prepare("SELECT * FROM links WHERE x = '$userVar' AND y = %s", $y));
    $pdo->query('SELECT * FROM links WHERE id = ' . $id);
    $db->exec("DELETE FROM links WHERE k = '" . $_GET['k'] . "'");
}

function inertShapedSqlCalls(DOMXPath $xpath, int $id, string $tag): void
{
    global $wpdb;

    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}links WHERE id = %d", $id));
    $wpdb->query("ALTER TABLE {$wpdb->prefix}links ADD INDEX idx (col)");
    $xpath->query('//item[@id=' . $tag . ']');
}

function shadowedWpdbSqlCall(): void
{
    $wpdb = (object) ['prefix' => $_GET['prefix']];
    $wpdb->query("SELECT * FROM {$wpdb->prefix}links");
}
