<?php
require_once __DIR__ . '/tools/finance_dashboard/config_finance.php';
if ((int)$pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn() === 0) {
  $pdo->exec("INSERT INTO accounts (name,type,is_active) VALUES ('Készpénz','CASH',1),('Raiffeisen','BANK',1),('OTP SZÉP','CARD',1),('Revolut','CARD',1)");
}
if ((int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
  $pdo->exec("INSERT INTO categories (name,kind) VALUES ('Általános bevétel','income'),('Általános kiadás','expense'),('Utalás','transfer')");
}
echo 'OK';
