<?php
/**
 * Lazy PDO singleton.
 *
 * The function_exists() guard lets TaskFlow (/taskflow/) borrow MagDyn's page
 * chrome — see taskflow/magdyn_chrome.php. TaskFlow declares its own db()
 * first, and the two are interchangeable: same credentials from the same
 * config/db.config.php, same database, same PDO attributes. Everything below
 * (db_all/db_one/db_val/db_exec) goes through whichever one is in force, so a
 * TaskFlow page and a MagDyn page still read the same rows the same way.
 *
 * On a MagDyn request nothing else declares db(), so the guard is always true
 * and behaviour is unchanged.
 *
 * Created: 20260515_060024_IST
 */

if (!function_exists('db')) {
    function db()
    {
        static $pdo = null;
        if ($pdo !== null) return $pdo;

        $cfg = $GLOBALS['DB'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
        );

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Defence in depth: even though the DSN sets charset, some
                // servers/clients have ignored it historically. Force the session
                // charset explicitly on every connection.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed. Check config/db.config.php — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
        }
        return $pdo;
    }
}

/** Convenience: fetch all rows */
function db_all($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Convenience: fetch single row or null */
function db_one($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Convenience: fetch single scalar */
function db_val($sql, array $params = [], $default = null)
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

/** Convenience: execute write */
function db_exec($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
