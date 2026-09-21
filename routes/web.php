<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Middleware\AdminSession;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/admin/login', 'admin.login')->name('login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::prefix('admin')->middleware(['auth', AdminSession::class])->group(function () {
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::get('dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('participants', [AdminController::class, 'participants'])->name('participants');
    Route::get('participants/{participant}', [AdminController::class, 'participant'])->name('participant');
    Route::delete('participants/{participant}', [AdminController::class, 'deleteParticipant'])->name('participant.delete');
    Route::get('recordings', [AdminController::class, 'recordings'])->name('recordings');
    Route::get('recordings/{recording}', [AdminController::class, 'recording'])->name('recording');
    Route::get('recordings/{recording}/download', [AdminController::class, 'download'])->name('recording.download');
    Route::get('system', [AdminController::class, 'system'])->name('system');
});
