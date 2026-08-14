<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Configuration SMTP, entierement optionnelle. Tant que SMTP_HOST est vide,
 * NotificationService/SmtpMailer restent silencieux (log uniquement, aucun
 * envoi tente) — voir SmtpMailer::isConfigured().
 */
define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_USERNAME', env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', env('SMTP_PASSWORD', ''));
define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', 'no-reply@manmarket.ci'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'ManMarket'));
