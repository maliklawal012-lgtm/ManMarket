<?php

declare(strict_types=1);

/**
 * Job planifie (cron / Tache planifiee Windows) : sauvegarde complete de la
 * base de donnees (mysqldump, compressee), avec rotation automatique des
 * anciennes sauvegardes. Les identifiants ne sont JAMAIS passes en ligne de
 * commande (visibles dans la liste des processus) : ecrits dans un fichier
 * d'options temporaire (--defaults-extra-file), supprime immediatement apres
 * usage.
 *
 * Usage CLI :
 *   php cron/backup_database.php
 *
 * Frequence recommandee : quotidienne, en dehors des heures de pointe.
 *
 * IMPORTANT : ce script sauvegarde en LOCAL, dans backups/ (deja bloque en
 * HTTP par .htaccess). Il ne satisfait a lui seul PAS l'exigence "sauvegardes
 * separees du serveur principal" — apres chaque execution, synchroniser
 * backups/ vers un stockage externe (rsync, upload S3/Backblaze, etc.).
 *
 * Sous WAMP, mysqldump n'est generalement pas dans le PATH systeme : definir
 * MYSQLDUMP_BIN dans .env avec le chemin complet, ex.
 *   MYSQLDUMP_BIN=C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut etre execute qu\'en ligne de commande.');
}

require_once __DIR__ . '/../config/env.php';

const BACKUP_RETENTION_DAYS = 14;

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}

$dbHost = env_required('DB_HOST');
$dbName = env_required('DB_NAME');
$dbUser = env_required('DB_USER');
$dbPass = env('DB_PASS', '');

$optionsFile = tempnam(sys_get_temp_dir(), 'mmbak_');
file_put_contents($optionsFile, "[client]\nuser={$dbUser}\npassword={$dbPass}\nhost={$dbHost}\n");
chmod($optionsFile, 0600);

$timestamp = date('Y-m-d_His');
$outputFile = $backupDir . '/manmarket-' . $timestamp . '.sql.gz';

$mysqldumpBin = env('MYSQLDUMP_BIN', 'mysqldump');
$command = escapeshellarg($mysqldumpBin)
    . ' --defaults-extra-file=' . escapeshellarg($optionsFile)
    . ' --single-transaction --quick --routines --triggers '
    . escapeshellarg($dbName);

$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

if (!is_resource($process)) {
    unlink($optionsFile);
    fwrite(STDERR, "Impossible de lancer mysqldump (verifier MYSQLDUMP_BIN dans .env).\n");
    exit(1);
}

$gz = gzopen($outputFile, 'wb9');
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 65536);
    if ($chunk !== false && $chunk !== '') {
        gzwrite($gz, $chunk);
    }
}
gzclose($gz);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

unlink($optionsFile);

if ($exitCode !== 0) {
    @unlink($outputFile);
    fwrite(STDERR, "Echec de la sauvegarde (mysqldump code {$exitCode}) : {$stderr}\n");
    exit(1);
}

$sizeKb = (int) round(filesize($outputFile) / 1024);
echo sprintf("[%s] Sauvegarde creee : %s (%d Ko)\n", date('Y-m-d H:i:s'), basename($outputFile), $sizeKb);

$deleted = 0;
foreach (glob($backupDir . '/manmarket-*.sql.gz') ?: [] as $file) {
    if (filemtime($file) < time() - BACKUP_RETENTION_DAYS * 86400) {
        unlink($file);
        $deleted++;
    }
}
if ($deleted > 0) {
    echo sprintf("[%s] %d ancienne(s) sauvegarde(s) supprimee(s) (retention = %d jours).\n", date('Y-m-d H:i:s'), $deleted, BACKUP_RETENTION_DAYS);
}

exit(0);
