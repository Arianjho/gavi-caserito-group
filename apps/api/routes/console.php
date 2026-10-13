<?php

declare(strict_types=1);

use App\Modules\Fiscal\Application\FiscalReconciler;
use App\Modules\Notifications\Application\DebtReminderRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('fiscal:reconcile {--minutes=10 : Antigüedad mínima sin cambios}', function (FiscalReconciler $reconciler) {
    $result = $reconciler->run((int) $this->option('minutes'));
    $this->info("Reenviados: {$result['resent']}; consultados: {$result['refreshed']}.");
})->purpose('Reintenta o consulta los comprobantes electrónicos sin estado final');

Schedule::command('fiscal:reconcile')->everyTenMinutes()->withoutOverlapping();

Artisan::command('notify:debts {--days=2 : Días de anticipación} {--repeat=7 : Días entre avisos}', function (DebtReminderRunner $runner) {
    $count = $runner->run((int) $this->option('days'), (int) $this->option('repeat'));
    $this->info("Recordatorios encolados: {$count}.");
})->purpose('Avisa por WhatsApp las deudas por vencer o vencidas');

Schedule::command('notify:debts')->dailyAt('09:00')->withoutOverlapping();
