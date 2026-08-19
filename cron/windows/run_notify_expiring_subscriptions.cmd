@echo off
"C:\wamp64\bin\php\php8.4.15\php.exe" "C:\wamp64\www\market\cron\notify_expiring_subscriptions.php" >> "C:\wamp64\www\market\logs\subscription_reminder_cron.log" 2>&1
